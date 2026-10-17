<?php

namespace App\Enterprise\Finance;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\User;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinanceAnalyticsService
{
    private const REPORTS = [
        'cash_flow',
        'budget_vs_actual',
        'tax_vat',
        'consolidated',
        'variance_exceptions',
        'fixed_assets',
        'close_status',
    ];

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleEntitlementResolver $entitlements,
    ) {}

    /** @return array<string,mixed> */
    public function overview(User $actor, ResolvedTenantContext $context, ?int $periodId = null): array
    {
        $scope = $this->authorizedScope($actor, $context);
        $period = $this->selectedPeriod($scope, $periodId);

        if ($period === null) {
            return [
                'state' => 'empty',
                'period' => null,
                'metrics' => [],
                'trend' => [],
                'aging' => ['ar' => [], 'ap' => []],
                'expenses' => [],
                'exceptions' => [],
            ];
        }

        $profit = $this->profitAndLossSummary($scope, (int) $period->id);
        $ar = $this->exposureSummary('enterprise_ar_documents', 'enterprise_ar_allocations', 'ar_document_id', $scope, false);
        $ap = $this->exposureSummary('enterprise_ap_documents', 'enterprise_ap_allocations', 'ap_document_id', $scope, true);
        $budget = $this->budgetSummary($scope, (int) $period->id);
        $tax = $this->taxSummary($scope, (int) $period->id);
        $close = $this->closeSummary($scope, (int) $period->id);

        return [
            'state' => 'current',
            'period' => $period,
            'metrics' => [
                'revenue' => $profit['revenue'],
                'expense' => $profit['expense'],
                'result' => $profit['result'],
                'ar_outstanding' => $ar['outstanding'],
                'ar_overdue' => $ar['overdue'],
                'ap_outstanding' => $ap['outstanding'],
                'ap_overdue' => $ap['overdue'],
                'unposted_journals' => DB::table('enterprise_finance_journals')
                    ->where('organization_id', $scope->organizationId)
                    ->where('company_id', $scope->companyId)
                    ->where('legal_entity_id', $scope->legalEntityId)
                    ->where('status', 'draft')
                    ->count(),
                'pending_approvals' => DB::table('enterprise_workflow_requests')
                    ->where('scope_type', 'store')
                    ->where('scope_id', $scope->storeId)
                    ->where('status', 'PENDING')
                    ->where('entity_type', 'like', 'enterprise_finance_%')
                    ->count(),
                'close_blockers' => $close['blocking'],
                'budget' => $budget['budget'],
                'budget_actual' => $budget['actual'],
                'budget_variance' => $budget['variance'],
                'tax_position' => $tax['net'],
            ],
            'trend' => $this->profitAndLossTrend($scope),
            'aging' => [
                'ar' => $this->agingBuckets('enterprise_ar_documents', 'enterprise_ar_allocations', 'ar_document_id', $scope, false),
                'ap' => $this->agingBuckets('enterprise_ap_documents', 'enterprise_ap_allocations', 'ap_document_id', $scope, true),
            ],
            'expenses' => $this->expenseByCostCenter($scope, (int) $period->id),
            'exceptions' => $close['exceptions'],
        ];
    }

    /** @return array<string,mixed> */
    public function report(
        User $actor,
        ResolvedTenantContext $context,
        string $report,
        int $periodId,
    ): array {
        if (! in_array($report, self::REPORTS, true)) {
            throw ValidationException::withMessages(['report' => ['Unsupported Finance report.']]);
        }

        $scope = $this->authorizedScope($actor, $context);
        $period = $this->selectedPeriod($scope, $periodId);
        if ($period === null || (int) $period->id !== $periodId) {
            throw ValidationException::withMessages(['period_id' => ['A valid Finance fiscal period is required.']]);
        }

        $lines = match ($report) {
            'cash_flow' => $this->cashFlowLines($scope, $periodId),
            'budget_vs_actual' => $this->budgetActualLines($scope, $periodId),
            'tax_vat' => $this->taxLines($scope, $periodId),
            'consolidated' => $this->consolidatedLines($actor, $context, $scope, $period),
            'variance_exceptions' => $this->varianceLines($scope, $periodId),
            'fixed_assets' => $this->fixedAssetLines($scope),
            'close_status' => $this->closeLines($scope, $periodId),
        };

        return [
            'code' => $report,
            'period' => $period,
            'lines' => $lines,
            'row_count' => count($lines),
            'export_handoff' => 'enterprise_reporting',
            'scheduled_reporting' => 'enterprise_reporting',
        ];
    }

    /** @return array{revenue:float,expense:float,result:float} */
    private function profitAndLossSummary(EnterpriseDataScope $scope, int $periodId): array
    {
        $row = DB::table('enterprise_finance_ledger_entries as ledger')
            ->join('enterprise_finance_accounts as account', 'account.id', '=', 'ledger.account_id')
            ->where('ledger.organization_id', $scope->organizationId)
            ->where('ledger.company_id', $scope->companyId)
            ->where('ledger.legal_entity_id', $scope->legalEntityId)
            ->where('ledger.fiscal_period_id', $periodId)
            ->selectRaw("SUM(CASE WHEN account.type = 'revenue' THEN ledger.credit - ledger.debit ELSE 0 END) as revenue")
            ->selectRaw("SUM(CASE WHEN account.type = 'expense' THEN ledger.debit - ledger.credit ELSE 0 END) as expense")
            ->first();

        $revenue = round((float) ($row->revenue ?? 0), 3);
        $expense = round((float) ($row->expense ?? 0), 3);

        return ['revenue' => $revenue, 'expense' => $expense, 'result' => round($revenue - $expense, 3)];
    }

    /** @return list<array<string,mixed>> */
    private function profitAndLossTrend(EnterpriseDataScope $scope): array
    {
        $periods = $this->periodQuery($scope)->orderByDesc('ends_on')->limit(6)->get()->reverse()->values();
        if ($periods->isEmpty()) {
            return [];
        }

        $rows = DB::table('enterprise_finance_ledger_entries as ledger')
            ->join('enterprise_finance_accounts as account', 'account.id', '=', 'ledger.account_id')
            ->where('ledger.organization_id', $scope->organizationId)
            ->where('ledger.company_id', $scope->companyId)
            ->where('ledger.legal_entity_id', $scope->legalEntityId)
            ->whereIn('ledger.fiscal_period_id', $periods->pluck('id')->all())
            ->whereIn('account.type', ['revenue', 'expense'])
            ->selectRaw('ledger.fiscal_period_id, account.type, SUM(ledger.debit) as debit, SUM(ledger.credit) as credit')
            ->groupBy('ledger.fiscal_period_id', 'account.type')
            ->get()
            ->groupBy('fiscal_period_id');

        return $periods->map(function ($period) use ($rows): array {
            $group = $rows->get($period->id, collect());
            $revenueRow = $group->firstWhere('type', 'revenue');
            $expenseRow = $group->firstWhere('type', 'expense');
            $revenue = round((float) (($revenueRow->credit ?? 0) - ($revenueRow->debit ?? 0)), 3);
            $expense = round((float) (($expenseRow->debit ?? 0) - ($expenseRow->credit ?? 0)), 3);

            return [
                'period_id' => (int) $period->id,
                'code' => (string) $period->code,
                'revenue' => $revenue,
                'expense' => $expense,
                'result' => round($revenue - $expense, 3),
            ];
        })->all();
    }

    /** @return array{outstanding:float,overdue:float} */
    private function exposureSummary(
        string $documents,
        string $allocations,
        string $foreignKey,
        EnterpriseDataScope $scope,
        bool $storeScoped,
    ): array {
        $allocationTotals = DB::table($allocations)
            ->selectRaw($foreignKey.', SUM(amount) as allocated_amount')
            ->where('status', 'posted')
            ->groupBy($foreignKey);

        $base = DB::table($documents.' as document')
            ->leftJoinSub($allocationTotals, 'allocations', 'allocations.'.$foreignKey, '=', 'document.id')
            ->where('document.organization_id', $scope->organizationId)
            ->where('document.company_id', $scope->companyId)
            ->where('document.legal_entity_id', $scope->legalEntityId)
            ->when($storeScoped, fn ($query) => $query->where('document.store_id', $scope->storeId))
            ->where('document.status', 'posted')
            ->whereRaw('(document.amount - COALESCE(allocations.allocated_amount, 0)) > 0');

        $outstandingRow = (clone $base)->selectRaw(
            'COALESCE(SUM(document.amount - COALESCE(allocations.allocated_amount, 0)), 0) as amount',
        )->first();
        $overdueRow = (clone $base)->whereDate('document.due_date', '<', now()->toDateString())->selectRaw(
            'COALESCE(SUM(document.amount - COALESCE(allocations.allocated_amount, 0)), 0) as amount',
        )->first();

        return [
            'outstanding' => round((float) ($outstandingRow->amount ?? 0), 3),
            'overdue' => round((float) ($overdueRow->amount ?? 0), 3),
        ];
    }

    /** @return list<array{bucket:string,amount:float,documents:int}> */
    private function agingBuckets(
        string $documents,
        string $allocations,
        string $foreignKey,
        EnterpriseDataScope $scope,
        bool $storeScoped,
    ): array {
        $allocationTotals = DB::table($allocations)
            ->selectRaw($foreignKey.', SUM(amount) as allocated_amount')
            ->where('status', 'posted')
            ->groupBy($foreignKey);

        $today = now()->toDateString();
        $d30 = now()->subDays(30)->toDateString();
        $d60 = now()->subDays(60)->toDateString();
        $d90 = now()->subDays(90)->toDateString();
        $expression = "CASE
            WHEN document.due_date >= ? THEN 'current'
            WHEN document.due_date >= ? THEN '1_30'
            WHEN document.due_date >= ? THEN '31_60'
            WHEN document.due_date >= ? THEN '61_90'
            ELSE '91_plus'
        END";

        return DB::table($documents.' as document')
            ->leftJoinSub($allocationTotals, 'allocations', 'allocations.'.$foreignKey, '=', 'document.id')
            ->where('document.organization_id', $scope->organizationId)
            ->where('document.company_id', $scope->companyId)
            ->where('document.legal_entity_id', $scope->legalEntityId)
            ->when($storeScoped, fn ($query) => $query->where('document.store_id', $scope->storeId))
            ->where('document.status', 'posted')
            ->whereRaw('(document.amount - COALESCE(allocations.allocated_amount, 0)) > 0')
            ->selectRaw($expression.' as bucket', [$today, $d30, $d60, $d90])
            ->selectRaw('COUNT(*) as documents')
            ->selectRaw('SUM(document.amount - COALESCE(allocations.allocated_amount, 0)) as amount')
            ->groupByRaw($expression, [$today, $d30, $d60, $d90])
            ->get()
            ->map(static fn ($row): array => [
                'bucket' => (string) $row->bucket,
                'amount' => round((float) $row->amount, 3),
                'documents' => (int) $row->documents,
            ])
            ->values()
            ->all();
    }

    /** @return array{budget:float,actual:float,variance:float} */
    private function budgetSummary(EnterpriseDataScope $scope, int $periodId): array
    {
        $budget = (float) DB::table('enterprise_finance_budget_lines as line')
            ->join('enterprise_finance_budgets as budget', 'budget.id', '=', 'line.budget_id')
            ->where('budget.organization_id', $scope->organizationId)
            ->where('budget.company_id', $scope->companyId)
            ->where('budget.legal_entity_id', $scope->legalEntityId)
            ->whereIn('budget.status', ['approved', 'locked'])
            ->where('line.fiscal_period_id', $periodId)
            ->sum('line.amount');

        $actualRow = DB::table('enterprise_finance_ledger_entries as ledger')
            ->join('enterprise_finance_accounts as account', 'account.id', '=', 'ledger.account_id')
            ->where('ledger.organization_id', $scope->organizationId)
            ->where('ledger.company_id', $scope->companyId)
            ->where('ledger.legal_entity_id', $scope->legalEntityId)
            ->where('ledger.fiscal_period_id', $periodId)
            ->selectRaw("COALESCE(SUM(CASE WHEN account.normal_balance = 'debit' THEN ledger.debit - ledger.credit ELSE ledger.credit - ledger.debit END), 0) as actual")
            ->first();
        $actual = (float) ($actualRow->actual ?? 0);

        return [
            'budget' => round($budget, 3),
            'actual' => round($actual, 3),
            'variance' => round($actual - $budget, 3),
        ];
    }

    /** @return array{input:float,output:float,net:float} */
    private function taxSummary(EnterpriseDataScope $scope, int $periodId): array
    {
        $row = DB::table('enterprise_finance_tax_entries as tax')
            ->join('enterprise_finance_journal_lines as journal_line', 'journal_line.id', '=', 'tax.journal_line_id')
            ->join('enterprise_finance_journals as journal', 'journal.id', '=', 'journal_line.journal_id')
            ->where('tax.organization_id', $scope->organizationId)
            ->where('tax.company_id', $scope->companyId)
            ->where('tax.legal_entity_id', $scope->legalEntityId)
            ->where('journal.fiscal_period_id', $periodId)
            ->selectRaw("SUM(CASE WHEN tax.direction = 'input' THEN tax.tax_amount ELSE 0 END) as input_tax")
            ->selectRaw("SUM(CASE WHEN tax.direction = 'output' THEN tax.tax_amount ELSE 0 END) as output_tax")
            ->first();

        $input = round((float) ($row->input_tax ?? 0), 3);
        $output = round((float) ($row->output_tax ?? 0), 3);

        return ['input' => $input, 'output' => $output, 'net' => round($output - $input, 3)];
    }

    /** @return array{blocking:int,exceptions:list<array<string,mixed>>} */
    private function closeSummary(EnterpriseDataScope $scope, int $periodId): array
    {
        $rows = DB::table('enterprise_finance_close_checks')
            ->where('fiscal_period_id', $periodId)
            ->whereIn('status', ['block', 'warning'])
            ->orderByDesc('is_blocking')
            ->orderByDesc('exception_count')
            ->limit(12)
            ->get(['check_code', 'is_blocking', 'status', 'exception_count', 'evaluated_at']);

        return [
            'blocking' => $rows->filter(fn ($row): bool => (bool) $row->is_blocking && $row->status === 'block')->count(),
            'exceptions' => $rows->map(static fn ($row): array => [
                'code' => (string) $row->check_code,
                'blocking' => (bool) $row->is_blocking,
                'status' => (string) $row->status,
                'count' => (int) $row->exception_count,
                'evaluated_at' => (string) $row->evaluated_at,
            ])->all(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function expenseByCostCenter(EnterpriseDataScope $scope, int $periodId): array
    {
        return DB::table('enterprise_finance_ledger_entries as ledger')
            ->join('enterprise_finance_accounts as account', 'account.id', '=', 'ledger.account_id')
            ->join('enterprise_finance_journal_lines as line', 'line.id', '=', 'ledger.journal_line_id')
            ->leftJoin('enterprise_cost_centers as center', 'center.id', '=', 'line.cost_center_id')
            ->where('ledger.organization_id', $scope->organizationId)
            ->where('ledger.company_id', $scope->companyId)
            ->where('ledger.legal_entity_id', $scope->legalEntityId)
            ->where('ledger.fiscal_period_id', $periodId)
            ->where('account.type', 'expense')
            ->selectRaw("COALESCE(center.code, 'UNASSIGNED') as code")
            ->selectRaw("COALESCE(center.name, 'Unassigned') as name")
            ->selectRaw('SUM(ledger.debit - ledger.credit) as amount')
            ->groupBy('center.id', 'center.code', 'center.name')
            ->orderByDesc('amount')
            ->limit(8)
            ->get()
            ->map(static fn ($row): array => [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'amount' => round((float) $row->amount, 3),
            ])->all();
    }

    /** @return list<array<string,mixed>> */
    private function cashFlowLines(EnterpriseDataScope $scope, int $periodId): array
    {
        return DB::table('enterprise_finance_ledger_entries as ledger')
            ->join('enterprise_finance_accounts as account', 'account.id', '=', 'ledger.account_id')
            ->where('ledger.organization_id', $scope->organizationId)
            ->where('ledger.company_id', $scope->companyId)
            ->where('ledger.legal_entity_id', $scope->legalEntityId)
            ->where('ledger.fiscal_period_id', $periodId)
            ->whereNotNull('account.cash_flow_classification')
            ->select('account.cash_flow_classification as category')
            ->selectRaw('SUM(ledger.debit - ledger.credit) as amount')
            ->groupBy('account.cash_flow_classification')
            ->orderBy('account.cash_flow_classification')
            ->get()
            ->map(static fn ($row): array => ['category' => (string) $row->category, 'amount' => round((float) $row->amount, 3)])
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function budgetActualLines(EnterpriseDataScope $scope, int $periodId): array
    {
        $budget = DB::table('enterprise_finance_budget_lines as line')
            ->join('enterprise_finance_budgets as budget', 'budget.id', '=', 'line.budget_id')
            ->where('budget.organization_id', $scope->organizationId)
            ->where('budget.company_id', $scope->companyId)
            ->where('budget.legal_entity_id', $scope->legalEntityId)
            ->whereIn('budget.status', ['approved', 'locked'])
            ->where('line.fiscal_period_id', $periodId)
            ->selectRaw('line.account_id, SUM(line.amount) as budget_amount')
            ->groupBy('line.account_id');

        return DB::table('enterprise_finance_accounts as account')
            ->leftJoinSub($budget, 'budget_lines', 'budget_lines.account_id', '=', 'account.id')
            ->leftJoin('enterprise_finance_ledger_entries as ledger', function ($join) use ($periodId): void {
                $join->on('ledger.account_id', '=', 'account.id')->where('ledger.fiscal_period_id', $periodId);
            })
            ->where('account.organization_id', $scope->organizationId)
            ->where('account.company_id', $scope->companyId)
            ->where('account.legal_entity_id', $scope->legalEntityId)
            ->where(function ($query): void {
                $query->whereNotNull('budget_lines.budget_amount')->orWhereNotNull('ledger.id');
            })
            ->select(['account.id', 'account.code', 'account.name', 'account.normal_balance'])
            ->selectRaw('COALESCE(budget_lines.budget_amount, 0) as budget_amount')
            ->selectRaw("COALESCE(SUM(CASE WHEN account.normal_balance = 'debit' THEN ledger.debit - ledger.credit ELSE ledger.credit - ledger.debit END), 0) as actual_amount")
            ->groupBy('account.id', 'account.code', 'account.name', 'account.normal_balance', 'budget_lines.budget_amount')
            ->orderBy('account.code')
            ->limit(100)
            ->get()
            ->map(static function ($row): array {
                $budgetAmount = round((float) $row->budget_amount, 3);
                $actualAmount = round((float) $row->actual_amount, 3);

                return [
                    'account' => (string) $row->code.' · '.(string) $row->name,
                    'budget' => $budgetAmount,
                    'actual' => $actualAmount,
                    'variance' => round($actualAmount - $budgetAmount, 3),
                ];
            })->all();
    }

    /** @return list<array<string,mixed>> */
    private function taxLines(EnterpriseDataScope $scope, int $periodId): array
    {
        return DB::table('enterprise_finance_tax_entries as tax')
            ->join('enterprise_finance_tax_codes as code', 'code.id', '=', 'tax.tax_code_id')
            ->join('enterprise_finance_journal_lines as line', 'line.id', '=', 'tax.journal_line_id')
            ->join('enterprise_finance_journals as journal', 'journal.id', '=', 'line.journal_id')
            ->where('tax.organization_id', $scope->organizationId)
            ->where('tax.company_id', $scope->companyId)
            ->where('tax.legal_entity_id', $scope->legalEntityId)
            ->where('journal.fiscal_period_id', $periodId)
            ->select(['code.code', 'code.name', 'tax.direction'])
            ->selectRaw('SUM(tax.taxable_amount) as taxable_amount')
            ->selectRaw('SUM(tax.tax_amount) as tax_amount')
            ->groupBy('code.code', 'code.name', 'tax.direction')
            ->orderBy('code.code')
            ->get()
            ->map(static fn ($row): array => [
                'tax_code' => (string) $row->code.' · '.(string) $row->name,
                'direction' => (string) $row->direction,
                'taxable_amount' => round((float) $row->taxable_amount, 3),
                'tax_amount' => round((float) $row->tax_amount, 3),
            ])->all();
    }

    /** @return list<array<string,mixed>> */
    private function fixedAssetLines(EnterpriseDataScope $scope): array
    {
        $depreciation = DB::table('enterprise_fixed_asset_depreciation_runs')
            ->selectRaw('asset_id, SUM(amount) as depreciation')
            ->where('status', 'posted')
            ->groupBy('asset_id');

        return DB::table('enterprise_fixed_assets as asset')
            ->join('enterprise_fixed_asset_categories as category', 'category.id', '=', 'asset.category_id')
            ->leftJoinSub($depreciation, 'depreciation', 'depreciation.asset_id', '=', 'asset.id')
            ->where('asset.organization_id', $scope->organizationId)
            ->where('asset.company_id', $scope->companyId)
            ->where('asset.legal_entity_id', $scope->legalEntityId)
            ->select(['asset.asset_code', 'asset.name', 'category.code as category_code', 'asset.acquisition_amount', 'asset.salvage_value', 'asset.status'])
            ->selectRaw('COALESCE(depreciation.depreciation, 0) as accumulated_depreciation')
            ->orderBy('asset.asset_code')
            ->limit(100)
            ->get()
            ->map(static function ($row): array {
                $amount = round((float) $row->acquisition_amount, 3);
                $depreciationAmount = round((float) $row->accumulated_depreciation, 3);

                return [
                    'asset' => (string) $row->asset_code.' · '.(string) $row->name,
                    'category' => (string) $row->category_code,
                    'cost' => $amount,
                    'accumulated_depreciation' => $depreciationAmount,
                    'book_value' => round(max((float) $row->salvage_value, $amount - $depreciationAmount), 3),
                    'status' => (string) $row->status,
                ];
            })->all();
    }

    /** @return list<array<string,mixed>> */
    private function closeLines(EnterpriseDataScope $scope, int $periodId): array
    {
        return DB::table('enterprise_finance_close_checks')
            ->where('fiscal_period_id', $periodId)
            ->orderByDesc('is_blocking')
            ->orderBy('check_code')
            ->get(['check_code', 'is_blocking', 'status', 'exception_count', 'evaluated_at'])
            ->map(static fn ($row): array => [
                'check' => (string) $row->check_code,
                'blocking' => (bool) $row->is_blocking,
                'status' => (string) $row->status,
                'exceptions' => (int) $row->exception_count,
                'evaluated_at' => (string) $row->evaluated_at,
            ])->all();
    }

    /** @return list<array<string,mixed>> */
    private function varianceLines(EnterpriseDataScope $scope, int $periodId): array
    {
        return collect($this->budgetActualLines($scope, $periodId))
            ->filter(static fn (array $line): bool => abs((float) $line['variance']) >= 0.001)
            ->sortByDesc(static fn (array $line): float => abs((float) $line['variance']))
            ->values()
            ->take(100)
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function consolidatedLines(
        User $actor,
        ResolvedTenantContext $context,
        EnterpriseDataScope $scope,
        object $period,
    ): array {
        abort_unless(
            $actor->hasRole('SUPER_ADMIN') || $actor->hasPermission('finance.approve', $context->storeId),
            403,
        );

        $legalEntityIds = DB::table('enterprise_legal_entities')
            ->where('company_id', $scope->companyId)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $this->entitlements->allows(
                FinanceFoundationService::MODULE,
                new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $id),
                'finance.foundation.read',
            ))
            ->values()
            ->all();

        if ($legalEntityIds === []) {
            return [];
        }

        $periodIds = DB::table('enterprise_fiscal_periods')
            ->whereIn('legal_entity_id', $legalEntityIds)
            ->where('code', (string) $period->code)
            ->pluck('id')
            ->all();

        $raw = DB::table('enterprise_finance_ledger_entries as ledger')
            ->join('enterprise_finance_accounts as account', 'account.id', '=', 'ledger.account_id')
            ->whereIn('ledger.legal_entity_id', $legalEntityIds)
            ->whereIn('ledger.fiscal_period_id', $periodIds)
            ->select('account.type')
            ->selectRaw("SUM(CASE WHEN account.type IN ('asset','expense') THEN ledger.debit - ledger.credit ELSE ledger.credit - ledger.debit END) as amount")
            ->groupBy('account.type')
            ->pluck('amount', 'account.type');

        $yearCode = (string) DB::table('enterprise_fiscal_years')->where('id', $period->fiscal_year_id)->value('code');
        $eliminations = DB::table('enterprise_finance_consolidation_eliminations')
            ->where('company_id', $scope->companyId)
            ->where('fiscal_year_code', $yearCode)
            ->where('period_code', (string) $period->code)
            ->where('is_active', true)
            ->select('account_type')
            ->selectRaw('SUM(debit) as debit, SUM(credit) as credit')
            ->groupBy('account_type')
            ->get()
            ->keyBy('account_type');

        return collect(['asset', 'liability', 'equity', 'revenue', 'expense'])->map(function (string $type) use ($raw, $eliminations, $legalEntityIds): array {
            $base = round((float) ($raw[$type] ?? 0), 3);
            $elimination = $eliminations->get($type);
            $adjustment = $elimination === null
                ? 0.0
                : (in_array($type, ['asset', 'expense'], true)
                    ? (float) $elimination->debit - (float) $elimination->credit
                    : (float) $elimination->credit - (float) $elimination->debit);

            return [
                'account_type' => $type,
                'pre_elimination' => $base,
                'eliminations' => round($adjustment, 3),
                'consolidated' => round($base + $adjustment, 3),
                'legal_entities' => count($legalEntityIds),
            ];
        })->all();
    }

    private function selectedPeriod(EnterpriseDataScope $scope, ?int $periodId): ?object
    {
        $query = $this->periodQuery($scope);
        if ($periodId !== null && $periodId > 0) {
            return $query->where('id', $periodId)->first();
        }

        return (clone $query)->where('status', 'open')->orderByDesc('ends_on')->first()
            ?? $query->orderByDesc('ends_on')->first();
    }

    private function periodQuery(EnterpriseDataScope $scope)
    {
        return DB::table('enterprise_fiscal_periods')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId);
    }

    private function authorizedScope(User $actor, ResolvedTenantContext $context): EnterpriseDataScope
    {
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');
        $scope = $context->enterpriseScope;
        $this->authorizer->authorize($actor, $context, 'finance.view', 'legal_entity', $scope->legalEntityId);
        abort_unless(
            $this->entitlements->allows(
                FinanceFoundationService::MODULE,
                new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
                'finance.foundation.read',
            ),
            403,
        );

        return $scope;
    }
}
