<?php

namespace App\Enterprise\Finance;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\EnterpriseAccountsPayableAllocation;
use App\Models\EnterpriseAccountsPayableDocument;
use App\Models\EnterpriseFinanceAccount;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AccountsPayableService
{
    private const VIEW_PERMISSION = 'finance.view';

    private const MANAGE_PERMISSION = 'finance.manage';

    private const POST_PERMISSION = 'finance.post';

    private const READ_CAPABILITY = 'finance.ap.read';

    private const MANAGE_CAPABILITY = 'finance.ap.manage';

    private const POST_CAPABILITY = 'finance.ap.post';

    private const STATUSES = ['draft', 'posted', 'cancelled'];

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly GeneralLedgerService $generalLedger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{supplier_reference?:string,status?:string,date_from?:string,date_to?:string}  $filters
     * @return LengthAwarePaginator<int, EnterpriseAccountsPayableDocument>
     */
    public function paginate(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::VIEW_PERMISSION,
            self::READ_CAPABILITY,
        );
        $validated = Validator::make($filters, [
            'supplier_reference' => ['nullable', 'string', 'max:128'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ])->validate();

        $perPage = max(10, min(100, $perPage));

        return $this->documentQuery($scope)
            ->when(
                $validated['supplier_reference'] ?? null,
                fn (Builder $query, string $supplierReference): Builder => $query->where('supplier_reference', trim($supplierReference)),
            )
            ->when(
                $validated['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when(
                $validated['date_from'] ?? null,
                fn (Builder $query, string $date): Builder => $query->whereDate('entry_date', '>=', $date),
            )
            ->when(
                $validated['date_to'] ?? null,
                fn (Builder $query, string $date): Builder => $query->whereDate('entry_date', '<=', $date),
            )
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @param array<string, mixed> $attributes */
    public function createDraft(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseAccountsPayableDocument {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::MANAGE_PERMISSION,
            self::MANAGE_CAPABILITY,
        );
        $data = Validator::make($attributes, [
            'idempotency_key' => [
                'required',
                'string',
                'max:128',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/',
            ],
            'store_id' => ['required', 'integer', 'min:1'],
            'supplier_reference' => ['required', 'string', 'max:128'],
            'supplier_name' => ['nullable', 'string', 'max:190'],
            'entry_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:entry_date'],
            'amount' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'payable_account_id' => ['required', 'integer', 'min:1'],
            'offset_account_id' => ['required', 'integer', 'min:1', 'different:payable_account_id'],
            'description' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $this->assertStoreInScope($scope, (int) $data['store_id']);
        $this->assertPostingAccount($scope, (int) $data['payable_account_id']);
        $this->assertPostingAccount($scope, (int) $data['offset_account_id']);

        $supplierReference = trim((string) $data['supplier_reference']);
        $supplierName = $this->trimNullable($data['supplier_name'] ?? null);
        if ($supplierReference === '') {
            throw ValidationException::withMessages([
                'supplier_reference' => ['Supplier reference is required.'],
            ]);
        }

        $entryDate = CarbonImmutable::parse((string) $data['entry_date'])->toDateString();
        $dueDate = CarbonImmutable::parse((string) $data['due_date'])->toDateString();
        $amount = $this->formatMills($this->toMills($data['amount']));
        if ($this->toMills($amount) < 1) {
            throw ValidationException::withMessages([
                'amount' => ['AP amount must be greater than zero.'],
            ]);
        }

        $currency = strtoupper((string) ($data['currency_code'] ?? 'KWD'));
        $description = $this->trimNullable($data['description'] ?? null);
        $idempotencyKey = trim((string) $data['idempotency_key']);
        $requestHash = hash('sha256', json_encode([
            'store_id' => (int) $data['store_id'],
            'supplier_reference' => $supplierReference,
            'supplier_name' => $supplierName,
            'entry_date' => $entryDate,
            'due_date' => $dueDate,
            'amount' => $amount,
            'currency_code' => $currency,
            'payable_account_id' => (int) $data['payable_account_id'],
            'offset_account_id' => (int) $data['offset_account_id'],
            'description' => $description,
        ], JSON_THROW_ON_ERROR));

        $existing = $this->documentQuery($scope)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing instanceof EnterpriseAccountsPayableDocument) {
            $this->assertRequestHash($existing->request_hash, $requestHash);

            return $existing;
        }

        return DB::transaction(function () use (
            $actor,
            $scope,
            $data,
            $supplierReference,
            $supplierName,
            $entryDate,
            $dueDate,
            $amount,
            $currency,
            $description,
            $idempotencyKey,
            $requestHash,
        ): EnterpriseAccountsPayableDocument {
            $existing = $this->documentQuery($scope)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof EnterpriseAccountsPayableDocument) {
                $this->assertRequestHash($existing->request_hash, $requestHash);

                return $existing;
            }

            $document = EnterpriseAccountsPayableDocument::query()->create([
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'store_id' => (int) $data['store_id'],
                'supplier_reference' => $supplierReference,
                'supplier_name' => $supplierName,
                'document_number' => 'AP-'.Str::ulid(),
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'document_type' => 'invoice',
                'entry_date' => $entryDate,
                'due_date' => $dueDate,
                'amount' => $amount,
                'currency_code' => $currency,
                'payable_account_id' => (int) $data['payable_account_id'],
                'offset_account_id' => (int) $data['offset_account_id'],
                'description' => $description,
                'status' => 'draft',
                'created_by' => $actor->id,
            ]);

            $this->audit->record(
                'enterprise.finance.ap_document_created',
                $actor,
                $document,
                null,
                $document->fresh()->toArray(),
            );

            return $document->refresh();
        }, 3);
    }

    public function post(
        User $actor,
        ResolvedTenantContext $context,
        int $documentId,
    ): EnterpriseAccountsPayableDocument {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::POST_PERMISSION,
            self::POST_CAPABILITY,
        );

        return DB::transaction(function () use (
            $actor,
            $context,
            $scope,
            $documentId,
        ): EnterpriseAccountsPayableDocument {
            $document = $this->documentQuery($scope)
                ->whereKey($documentId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status === 'posted') {
                return $document;
            }

            if ($document->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => ['Only draft AP documents can be posted.'],
                ]);
            }

            if ((int) $document->created_by === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'poster' => ['The AP document creator cannot post the same document.'],
                ]);
            }

            $requester = User::query()->find($document->created_by);
            if (! $requester instanceof User) {
                throw ValidationException::withMessages([
                    'created_by' => ['The AP document creator is unavailable.'],
                ]);
            }

            $journal = $this->generalLedger->createDraft(
                $requester,
                $context,
                [
                    'idempotency_key' => 'ap-document:'.$document->id.':'.$document->request_hash,
                    'entry_date' => CarbonImmutable::parse((string) $document->entry_date)->toDateString(),
                    'description' => Str::limit(
                        'AP '.$document->document_number.($document->description ? ': '.$document->description : ''),
                        255,
                        '',
                    ),
                    'lines' => [
                        [
                            'account_id' => (int) $document->offset_account_id,
                            'debit' => $document->amount,
                            'credit' => '0.000',
                            'memo' => 'AP offset '.$document->document_number,
                        ],
                        [
                            'account_id' => (int) $document->payable_account_id,
                            'debit' => '0.000',
                            'credit' => $document->amount,
                            'memo' => 'AP payable '.$document->document_number,
                        ],
                    ],
                ],
            );
            $journal = $this->generalLedger->post($actor, $context, $journal->id);

            $before = $document->toArray();
            $document->forceFill([
                'status' => 'posted',
                'journal_id' => $journal->id,
                'posted_at' => $journal->posted_at ?? now(),
                'posted_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.finance.ap_document_posted',
                $actor,
                $document,
                $before,
                $document->fresh()->toArray(),
            );

            return $document->refresh();
        }, 3);
    }

    public function cancel(
        User $actor,
        ResolvedTenantContext $context,
        int $documentId,
    ): EnterpriseAccountsPayableDocument {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::MANAGE_PERMISSION,
            self::MANAGE_CAPABILITY,
        );

        return DB::transaction(function () use ($actor, $scope, $documentId): EnterpriseAccountsPayableDocument {
            $document = $this->documentQuery($scope)
                ->whereKey($documentId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($document->status === 'cancelled') {
                return $document;
            }

            if ($document->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => ['Posted AP documents require a controlled reversal path.'],
                ]);
            }

            $before = $document->toArray();
            $document->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.finance.ap_document_cancelled',
                $actor,
                $document,
                $before,
                $document->fresh()->toArray(),
            );

            return $document->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function createAllocation(
        User $actor,
        ResolvedTenantContext $context,
        int $documentId,
        array $attributes,
    ): EnterpriseAccountsPayableAllocation {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::MANAGE_PERMISSION,
            self::MANAGE_CAPABILITY,
        );
        $data = Validator::make($attributes, [
            'idempotency_key' => [
                'required',
                'string',
                'max:128',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/',
            ],
            'allocation_date' => ['required', 'date'],
            'amount' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'settlement_account_id' => ['required', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:180'],
        ])->validate();

        $document = $this->documentQuery($scope)->whereKey($documentId)->firstOrFail();
        if ($document->status !== 'posted') {
            throw ValidationException::withMessages([
                'status' => ['Only posted AP documents can receive allocations.'],
            ]);
        }

        $this->assertPostingAccount($scope, (int) $data['settlement_account_id']);
        $amount = $this->formatMills($this->toMills($data['amount']));
        if ($this->toMills($amount) < 1) {
            throw ValidationException::withMessages([
                'amount' => ['Allocation amount must be greater than zero.'],
            ]);
        }

        $allocationDate = CarbonImmutable::parse((string) $data['allocation_date'])->toDateString();
        $idempotencyKey = trim((string) $data['idempotency_key']);
        $reference = $this->trimNullable($data['reference'] ?? null);
        $requestHash = hash('sha256', json_encode([
            'allocation_date' => $allocationDate,
            'amount' => $amount,
            'settlement_account_id' => (int) $data['settlement_account_id'],
            'reference' => $reference,
        ], JSON_THROW_ON_ERROR));

        $existing = EnterpriseAccountsPayableAllocation::query()
            ->where('ap_document_id', $document->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing instanceof EnterpriseAccountsPayableAllocation) {
            $this->assertRequestHash($existing->request_hash, $requestHash);

            return $existing;
        }

        if ($this->toMills($amount) > $this->toMills($this->outstandingAmount($document))) {
            throw ValidationException::withMessages([
                'amount' => ['Allocation exceeds the outstanding AP amount.'],
            ]);
        }

        $allocation = EnterpriseAccountsPayableAllocation::query()->create([
            'ap_document_id' => $document->id,
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'allocation_date' => $allocationDate,
            'amount' => $amount,
            'settlement_account_id' => (int) $data['settlement_account_id'],
            'reference' => $reference,
            'status' => 'draft',
            'created_by' => $actor->id,
        ]);

        $this->audit->record(
            'enterprise.finance.ap_allocation_created',
            $actor,
            $allocation,
            null,
            $allocation->toArray(),
        );

        return $allocation->refresh();
    }

    public function postAllocation(
        User $actor,
        ResolvedTenantContext $context,
        int $allocationId,
    ): EnterpriseAccountsPayableAllocation {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::POST_PERMISSION,
            self::POST_CAPABILITY,
        );

        return DB::transaction(function () use (
            $actor,
            $context,
            $scope,
            $allocationId,
        ): EnterpriseAccountsPayableAllocation {
            $allocation = EnterpriseAccountsPayableAllocation::query()
                ->whereHas(
                    'document',
                    fn (Builder $query): Builder => $query
                        ->where('organization_id', $scope->organizationId)
                        ->where('company_id', $scope->companyId)
                        ->where('legal_entity_id', $scope->legalEntityId)
                        ->where('store_id', $scope->storeId),
                )
                ->whereKey($allocationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($allocation->status === 'posted') {
                return $allocation;
            }

            if ($allocation->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => ['Only draft AP allocations can be posted.'],
                ]);
            }

            if ((int) $allocation->created_by === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'poster' => ['The allocation creator cannot post the same allocation.'],
                ]);
            }

            $document = $this->documentQuery($scope)
                ->whereKey($allocation->ap_document_id)
                ->lockForUpdate()
                ->firstOrFail();

            $postedAllocations = EnterpriseAccountsPayableAllocation::query()
                ->where('ap_document_id', $document->id)
                ->where('status', 'posted')
                ->sum('amount');

            if (
                $this->toMills($postedAllocations) + $this->toMills($allocation->amount)
                > $this->toMills($document->amount)
            ) {
                throw ValidationException::withMessages([
                    'amount' => ['Posting would over-allocate the AP document.'],
                ]);
            }

            $requester = User::query()->find($allocation->created_by);
            if (! $requester instanceof User) {
                throw ValidationException::withMessages([
                    'created_by' => ['The allocation creator is unavailable.'],
                ]);
            }

            $journal = $this->generalLedger->createDraft(
                $requester,
                $context,
                [
                    'idempotency_key' => 'ap-allocation:'.$allocation->id.':'.$allocation->request_hash,
                    'entry_date' => CarbonImmutable::parse((string) $allocation->allocation_date)->toDateString(),
                    'description' => 'AP settlement '.$document->document_number,
                    'lines' => [
                        [
                            'account_id' => (int) $document->payable_account_id,
                            'debit' => $allocation->amount,
                            'credit' => '0.000',
                            'memo' => 'AP settlement '.$document->document_number,
                        ],
                        [
                            'account_id' => (int) $allocation->settlement_account_id,
                            'debit' => '0.000',
                            'credit' => $allocation->amount,
                            'memo' => $allocation->reference,
                        ],
                    ],
                ],
            );
            $journal = $this->generalLedger->post($actor, $context, $journal->id);

            $before = $allocation->toArray();
            $allocation->forceFill([
                'status' => 'posted',
                'journal_id' => $journal->id,
                'posted_at' => $journal->posted_at ?? now(),
                'posted_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.finance.ap_allocation_posted',
                $actor,
                $allocation,
                $before,
                $allocation->fresh()->toArray(),
            );

            return $allocation->refresh();
        }, 3);
    }

    /** @return Collection<int, \stdClass> */
    public function aging(
        User $actor,
        ResolvedTenantContext $context,
        ?string $supplierReference = null,
        int $limit = 25,
    ): Collection {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::VIEW_PERMISSION,
            self::READ_CAPABILITY,
        );
        $limit = max(1, min(100, $limit));

        $allocations = DB::table('enterprise_ap_allocations')
            ->selectRaw('ap_document_id, SUM(amount) as allocated_amount')
            ->where('status', 'posted')
            ->groupBy('ap_document_id');

        return DB::table('enterprise_ap_documents as document')
            ->leftJoinSub($allocations, 'allocations', 'allocations.ap_document_id', '=', 'document.id')
            ->where('document.organization_id', $scope->organizationId)
            ->where('document.company_id', $scope->companyId)
            ->where('document.legal_entity_id', $scope->legalEntityId)
            ->where('document.store_id', $scope->storeId)
            ->where('document.status', 'posted')
            ->when(
                $supplierReference !== null,
                fn ($query) => $query->where('document.supplier_reference', trim($supplierReference)),
            )
            ->whereRaw('(document.amount - COALESCE(allocations.allocated_amount, 0)) > 0')
            ->select([
                'document.id',
                'document.document_number',
                'document.supplier_reference',
                'document.supplier_name',
                'document.due_date',
                'document.amount',
                'document.currency_code',
            ])
            ->selectRaw('COALESCE(allocations.allocated_amount, 0) as allocated_amount')
            ->selectRaw(
                '(document.amount - COALESCE(allocations.allocated_amount, 0)) as outstanding_amount',
            )
            ->selectRaw(
                "CASE
                    WHEN document.due_date >= ? THEN 'current'
                    WHEN document.due_date >= ? THEN '1_30'
                    WHEN document.due_date >= ? THEN '31_60'
                    WHEN document.due_date >= ? THEN '61_90'
                    ELSE '91_plus'
                END as aging_bucket",
                [
                    now()->toDateString(),
                    now()->subDays(30)->toDateString(),
                    now()->subDays(60)->toDateString(),
                    now()->subDays(90)->toDateString(),
                ],
            )
            ->orderBy('document.due_date')
            ->orderBy('document.id')
            ->limit($limit)
            ->get();
    }

    private function outstandingAmount(
        EnterpriseAccountsPayableDocument $document,
    ): string {
        $allocated = EnterpriseAccountsPayableAllocation::query()
            ->where('ap_document_id', $document->id)
            ->where('status', 'posted')
            ->sum('amount');

        return $this->formatMills(
            max(
                0,
                $this->toMills($document->amount) - $this->toMills($allocated),
            ),
        );
    }

    private function authorizedScope(
        User $actor,
        ResolvedTenantContext $context,
        string $permission,
        string $capability,
    ): EnterpriseDataScope {
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');

        $scope = $context->enterpriseScope;
        $this->authorizer->authorize(
            $actor,
            $context,
            $permission,
            'legal_entity',
            $scope->legalEntityId,
        );

        abort_unless(
            $this->entitlements->allows(
                FinanceFoundationService::MODULE,
                new EntitlementScope(
                    EntitlementScope::LEGAL_ENTITY,
                    $scope->legalEntityId,
                ),
                $capability,
            ),
            403,
        );

        return $scope;
    }

    /** @return Builder<EnterpriseAccountsPayableDocument> */
    private function documentQuery(
        EnterpriseDataScope $scope,
    ): Builder {
        return EnterpriseAccountsPayableDocument::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('store_id', $scope->storeId);
    }

    private function assertStoreInScope(
        EnterpriseDataScope $scope,
        int $storeId,
    ): void {
        abort_unless($storeId === $scope->storeId, 404);

        $exists = DB::table('enterprise_store_scopes as store_scope')
            ->join(
                'enterprise_business_units as business_unit',
                'business_unit.id',
                '=',
                'store_scope.business_unit_id',
            )
            ->where('store_scope.store_id', $storeId)
            ->where('business_unit.legal_entity_id', $scope->legalEntityId)
            ->exists();

        abort_unless($exists, 404);
    }

    private function assertPostingAccount(
        EnterpriseDataScope $scope,
        int $accountId,
    ): void {
        $exists = EnterpriseFinanceAccount::query()
            ->whereKey($accountId)
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('status', 'active')
            ->where('allow_posting', true)
            ->where('is_control', false)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'account_id' => [
                    'Finance account must be an active posting account in the same legal entity.',
                ],
            ]);
        }
    }

    private function toMills(mixed $value): int
    {
        $raw = trim((string) $value);
        if (! preg_match('/^\d{1,15}(?:\.\d{1,3})?$/', $raw)) {
            throw ValidationException::withMessages([
                'amount' => ['Amount must have at most three decimals.'],
            ]);
        }

        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $fraction = str_pad($fraction, 3, '0');

        return ((int) $whole * 1000) + (int) $fraction;
    }

    private function formatMills(int $mills): string
    {
        return intdiv($mills, 1000).'.'.str_pad(
            (string) ($mills % 1000),
            3,
            '0',
            STR_PAD_LEFT,
        );
    }

    private function trimNullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function assertRequestHash(
        mixed $stored,
        string $requestHash,
    ): void {
        if (! is_string($stored) || ! hash_equals($stored, $requestHash)) {
            throw ValidationException::withMessages([
                'idempotency_key' => [
                    'Idempotency key was already used for a different AP request.',
                ],
            ]);
        }
    }
}
