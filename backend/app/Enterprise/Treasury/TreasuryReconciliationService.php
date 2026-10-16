<?php

namespace App\Enterprise\Treasury;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\EnterpriseBankAccount;
use App\Models\EnterpriseCashbox;
use App\Models\EnterpriseTreasuryCashClosing;
use App\Models\EnterpriseTreasuryPayment;
use App\Models\EnterpriseTreasuryReceipt;
use App\Models\EnterpriseTreasuryReconciliation;
use App\Models\EnterpriseTreasuryReconciliationLine;
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

final class TreasuryReconciliationService
{
    public const MODULE = 'treasury';

    private const VIEW_PERMISSION = 'finance.view';

    private const MANAGE_PERMISSION = 'finance.manage';

    private const APPROVE_PERMISSION = 'finance.manage';

    private const RECONCILIATION_READ_CAPABILITY = 'treasury.reconciliation.read';

    private const RECONCILIATION_MANAGE_CAPABILITY = 'treasury.reconciliation.manage';

    private const RECONCILIATION_APPROVE_CAPABILITY = 'treasury.reconciliation.approve';

    private const CASH_CLOSE_READ_CAPABILITY = 'treasury.cash_closing.read';

    private const CASH_CLOSE_MANAGE_CAPABILITY = 'treasury.cash_closing.manage';

    private const CASH_CLOSE_APPROVE_CAPABILITY = 'treasury.cash_closing.approve';

    private const TRANSACTION_TYPES = [
        EnterpriseTreasuryReconciliationLine::TYPE_RECEIPT,
        EnterpriseTreasuryReconciliationLine::TYPE_PAYMENT,
    ];

    private const DEFAULT_LIST_WINDOW_DAYS = 90;

    private const MAX_LIST_WINDOW_DAYS = 366;

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{status?:string,bank_account_id?:int,date_from?:string,date_to?:string}  $filters
     * @return LengthAwarePaginator<int, EnterpriseTreasuryReconciliation>
     */
    public function paginateReconciliations(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::VIEW_PERMISSION,
            self::RECONCILIATION_READ_CAPABILITY,
        );
        $validated = Validator::make($filters, [
            'status' => ['nullable', Rule::in([
                EnterpriseTreasuryReconciliation::STATUS_DRAFT,
                EnterpriseTreasuryReconciliation::STATUS_CLOSED,
            ])],
            'bank_account_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ])->validate();

        $window = $this->boundedDateWindow($validated);
        $perPage = max(10, min(100, $perPage));

        return $this->reconciliationQuery($scope)
            ->when(
                $validated['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when(
                $validated['bank_account_id'] ?? null,
                fn (Builder $query, int $bankId): Builder => $query->where('bank_account_id', $bankId),
            )
            ->whereDate('period_to', '>=', $window['from'])
            ->whereDate('period_from', '<=', $window['to'])
            ->orderByDesc('period_to')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @param array<string, mixed> $attributes */
    public function createReconciliationDraft(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseTreasuryReconciliation {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::MANAGE_PERMISSION,
            self::RECONCILIATION_MANAGE_CAPABILITY,
        );
        $data = Validator::make($attributes, [
            'idempotency_key' => [
                'required',
                'string',
                'max:128',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/',
            ],
            'bank_account_id' => ['required', 'integer', 'min:1'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'statement_receipts_total' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'statement_payments_total' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
        ])->validate();

        $bank = $this->bankAccountForScope($scope, (int) $data['bank_account_id'], false);
        $normalized = [
            'bank_account_id' => (int) $bank->id,
            'period_from' => CarbonImmutable::parse((string) $data['period_from'])->toDateString(),
            'period_to' => CarbonImmutable::parse((string) $data['period_to'])->toDateString(),
            'statement_receipts_total' => $this->formatMills($this->toMills($data['statement_receipts_total'])),
            'statement_payments_total' => $this->formatMills($this->toMills($data['statement_payments_total'])),
            'currency_code' => strtoupper((string) $bank->currency_code),
        ];
        $idempotencyKey = trim((string) $data['idempotency_key']);
        $requestHash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));

        $existing = $this->reconciliationQuery($scope)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing instanceof EnterpriseTreasuryReconciliation) {
            $this->assertRequestHash($existing->request_hash, $requestHash);

            return $existing;
        }

        return DB::transaction(function () use (
            $actor,
            $scope,
            $idempotencyKey,
            $requestHash,
            $normalized,
        ): EnterpriseTreasuryReconciliation {
            $existing = $this->reconciliationQuery($scope)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof EnterpriseTreasuryReconciliation) {
                $this->assertRequestHash($existing->request_hash, $requestHash);

                return $existing;
            }

            $reconciliation = EnterpriseTreasuryReconciliation::query()->create([
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'store_id' => $scope->storeId,
                'bank_account_id' => $normalized['bank_account_id'],
                'reconciliation_number' => 'REC-'.Str::ulid(),
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'period_from' => $normalized['period_from'],
                'period_to' => $normalized['period_to'],
                'statement_receipts_total' => $normalized['statement_receipts_total'],
                'statement_payments_total' => $normalized['statement_payments_total'],
                'currency_code' => $normalized['currency_code'],
                'status' => EnterpriseTreasuryReconciliation::STATUS_DRAFT,
                'requested_by' => $actor->id,
            ]);

            $this->audit->record(
                'enterprise.treasury.reconciliation_created',
                $actor,
                $reconciliation,
                null,
                $reconciliation->fresh()->toArray(),
            );

            return $reconciliation->refresh();
        }, 3);
    }

