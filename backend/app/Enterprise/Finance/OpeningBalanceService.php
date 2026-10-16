<?php

namespace App\Enterprise\Finance;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\EnterpriseCostCenter;
use App\Models\EnterpriseFinanceAccount;
use App\Models\EnterpriseFinanceOpeningBalanceBatch;
use App\Models\EnterpriseFinanceOpeningBalanceLine;
use App\Models\EnterpriseFiscalPeriod;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OpeningBalanceService
{
    private const VIEW_PERMISSION = 'finance.view';

    private const MANAGE_PERMISSION = 'finance.manage';

    private const POST_PERMISSION = 'finance.post';

    private const READ_CAPABILITY = 'finance.opening_balances.read';

    private const MANAGE_CAPABILITY = 'finance.opening_balances.manage';

    private const POST_CAPABILITY = 'finance.opening_balances.post';

    private const STATUSES = ['draft', 'posted', 'cancelled'];

    private const MAX_LINES = 200;

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly AuditLogger $audit,
        private readonly GeneralLedgerService $generalLedger,
    ) {}

    /**
     * @param  array{q?:string,status?:string,fiscal_period_id?:int,date_from?:string,date_to?:string}  $filters
     * @return LengthAwarePaginator<int, EnterpriseFinanceOpeningBalanceBatch>
     */
    public function paginate(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION, self::READ_CAPABILITY);
        $validated = Validator::make($filters, [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'fiscal_period_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ])->validate();

        $term = $this->safePrefix((string) ($validated['q'] ?? ''));
        $perPage = max(10, min(100, $perPage));

        return $this->batchQuery($scope)
            ->when(
                $validated['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when(
                $validated['fiscal_period_id'] ?? null,
                fn (Builder $query, int $periodId): Builder => $query->where('fiscal_period_id', $periodId),
            )
            ->when(
                $validated['date_from'] ?? null,
                fn (Builder $query, string $date): Builder => $query->whereDate('entry_date', '>=', $date),
            )
            ->when(
                $validated['date_to'] ?? null,
                fn (Builder $query, string $date): Builder => $query->whereDate('entry_date', '<=', $date),
            )
            ->when(
                $term !== '',
                fn (Builder $query): Builder => $query->where('batch_number', 'like', $term.'%'),
            )
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param array{
     *   idempotency_key:mixed,
     *   fiscal_period_id:mixed,
     *   entry_date:mixed,
     *   description?:mixed,
     *   lines:mixed
     * } $attributes
     */
    public function createDraft(
        User $actor,
        ResolvedTenantContext $context,
        array $attributes,
    ): EnterpriseFinanceOpeningBalanceBatch {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION, self::MANAGE_CAPABILITY);
        $data = $this->validatedDraft($attributes);
        $entryDate = CarbonImmutable::parse((string) $data['entry_date'])->toDateString();
        $description = $this->trimNullable($data['description'] ?? null);
        $lines = $this->normalizeLines($data['lines']);
        $totalDebitMills = array_sum(array_column($lines, 'debit_mills'));
        $totalCreditMills = array_sum(array_column($lines, 'credit_mills'));

        if ($totalDebitMills < 1 || $totalDebitMills !== $totalCreditMills) {
            throw ValidationException::withMessages([
                'lines' => ['Opening-balance debits and credits must be positive and exactly balanced.'],
            ]);
        }

        $idempotencyKey = trim((string) $data['idempotency_key']);
        $fiscalPeriodId = (int) $data['fiscal_period_id'];
        $requestHash = $this->requestHash(
            $fiscalPeriodId,
            $entryDate,
            $description,
            $lines,
        );

        $existing = $this->batchQuery($scope)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing instanceof EnterpriseFinanceOpeningBalanceBatch) {
            $this->assertMatchingIdempotentRequest($existing, $requestHash);

            return $existing->load('lines');
        }

        $this->assertScopedOpenPeriod($scope, $fiscalPeriodId, $entryDate);
        $this->assertPostingAccounts($scope, $lines);
        $this->assertCostCenters($scope, $lines);

        return DB::transaction(function () use (
            $actor,
            $context,
            $scope,
            $fiscalPeriodId,
            $entryDate,
            $description,
            $lines,
            $totalDebitMills,
            $totalCreditMills,
            $idempotencyKey,
            $requestHash,
        ): EnterpriseFinanceOpeningBalanceBatch {
            $existing = $this->batchQuery($scope)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof EnterpriseFinanceOpeningBalanceBatch) {
                $this->assertMatchingIdempotentRequest($existing, $requestHash);

                return $existing->load('lines');
            }

            $batch = EnterpriseFinanceOpeningBalanceBatch::query()->create([
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'fiscal_period_id' => $fiscalPeriodId,
                'batch_number' => 'OB-'.Str::ulid(),
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'entry_date' => $entryDate,
                'description' => $description,
                'status' => 'draft',
                'total_debit' => $this->formatMills($totalDebitMills),
                'total_credit' => $this->formatMills($totalCreditMills),
                'created_by' => $actor->id,
            ]);

            foreach ($lines as $index => $line) {
                EnterpriseFinanceOpeningBalanceLine::query()->create([
                    'batch_id' => $batch->id,
                    'line_number' => $index + 1,
                    'account_id' => $line['account_id'],
                    'cost_center_id' => $line['cost_center_id'],
                    'debit' => $this->formatMills($line['debit_mills']),
                    'credit' => $this->formatMills($line['credit_mills']),
                    'memo' => $line['memo'],
                ]);
            }

            $this->audit->record(
                'enterprise.finance.opening_balance_created',
                $actor,
                $batch,
                null,
                $this->auditSnapshot($batch->fresh()->toArray(), $context),
            );

            return $batch->refresh()->load('lines');
        }, 3);
    }

    public function cancel(
        User $actor,
        ResolvedTenantContext $context,
        int $batchId,
    ): EnterpriseFinanceOpeningBalanceBatch {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION, self::MANAGE_CAPABILITY);

        return DB::transaction(function () use ($actor, $context, $scope, $batchId): EnterpriseFinanceOpeningBalanceBatch {
            /** @var EnterpriseFinanceOpeningBalanceBatch $batch */
            $batch = $this->batchQuery($scope)
                ->whereKey($batchId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($batch->status === 'cancelled') {
                return $batch->load('lines');
            }

            if ($batch->status === 'posted') {
                throw ValidationException::withMessages([
                    'status' => ['Posted opening-balance batches cannot be cancelled.'],
                ]);
            }

            $before = $batch->toArray();
            $batch->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.finance.opening_balance_cancelled',
                $actor,
                $batch,
                $this->auditSnapshot($before, $context),
                $this->auditSnapshot($batch->fresh()->toArray(), $context),
            );

            return $batch->refresh()->load('lines');
        }, 3);
    }

    public function post(
        User $actor,
        ResolvedTenantContext $context,
        int $batchId,
    ): EnterpriseFinanceOpeningBalanceBatch {
        $scope = $this->authorizedScope($actor, $context, self::POST_PERMISSION, self::POST_CAPABILITY);

        return DB::transaction(function () use ($actor, $context, $scope, $batchId): EnterpriseFinanceOpeningBalanceBatch {
            /** @var EnterpriseFinanceOpeningBalanceBatch $batch */
            $batch = $this->batchQuery($scope)
                ->whereKey($batchId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($batch->status === 'posted') {
                return $batch->load(['lines', 'journal.lines']);
            }

            if ($batch->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'status' => ['Cancelled opening-balance batches cannot be posted.'],
                ]);
            }

            if ((int) $batch->created_by === (int) $actor->id) {
                throw ValidationException::withMessages([
                    'poster' => ['The opening-balance creator cannot post the same batch.'],
                ]);
            }

            $entryDate = CarbonImmutable::parse((string) $batch->entry_date)->toDateString();
            $this->assertScopedOpenPeriod(
                $scope,
                (int) $batch->fiscal_period_id,
                $entryDate,
                true,
            );

            $storedLines = EnterpriseFinanceOpeningBalanceLine::query()
                ->where('batch_id', $batch->id)
                ->orderBy('line_number')
                ->lockForUpdate()
                ->get();

            if ($storedLines->count() < 2) {
                throw ValidationException::withMessages([
                    'lines' => ['An opening-balance batch requires at least two lines.'],
                ]);
            }

            $lines = $storedLines
                ->map(fn (EnterpriseFinanceOpeningBalanceLine $line): array => [
                    'account_id' => (int) $line->account_id,
                    'cost_center_id' => $line->cost_center_id === null ? null : (int) $line->cost_center_id,
                    'debit_mills' => $this->toMills($line->debit, 'lines'),
                    'credit_mills' => $this->toMills($line->credit, 'lines'),
                    'memo' => $line->memo,
                ])
                ->all();

            $this->assertPostingAccounts($scope, $lines);
            $this->assertCostCenters($scope, $lines);

            $totalDebitMills = array_sum(array_column($lines, 'debit_mills'));
            $totalCreditMills = array_sum(array_column($lines, 'credit_mills'));
            if (
                $totalDebitMills < 1
                || $totalDebitMills !== $totalCreditMills
                || $totalDebitMills !== $this->toMills($batch->total_debit, 'total_debit')
                || $totalCreditMills !== $this->toMills($batch->total_credit, 'total_credit')
            ) {
                throw ValidationException::withMessages([
                    'lines' => ['Opening-balance totals no longer satisfy the balanced posting contract.'],
                ]);
            }

            $requester = $batch->created_by === null
                ? null
                : User::query()->whereKey((int) $batch->created_by)->first();

            if (! $requester instanceof User) {
                throw ValidationException::withMessages([
                    'created_by' => ['The opening-balance creator is no longer available for journal provenance.'],
                ]);
            }

            $journal = $this->generalLedger->createDraft(
                $requester,
                $context,
                [
                    'idempotency_key' => 'opening-balance:'.$batch->id.':'.$batch->request_hash,
                    'entry_date' => $entryDate,
                    'description' => Str::limit(
                        'Opening balance '.$batch->batch_number.($batch->description ? ': '.$batch->description : ''),
                        255,
                        '',
                    ),
                    'lines' => array_map(
                        fn (array $line): array => [
                            'account_id' => $line['account_id'],
                            'debit' => $this->formatMills($line['debit_mills']),
                            'credit' => $this->formatMills($line['credit_mills']),
                            'memo' => $line['memo'],
                        ],
                        $lines,
                    ),
                ],
            );

            $journal = $this->generalLedger->post(
                $actor,
                $context,
                $journal->id,
                new FinancePostingApprovalContext(
                    action: FinanceApprovalService::ACTION_OPENING_BALANCE_POST,
                    entityType: 'enterprise_finance_opening_balance',
                    entityId: (string) $batch->id,
                    makerId: (int) $batch->created_by,
                    amount: (string) $batch->total_debit,
                ),
            );

            $before = $batch->toArray();
            $batch->forceFill([
                'status' => 'posted',
                'journal_id' => $journal->id,
                'posted_at' => $journal->posted_at ?? now(),
                'posted_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.finance.opening_balance_posted',
                $actor,
                $batch,
                $this->auditSnapshot($before, $context),
                $this->auditSnapshot($batch->fresh()->toArray(), $context),
            );

            return $batch->refresh()->load(['lines', 'journal.lines']);
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
                new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
                $capability,
            ),
            403,
        );

        return $scope;
    }

    /** @return Builder<EnterpriseFinanceOpeningBalanceBatch> */
    private function batchQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseFinanceOpeningBalanceBatch::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    /** @return Builder<EnterpriseFinanceAccount> */
    private function accountQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseFinanceAccount::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    /** @return Builder<EnterpriseCostCenter> */
    private function costCenterQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseCostCenter::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    /** @return Builder<EnterpriseFiscalPeriod> */
    private function fiscalPeriodQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseFiscalPeriod::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validatedDraft(array $attributes): array
    {
        if (array_key_exists('idempotency_key', $attributes)) {
            $attributes['idempotency_key'] = trim((string) $attributes['idempotency_key']);
        }
        if (array_key_exists('description', $attributes) && is_string($attributes['description'])) {
            $attributes['description'] = trim($attributes['description']);
        }

        return Validator::make($attributes, [
            'idempotency_key' => [
                'required',
                'string',
                'max:128',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/',
            ],
            'fiscal_period_id' => ['required', 'integer', 'min:1'],
            'entry_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:'.self::MAX_LINES],
            'lines.*.account_id' => ['required', 'integer', 'min:1'],
            'lines.*.cost_center_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.debit' => ['nullable', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'lines.*.credit' => ['nullable', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ])->validate();
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{account_id:int,cost_center_id:?int,debit_mills:int,credit_mills:int,memo:?string}>
     */
    private function normalizeLines(array $lines): array
    {
        $normalized = [];

        foreach ($lines as $index => $line) {
            $debitMills = $this->toMills($line['debit'] ?? null, 'lines.'.($index + 1).'.debit');
            $creditMills = $this->toMills($line['credit'] ?? null, 'lines.'.($index + 1).'.credit');

            if (($debitMills > 0) === ($creditMills > 0)) {
                throw ValidationException::withMessages([
                    'lines.'.($index + 1) => ['Each opening-balance line must contain exactly one positive debit or credit amount.'],
                ]);
            }

            $normalized[] = [
                'account_id' => (int) $line['account_id'],
                'cost_center_id' => isset($line['cost_center_id']) ? (int) $line['cost_center_id'] : null,
                'debit_mills' => $debitMills,
                'credit_mills' => $creditMills,
                'memo' => $this->trimNullable($line['memo'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{account_id:int,cost_center_id:?int,debit_mills:int,credit_mills:int,memo:?string}>  $lines
     */
    private function assertPostingAccounts(EnterpriseDataScope $scope, array $lines): void
    {
        $ids = array_values(array_unique(array_column($lines, 'account_id')));
        $accounts = $this->accountQuery($scope)
            ->whereIn('id', $ids)
            ->where('status', 'active')
            ->where('allow_posting', true)
            ->where('is_control', false)
            ->get(['id'])
            ->keyBy('id');

        foreach ($ids as $id) {
            if (! $accounts->has($id)) {
                throw ValidationException::withMessages([
                    'lines' => ['Every opening-balance account must be an active posting account in the same legal entity.'],
                ]);
            }
        }
    }

    /**
     * @param  array<int, array{account_id:int,cost_center_id:?int,debit_mills:int,credit_mills:int,memo:?string}>  $lines
     */
    private function assertCostCenters(EnterpriseDataScope $scope, array $lines): void
    {
        $ids = array_values(array_unique(array_filter(
            array_column($lines, 'cost_center_id'),
            static fn (mixed $id): bool => $id !== null,
        )));

        if ($ids === []) {
            return;
        }

        $costCenters = $this->costCenterQuery($scope)
            ->whereIn('id', $ids)
            ->where('status', 'active')
            ->get(['id'])
            ->keyBy('id');

        foreach ($ids as $id) {
            if (! $costCenters->has($id)) {
                throw ValidationException::withMessages([
                    'lines' => ['Every opening-balance cost center must be active and belong to the same legal entity.'],
                ]);
            }
        }
    }

    private function assertScopedOpenPeriod(
        EnterpriseDataScope $scope,
        int $fiscalPeriodId,
        string $entryDate,
        bool $lock = false,
    ): EnterpriseFiscalPeriod {
        $query = $this->fiscalPeriodQuery($scope)
            ->whereKey($fiscalPeriodId)
            ->where('status', 'open')
            ->whereDate('starts_on', '<=', $entryDate)
            ->whereDate('ends_on', '>=', $entryDate);

        if ($lock) {
            $query->lockForUpdate();
        }

        $period = $query->first();

        if (! $period instanceof EnterpriseFiscalPeriod) {
            throw ValidationException::withMessages([
                'fiscal_period_id' => ['The opening-balance batch must belong to an open fiscal period containing the entry date.'],
            ]);
        }

        return $period;
    }

    /**
     * @param  array<int, array{account_id:int,cost_center_id:?int,debit_mills:int,credit_mills:int,memo:?string}>  $lines
     */
    private function requestHash(
        int $fiscalPeriodId,
        string $entryDate,
        ?string $description,
        array $lines,
    ): string {
        $normalizedLines = array_map(
            fn (array $line): array => [
                'account_id' => $line['account_id'],
                'cost_center_id' => $line['cost_center_id'],
                'debit' => $this->formatMills($line['debit_mills']),
                'credit' => $this->formatMills($line['credit_mills']),
                'memo' => $line['memo'],
            ],
            $lines,
        );

        return hash('sha256', json_encode([
            'fiscal_period_id' => $fiscalPeriodId,
            'entry_date' => $entryDate,
            'description' => $description,
            'lines' => $normalizedLines,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertMatchingIdempotentRequest(
        EnterpriseFinanceOpeningBalanceBatch $batch,
        string $requestHash,
    ): void {
        if (! hash_equals((string) $batch->request_hash, $requestHash)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['The idempotency key is already bound to a different opening-balance request.'],
            ]);
        }
    }

    private function toMills(mixed $value, string $field): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $raw = trim((string) $value);
        if (! preg_match('/^(\d{1,15})(?:\.(\d{1,3}))?$/', $raw, $matches)) {
            throw ValidationException::withMessages([
                $field => ['Amounts must use at most three decimal places.'],
            ]);
        }

        $whole = (int) $matches[1];
        $fraction = str_pad($matches[2] ?? '', 3, '0');

        return ($whole * 1000) + (int) $fraction;
    }

    private function formatMills(int $mills): string
    {
        return intdiv($mills, 1000).'.'.str_pad((string) ($mills % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function safePrefix(string $term): string
    {
        return str_replace(['%', '_'], '', trim($term));
    }

    private function trimNullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function auditSnapshot(array $values, ResolvedTenantContext $context): array
    {
        return [
            ...$values,
            'store_id' => $context->storeId,
        ];
    }
}
