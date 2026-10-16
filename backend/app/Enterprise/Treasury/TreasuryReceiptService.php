<?php

namespace App\Enterprise\Treasury;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\EnterpriseBankAccount;
use App\Models\EnterpriseCashbox;
use App\Models\EnterpriseTreasuryReceipt;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TreasuryReceiptService
{
    public const MODULE = 'treasury';

    private const VIEW_PERMISSION = 'finance.view';

    private const MANAGE_PERMISSION = 'finance.manage';

    private const APPROVE_PERMISSION = 'finance.manage';

    private const READ_CAPABILITY = 'treasury.receipts.read';

    private const MANAGE_CAPABILITY = 'treasury.receipts.manage';

    private const APPROVE_CAPABILITY = 'treasury.receipts.approve';

    private const STATUSES = [
        EnterpriseTreasuryReceipt::STATUS_DRAFT,
        EnterpriseTreasuryReceipt::STATUS_POSTED,
        EnterpriseTreasuryReceipt::STATUS_CANCELLED,
    ];

    private const TARGET_TYPES = [
        EnterpriseTreasuryReceipt::TARGET_CASHBOX,
        EnterpriseTreasuryReceipt::TARGET_BANK_ACCOUNT,
    ];

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly AuditLogger $audit,
        private readonly TreasuryIntegrationEventPublisher $integrationEvents,
    ) {}

    /**
     * @param  array{
     *   q?:string,
     *   status?:string,
     *   target_type?:string,
     *   value_date_from?:string,
     *   value_date_to?:string
     * }  $filters
     * @return LengthAwarePaginator<int, EnterpriseTreasuryReceipt>
     */
    public function paginateReceipts(
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
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'target_type' => ['nullable', Rule::in(self::TARGET_TYPES)],
            'value_date_from' => ['nullable', 'date'],
            'value_date_to' => ['nullable', 'date', 'after_or_equal:value_date_from'],
        ])->validate();

        $term = $this->safePrefix((string) ($validated['q'] ?? ''));
        $perPage = max(10, min(100, $perPage));

        return $this->receiptQuery($scope)
            ->when(
                $validated['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when(
                $validated['target_type'] ?? null,
                fn (Builder $query, string $targetType): Builder => $query->where('target_type', $targetType),
            )
            ->when(
                $validated['value_date_from'] ?? null,
                fn (Builder $query, string $date): Builder => $query->whereDate('value_date', '>=', $date),
            )
            ->when(
                $validated['value_date_to'] ?? null,
                fn (Builder $query, string $date): Builder => $query->whereDate('value_date', '<=', $date),
            )
            ->when(
                $term !== '',
                fn (Builder $query): Builder => $query->where(
                    fn (Builder $nested): Builder => $nested
                        ->where('receipt_number', 'like', $term.'%')
                        ->orWhere('reference', 'like', $term.'%'),
                ),
            )
            ->orderByDesc('value_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param array{
     *   idempotency_key:mixed,
     *   target_type:mixed,
     *   target_id:mixed,
     *   amount:mixed,
     *   value_date:mixed,
     *   reference:mixed,
     *   description?:mixed
     * } $attributes
     */
    public function createDraft(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseTreasuryReceipt {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::MANAGE_PERMISSION,
            self::MANAGE_CAPABILITY,
        );

        $data = $this->validatedDraft($attributes);
        $amountMills = $this->toMills($data['amount']);
        if ($amountMills < 1) {
            throw ValidationException::withMessages([
                'amount' => ['Receipt amount must be greater than zero.'],
            ]);
        }

        $normalized = [
            'target_type' => (string) $data['target_type'],
            'target_id' => (int) $data['target_id'],
            'amount_mills' => $amountMills,
            'value_date' => CarbonImmutable::parse((string) $data['value_date'])->toDateString(),
            'reference' => trim((string) $data['reference']),
            'description' => $this->trimNullable($data['description'] ?? null),
        ];
        $idempotencyKey = trim((string) $data['idempotency_key']);
        $requestHash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));

        $existing = $this->receiptQuery($scope)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing instanceof EnterpriseTreasuryReceipt) {
            $this->assertMatchingIdempotentRequest($existing, $requestHash);

            return $existing;
        }

        return DB::transaction(function () use (
            $actor,
            $scope,
            $idempotencyKey,
            $requestHash,
            $normalized,
        ): EnterpriseTreasuryReceipt {
            $existing = $this->receiptQuery($scope)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof EnterpriseTreasuryReceipt) {
                $this->assertMatchingIdempotentRequest($existing, $requestHash);

                return $existing;
            }

            $target = $this->targetForUsage(
                $scope,
                $normalized['target_type'],
                $normalized['target_id'],
                'operator_visibility',
                null,
                true,
            );

            $receipt = EnterpriseTreasuryReceipt::query()->create([
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'store_id' => $scope->storeId,
                'receipt_number' => 'RCPT-'.Str::ulid(),
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'target_type' => $normalized['target_type'],
                'target_id' => $normalized['target_id'],
                'amount' => $this->formatMills($normalized['amount_mills']),
                'currency_code' => strtoupper((string) $target->getAttribute('currency_code')),
                'value_date' => $normalized['value_date'],
                'reference' => $normalized['reference'],
                'description' => $normalized['description'],
                'status' => EnterpriseTreasuryReceipt::STATUS_DRAFT,
                'requested_by' => $actor->id,
            ]);

            $this->audit->record(
                'enterprise.treasury.receipt_created',
                $actor,
                $receipt,
                null,
                $receipt->fresh()->toArray(),
            );

            return $receipt->refresh();
        }, 3);
    }

    public function post(
        User $actor,
        ResolvedTenantContext $context,
        int $receiptId,
    ): EnterpriseTreasuryReceipt {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::APPROVE_PERMISSION,
            self::APPROVE_CAPABILITY,
        );

        return DB::transaction(function () use ($actor, $scope, $receiptId): EnterpriseTreasuryReceipt {
            /** @var EnterpriseTreasuryReceipt $receipt */
            $receipt = $this->receiptQuery($scope)
                ->whereKey($receiptId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($receipt->status === EnterpriseTreasuryReceipt::STATUS_POSTED) {
                return $receipt;
            }

            if ($receipt->status === EnterpriseTreasuryReceipt::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => ['A cancelled receipt cannot be posted.'],
                ]);
            }

            if ((int) $receipt->requested_by === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'approver' => ['The receipt operator cannot approve the same receipt.'],
                ]);
            }

            $this->targetForUsage(
                $scope,
                (string) $receipt->target_type,
                (int) $receipt->target_id,
                'approval_visibility',
                (string) $receipt->currency_code,
                true,
            );

            $before = $receipt->toArray();
            $receipt->forceFill([
                'status' => EnterpriseTreasuryReceipt::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by' => $actor->id,
            ])->save();

            $this->integrationEvents->publishReceiptPosted($actor, $scope, $receipt);

            $this->audit->record(
                'enterprise.treasury.receipt_posted',
                $actor,
                $receipt,
                $before,
                $receipt->fresh()->toArray(),
            );

            return $receipt->refresh();
        }, 3);
    }

    public function cancel(
        User $actor,
        ResolvedTenantContext $context,
        int $receiptId,
    ): EnterpriseTreasuryReceipt {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::MANAGE_PERMISSION,
            self::MANAGE_CAPABILITY,
        );

        return DB::transaction(function () use ($actor, $scope, $receiptId): EnterpriseTreasuryReceipt {
            /** @var EnterpriseTreasuryReceipt $receipt */
            $receipt = $this->receiptQuery($scope)
                ->whereKey($receiptId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($receipt->status === EnterpriseTreasuryReceipt::STATUS_CANCELLED) {
                return $receipt;
            }

            if ($receipt->status === EnterpriseTreasuryReceipt::STATUS_POSTED) {
                throw ValidationException::withMessages([
                    'status' => ['A posted receipt cannot be cancelled; a separate reversal path is required.'],
                ]);
            }

            $before = $receipt->toArray();
            $receipt->forceFill([
                'status' => EnterpriseTreasuryReceipt::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.treasury.receipt_cancelled',
                $actor,
                $receipt,
                $before,
                $receipt->fresh()->toArray(),
            );

            return $receipt->refresh();
        }, 3);
    }

    private function authorizedScope(
        User $actor,
        ResolvedTenantContext $context,
        string $permission,
        string $capability,
    ): EnterpriseDataScope {
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');

        $scope = $context->enterpriseScope;

        abort_unless(
            $this->entitlements->allows(
                self::MODULE,
                new EntitlementScope(EntitlementScope::STORE, $scope->storeId),
                $capability,
            ),
            403,
        );

        $this->authorizer->authorize(
            $actor,
            $context,
            $permission,
            'store',
            $scope->storeId,
        );

        return $scope;
    }

    /** @return Builder<EnterpriseTreasuryReceipt> */
    private function receiptQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseTreasuryReceipt::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId);
    }

    private function targetForUsage(
        EnterpriseDataScope $scope,
        string $targetType,
        int $targetId,
        string $visibilityField,
        ?string $expectedCurrency,
        bool $lock,
    ): EnterpriseCashbox|EnterpriseBankAccount {
        $query = match ($targetType) {
            EnterpriseTreasuryReceipt::TARGET_CASHBOX => EnterpriseCashbox::query(),
            EnterpriseTreasuryReceipt::TARGET_BANK_ACCOUNT => EnterpriseBankAccount::query(),
            default => throw ValidationException::withMessages([
                'target_type' => ['Unsupported Treasury receipt target type.'],
            ]),
        };

        $query->whereKey($targetId)->where('company_id', $scope->companyId);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var EnterpriseCashbox|EnterpriseBankAccount|null $target */
        $target = $query->first();

        if ($target === null) {
            $model = $targetType === EnterpriseTreasuryReceipt::TARGET_CASHBOX
                ? EnterpriseCashbox::class
                : EnterpriseBankAccount::class;

            throw (new ModelNotFoundException)->setModel($model, [$targetId]);
        }

        if (! $target->is_active) {
            throw ValidationException::withMessages([
                'target_id' => ['Treasury receipt target must be active.'],
            ]);
        }

        $visibility = (string) $target->getAttribute($visibilityField);
        if (
            $visibility !== EnterpriseCashbox::VISIBILITY_COMPANY
            && (
                $visibility !== EnterpriseCashbox::VISIBILITY_STORE
                || (int) $target->store_id !== $scope->storeId
            )
        ) {
            throw (new ModelNotFoundException)->setModel($target::class, [$targetId]);
        }

        if (
            $expectedCurrency !== null
            && strtoupper((string) $target->currency_code) !== strtoupper($expectedCurrency)
        ) {
            throw ValidationException::withMessages([
                'target_id' => ['Treasury receipt target currency no longer matches the receipt currency.'],
            ]);
        }

        return $target;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validatedDraft(array $attributes): array
    {
        foreach (['idempotency_key', 'reference', 'description'] as $field) {
            if (array_key_exists($field, $attributes) && is_string($attributes[$field])) {
                $attributes[$field] = trim($attributes[$field]);
            }
        }

        if (array_key_exists('amount', $attributes) && is_scalar($attributes['amount'])) {
            $attributes['amount'] = trim((string) $attributes['amount']);
        }

        return Validator::make($attributes, [
            'idempotency_key' => [
                'required',
                'string',
                'max:128',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/',
            ],
            'target_type' => ['required', Rule::in(self::TARGET_TYPES)],
            'target_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'string', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'value_date' => ['required', 'date'],
            'reference' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:255'],
        ])->validate();
    }

    private function toMills(mixed $value): int
    {
        $raw = trim((string) $value);
        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $fraction = str_pad($fraction, 3, '0');

        return ((int) $whole * 1000) + (int) $fraction;
    }

    private function formatMills(int $mills): string
    {
        return intdiv($mills, 1000).'.'.str_pad((string) ($mills % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function trimNullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function safePrefix(string $value): string
    {
        $trimmed = trim($value);

        return preg_replace('/[%_\\\\]+/', '', $trimmed) ?? '';
    }

    private function assertMatchingIdempotentRequest(
        EnterpriseTreasuryReceipt $receipt,
        string $requestHash,
    ): void {
        if (! hash_equals((string) $receipt->request_hash, $requestHash)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['Idempotency key was already used for a different Treasury receipt request.'],
            ]);
        }
    }
}