    public function addMatch(
        User $actor,
        ResolvedTenantContext $context,
        int $reconciliationId,
        string $transactionType,
        int $transactionId,
    ): EnterpriseTreasuryReconciliationLine {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::MANAGE_PERMISSION,
            self::RECONCILIATION_MANAGE_CAPABILITY,
        );
        Validator::make(
            ['transaction_type' => $transactionType, 'transaction_id' => $transactionId],
            [
                'transaction_type' => ['required', Rule::in(self::TRANSACTION_TYPES)],
                'transaction_id' => ['required', 'integer', 'min:1'],
            ],
        )->validate();

        return DB::transaction(function () use (
            $actor,
            $scope,
            $reconciliationId,
            $transactionType,
            $transactionId,
        ): EnterpriseTreasuryReconciliationLine {
            $reconciliation = $this->reconciliationQuery($scope)
                ->whereKey($reconciliationId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($reconciliation->status !== EnterpriseTreasuryReconciliation::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => ['Only draft reconciliations can accept matches.'],
                ]);
            }

            $transaction = $this->reconcilableTransaction(
                $scope,
                $reconciliation,
                $transactionType,
                $transactionId,
                true,
            );

            $existing = EnterpriseTreasuryReconciliationLine::query()
                ->where('transaction_type', $transactionType)
                ->where('transaction_id', $transactionId)
                ->first();
            if ($existing instanceof EnterpriseTreasuryReconciliationLine) {
                if ((int) $existing->reconciliation_id === (int) $reconciliation->id) {
                    return $existing;
                }

                throw ValidationException::withMessages([
                    'transaction_id' => ['Treasury transaction is already assigned to another reconciliation.'],
                ]);
            }
            $line = EnterpriseTreasuryReconciliationLine::query()->create([
                'reconciliation_id' => $reconciliation->id,
                'transaction_type' => $transactionType,
                'transaction_id' => $transaction->id,
                'amount' => $transaction->amount,
                'value_date' => CarbonImmutable::parse((string) $transaction->value_date)->toDateString(),
                'reference' => $transaction->reference,
            ]);

            $this->audit->record(
                'enterprise.treasury.reconciliation_match_added',
                $actor,
                $reconciliation,
                null,
                [
                    'line_id' => $line->id,
                    'transaction_type' => $transactionType,
                    'transaction_id' => $transaction->id,
                    'amount' => $line->amount,
                ],
            );

            return $line->refresh();
        }, 3);
    }

    public function closeReconciliation(
        User $actor,
        ResolvedTenantContext $context,
        int $reconciliationId,
    ): EnterpriseTreasuryReconciliation {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::APPROVE_PERMISSION,
            self::RECONCILIATION_APPROVE_CAPABILITY,
        );

        return DB::transaction(function () use ($actor, $scope, $reconciliationId): EnterpriseTreasuryReconciliation {
            $reconciliation = $this->reconciliationQuery($scope)
                ->whereKey($reconciliationId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($reconciliation->status === EnterpriseTreasuryReconciliation::STATUS_CLOSED) {
                return $reconciliation;
            }
            if ((int) $reconciliation->requested_by === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'approver' => ['The reconciliation maker cannot close the same reconciliation.'],
                ]);
            }

            $receiptTotal = EnterpriseTreasuryReconciliationLine::query()
                ->where('reconciliation_id', $reconciliation->id)
                ->where('transaction_type', EnterpriseTreasuryReconciliationLine::TYPE_RECEIPT)
                ->sum('amount');
            $paymentTotal = EnterpriseTreasuryReconciliationLine::query()
                ->where('reconciliation_id', $reconciliation->id)
                ->where('transaction_type', EnterpriseTreasuryReconciliationLine::TYPE_PAYMENT)
                ->sum('amount');

            if (
                $this->toMills($receiptTotal) !== $this->toMills($reconciliation->statement_receipts_total)
                || $this->toMills($paymentTotal) !== $this->toMills($reconciliation->statement_payments_total)
            ) {
                throw ValidationException::withMessages([
                    'statement_totals' => ['Matched internal Treasury totals do not match the bank statement totals.'],
                ]);
            }

            $before = $reconciliation->toArray();
            $reconciliation->forceFill([
                'status' => EnterpriseTreasuryReconciliation::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.treasury.reconciliation_closed',
                $actor,
                $reconciliation,
                $before,
                $reconciliation->fresh()->toArray(),
            );

            return $reconciliation->refresh();
        }, 3);
    }

    /**
     * @param  array{status?:string,cashbox_id?:int,date_from?:string,date_to?:string}  $filters
     * @return LengthAwarePaginator<int, EnterpriseTreasuryCashClosing>
     */
    public function paginateCashClosings(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::VIEW_PERMISSION,
            self::CASH_CLOSE_READ_CAPABILITY,
        );
        $validated = Validator::make($filters, [
            'status' => ['nullable', Rule::in([
                EnterpriseTreasuryCashClosing::STATUS_DRAFT,
                EnterpriseTreasuryCashClosing::STATUS_CLOSED,
            ])],
            'cashbox_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ])->validate();
        $window = $this->boundedDateWindow($validated);
        $perPage = max(10, min(100, $perPage));

        return $this->cashClosingQuery($scope)
            ->when(
                $validated['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when(
                $validated['cashbox_id'] ?? null,
                fn (Builder $query, int $cashboxId): Builder => $query->where('cashbox_id', $cashboxId),
            )
            ->whereDate('business_date', '>=', $window['from'])
            ->whereDate('business_date', '<=', $window['to'])
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @param array<string, mixed> $attributes */
    public function createCashClosingDraft(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseTreasuryCashClosing {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::MANAGE_PERMISSION,
            self::CASH_CLOSE_MANAGE_CAPABILITY,
        );
        $data = Validator::make($attributes, [
            'idempotency_key' => [
                'required',
                'string',
                'max:128',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/',
            ],
            'cashbox_id' => ['required', 'integer', 'min:1'],
            'business_date' => ['required', 'date'],
            'opening_amount' => ['required', 'regex:/^\\d{1,15}(?:\\.\\d{1,3})?$/'],
            'declared_closing_amount' => ['required', 'regex:/^\\d{1,15}(?:\\.\\d{1,3})?$/'],
        ])->validate();

        $idempotencyKey = trim((string) $data['idempotency_key']);
        $cashboxId = (int) $data['cashbox_id'];
        $businessDate = CarbonImmutable::parse((string) $data['business_date'])->toDateString();
        $openingMills = $this->toMills($data['opening_amount']);
        $declaredClosingMills = $this->toMills($data['declared_closing_amount']);

        return DB::transaction(function () use (
            $actor,
            $scope,
            $idempotencyKey,
            $cashboxId,
            $businessDate,
            $openingMills,
            $declaredClosingMills,
        ): EnterpriseTreasuryCashClosing {
            $cashbox = $this->cashboxForScope($scope, $cashboxId, true);
            $normalized = [
                'cashbox_id' => (int) $cashbox->id,
                'business_date' => $businessDate,
                'opening_amount' => $this->formatMills($openingMills),
                'declared_closing_amount' => $this->formatMills($declaredClosingMills),
                'currency_code' => strtoupper((string) $cashbox->currency_code),
            ];
            $requestHash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));

            $existing = $this->cashClosingQuery($scope)
                ->where(function (Builder $query) use ($idempotencyKey, $normalized): void {
                    $query->where('idempotency_key', $idempotencyKey)
                        ->orWhere(function (Builder $sameDay) use ($normalized): void {
                            $sameDay->where('cashbox_id', $normalized['cashbox_id'])
                                ->whereDate('business_date', $normalized['business_date']);
                        });
                })
                ->lockForUpdate()
                ->first();
            if ($existing instanceof EnterpriseTreasuryCashClosing) {
                $this->assertRequestHash($existing->request_hash, $requestHash);

                return $existing;
            }

            $activity = $this->cashboxActivity($scope, (int) $cashbox->id, $businessDate);
            $expectedMills = $openingMills + $activity['receipts_mills'] - $activity['payments_mills'];
            if ($expectedMills < 0) {
                throw ValidationException::withMessages([
                    'opening_amount' => ['Cashbox activity would produce a negative expected closing balance.'],
                ]);
            }

            $closing = EnterpriseTreasuryCashClosing::query()->create([
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'store_id' => $scope->storeId,
                'cashbox_id' => $normalized['cashbox_id'],
                'closing_number' => 'CLOSE-'.Str::ulid(),
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'business_date' => $normalized['business_date'],
                'opening_amount' => $normalized['opening_amount'],
                'receipt_total' => $this->formatMills($activity['receipts_mills']),
                'payment_total' => $this->formatMills($activity['payments_mills']),
                'expected_closing_amount' => $this->formatMills($expectedMills),
                'declared_closing_amount' => $normalized['declared_closing_amount'],
                'currency_code' => $normalized['currency_code'],
                'status' => EnterpriseTreasuryCashClosing::STATUS_DRAFT,
                'requested_by' => $actor->id,
            ]);

            $this->audit->record(
                'enterprise.treasury.cash_closing_created',
                $actor,
                $closing,
                null,
                $closing->fresh()->toArray(),
            );

            return $closing->refresh();
        }, 3);
    }

    public function closeCashClosing(
        User $actor,
        ResolvedTenantContext $context,
        int $closingId,
    ): EnterpriseTreasuryCashClosing {
        $scope = $this->authorizedScope(
            $actor,
            $context,
            self::APPROVE_PERMISSION,
            self::CASH_CLOSE_APPROVE_CAPABILITY,
        );

        return DB::transaction(function () use ($actor, $scope, $closingId): EnterpriseTreasuryCashClosing {
            $closing = $this->cashClosingQuery($scope)
                ->whereKey($closingId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($closing->status === EnterpriseTreasuryCashClosing::STATUS_CLOSED) {
                return $closing;
            }
            if ((int) $closing->requested_by === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'approver' => ['The cash-closing maker cannot close the same cashbox day.'],
                ]);
            }

            $this->cashboxForScope($scope, (int) $closing->cashbox_id, true);
            $businessDate = CarbonImmutable::parse((string) $closing->business_date)->toDateString();
            $activity = $this->cashboxActivity($scope, (int) $closing->cashbox_id, $businessDate);
            $expectedMills = $this->toMills($closing->opening_amount)
                + $activity['receipts_mills']
                - $activity['payments_mills'];

            if ($expectedMills < 0 || $expectedMills !== $this->toMills($closing->declared_closing_amount)) {
                throw ValidationException::withMessages([
                    'declared_closing_amount' => [
                        'Declared cash closing does not reconcile to opening balance plus posted Treasury activity.',
                    ],
                ]);
            }

            $before = $closing->toArray();
            $closing->forceFill([
                'receipt_total' => $this->formatMills($activity['receipts_mills']),
                'payment_total' => $this->formatMills($activity['payments_mills']),
                'expected_closing_amount' => $this->formatMills($expectedMills),
                'status' => EnterpriseTreasuryCashClosing::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.treasury.cash_closing_closed',
                $actor,
                $closing,
                $before,
                $closing->fresh()->toArray(),
            );

            return $closing->refresh();
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
        $this->authorizer->authorize($actor, $context, $permission, 'store', $scope->storeId);

        return $scope;
    }

    /** @return Builder<EnterpriseTreasuryReconciliation> */
    private function reconciliationQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseTreasuryReconciliation::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('store_id', $scope->storeId);
    }

    /** @return Builder<EnterpriseTreasuryCashClosing> */
    private function cashClosingQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseTreasuryCashClosing::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('store_id', $scope->storeId);
    }

    private function bankAccountForScope(
        EnterpriseDataScope $scope,
        int $bankAccountId,
        bool $lock,
    ): EnterpriseBankAccount {
        $query = EnterpriseBankAccount::query()
            ->whereKey($bankAccountId)
            ->where('company_id', $scope->companyId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $bank = $query->first();
        if (! $bank instanceof EnterpriseBankAccount) {
            throw (new ModelNotFoundException)->setModel(EnterpriseBankAccount::class, [$bankAccountId]);
        }
        if (! $bank->is_active) {
            throw ValidationException::withMessages([
                'bank_account_id' => ['Bank account must be active.'],
            ]);
        }
        if (
            $bank->approval_visibility !== EnterpriseBankAccount::VISIBILITY_COMPANY
            && (
                $bank->approval_visibility !== EnterpriseBankAccount::VISIBILITY_STORE
                || (int) $bank->store_id !== $scope->storeId
            )
        ) {
            throw (new ModelNotFoundException)->setModel(EnterpriseBankAccount::class, [$bankAccountId]);
        }

        return $bank;
    }

    private function cashboxForScope(
        EnterpriseDataScope $scope,
        int $cashboxId,
        bool $lock,
    ): EnterpriseCashbox {
        $query = EnterpriseCashbox::query()
            ->whereKey($cashboxId)
            ->where('company_id', $scope->companyId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $cashbox = $query->first();
        if (! $cashbox instanceof EnterpriseCashbox) {
            throw (new ModelNotFoundException)->setModel(EnterpriseCashbox::class, [$cashboxId]);
        }
        if (! $cashbox->is_active) {
            throw ValidationException::withMessages([
                'cashbox_id' => ['Cashbox must be active.'],
            ]);
        }
        if (
            $cashbox->approval_visibility !== EnterpriseCashbox::VISIBILITY_COMPANY
            && (
                $cashbox->approval_visibility !== EnterpriseCashbox::VISIBILITY_STORE
                || (int) $cashbox->store_id !== $scope->storeId
            )
        ) {
            throw (new ModelNotFoundException)->setModel(EnterpriseCashbox::class, [$cashboxId]);
        }

        return $cashbox;
    }

    private function reconcilableTransaction(
        EnterpriseDataScope $scope,
        EnterpriseTreasuryReconciliation $reconciliation,
        string $transactionType,
        int $transactionId,
        bool $lock = false,
    ): EnterpriseTreasuryReceipt|EnterpriseTreasuryPayment {
        $query = match ($transactionType) {
            EnterpriseTreasuryReconciliationLine::TYPE_RECEIPT => EnterpriseTreasuryReceipt::query()
                ->where('status', EnterpriseTreasuryReceipt::STATUS_POSTED),
            EnterpriseTreasuryReconciliationLine::TYPE_PAYMENT => EnterpriseTreasuryPayment::query()
                ->where('status', EnterpriseTreasuryPayment::STATUS_POSTED),
            default => throw ValidationException::withMessages([
                'transaction_type' => ['Unsupported reconciliation transaction type.'],
            ]),
        };

        $query
            ->whereKey($transactionId)
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $transaction = $query->first();

        if (
            ! $transaction instanceof EnterpriseTreasuryReceipt
            && ! $transaction instanceof EnterpriseTreasuryPayment
        ) {
            $model = $transactionType === EnterpriseTreasuryReconciliationLine::TYPE_RECEIPT
                ? EnterpriseTreasuryReceipt::class
                : EnterpriseTreasuryPayment::class;

            throw (new ModelNotFoundException)->setModel($model, [$transactionId]);
        }

        $periodFrom = CarbonImmutable::parse((string) $reconciliation->period_from)->startOfDay();
        $periodTo = CarbonImmutable::parse((string) $reconciliation->period_to)->endOfDay();
        $valueDate = CarbonImmutable::parse((string) $transaction->value_date);
        if (
            (string) $transaction->target_type !== EnterpriseTreasuryReceipt::TARGET_BANK_ACCOUNT
            || (int) $transaction->target_id !== (int) $reconciliation->bank_account_id
            || $valueDate->lt($periodFrom)
            || $valueDate->gt($periodTo)
        ) {
            throw ValidationException::withMessages([
                'transaction_id' => [
                    'Treasury transaction must belong to this bank account and reconciliation period.',
                ],
            ]);
        }

        if (strtoupper((string) $transaction->currency_code) !== strtoupper((string) $reconciliation->currency_code)) {
            throw ValidationException::withMessages([
                'transaction_id' => ['Treasury transaction currency does not match the reconciliation currency.'],
            ]);
        }

        return $transaction;
    }

    /** @return array{receipts_mills:int,payments_mills:int} */
    private function cashboxActivity(
        EnterpriseDataScope $scope,
        int $cashboxId,
        string $businessDate,
    ): array {
        $receiptTotal = EnterpriseTreasuryReceipt::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->where('target_type', EnterpriseTreasuryReceipt::TARGET_CASHBOX)
            ->where('target_id', $cashboxId)
            ->where('status', EnterpriseTreasuryReceipt::STATUS_POSTED)
            ->whereDate('value_date', $businessDate)
            ->sum('amount');

        $paymentTotal = EnterpriseTreasuryPayment::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->where('target_type', EnterpriseTreasuryPayment::TARGET_CASHBOX)
            ->where('target_id', $cashboxId)
            ->where('status', EnterpriseTreasuryPayment::STATUS_POSTED)
            ->whereDate('value_date', $businessDate)
            ->sum('amount');

        return [
            'receipts_mills' => $this->toMills($receiptTotal),
            'payments_mills' => $this->toMills($paymentTotal),
        ];
    }

    /**
     * @param  array{date_from?:string,date_to?:string}  $filters
     * @return array{from:string,to:string}
     */
    private function boundedDateWindow(array $filters): array
    {
        $hasFrom = isset($filters['date_from']);
        $hasTo = isset($filters['date_to']);

        if (! $hasFrom && ! $hasTo) {
            $to = CarbonImmutable::today();
            $from = $to->subDays(self::DEFAULT_LIST_WINDOW_DAYS - 1);
        } elseif ($hasFrom && ! $hasTo) {
            $from = CarbonImmutable::parse((string) $filters['date_from'])->startOfDay();
            $to = $from->addDays(self::MAX_LIST_WINDOW_DAYS - 1);
        } elseif (! $hasFrom && $hasTo) {
            $to = CarbonImmutable::parse((string) $filters['date_to'])->startOfDay();
            $from = $to->subDays(self::MAX_LIST_WINDOW_DAYS - 1);
        } else {
            $from = CarbonImmutable::parse((string) $filters['date_from'])->startOfDay();
            $to = CarbonImmutable::parse((string) $filters['date_to'])->startOfDay();
        }

        if (($from->diffInDays($to) + 1) > self::MAX_LIST_WINDOW_DAYS) {
            throw ValidationException::withMessages([
                'date_to' => ['Date range may not exceed 366 days.'],
            ]);
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];
    }

    private function toMills(mixed $value): int
    {
        $raw = trim((string) $value);
        if (! preg_match('/^\d{1,15}(?:\.\d{1,3})?$/', $raw)) {
            throw ValidationException::withMessages([
                'amount' => ['Amount must have at most three decimal places.'],
            ]);
        }

        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $fraction = str_pad($fraction, 3, '0');

        return ((int) $whole * 1000) + (int) $fraction;
    }

    private function formatMills(int $mills): string
    {
        return intdiv($mills, 1000).'.'.str_pad((string) ($mills % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function assertRequestHash(mixed $stored, string $requestHash): void
    {
        if (! is_string($stored) || ! hash_equals($stored, $requestHash)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['Idempotency key is already bound to a different Treasury request.'],
            ]);
        }
    }
}
