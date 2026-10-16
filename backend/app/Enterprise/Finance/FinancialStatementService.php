<?php

namespace App\Enterprise\Finance;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\EnterpriseCostCenter;
use App\Models\EnterpriseFinanceAccount;
use App\Models\EnterpriseFiscalPeriod;
use App\Models\User;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinancialStatementService
{
    private const VIEW_PERMISSION = 'finance.view';

    private const READ_CAPABILITY = 'finance.gl.read';

    private const MAX_ACCOUNTS = 5000;

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleEntitlementResolver $entitlements,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function trialBalance(
        User $actor,
        ResolvedTenantContext $context,
        int $fiscalPeriodId,
        ?int $comparativeFiscalPeriodId = null,
    ): array {
        $scope = $this->authorizedScope($actor, $context);
        $period = $this->period($scope, $fiscalPeriodId);
        $comparison = $comparativeFiscalPeriodId === null
            ? null
            : $this->period($scope, $comparativeFiscalPeriodId);

        $current = $this->trialBalanceSnapshot($scope, $period);
        $comparative = $comparison === null
            ? null
            : $this->trialBalanceSnapshot($scope, $comparison);

        return [
            'statement' => 'trial_balance',
            'period' => $this->periodMetadata($period),
            'comparison_period' => $comparison === null ? null : $this->periodMetadata($comparison),
            'totals' => $current['totals'],
            'comparative_totals' => $comparative['totals'] ?? null,
            'reconciled' => $current['totals']['debit_mills'] === $current['totals']['credit_mills'],
            'lines' => $this->attachComparison($current['lines'], $comparative['lines'] ?? []),
        ];
    }

    /**
     * Profit & loss is period-bounded. Cost-center filtering is applied only to
     * ledger entries with canonical cost-center provenance. Today that provenance
     * is carried by posted opening-balance lines; ordinary GL entries remain
     * explicitly unassigned rather than being guessed or duplicated.
     *
     * @return array<string, mixed>
     */
    public function profitAndLoss(
        User $actor,
        ResolvedTenantContext $context,
        int $fiscalPeriodId,
        ?int $comparativeFiscalPeriodId = null,
        ?int $costCenterId = null,
    ): array {
        $scope = $this->authorizedScope($actor, $context);
        $period = $this->period($scope, $fiscalPeriodId);
        $comparison = $comparativeFiscalPeriodId === null
            ? null
            : $this->period($scope, $comparativeFiscalPeriodId);
        $costCenter = $costCenterId === null ? null : $this->costCenter($scope, $costCenterId);

        $current = $this->profitAndLossSnapshot($scope, $period, $costCenterId);
        $comparative = $comparison === null
            ? null
            : $this->profitAndLossSnapshot($scope, $comparison, $costCenterId);

        return [
            'statement' => 'profit_and_loss',
            'period' => $this->periodMetadata($period),
            'comparison_period' => $comparison === null ? null : $this->periodMetadata($comparison),
            'cost_center' => $costCenter === null ? null : [
                'id' => (int) $costCenter->id,
                'code' => (string) $costCenter->code,
                'name' => (string) $costCenter->name,
            ],
            'cost_center_semantics' => 'Only explicitly attributed canonical GL provenance is included; unassigned ledger entries are never inferred.',
            'totals' => $current['totals'],
            'comparative_totals' => $comparative['totals'] ?? null,
            'lines' => $this->attachComparison($current['lines'], $comparative['lines'] ?? []),
        ];
    }

    /**
     * Balance sheet semantics are bounded to the selected fiscal year start
     * through the selected period end. Prior-history carry-forward therefore
     * remains the responsibility of canonical opening-balance journals.
     *
     * @return array<string, mixed>
     */
    public function balanceSheet(
        User $actor,
        ResolvedTenantContext $context,
        int $fiscalPeriodId,
        ?int $comparativeFiscalPeriodId = null,
    ): array {
        $scope = $this->authorizedScope($actor, $context);
        $period = $this->period($scope, $fiscalPeriodId);
        $comparison = $comparativeFiscalPeriodId === null
            ? null
            : $this->period($scope, $comparativeFiscalPeriodId);

        $current = $this->balanceSheetSnapshot($scope, $period);
        $comparative = $comparison === null
            ? null
            : $this->balanceSheetSnapshot($scope, $comparison);

        return [
            'statement' => 'balance_sheet',
            'period' => $this->periodMetadata($period),
            'comparison_period' => $comparison === null ? null : $this->periodMetadata($comparison),
            'window_start' => $current['window_start'],
            'as_of' => $current['as_of'],
            'comparative_window_start' => $comparative['window_start'] ?? null,
            'comparative_as_of' => $comparative['as_of'] ?? null,
            'totals' => $current['totals'],
            'comparative_totals' => $comparative['totals'] ?? null,
            'lines' => $this->attachComparison($current['lines'], $comparative['lines'] ?? []),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, object>
     */
    public function paginateDrillDown(
        User $actor,
        ResolvedTenantContext $context,
        int $fiscalPeriodId,
        int $accountId,
        ?int $costCenterId = null,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context);
        $period = $this->period($scope, $fiscalPeriodId);
        $this->account($scope, $accountId);
        if ($costCenterId !== null) {
            $this->costCenter($scope, $costCenterId);
        }

        $perPage = max(10, min(100, $perPage));
        $query = $this->ledgerWithDimensions($scope)
            ->where('ledger.fiscal_period_id', $period->id)
            ->where('ledger.account_id', $accountId);

        if ($costCenterId !== null) {
            $query->where('opening_line.cost_center_id', $costCenterId);
        }

        return $query
            ->select([
                'ledger.id',
                'ledger.journal_id',
                'ledger.journal_line_id',
                'journal.journal_number',
                'ledger.entry_date',
                'ledger.debit',
                'ledger.credit',
                DB::raw('opening_line.cost_center_id as cost_center_id'),
            ])
            ->orderByDesc('ledger.entry_date')
            ->orderByDesc('ledger.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** @return array{totals:array{debit_mills:int,credit_mills:int},lines:list<array<string,mixed>>} */
    private function trialBalanceSnapshot(EnterpriseDataScope $scope, EnterpriseFiscalPeriod $period): array
    {
        $accounts = $this->statementAccounts($scope, ['asset', 'liability', 'equity', 'revenue', 'expense']);
        $aggregates = $this->aggregateByAccount(
            $scope,
            ['asset', 'liability', 'equity', 'revenue', 'expense'],
            static fn (QueryBuilder $query): QueryBuilder => $query->where('ledger.fiscal_period_id', $period->id),
        );
        $lines = $this->rollUp($accounts, $aggregates, 'trial_balance');

        return [
            'totals' => [
                'debit_mills' => array_sum(array_column($aggregates, 'debit_mills')),
                'credit_mills' => array_sum(array_column($aggregates, 'credit_mills')),
            ],
            'lines' => $lines,
        ];
    }

    /** @return array{totals:array{revenue_mills:int,expense_mills:int,profit_mills:int},lines:list<array<string,mixed>>} */
    private function profitAndLossSnapshot(
        EnterpriseDataScope $scope,
        EnterpriseFiscalPeriod $period,
        ?int $costCenterId,
    ): array {
        $accounts = $this->statementAccounts($scope, ['revenue', 'expense']);
        $aggregates = $this->aggregateByAccount(
            $scope,
            ['revenue', 'expense'],
            function (QueryBuilder $query) use ($period, $costCenterId): QueryBuilder {
                $query->where('ledger.fiscal_period_id', $period->id);
                if ($costCenterId !== null) {
                    $query->where('opening_line.cost_center_id', $costCenterId);
                }

                return $query;
            },
        );
        $lines = $this->rollUp($accounts, $aggregates, 'profit_and_loss');

        $revenue = 0;
        $expense = 0;
        foreach ($aggregates as $aggregate) {
            $amount = $aggregate['type'] === 'revenue'
                ? $aggregate['credit_mills'] - $aggregate['debit_mills']
                : $aggregate['debit_mills'] - $aggregate['credit_mills'];
            if ($aggregate['type'] === 'revenue') {
                $revenue += $amount;
            } else {
                $expense += $amount;
            }
        }

        return [
            'totals' => [
                'revenue_mills' => $revenue,
                'expense_mills' => $expense,
                'profit_mills' => $revenue - $expense,
            ],
            'lines' => $lines,
        ];
    }

    /** @return array{window_start:string,as_of:string,totals:array{asset_mills:int,liability_mills:int,equity_mills:int,current_earnings_mills:int,net_mills:int},lines:list<array<string,mixed>>} */
    private function balanceSheetSnapshot(EnterpriseDataScope $scope, EnterpriseFiscalPeriod $period): array
    {
        $period->loadMissing('fiscalYear');
        $year = $period->fiscalYear;
        if ($year === null) {
            throw ValidationException::withMessages([
                'fiscal_period_id' => ['The fiscal period must belong to a fiscal year.'],
            ]);
        }

        $windowStart = $this->dateString($year->starts_on);
        $asOf = $this->dateString($period->ends_on);
        $accounts = $this->statementAccounts($scope, ['asset', 'liability', 'equity']);
        $aggregates = $this->aggregateByAccount(
            $scope,
            ['asset', 'liability', 'equity'],
            static fn (QueryBuilder $query): QueryBuilder => $query
                ->whereDate('ledger.entry_date', '>=', $windowStart)
                ->whereDate('ledger.entry_date', '<=', $asOf),
        );
        $lines = $this->rollUp($accounts, $aggregates, 'balance_sheet');

        $totals = ['asset_mills' => 0, 'liability_mills' => 0, 'equity_mills' => 0];
        foreach ($aggregates as $aggregate) {
            $amount = $aggregate['type'] === 'asset'
                ? $aggregate['debit_mills'] - $aggregate['credit_mills']
                : $aggregate['credit_mills'] - $aggregate['debit_mills'];
            if ($aggregate['type'] === 'asset') {
                $totals['asset_mills'] += $amount;
            } elseif ($aggregate['type'] === 'liability') {
                $totals['liability_mills'] += $amount;
            } else {
                $totals['equity_mills'] += $amount;
            }
        }

        $income = $this->aggregateByAccount(
            $scope,
            ['revenue', 'expense'],
            static fn (QueryBuilder $query): QueryBuilder => $query
                ->whereDate('ledger.entry_date', '>=', $windowStart)
                ->whereDate('ledger.entry_date', '<=', $asOf),
        );
        $currentEarnings = 0;
        foreach ($income as $aggregate) {
            $currentEarnings += $aggregate['type'] === 'revenue'
                ? $aggregate['credit_mills'] - $aggregate['debit_mills']
                : -($aggregate['debit_mills'] - $aggregate['credit_mills']);
        }
        $totals['equity_mills'] += $currentEarnings;

        if ($currentEarnings !== 0) {
            $lines[] = [
                'line_key' => 'current_year_earnings',
                'account_id' => null,
                'parent_id' => null,
                'code' => 'CURRENT-EARNINGS',
                'name' => 'Current fiscal-year earnings',
                'type' => 'equity',
                'is_control' => true,
                'direct_debit_mills' => 0,
                'direct_credit_mills' => 0,
                'debit_mills' => 0,
                'credit_mills' => 0,
                'balance_mills' => $currentEarnings,
            ];
        }

        return [
            'window_start' => $windowStart,
            'as_of' => $asOf,
            'totals' => [
                ...$totals,
                'current_earnings_mills' => $currentEarnings,
                'net_mills' => $totals['asset_mills'] - $totals['liability_mills'] - $totals['equity_mills'],
            ],
            'lines' => $lines,
        ];
    }

    /**
     * @param  list<string>  $types
     * @return Collection<int, EnterpriseFinanceAccount>
     */
    private function statementAccounts(EnterpriseDataScope $scope, array $types): Collection
    {
        $accounts = $this->accountQuery($scope)
            ->whereIn('type', $types)
            ->orderBy('code')
            ->orderBy('id')
            ->limit(self::MAX_ACCOUNTS + 1)
            ->get(['id', 'parent_id', 'code', 'name', 'type', 'normal_balance', 'is_control', 'allow_posting']);

        if ($accounts->count() > self::MAX_ACCOUNTS) {
            throw ValidationException::withMessages([
                'accounts' => ['Financial statements are bounded to '.self::MAX_ACCOUNTS.' accounts per legal entity.'],
            ]);
        }

        return $accounts;
    }

    /**
     * @param  list<string>  $types
     * @param  callable(QueryBuilder):QueryBuilder  $window
     * @return array<int, array{account_id:int,type:string,debit_mills:int,credit_mills:int}>
     */
    private function aggregateByAccount(
        EnterpriseDataScope $scope,
        array $types,
        callable $window,
    ): array {
        $query = $this->ledgerWithDimensions($scope)
            ->whereIn('account.type', $types);
        $window($query);

        return $query
            ->groupBy('ledger.account_id', 'account.type')
            ->selectRaw('ledger.account_id as account_id, account.type as type, SUM(ledger.debit) as debit_total, SUM(ledger.credit) as credit_total')
            ->get()
            ->mapWithKeys(function (object $row): array {
                $id = (int) $row->account_id;

                return [$id => [
                    'account_id' => $id,
                    'type' => (string) $row->type,
                    'debit_mills' => $this->toMills($row->debit_total),
                    'credit_mills' => $this->toMills($row->credit_total),
                ]];
            })
            ->all();
    }

    /**
     * @param  Collection<int, EnterpriseFinanceAccount>  $accounts
     * @param  array<int, array{account_id:int,type:string,debit_mills:int,credit_mills:int}>  $aggregates
     * @return list<array<string,mixed>>
     */
    private function rollUp(Collection $accounts, array $aggregates, string $statement): array
    {
        $byId = $accounts->keyBy('id');
        $totals = [];

        foreach ($accounts as $account) {
            $id = (int) $account->id;
            $totals[$id] = [
                'debit_mills' => $aggregates[$id]['debit_mills'] ?? 0,
                'credit_mills' => $aggregates[$id]['credit_mills'] ?? 0,
            ];
        }

        foreach ($aggregates as $accountId => $aggregate) {
            $visited = [];
            $cursor = $byId->get($accountId)?->parent_id;
            while ($cursor !== null && $byId->has((int) $cursor)) {
                $parentId = (int) $cursor;
                if (isset($visited[$parentId])) {
                    throw ValidationException::withMessages([
                        'accounts' => ['Account hierarchy contains a cycle.'],
                    ]);
                }
                $visited[$parentId] = true;
                $totals[$parentId]['debit_mills'] += $aggregate['debit_mills'];
                $totals[$parentId]['credit_mills'] += $aggregate['credit_mills'];
                $cursor = $byId->get($parentId)?->parent_id;
            }
        }

        $lines = [];
        foreach ($accounts as $account) {
            $id = (int) $account->id;
            $debit = $totals[$id]['debit_mills'];
            $credit = $totals[$id]['credit_mills'];
            if ($debit === 0 && $credit === 0) {
                continue;
            }

            $directDebit = $aggregates[$id]['debit_mills'] ?? 0;
            $directCredit = $aggregates[$id]['credit_mills'] ?? 0;
            $lines[] = [
                'line_key' => 'account:'.$id,
                'account_id' => $id,
                'parent_id' => $account->parent_id === null ? null : (int) $account->parent_id,
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'type' => (string) $account->type,
                'is_control' => (bool) $account->is_control,
                'direct_debit_mills' => $directDebit,
                'direct_credit_mills' => $directCredit,
                'debit_mills' => $debit,
                'credit_mills' => $credit,
                'balance_mills' => $this->statementBalance($statement, (string) $account->type, $debit, $credit),
            ];
        }

        return $lines;
    }

    /**
     * @param  list<array<string,mixed>>  $current
     * @param  list<array<string,mixed>>  $comparative
     * @return list<array<string,mixed>>
     */
    private function attachComparison(array $current, array $comparative): array
    {
        $currentByKey = collect($current)->keyBy('line_key');
        $comparativeByKey = collect($comparative)->keyBy('line_key');
        $keys = $currentByKey->keys()->merge($comparativeByKey->keys())->unique()->values();

        return $keys->map(static function (string $key) use ($currentByKey, $comparativeByKey): array {
            $currentLine = $currentByKey->get($key);
            $comparativeLine = $comparativeByKey->get($key);
            $base = is_array($currentLine)
                ? $currentLine
                : (is_array($comparativeLine) ? $comparativeLine : []);
            $currentBalance = is_array($currentLine) ? (int) $currentLine['balance_mills'] : 0;
            $comparativeBalance = is_array($comparativeLine) ? (int) $comparativeLine['balance_mills'] : 0;

            return [
                ...$base,
                'direct_debit_mills' => is_array($currentLine) ? (int) ($currentLine['direct_debit_mills'] ?? 0) : 0,
                'direct_credit_mills' => is_array($currentLine) ? (int) ($currentLine['direct_credit_mills'] ?? 0) : 0,
                'debit_mills' => is_array($currentLine) ? (int) ($currentLine['debit_mills'] ?? 0) : 0,
                'credit_mills' => is_array($currentLine) ? (int) ($currentLine['credit_mills'] ?? 0) : 0,
                'balance_mills' => $currentBalance,
                'comparative_balance_mills' => $comparativeBalance,
                'variance_mills' => $currentBalance - $comparativeBalance,
            ];
        })->all();
    }

    private function statementBalance(string $statement, string $type, int $debit, int $credit): int
    {
        if ($statement === 'trial_balance') {
            return $type === 'asset' || $type === 'expense'
                ? $debit - $credit
                : $credit - $debit;
        }

        if ($statement === 'profit_and_loss') {
            return $type === 'revenue' ? $credit - $debit : $debit - $credit;
        }

        return $type === 'asset' ? $debit - $credit : $credit - $debit;
    }

    private function authorizedScope(User $actor, ResolvedTenantContext $context): EnterpriseDataScope
    {
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');
        $scope = $context->enterpriseScope;
        $this->authorizer->authorize(
            $actor,
            $context,
            self::VIEW_PERMISSION,
            'legal_entity',
            $scope->legalEntityId,
        );
        abort_unless(
            $this->entitlements->allows(
                FinanceFoundationService::MODULE,
                new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
                self::READ_CAPABILITY,
            ),
            403,
        );

        return $scope;
    }

    private function period(EnterpriseDataScope $scope, int $periodId): EnterpriseFiscalPeriod
    {
        /** @var EnterpriseFiscalPeriod $period */
        $period = $this->periodQuery($scope)
            ->whereKey($periodId)
            ->with('fiscalYear')
            ->firstOrFail();

        return $period;
    }

    private function account(EnterpriseDataScope $scope, int $accountId): EnterpriseFinanceAccount
    {
        /** @var EnterpriseFinanceAccount $account */
        $account = $this->accountQuery($scope)->whereKey($accountId)->firstOrFail();

        return $account;
    }

    private function costCenter(EnterpriseDataScope $scope, int $costCenterId): EnterpriseCostCenter
    {
        /** @var EnterpriseCostCenter $costCenter */
        $costCenter = EnterpriseCostCenter::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->whereKey($costCenterId)
            ->where('status', 'active')
            ->firstOrFail();

        return $costCenter;
    }

    /** @return Builder<EnterpriseFinanceAccount> */
    private function accountQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseFinanceAccount::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    /** @return Builder<EnterpriseFiscalPeriod> */
    private function periodQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseFiscalPeriod::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    private function ledgerWithDimensions(EnterpriseDataScope $scope): QueryBuilder
    {
        return DB::table('enterprise_finance_ledger_entries as ledger')
            ->join('enterprise_finance_accounts as account', 'account.id', '=', 'ledger.account_id')
            ->join('enterprise_finance_journals as journal', 'journal.id', '=', 'ledger.journal_id')
            ->join('enterprise_finance_journal_lines as journal_line', 'journal_line.id', '=', 'ledger.journal_line_id')
            ->leftJoin('enterprise_finance_opening_balance_batches as opening_batch', function ($join): void {
                $join->on('opening_batch.journal_id', '=', 'ledger.journal_id')
                    ->where('opening_batch.status', '=', 'posted');
            })
            ->leftJoin('enterprise_finance_opening_balance_lines as opening_line', function ($join): void {
                $join->on('opening_line.batch_id', '=', 'opening_batch.id')
                    ->on('opening_line.line_number', '=', 'journal_line.line_number');
            })
            ->where('ledger.organization_id', $scope->organizationId)
            ->where('ledger.company_id', $scope->companyId)
            ->where('ledger.legal_entity_id', $scope->legalEntityId);
    }

    /** @return array<string,mixed> */
    private function periodMetadata(EnterpriseFiscalPeriod $period): array
    {
        return [
            'id' => (int) $period->id,
            'fiscal_year_id' => (int) $period->fiscal_year_id,
            'code' => (string) $period->code,
            'name' => (string) $period->name,
            'status' => (string) $period->status,
            'starts_on' => $this->dateString($period->starts_on),
            'ends_on' => $this->dateString($period->ends_on),
        ];
    }

    private function dateString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $date = substr(trim((string) $value), 0, 10);
        if (! preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)) {
            throw ValidationException::withMessages([
                'fiscal_period_id' => ['Fiscal period dates are invalid.'],
            ]);
        }

        return $date;
    }

    private function toMills(mixed $value): int
    {
        $raw = trim((string) $value);
        if (! preg_match('/^(\d{1,15})(?:\.(\d{1,3}))?$/', $raw, $matches)) {
            throw ValidationException::withMessages([
                'ledger' => ['Canonical GL aggregate precision is invalid.'],
            ]);
        }

        $fraction = str_pad($matches[2] ?? '', 3, '0');

        return ((int) $matches[1] * 1000) + (int) $fraction;
    }
}
