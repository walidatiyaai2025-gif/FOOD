<?php

namespace App\Enterprise\Treasury;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\EnterpriseBankAccount;
use App\Models\EnterpriseCashbox;
use App\Models\EnterpriseTreasuryPayment;
use App\Models\EnterpriseTreasuryReceipt;
use App\Models\User;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class TreasuryAnalyticsService
{
    public const MODULE = 'treasury';

    private const VIEW_PERMISSION = 'finance.view';

    private const READ_CAPABILITY = 'treasury.receipts.read';

    private const MAX_WINDOW_DAYS = 90;

    /** @var array<string,string> */
    public const REPORTS = [
        'treasury.daily_position' => 'daily_position',
        'treasury.cash_forecast' => 'forecast',
        'treasury.bank_reconciliation' => 'bank_reconciliation',
        'treasury.cheque_maturity' => 'cheque_maturity',
        'treasury.facility_repayment' => 'facility_repayment',
        'treasury.investment_maturity' => 'investment_maturity',
        'treasury.exceptions' => 'exceptions',
    ];

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleEntitlementResolver $entitlements,
    ) {}

    /** @return array<string,mixed> */
    public function dashboard(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
    ): array {
        $scope = $this->authorizedScope($actor, $context);
        $window = $this->window($filters);
        $position = $this->cashPosition($scope);
        $today = CarbonImmutable::today();
        $todayFlow = $this->flowByCurrency($scope, $today, $today);
        $forecast = $this->forecastRows($scope, $today->addDay(), $today->addDays(90));
        $positionByCurrency = $this->sumPositionByCurrency($position);
        $liquidityGap = $this->liquidityGap($positionByCurrency, $forecast);

        $cashOnly = $this->sumPositionByCurrency($position->where('target_type', 'cashbox'));
        $bankOnly = $this->sumPositionByCurrency($position->where('target_type', 'bank_account'));

        $unreconciled = $this->unreconciledCount($scope);
        $overdueCheques = (int) DB::table('enterprise_treasury_cheques')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->whereDate('due_date', '<', $today->toDateString())
            ->whereNotIn('status', ['cleared', 'cancelled'])
            ->count();
        $upcomingMaturities = (int) DB::table('enterprise_treasury_repayments as repayment')
            ->join('enterprise_treasury_drawdowns as drawdown', 'drawdown.id', '=', 'repayment.drawdown_id')
            ->join('enterprise_treasury_facilities as facility', 'facility.id', '=', 'drawdown.facility_id')
            ->where('facility.organization_id', $scope->organizationId)
            ->where('facility.company_id', $scope->companyId)
            ->where('facility.legal_entity_id', $scope->legalEntityId)
            ->where('facility.store_id', $scope->storeId)
            ->where('repayment.status', 'scheduled')
            ->whereBetween('repayment.due_date', [$today->toDateString(), $today->addDays(30)->toDateString()])
            ->count()
            + (int) DB::table('enterprise_treasury_investments')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('legal_entity_id', $scope->legalEntityId)
                ->where('store_id', $scope->storeId)
                ->where('status', 'active')
                ->whereBetween('maturity_date', [$today->toDateString(), $today->addDays(30)->toDateString()])
                ->count();

        $pendingApprovals = $this->pendingApprovalCount($scope);

        return [
            'state' => 'current',
            'from' => $window['from']->toDateString(),
            'to' => $window['to']->toDateString(),
            'as_of' => now()->toIso8601String(),
            'fx_policy' => 'no_conversion',
            'kpis' => [
                [
                    'key' => 'cash_on_hand',
                    'label' => __('enterprise_treasury.dashboard.cash_on_hand'),
                    'value' => $this->formatCurrencyMap($cashOnly),
                    'drilldown_url' => route('admin.erp.treasury.index', ['tab' => 'cash_closing', 'store_id' => $scope->storeId]),
                ],
                [
                    'key' => 'bank_balance',
                    'label' => __('enterprise_treasury.dashboard.bank_balance'),
                    'value' => $this->formatCurrencyMap($bankOnly),
                    'drilldown_url' => route('admin.erp.treasury.index', ['tab' => 'reconciliation', 'store_id' => $scope->storeId]),
                ],
                [
                    'key' => 'today_inflow',
                    'label' => __('enterprise_treasury.dashboard.today_inflow'),
                    'value' => $this->formatCurrencyMap($todayFlow['inflow']),
                    'drilldown_url' => route('admin.erp.treasury.index', ['tab' => 'receipts', 'store_id' => $scope->storeId]),
                ],
                [
                    'key' => 'today_outflow',
                    'label' => __('enterprise_treasury.dashboard.today_outflow'),
                    'value' => $this->formatCurrencyMap($todayFlow['outflow']),
                    'drilldown_url' => route('admin.erp.treasury.index', ['tab' => 'payments', 'store_id' => $scope->storeId]),
                ],
                [
                    'key' => 'unreconciled',
                    'label' => __('enterprise_treasury.dashboard.unreconciled'),
                    'value' => number_format($unreconciled),
                    'drilldown_url' => route('admin.erp.treasury.index', ['tab' => 'reconciliation', 'store_id' => $scope->storeId]),
                ],
                [
                    'key' => 'overdue_cheques',
                    'label' => __('enterprise_treasury.dashboard.overdue_cheques'),
                    'value' => number_format($overdueCheques),
                    'status' => $overdueCheques > 0
                        ? ['label' => __('enterprise_treasury.dashboard.attention'), 'state' => 'warning']
                        : ['label' => __('enterprise_treasury.dashboard.current'), 'state' => 'success'],
                    'drilldown_url' => route('admin.erp.treasury.index', ['tab' => 'cheques', 'store_id' => $scope->storeId, 'overdue' => 1]),
                ],
                [
                    'key' => 'upcoming_maturities',
                    'label' => __('enterprise_treasury.dashboard.upcoming_maturities'),
                    'value' => number_format($upcomingMaturities),
                    'drilldown_url' => route('admin.erp.treasury.index', ['tab' => 'financing', 'store_id' => $scope->storeId]),
                ],
                [
                    'key' => 'liquidity_gap',
                    'label' => __('enterprise_treasury.dashboard.forecast_gap'),
                    'value' => $this->formatCurrencyMap($liquidityGap),
                    'status' => collect($liquidityGap)->contains(fn (int $value): bool => $value < 0)
                        ? ['label' => __('enterprise_treasury.dashboard.attention'), 'state' => 'danger']
                        : ['label' => __('enterprise_treasury.dashboard.current'), 'state' => 'success'],
                    'drilldown_url' => route('admin.erp.treasury.index', ['tab' => 'forecast', 'store_id' => $scope->storeId]),
                ],
                [
                    'key' => 'pending_approvals',
                    'label' => __('enterprise_treasury.dashboard.pending_approvals'),
                    'value' => number_format($pendingApprovals),
                ],
            ],
            'cash_position' => $position->values()->all(),
            'actual_trend' => $this->actualTrend($scope, $window['from'], $window['to']),
            'reconciliation_status' => $this->reconciliationStatus($scope, $window['from'], $window['to']),
            'cheque_maturity' => $this->chequeMaturity($scope, $today),
            'forecast' => $this->aggregateForecast($forecast),
            'repayment_profile' => $this->repaymentProfile($scope, $today, $today->addMonthsNoOverflow(12)),
        ];
    }

    /** @return array<string,mixed> */
    public function forecast(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
    ): array {
        $scope = $this->authorizedScope($actor, $context);
        $data = Validator::make($filters, [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ])->validate();
        $from = isset($data['from'])
            ? CarbonImmutable::parse((string) $data['from'])
            : CarbonImmutable::today()->addDay();
        $to = isset($data['to'])
            ? CarbonImmutable::parse((string) $data['to'])
            : $from->addDays(89);
        if ($from->diffInDays($to) + 1 > self::MAX_WINDOW_DAYS) {
            $to = $from->addDays(self::MAX_WINDOW_DAYS - 1);
        }

        $rows = $this->forecastRows($scope, $from, $to);
        $opening = $this->sumPositionByCurrency($this->cashPosition($scope));
        $running = $opening;
        $decorated = [];

        foreach ($rows as $row) {
            $currency = (string) $row['currency'];
            $running[$currency] = ($running[$currency] ?? 0) + (int) $row['net_mills'];
            $decorated[] = [
                ...$row,
                'running_balance' => $this->formatMills($running[$currency]),
            ];
        }

        return [
            'state' => 'current',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'fx_policy' => 'no_conversion',
            'opening_by_currency' => $this->formatCurrencyMap($opening),
            'rows' => $decorated,
        ];
    }

    /** @return array<string,list<array<string,mixed>>> */
    public function reports(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
    ): array {
        $scope = $this->authorizedScope($actor, $context);
        $window = $this->window($filters);
        $today = CarbonImmutable::today();

        $bankReconciliation = DB::table('enterprise_treasury_reconciliations as reconciliation')
            ->join('enterprise_bank_accounts as bank', 'bank.id', '=', 'reconciliation.bank_account_id')
            ->where('reconciliation.organization_id', $scope->organizationId)
            ->where('reconciliation.company_id', $scope->companyId)
            ->where('reconciliation.legal_entity_id', $scope->legalEntityId)
            ->where('reconciliation.store_id', $scope->storeId)
            ->whereDate('reconciliation.period_to', '>=', $window['from']->toDateString())
            ->whereDate('reconciliation.period_from', '<=', $window['to']->toDateString())
            ->orderByDesc('reconciliation.period_to')
            ->limit(100)
            ->get([
                'reconciliation.reconciliation_number as reference',
                'bank.code as bank_code',
                'bank.bank_name',
                'reconciliation.period_from',
                'reconciliation.period_to',
                'reconciliation.statement_receipts_total',
                'reconciliation.statement_payments_total',
                'reconciliation.currency_code as currency',
                'reconciliation.status',
            ])
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $cheques = DB::table('enterprise_treasury_cheques as cheque')
            ->join('enterprise_bank_accounts as bank', 'bank.id', '=', 'cheque.bank_account_id')
            ->where('cheque.organization_id', $scope->organizationId)
            ->where('cheque.company_id', $scope->companyId)
            ->where('cheque.store_id', $scope->storeId)
            ->whereDate('cheque.due_date', '>=', $window['from']->toDateString())
            ->whereDate('cheque.due_date', '<=', $window['to']->copy()->addDays(90)->toDateString())
            ->orderBy('cheque.due_date')
            ->limit(100)
            ->get([
                'cheque.instrument_number as reference',
                'bank.code as bank_code',
                'bank.bank_name',
                'cheque.direction',
                'cheque.due_date',
                'cheque.amount',
                'cheque.currency_code as currency',
                'cheque.status',
            ])
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $repayments = DB::table('enterprise_treasury_repayments as repayment')
            ->join('enterprise_treasury_drawdowns as drawdown', 'drawdown.id', '=', 'repayment.drawdown_id')
            ->join('enterprise_treasury_facilities as facility', 'facility.id', '=', 'drawdown.facility_id')
            ->where('facility.organization_id', $scope->organizationId)
            ->where('facility.company_id', $scope->companyId)
            ->where('facility.legal_entity_id', $scope->legalEntityId)
            ->where('facility.store_id', $scope->storeId)
            ->whereDate('repayment.due_date', '>=', $window['from']->toDateString())
            ->whereDate('repayment.due_date', '<=', $window['to']->copy()->addDays(365)->toDateString())
            ->orderBy('repayment.due_date')
            ->limit(100)
            ->get([
                'facility.code as facility_code',
                'facility.lender_name',
                'drawdown.reference as drawdown_reference',
                'repayment.installment_number',
                'repayment.due_date',
                'repayment.principal_amount',
                'repayment.interest_amount',
                'facility.currency_code as currency',
                'repayment.status',
                'repayment.finance_journal_id',
            ])
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $investments = DB::table('enterprise_treasury_investments')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('store_id', $scope->storeId)
            ->whereDate('maturity_date', '>=', $window['from']->toDateString())
            ->whereDate('maturity_date', '<=', $window['to']->copy()->addDays(365)->toDateString())
            ->orderBy('maturity_date')
            ->limit(100)
            ->get([
                'code',
                'institution',
                'instrument_type',
                'maturity_date',
                'principal_amount',
                'expected_return_amount',
                'currency_code as currency',
                'status',
                'finance_journal_id',
            ])
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $exceptions = [
            [
                'exception' => __('enterprise_treasury.reports.unreconciled'),
                'count' => $this->unreconciledCount($scope),
                'severity' => 'warning',
            ],
            [
                'exception' => __('enterprise_treasury.reports.overdue_cheques'),
                'count' => (int) DB::table('enterprise_treasury_cheques')
                    ->where('organization_id', $scope->organizationId)
                    ->where('company_id', $scope->companyId)
                    ->where('store_id', $scope->storeId)
                    ->whereDate('due_date', '<', $today->toDateString())
                    ->whereNotIn('status', ['cleared', 'cancelled'])
                    ->count(),
                'severity' => 'danger',
            ],
            [
                'exception' => __('enterprise_treasury.reports.overdue_repayments'),
                'count' => (int) DB::table('enterprise_treasury_repayments as repayment')
                    ->join('enterprise_treasury_drawdowns as drawdown', 'drawdown.id', '=', 'repayment.drawdown_id')
                    ->join('enterprise_treasury_facilities as facility', 'facility.id', '=', 'drawdown.facility_id')
                    ->where('facility.organization_id', $scope->organizationId)
                    ->where('facility.company_id', $scope->companyId)
                    ->where('facility.legal_entity_id', $scope->legalEntityId)
                    ->where('facility.store_id', $scope->storeId)
                    ->where('repayment.status', 'scheduled')
                    ->whereDate('repayment.due_date', '<', $today->toDateString())
                    ->count(),
                'severity' => 'danger',
            ],
        ];

        return [
            'daily_position' => $this->cashPosition($scope)->values()->all(),
            'forecast' => $this->forecastRows($scope, $today->addDay(), $today->addDays(90)),
            'bank_reconciliation' => $bankReconciliation,
            'cheque_maturity' => $cheques,
            'facility_repayment' => $repayments,
            'investment_maturity' => $investments,
            'exceptions' => $exceptions,
        ];
    }

    public function reportRowEstimate(
        User $actor,
        ResolvedTenantContext $context,
        string $reportCode,
        array $filters = [],
    ): int {
        $key = self::REPORTS[$reportCode] ?? null;
        if ($key === null) {
            return 0;
        }

        return count($this->reports($actor, $context, $filters)[$key] ?? []);
    }

    /** @return Collection<int,array<string,mixed>> */
    private function cashPosition(EnterpriseDataScope $scope): Collection
    {
        $receiptTotals = DB::table('enterprise_treasury_receipts')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->where('status', EnterpriseTreasuryReceipt::STATUS_POSTED)
            ->whereDate('value_date', '<=', CarbonImmutable::today()->toDateString())
            ->groupBy('target_type', 'target_id', 'currency_code')
            ->get([
                'target_type',
                'target_id',
                'currency_code',
                DB::raw('SUM(amount) as amount'),
            ])
            ->keyBy(fn (object $row): string => $row->target_type.':'.$row->target_id.':'.$row->currency_code);

        $paymentTotals = DB::table('enterprise_treasury_payments')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->where('status', EnterpriseTreasuryPayment::STATUS_POSTED)
            ->whereDate('value_date', '<=', CarbonImmutable::today()->toDateString())
            ->groupBy('target_type', 'target_id', 'currency_code')
            ->get([
                'target_type',
                'target_id',
                'currency_code',
                DB::raw('SUM(amount) as amount'),
            ])
            ->keyBy(fn (object $row): string => $row->target_type.':'.$row->target_id.':'.$row->currency_code);

        $rows = collect();
        foreach (DB::table('enterprise_cashboxes')
            ->where('company_id', $scope->companyId)
            ->where('is_active', true)
            ->where(function ($query) use ($scope): void {
                $query->where('operator_visibility', EnterpriseCashbox::VISIBILITY_COMPANY)
                    ->orWhere(function ($storeVisible) use ($scope): void {
                        $storeVisible->where('operator_visibility', EnterpriseCashbox::VISIBILITY_STORE)
                            ->where('store_id', $scope->storeId);
                    });
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'currency_code']) as $target) {
            $rows->push($this->positionRow('cashbox', $target, $receiptTotals, $paymentTotals));
        }
        foreach (DB::table('enterprise_bank_accounts')
            ->where('company_id', $scope->companyId)
            ->where('is_active', true)
            ->where(function ($query) use ($scope): void {
                $query->where('operator_visibility', EnterpriseBankAccount::VISIBILITY_COMPANY)
                    ->orWhere(function ($storeVisible) use ($scope): void {
                        $storeVisible->where('operator_visibility', EnterpriseBankAccount::VISIBILITY_STORE)
                            ->where('store_id', $scope->storeId);
                    });
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'bank_name', 'currency_code']) as $target) {
            $rows->push($this->positionRow('bank_account', $target, $receiptTotals, $paymentTotals));
        }

        return $rows;
    }

    /** @param Collection<string,\stdClass> $receiptTotals
     * @param  Collection<string,\stdClass>  $paymentTotals
     * @return array<string,mixed>
     */
    private function positionRow(
        string $type,
        object $target,
        Collection $receiptTotals,
        Collection $paymentTotals,
    ): array {
        $currency = strtoupper((string) $target->currency_code);
        $key = $type.':'.$target->id.':'.$currency;
        $receipts = $this->toMills((string) ($receiptTotals->get($key)->amount ?? '0'));
        $payments = $this->toMills((string) ($paymentTotals->get($key)->amount ?? '0'));
        $label = (string) $target->code.' · '.($type === 'bank_account'
            ? (string) $target->bank_name
            : (string) $target->name);

        return [
            'target_type' => $type,
            'target' => $label,
            'currency' => $currency,
            'receipts' => $this->formatMills($receipts),
            'payments' => $this->formatMills($payments),
            'balance' => $this->formatSignedMills($receipts - $payments),
            'balance_mills' => $receipts - $payments,
            'basis' => 'posted_treasury_ledger',
        ];
    }

    /** @return array{inflow:array<string,int>,outflow:array<string,int>} */
    private function flowByCurrency(
        EnterpriseDataScope $scope,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $inflow = DB::table('enterprise_treasury_receipts')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->where('status', EnterpriseTreasuryReceipt::STATUS_POSTED)
            ->whereBetween('value_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('currency_code')
            ->get(['currency_code', DB::raw('SUM(amount) as amount')])
            ->mapWithKeys(fn (object $row): array => [
                (string) $row->currency_code => $this->toMills((string) $row->amount),
            ])
            ->all();

        $outflow = DB::table('enterprise_treasury_payments')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->where('status', EnterpriseTreasuryPayment::STATUS_POSTED)
            ->whereBetween('value_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('currency_code')
            ->get(['currency_code', DB::raw('SUM(amount) as amount')])
            ->mapWithKeys(fn (object $row): array => [
                (string) $row->currency_code => $this->toMills((string) $row->amount),
            ])
            ->all();

        return ['inflow' => $inflow, 'outflow' => $outflow];
    }

    /** @return list<array<string,mixed>> */
    private function actualTrend(
        EnterpriseDataScope $scope,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $rows = [];
        $receipts = DB::table('enterprise_treasury_receipts')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->where('status', EnterpriseTreasuryReceipt::STATUS_POSTED)
            ->whereBetween('value_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('value_date', 'currency_code')
            ->get(['value_date', 'currency_code', DB::raw('SUM(amount) as amount')]);
        $payments = DB::table('enterprise_treasury_payments')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->where('status', EnterpriseTreasuryPayment::STATUS_POSTED)
            ->whereBetween('value_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('value_date', 'currency_code')
            ->get(['value_date', 'currency_code', DB::raw('SUM(amount) as amount')]);

        foreach ($receipts as $row) {
            $key = $row->value_date.'|'.$row->currency_code;
            $rows[$key] = [
                'date' => (string) $row->value_date,
                'currency' => (string) $row->currency_code,
                'inflow' => $this->formatMills($this->toMills((string) $row->amount)),
                'outflow' => '0.000',
            ];
        }
        foreach ($payments as $row) {
            $key = $row->value_date.'|'.$row->currency_code;
            $rows[$key] ??= [
                'date' => (string) $row->value_date,
                'currency' => (string) $row->currency_code,
                'inflow' => '0.000',
                'outflow' => '0.000',
            ];
            $rows[$key]['outflow'] = $this->formatMills($this->toMills((string) $row->amount));
        }
        usort($rows, static fn (array $a, array $b): int => [$a['date'], $a['currency']] <=> [$b['date'], $b['currency']]);

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function reconciliationStatus(
        EnterpriseDataScope $scope,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        return DB::table('enterprise_treasury_reconciliations')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('store_id', $scope->storeId)
            ->whereDate('period_to', '>=', $from->toDateString())
            ->whereDate('period_from', '<=', $to->toDateString())
            ->groupBy('status', 'currency_code')
            ->get([
                'status',
                'currency_code as currency',
                DB::raw('COUNT(*) as records'),
                DB::raw('SUM(statement_receipts_total) as receipts'),
                DB::raw('SUM(statement_payments_total) as payments'),
            ])
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function chequeMaturity(EnterpriseDataScope $scope, CarbonImmutable $today): array
    {
        $buckets = [
            'overdue' => 0,
            'next_7_days' => 0,
            'next_30_days' => 0,
            'later' => 0,
        ];

        foreach (DB::table('enterprise_treasury_cheques')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->whereNotIn('status', ['cleared', 'cancelled'])
            ->get(['due_date']) as $row) {
            $due = CarbonImmutable::parse((string) $row->due_date);
            $key = $due->lessThan($today)
                ? 'overdue'
                : ($due->lessThanOrEqualTo($today->addDays(7))
                    ? 'next_7_days'
                    : ($due->lessThanOrEqualTo($today->addDays(30)) ? 'next_30_days' : 'later'));
            $buckets[$key]++;
        }

        return collect($buckets)
            ->map(fn (int $count, string $bucket): array => ['bucket' => $bucket, 'count' => $count])
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function repaymentProfile(
        EnterpriseDataScope $scope,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        return DB::table('enterprise_treasury_repayments as repayment')
            ->join('enterprise_treasury_drawdowns as drawdown', 'drawdown.id', '=', 'repayment.drawdown_id')
            ->join('enterprise_treasury_facilities as facility', 'facility.id', '=', 'drawdown.facility_id')
            ->where('facility.organization_id', $scope->organizationId)
            ->where('facility.company_id', $scope->companyId)
            ->where('facility.legal_entity_id', $scope->legalEntityId)
            ->where('facility.store_id', $scope->storeId)
            ->where('repayment.status', 'scheduled')
            ->whereBetween('repayment.due_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('repayment.due_date')
            ->limit(100)
            ->get([
                'repayment.due_date as date',
                'repayment.principal_amount as principal',
                'repayment.interest_amount as interest',
                'facility.currency_code as currency',
                'facility.code as facility',
            ])
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function forecastRows(
        EnterpriseDataScope $scope,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $rows = [];
        $arAllocations = DB::table('enterprise_ar_allocations')
            ->where('status', 'posted')
            ->select('ar_document_id')
            ->selectRaw('SUM(amount) as allocated')
            ->groupBy('ar_document_id');
        $ar = DB::table('enterprise_ar_documents as document')
            ->leftJoinSub($arAllocations, 'allocation', 'allocation.ar_document_id', '=', 'document.id')
            ->where('document.organization_id', $scope->organizationId)
            ->where('document.company_id', $scope->companyId)
            ->where('document.legal_entity_id', $scope->legalEntityId)
            ->where('document.store_id', $scope->storeId)
            ->where('document.status', 'posted')
            ->whereBetween('document.due_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('document.due_date', 'document.currency_code')
            ->get([
                'document.due_date as date',
                'document.currency_code as currency',
                DB::raw('SUM(document.amount - COALESCE(allocation.allocated, 0)) as amount'),
            ]);
        foreach ($ar as $row) {
            $mills = max(0, $this->toMills((string) $row->amount));
            if ($mills > 0) {
                $rows[] = $this->forecastRow((string) $row->date, 'ar_due', (string) $row->currency, $mills, 0);
            }
        }

        $apAllocations = DB::table('enterprise_ap_allocations')
            ->where('status', 'posted')
            ->select('ap_document_id')
            ->selectRaw('SUM(amount) as allocated')
            ->groupBy('ap_document_id');
        $ap = DB::table('enterprise_ap_documents as document')
            ->leftJoinSub($apAllocations, 'allocation', 'allocation.ap_document_id', '=', 'document.id')
            ->where('document.organization_id', $scope->organizationId)
            ->where('document.company_id', $scope->companyId)
            ->where('document.legal_entity_id', $scope->legalEntityId)
            ->where('document.store_id', $scope->storeId)
            ->where('document.status', 'posted')
            ->whereBetween('document.due_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('document.due_date', 'document.currency_code')
            ->get([
                'document.due_date as date',
                'document.currency_code as currency',
                DB::raw('SUM(document.amount - COALESCE(allocation.allocated, 0)) as amount'),
            ]);
        foreach ($ap as $row) {
            $mills = max(0, $this->toMills((string) $row->amount));
            if ($mills > 0) {
                $rows[] = $this->forecastRow((string) $row->date, 'ap_due', (string) $row->currency, 0, $mills);
            }
        }

        foreach ($this->futureTreasuryFlows($scope, $from, $to, 'receipt') as $row) {
            $rows[] = $row;
        }
        foreach ($this->futureTreasuryFlows($scope, $from, $to, 'payment') as $row) {
            $rows[] = $row;
        }

        $repayments = DB::table('enterprise_treasury_repayments as repayment')
            ->join('enterprise_treasury_drawdowns as drawdown', 'drawdown.id', '=', 'repayment.drawdown_id')
            ->join('enterprise_treasury_facilities as facility', 'facility.id', '=', 'drawdown.facility_id')
            ->where('facility.organization_id', $scope->organizationId)
            ->where('facility.company_id', $scope->companyId)
            ->where('facility.legal_entity_id', $scope->legalEntityId)
            ->where('facility.store_id', $scope->storeId)
            ->where('repayment.status', 'scheduled')
            ->whereBetween('repayment.due_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('repayment.due_date', 'facility.currency_code')
            ->get([
                'repayment.due_date as date',
                'facility.currency_code as currency',
                DB::raw('SUM(repayment.principal_amount + repayment.interest_amount) as amount'),
            ]);
        foreach ($repayments as $row) {
            $rows[] = $this->forecastRow(
                (string) $row->date,
                'facility_repayment',
                (string) $row->currency,
                0,
                $this->toMills((string) $row->amount),
            );
        }

        $investments = DB::table('enterprise_treasury_investments')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('store_id', $scope->storeId)
            ->where('status', 'active')
            ->whereBetween('maturity_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('maturity_date', 'currency_code')
            ->get([
                'maturity_date as date',
                'currency_code as currency',
                DB::raw('SUM(principal_amount + expected_return_amount) as amount'),
            ]);
        foreach ($investments as $row) {
            $rows[] = $this->forecastRow(
                (string) $row->date,
                'investment_maturity',
                (string) $row->currency,
                $this->toMills((string) $row->amount),
                0,
            );
        }

        usort($rows, static fn (array $a, array $b): int => [$a['date'], $a['currency'], $a['source']] <=> [$b['date'], $b['currency'], $b['source']]);

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function futureTreasuryFlows(
        EnterpriseDataScope $scope,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $type,
    ): array {
        $table = $type === 'receipt' ? 'enterprise_treasury_receipts' : 'enterprise_treasury_payments';
        $rows = DB::table($table)
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->where('status', 'posted')
            ->whereBetween('value_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('value_date', 'currency_code')
            ->get([
                'value_date as date',
                'currency_code as currency',
                DB::raw('SUM(amount) as amount'),
            ]);

        return $rows->map(function (object $row) use ($type): array {
            $mills = $this->toMills((string) $row->amount);

            return $this->forecastRow(
                (string) $row->date,
                'treasury_'.$type,
                (string) $row->currency,
                $type === 'receipt' ? $mills : 0,
                $type === 'payment' ? $mills : 0,
            );
        })->all();
    }

    /** @return array<string,mixed> */
    private function forecastRow(
        string $date,
        string $source,
        string $currency,
        int $inflowMills,
        int $outflowMills,
    ): array {
        return [
            'date' => $date,
            'source' => $source,
            'currency' => strtoupper($currency),
            'inflow' => $this->formatMills($inflowMills),
            'outflow' => $this->formatMills($outflowMills),
            'net' => $this->formatSignedMills($inflowMills - $outflowMills),
            'net_mills' => $inflowMills - $outflowMills,
        ];
    }

    /** @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function aggregateForecast(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $key = $row['date'].'|'.$row['currency'];
            $grouped[$key] ??= [
                'date' => $row['date'],
                'currency' => $row['currency'],
                'inflow_mills' => 0,
                'outflow_mills' => 0,
            ];
            $grouped[$key]['inflow_mills'] += $this->toMills((string) $row['inflow']);
            $grouped[$key]['outflow_mills'] += $this->toMills((string) $row['outflow']);
        }

        return collect($grouped)
            ->sortBy(fn (array $row): string => $row['date'].'|'.$row['currency'])
            ->map(fn (array $row): array => [
                'date' => $row['date'],
                'currency' => $row['currency'],
                'inflow' => $this->formatMills($row['inflow_mills']),
                'outflow' => $this->formatMills($row['outflow_mills']),
                'net' => $this->formatSignedMills($row['inflow_mills'] - $row['outflow_mills']),
            ])
            ->values()
            ->all();
    }

    /** @return array<string,int> */
    private function sumPositionByCurrency(Collection $position): array
    {
        $totals = [];
        foreach ($position as $row) {
            $currency = (string) $row['currency'];
            $totals[$currency] = ($totals[$currency] ?? 0) + (int) $row['balance_mills'];
        }
        ksort($totals);

        return $totals;
    }

    /** @param array<string,int> $opening
     * @param  list<array<string,mixed>>  $forecast
     * @return array<string,int>
     */
    private function liquidityGap(array $opening, array $forecast): array
    {
        $running = $opening;
        $minimum = $opening;
        foreach ($forecast as $row) {
            $currency = (string) $row['currency'];
            $running[$currency] = ($running[$currency] ?? 0) + (int) $row['net_mills'];
            $minimum[$currency] = min($minimum[$currency] ?? 0, $running[$currency]);
        }

        return collect($minimum)
            ->map(fn (int $value): int => min(0, $value))
            ->all();
    }

    private function unreconciledCount(EnterpriseDataScope $scope): int
    {
        $receipts = DB::table('enterprise_treasury_receipts as transaction')
            ->where('transaction.organization_id', $scope->organizationId)
            ->where('transaction.company_id', $scope->companyId)
            ->where('transaction.store_id', $scope->storeId)
            ->where('transaction.status', 'posted')
            ->where('transaction.target_type', 'bank_account')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('enterprise_treasury_reconciliation_lines as line')
                    ->where('line.transaction_type', 'receipt')
                    ->whereColumn('line.transaction_id', 'transaction.id');
            })
            ->count();

        $payments = DB::table('enterprise_treasury_payments as transaction')
            ->where('transaction.organization_id', $scope->organizationId)
            ->where('transaction.company_id', $scope->companyId)
            ->where('transaction.store_id', $scope->storeId)
            ->where('transaction.status', 'posted')
            ->where('transaction.target_type', 'bank_account')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('enterprise_treasury_reconciliation_lines as line')
                    ->where('line.transaction_type', 'payment')
                    ->whereColumn('line.transaction_id', 'transaction.id');
            })
            ->count();

        return (int) $receipts + (int) $payments;
    }

    private function pendingApprovalCount(EnterpriseDataScope $scope): int
    {
        return (int) DB::table('enterprise_treasury_receipts')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId)
            ->where('status', 'draft')
            ->count()
            + (int) DB::table('enterprise_treasury_payments')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $scope->storeId)
                ->where('status', 'draft')
                ->count()
            + (int) DB::table('enterprise_treasury_reconciliations')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('legal_entity_id', $scope->legalEntityId)
                ->where('store_id', $scope->storeId)
                ->where('status', 'draft')
                ->count()
            + (int) DB::table('enterprise_treasury_cash_closings')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('legal_entity_id', $scope->legalEntityId)
                ->where('store_id', $scope->storeId)
                ->where('status', 'draft')
                ->count();
    }

    /** @param array<string,int> $values */
    private function formatCurrencyMap(array $values): string
    {
        if ($values === []) {
            return '—';
        }
        ksort($values);

        return collect($values)
            ->map(fn (int $mills, string $currency): string => $currency.' '.$this->formatSignedMills($mills))
            ->implode(' · ');
    }

    /** @return array{from:CarbonImmutable,to:CarbonImmutable} */
    private function window(array $filters): array
    {
        $data = Validator::make($filters, [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ])->validate();
        $to = isset($data['to']) ? CarbonImmutable::parse((string) $data['to']) : CarbonImmutable::today();
        $from = isset($data['from']) ? CarbonImmutable::parse((string) $data['from']) : $to->subDays(29);
        if ($from->diffInDays($to) + 1 > self::MAX_WINDOW_DAYS) {
            $from = $to->subDays(self::MAX_WINDOW_DAYS - 1);
        }

        return ['from' => $from, 'to' => $to];
    }

    private function authorizedScope(User $actor, ResolvedTenantContext $context): EnterpriseDataScope
    {
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');
        $scope = $context->enterpriseScope;
        abort_unless(
            $this->entitlements->allows(
                self::MODULE,
                new EntitlementScope(EntitlementScope::STORE, $scope->storeId),
                self::READ_CAPABILITY,
            ),
            403,
        );
        $this->authorizer->authorize($actor, $context, self::VIEW_PERMISSION, 'store', $scope->storeId);

        return $scope;
    }

    private function toMills(string $value): int
    {
        $raw = trim($value);
        if ($raw === '' || $raw === '0') {
            return 0;
        }
        $negative = str_starts_with($raw, '-');
        $raw = ltrim($raw, '+-');
        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 3), 3, '0');
        $mills = ((int) $whole * 1000) + (int) $fraction;

        return $negative ? -$mills : $mills;
    }

    private function formatMills(int $mills): string
    {
        $mills = max(0, $mills);

        return intdiv($mills, 1000).'.'.str_pad((string) ($mills % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function formatSignedMills(int $mills): string
    {
        $sign = $mills < 0 ? '-' : '';
        $absolute = abs($mills);

        return $sign.intdiv($absolute, 1000).'.'.str_pad((string) ($absolute % 1000), 3, '0', STR_PAD_LEFT);
    }
}
