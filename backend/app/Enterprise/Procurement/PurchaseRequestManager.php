<?php

namespace App\Enterprise\Procurement;

use App\Models\EnterprisePurchaseRequest;
use App\Models\EnterpriseSupplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PurchaseRequestManager
{
    private const VIEW_PERMISSION = 'procurement.purchase_requests.view';

    private const MANAGE_PERMISSION = 'procurement.purchase_requests.manage';

    private const APPROVE_PERMISSION = 'procurement.purchase_requests.approve';

    private const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'cancelled'];

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{q?:string,status?:string}  $filters
     * @return LengthAwarePaginator<int, EnterprisePurchaseRequest>
     */
    public function paginate(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION);
        $validated = Validator::make($filters, [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
        ])->validate();

        $perPage = max(10, min(100, $perPage));
        $term = trim((string) ($validated['q'] ?? ''));
        // Search the literal request-number prefix, not SQL wildcard patterns.
        $pattern = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        return $this->visibleQuery($scope)
            ->with('supplier:id,code,name_en,name_ar,status')
            ->when(
                $validated['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when(
                $term !== '',
                fn (Builder $query): Builder => $query->whereRaw("request_number LIKE ? ESCAPE '!'", [$pattern]),
            )
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function detail(
        User $actor,
        ResolvedTenantContext $context,
        int $requestId,
    ): EnterprisePurchaseRequest {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION);

        return $this->findVisible($scope, $requestId)
            ->load([
                'supplier:id,code,name_en,name_ar,status',
                'lines' => fn ($query) => $query->orderBy('line_no'),
            ]);
    }

    /** @param array<string, mixed> $attributes */
    public function create(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
        ?string $idempotencyKey = null,
    ): EnterprisePurchaseRequest {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);
        $idempotencyKey ??= $attributes['idempotency_key'] ?? null;
        unset($attributes['idempotency_key']);
        if ($idempotencyKey !== null) {
            Validator::make(['idempotency_key' => $idempotencyKey], [
                'idempotency_key' => ['required', 'uuid'],
            ])->validate();
        }

        $data = $this->validatedRequest($attributes);
        $payloadHash = $idempotencyKey === null ? null : $this->requestPayloadHash($data);

        if (($data['supplier_id'] ?? null) !== null) {
            $this->activeVisibleSupplier($scope, (int) $data['supplier_id']);
        }

        try {
            return DB::transaction(function () use ($actor, $scope, $data, $idempotencyKey, $payloadHash): EnterprisePurchaseRequest {
                if ($idempotencyKey !== null) {
                    $existing = $this->idempotentRequest($scope, $actor, $idempotencyKey, true);
                    if ($existing !== null) {
                        return $this->resolveCreateReplay($existing, (string) $payloadHash);
                    }
                }

                $publicId = (string) Str::uuid();
                $request = EnterprisePurchaseRequest::query()->create([
                    'public_id' => $publicId,
                    'request_number' => 'PR-'.strtoupper(substr(str_replace('-', '', $publicId), 0, 12)),
                    'organization_id' => $scope->organizationId,
                    'company_id' => $scope->companyId,
                    'store_id' => $scope->storeId,
                    'supplier_id' => $data['supplier_id'] ?? null,
                    'status' => 'draft',
                    'currency_code' => strtoupper((string) $data['currency_code']),
                    'needed_by' => $data['needed_by'] ?? null,
                    'reason' => $data['reason'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_payload_hash' => $payloadHash,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                $this->replaceLines($request, $data['lines']);

                $this->audit->record(
                    'enterprise.purchase_request.created',
                    $actor,
                    $request,
                    null,
                    [
                        'request_number' => $request->request_number,
                        'status' => $request->status,
                        'organization_id' => $request->organization_id,
                        'company_id' => $request->company_id,
                        'store_id' => $request->store_id,
                        'supplier_id' => $request->supplier_id,
                        'line_count' => count($data['lines']),
                    ],
                );

                return $request->load('lines');
            }, 3);
        } catch (QueryException $exception) {
            if ($idempotencyKey === null) {
                throw $exception;
            }

            $existing = $this->idempotentRequest($scope, $actor, $idempotencyKey);
            if ($existing === null) {
                throw $exception;
            }

            return $this->resolveCreateReplay($existing, (string) $payloadHash);
        }
    }

    /**
     * Update the creating maker's still-draft request through the existing
     * authorized POST collection action. A missing or foreign ID is never an
     * upsert; status and creator checks are repeated under the row lock.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateDraft(
        User $actor,
        ResolvedTenantContext $context,
        int $requestId,
        array $attributes,
    ): EnterprisePurchaseRequest {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);
        $data = $this->validatedRequest($attributes);

        if (($data['supplier_id'] ?? null) !== null) {
            $this->activeVisibleSupplier($scope, (int) $data['supplier_id']);
        }

        return DB::transaction(function () use ($actor, $scope, $requestId, $data): EnterprisePurchaseRequest {
            $request = $this->lockVisible($scope, $requestId);
            abort_if($request->status !== 'draft', 403, 'Only a draft purchase request can be edited.');
            abort_if((int) $request->created_by !== (int) $actor->id, 403, 'Only the creating maker can edit this draft.');

            $request->load(['lines' => fn ($query) => $query->orderBy('line_no')]);
            if ($this->requestMatchesPayload($request, $data)) {
                return $request;
            }

            $before = $this->requestAuditSnapshot($request);
            $request->forceFill([
                'supplier_id' => $data['supplier_id'] ?? null,
                'currency_code' => strtoupper((string) $data['currency_code']),
                'needed_by' => $data['needed_by'] ?? null,
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'updated_by' => $actor->id,
            ])->save();
            $this->replaceLines($request, $data['lines']);
            $request->load(['lines' => fn ($query) => $query->orderBy('line_no')]);

            $this->audit->record(
                'enterprise.purchase_request.updated',
                $actor,
                $request,
                $before,
                $this->requestAuditSnapshot($request),
            );

            return $request;
        }, 3);
    }

    public function submit(
        User $actor,
        ResolvedTenantContext $context,
        int $requestId,
    ): EnterprisePurchaseRequest {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);

        return DB::transaction(function () use ($actor, $scope, $requestId): EnterprisePurchaseRequest {
            $request = $this->lockVisible($scope, $requestId);
            if ($request->status === 'submitted') {
                return $request->refresh();
            }

            $this->requireStatus($request, ['draft'], 'submit');
            abort_if($request->lines()->count() < 1, 409, 'A purchase request must contain at least one line.');

            $before = $request->toArray();
            $request->forceFill([
                'status' => 'submitted',
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.purchase_request.submitted',
                $actor,
                $request,
                $before,
                $request->fresh()->toArray(),
            );

            return $request->refresh();
        }, 3);
    }

    public function approve(
        User $actor,
        ResolvedTenantContext $context,
        int $requestId,
        ?string $note = null,
    ): EnterprisePurchaseRequest {
        return $this->decide($actor, $context, $requestId, 'approved', $note);
    }

    public function reject(
        User $actor,
        ResolvedTenantContext $context,
        int $requestId,
        ?string $note = null,
    ): EnterprisePurchaseRequest {
        return $this->decide($actor, $context, $requestId, 'rejected', $note);
    }

    public function cancel(
        User $actor,
        ResolvedTenantContext $context,
        int $requestId,
    ): EnterprisePurchaseRequest {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);

        return DB::transaction(function () use ($actor, $scope, $requestId): EnterprisePurchaseRequest {
            $request = $this->lockVisible($scope, $requestId);
            if ($request->status === 'cancelled') {
                return $request->refresh();
            }

            $this->requireStatus($request, ['draft', 'submitted'], 'cancel');
            $before = $request->toArray();
            $request->forceFill([
                'status' => 'cancelled',
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.purchase_request.cancelled',
                $actor,
                $request,
                $before,
                $request->fresh()->toArray(),
            );

            return $request->refresh();
        }, 3);
    }

    private function decide(
        User $actor,
        ResolvedTenantContext $context,
        int $requestId,
        string $decision,
        ?string $note,
    ): EnterprisePurchaseRequest {
        $scope = $this->authorizedScope($actor, $context, self::APPROVE_PERMISSION);
        $note = $note === null ? null : trim($note);
        if ($note !== null && mb_strlen($note) > 2000) {
            throw ValidationException::withMessages([
                'decision_note' => ['Decision note may not be greater than 2000 characters.'],
            ]);
        }

        return DB::transaction(function () use (
            $actor,
            $scope,
            $requestId,
            $decision,
            $note,
        ): EnterprisePurchaseRequest {
            $request = $this->lockVisible($scope, $requestId);

            // A separate approver is mandatory even if the maker has approval permission.
            // Evaluate after scoped locking and before idempotent status handling so a
            // historical self-approved request cannot be silently re-affirmed.
            if ($decision === 'approved' && (
                (int) $request->created_by === (int) $actor->id
                || (int) $request->submitted_by === (int) $actor->id
            )) {
                throw ValidationException::withMessages([
                    'decision' => [__('enterprise_procurement.errors.self_approval')],
                ]);
            }

            if ($request->status === $decision) {
                return $request->refresh();
            }

            $this->requireStatus($request, ['submitted'], $decision);
            $before = $request->toArray();
            $request->forceFill([
                'status' => $decision,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.purchase_request.'.$decision,
                $actor,
                $request,
                $before,
                $request->fresh()->toArray(),
            );

            return $request->refresh();
        }, 3);
    }

    private function authorizedScope(
        User $actor,
        ResolvedTenantContext $context,
        string $permission,
    ): EnterpriseDataScope {
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');

        $scope = $context->enterpriseScope;
        $this->authorizer->authorize(
            $actor,
            $context,
            $permission,
            'company',
            $scope->companyId,
        );

        return $scope;
    }

    /** @return Builder<EnterprisePurchaseRequest> */
    private function visibleQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterprisePurchaseRequest::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId);
    }

    private function findVisible(
        EnterpriseDataScope $scope,
        int $requestId,
    ): EnterprisePurchaseRequest {
        return $this->visibleQuery($scope)->whereKey($requestId)->firstOrFail();
    }

    private function lockVisible(
        EnterpriseDataScope $scope,
        int $requestId,
    ): EnterprisePurchaseRequest {
        return $this->visibleQuery($scope)
            ->whereKey($requestId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function activeVisibleSupplier(
        EnterpriseDataScope $scope,
        int $supplierId,
    ): EnterpriseSupplier {
        return EnterpriseSupplier::query()
            ->whereKey($supplierId)
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('status', 'active')
            ->where(function (Builder $query) use ($scope): void {
                $query
                    ->where('scope_type', 'company')
                    ->orWhere(function (Builder $store) use ($scope): void {
                        $store
                            ->where('scope_type', 'store')
                            ->where('store_id', $scope->storeId);
                    });
            })
            ->firstOrFail();
    }

    private function idempotentRequest(
        EnterpriseDataScope $scope,
        User $actor,
        string $idempotencyKey,
        bool $lock = false,
    ): ?EnterprisePurchaseRequest {
        $query = $this->visibleQuery($scope)
            ->where('created_by', $actor->id)
            ->where('idempotency_key', $idempotencyKey);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function resolveCreateReplay(
        EnterprisePurchaseRequest $existing,
        string $payloadHash,
    ): EnterprisePurchaseRequest {
        $storedHash = (string) ($existing->idempotency_payload_hash ?? '');
        if ($storedHash !== '' && hash_equals($storedHash, $payloadHash)) {
            return $existing->load('lines');
        }

        throw ValidationException::withMessages([
            'idempotency_key' => [__('purchase_requests.idempotency_conflict')],
        ]);
    }

    /** @param list<array<string, mixed>> $lines */
    private function replaceLines(EnterprisePurchaseRequest $request, array $lines): void
    {
        $request->lines()->delete();
        foreach ($lines as $index => $line) {
            $request->lines()->create([
                'line_no' => $index + 1,
                'item_code' => $line['item_code'] ?? null,
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit' => $line['unit'],
                'estimated_unit_cost' => $line['estimated_unit_cost'] ?? null,
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function requestPayloadHash(array $data): string
    {
        $normalized = [
            'supplier_id' => isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            'currency_code' => strtoupper((string) $data['currency_code']),
            'needed_by' => empty($data['needed_by']) ? null : Carbon::parse($data['needed_by'])->toDateString(),
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
            'lines' => array_map(static fn (array $line): array => [
                'item_code' => $line['item_code'] ?? null,
                'description' => $line['description'],
                'quantity' => self::decimalIdentity($line['quantity']),
                'unit' => $line['unit'],
                'estimated_unit_cost' => self::decimalIdentity($line['estimated_unit_cost'] ?? null),
            ], array_values($data['lines'])),
        ];

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    private static function decimalIdentity(mixed $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 3, '.', '');
    }

    /** @param array<string, mixed> $data */
    private function requestMatchesPayload(EnterprisePurchaseRequest $request, array $data): bool
    {
        $storedNeededBy = $request->getRawOriginal('needed_by');
        $storedNeededBy = $storedNeededBy === null
            ? null
            : Carbon::parse((string) $storedNeededBy)->toDateString();

        if (
            ($request->supplier_id === null ? null : (int) $request->supplier_id)
                !== (isset($data['supplier_id']) ? (int) $data['supplier_id'] : null)
            || strtoupper((string) $request->currency_code) !== strtoupper((string) $data['currency_code'])
            || $storedNeededBy !== (empty($data['needed_by']) ? null : Carbon::parse($data['needed_by'])->toDateString())
            || $request->reason !== ($data['reason'] ?? null)
            || $request->notes !== ($data['notes'] ?? null)
            || $request->lines->count() !== count($data['lines'])
        ) {
            return false;
        }

        foreach (array_values($data['lines']) as $index => $line) {
            $existing = $request->lines->get($index);
            if (
                $existing === null
                || $existing->item_code !== ($line['item_code'] ?? null)
                || $existing->description !== $line['description']
                || self::decimalIdentity($existing->quantity) !== self::decimalIdentity($line['quantity'])
                || $existing->unit !== $line['unit']
                || self::decimalIdentity($existing->estimated_unit_cost)
                    !== self::decimalIdentity($line['estimated_unit_cost'] ?? null)
            ) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function requestAuditSnapshot(EnterprisePurchaseRequest $request): array
    {
        $snapshot = $request->toArray();
        $snapshot['lines'] = $request->lines->map(static fn ($line): array => $line->toArray())->all();

        return $snapshot;
    }

    /** @param list<string> $allowed */
    private function requireStatus(
        EnterprisePurchaseRequest $request,
        array $allowed,
        string $action,
    ): void {
        if (in_array((string) $request->status, $allowed, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => [
                sprintf(
                    'Purchase request %s cannot be %s from status %s.',
                    $request->request_number,
                    $action,
                    $request->status,
                ),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{
     *     supplier_id?:int|null,
     *     currency_code:string,
     *     needed_by?:string|null,
     *     reason?:string|null,
     *     notes?:string|null,
     *     lines:list<array{
     *         item_code?:string|null,
     *         description:string,
     *         quantity:mixed,
     *         unit:string,
     *         estimated_unit_cost?:mixed
     *     }>
     * }
     */
    private function validatedRequest(array $attributes): array
    {
        if (array_key_exists('currency_code', $attributes)) {
            $attributes['currency_code'] = strtoupper(trim((string) $attributes['currency_code']));
        }

        $data = Validator::make($attributes, [
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'needed_by' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.item_code' => ['nullable', 'string', 'max:96'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:999999999'],
            'lines.*.unit' => ['required', 'string', 'max:32'],
            'lines.*.estimated_unit_cost' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:999999999'],
        ])->validate();
        $data['lines'] = array_values($data['lines']);

        return $data;
    }
}
