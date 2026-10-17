<?php

namespace App\Http\Controllers\Admin;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Reporting\ReportExportManager;
use App\Enterprise\Reporting\ReportQuery;
use App\Enterprise\Reporting\ReportRegistry;
use App\Enterprise\Reporting\ScheduledReportManager;
use App\Enterprise\Treasury\TreasuryAnalyticsService;
use App\Enterprise\Treasury\TreasuryChequeService;
use App\Enterprise\Treasury\TreasuryPaymentService;
use App\Enterprise\Treasury\TreasuryPlanningService;
use App\Enterprise\Treasury\TreasuryReceiptService;
use App\Enterprise\Treasury\TreasuryReconciliationService;
use App\Http\Controllers\Controller;
use App\Models\EnterpriseBankAccount;
use App\Models\EnterpriseCashbox;
use App\Models\EnterpriseTreasuryPayment;
use App\Models\EnterpriseTreasuryReceipt;
use App\Models\User;
use App\Support\AdminNavigation;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class EnterpriseTreasuryController extends Controller
{
    /** @var list<string> */
    private const TABS = [
        'overview',
        'receipts',
        'payments',
        'cheques',
        'reconciliation',
        'cash_closing',
        'financing',
        'investments',
        'forecast',
        'reports',
    ];

    public function __construct(
        private readonly TreasuryReceiptService $receipts,
        private readonly TreasuryPaymentService $payments,
        private readonly TreasuryChequeService $cheques,
        private readonly TreasuryReconciliationService $reconciliation,
        private readonly TreasuryPlanningService $planning,
        private readonly TreasuryAnalyticsService $analytics,
        private readonly TenantContextResolver $tenants,
        private readonly AdminNavigation $navigation,
        private readonly ModuleRegistry $modules,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        abort_unless($this->modules->isEnabled('treasury'), 404, 'Treasury module is disabled.');

        [$context, $storeOptions, $storeId] = $this->context($request, $actor);
        $tab = (string) $request->query('tab', 'overview');
        abort_unless(in_array($tab, self::TABS, true), 404);
        abort_unless($this->allowed($actor, $storeId, 'finance.view'), 403);

        $cashboxes = EnterpriseCashbox::query()
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'currency_code']);

        $bankAccounts = EnterpriseBankAccount::query()
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get([
                'id',
                'code',
                'name',
                'bank_name',
                'account_name',
                'account_reference_last4',
                'currency_code',
            ]);

        $targetLabels = [];
        foreach ($cashboxes as $cashbox) {
            $targetLabels['cashbox:'.$cashbox->id] = $cashbox->code.' · '.$cashbox->name;
        }
        foreach ($bankAccounts as $bank) {
            $targetLabels['bank_account:'.$bank->id] = $bank->code.' · '.$bank->bank_name.' · ****'.$bank->account_reference_last4;
        }

        $data = [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'erp_treasury',
            'activeTab' => $tab,
            'tabs' => self::TABS,
            'storeOptions' => $storeOptions,
            'selectedStoreId' => $storeId,
            'cashboxes' => $cashboxes,
            'bankAccounts' => $bankAccounts,
            'targetLabels' => $targetLabels,
            'canManage' => $this->allowed($actor, $storeId, 'finance.manage'),
            'rows' => null,
            'dashboard' => null,
            'forecast' => null,
            'reports' => null,
            'repayments' => null,
            'facilityOptions' => collect(),
            'financeJournals' => collect(),
            'postedReceipts' => collect(),
            'postedPayments' => collect(),
            'receiptToken' => 'ui-receipt-'.Str::ulid(),
            'paymentToken' => 'ui-payment-'.Str::ulid(),
            'chequeToken' => 'ui-cheque-'.Str::ulid(),
            'transitionToken' => 'ui-cheque-transition-'.Str::ulid(),
            'reconciliationToken' => 'ui-reconciliation-'.Str::ulid(),
            'cashClosingToken' => 'ui-cash-close-'.Str::ulid(),
            'facilityToken' => 'ui-facility-'.Str::ulid(),
            'drawdownToken' => 'ui-drawdown-'.Str::ulid(),
            'investmentToken' => 'ui-investment-'.Str::ulid(),
            'reportToken' => 'ui-treasury-report-'.Str::ulid(),
        ];

        switch ($tab) {
            case 'overview':
                $data['dashboard'] = $this->analytics->dashboard($actor, $context, [
                    'from' => $request->query('from'),
                    'to' => $request->query('to'),
                ]);
                break;

            case 'financing':
                $data['rows'] = $this->planning->paginateFacilities($actor, $context, [
                    'q' => $request->query('q'),
                    'status' => $request->query('status'),
                ]);
                $data['repayments'] = $this->planning->paginateRepayments($actor, $context, [
                    'status' => $request->query('repayment_status'),
                    'facility_id' => $request->integer('facility_id') ?: null,
                    'from' => $request->query('date_from'),
                    'to' => $request->query('date_to'),
                ]);
                $data['facilityOptions'] = $this->planning->facilityOptions($actor, $context);
                $data['financeJournals'] = $this->planning->postedJournalOptions($actor, $context);
                break;

            case 'investments':
                $data['rows'] = $this->planning->paginateInvestments($actor, $context, [
                    'q' => $request->query('q'),
                    'status' => $request->query('status'),
                ]);
                $data['financeJournals'] = $this->planning->postedJournalOptions($actor, $context);
                break;

            case 'forecast':
                $data['forecast'] = $this->analytics->forecast($actor, $context, [
                    'from' => $request->query('from'),
                    'to' => $request->query('to'),
                ]);
                break;

            case 'reports':
                $data['reports'] = $this->analytics->reports($actor, $context, [
                    'from' => $request->query('from'),
                    'to' => $request->query('to'),
                ]);
                break;

            case 'receipts':
                $data['rows'] = $this->receipts->paginateReceipts($actor, $context, [
                    'q' => $request->query('q'),
                    'status' => $request->query('status'),
                    'target_type' => $request->query('target_type'),
                    'value_date_from' => $request->query('date_from'),
                    'value_date_to' => $request->query('date_to'),
                ], 25);
                break;

            case 'payments':
                $data['rows'] = $this->payments->paginatePayments($actor, $context, [
                    'q' => $request->query('q'),
                    'status' => $request->query('status'),
                    'target_type' => $request->query('target_type'),
                    'value_date_from' => $request->query('date_from'),
                    'value_date_to' => $request->query('date_to'),
                ], 25);
                break;

            case 'cheques':
                $data['rows'] = $this->cheques->paginateInstruments($actor, $context, [
                    'status' => $request->query('status'),
                    'direction' => $request->query('direction'),
                    'bank_account_id' => $request->integer('bank_account_id') ?: null,
                    'due_date_from' => $request->query('date_from'),
                    'due_date_to' => $request->query('date_to'),
                    'overdue' => $request->boolean('overdue'),
                ], 25);
                $bankIds = $bankAccounts->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                if ($bankIds !== []) {
                    $data['postedReceipts'] = EnterpriseTreasuryReceipt::query()
                        ->where('store_id', $storeId)
                        ->where('status', EnterpriseTreasuryReceipt::STATUS_POSTED)
                        ->where('target_type', EnterpriseTreasuryReceipt::TARGET_BANK_ACCOUNT)
                        ->whereIn('target_id', $bankIds)
                        ->latest('value_date')
                        ->limit(100)
                        ->get(['id', 'receipt_number', 'target_id', 'amount', 'currency_code', 'reference']);
                    $data['postedPayments'] = EnterpriseTreasuryPayment::query()
                        ->where('store_id', $storeId)
                        ->where('status', EnterpriseTreasuryPayment::STATUS_POSTED)
                        ->where('target_type', EnterpriseTreasuryPayment::TARGET_BANK_ACCOUNT)
                        ->whereIn('target_id', $bankIds)
                        ->latest('value_date')
                        ->limit(100)
                        ->get(['id', 'payment_number', 'target_id', 'amount', 'currency_code', 'reference']);
                }
                break;

            case 'reconciliation':
                $data['rows'] = $this->reconciliation->paginateReconciliations($actor, $context, [
                    'status' => $request->query('status'),
                    'bank_account_id' => $request->integer('bank_account_id') ?: null,
                    'date_from' => $request->query('date_from'),
                    'date_to' => $request->query('date_to'),
                ], 25);
                $bankIds = $bankAccounts->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                if ($bankIds !== []) {
                    $data['postedReceipts'] = EnterpriseTreasuryReceipt::query()
                        ->where('store_id', $storeId)
                        ->where('status', EnterpriseTreasuryReceipt::STATUS_POSTED)
                        ->where('target_type', EnterpriseTreasuryReceipt::TARGET_BANK_ACCOUNT)
                        ->whereIn('target_id', $bankIds)
                        ->latest('value_date')
                        ->limit(100)
                        ->get(['id', 'receipt_number', 'target_id', 'amount', 'currency_code', 'reference']);
                    $data['postedPayments'] = EnterpriseTreasuryPayment::query()
                        ->where('store_id', $storeId)
                        ->where('status', EnterpriseTreasuryPayment::STATUS_POSTED)
                        ->where('target_type', EnterpriseTreasuryPayment::TARGET_BANK_ACCOUNT)
                        ->whereIn('target_id', $bankIds)
                        ->latest('value_date')
                        ->limit(100)
                        ->get(['id', 'payment_number', 'target_id', 'amount', 'currency_code', 'reference']);
                }
                break;

            case 'cash_closing':
                $data['rows'] = $this->reconciliation->paginateCashClosings($actor, $context, [
                    'status' => $request->query('status'),
                    'cashbox_id' => $request->integer('cashbox_id') ?: null,
                    'date_from' => $request->query('date_from'),
                    'date_to' => $request->query('date_to'),
                ], 25);
                break;
        }

        return view('admin.enterprise-treasury', $data);
    }

    public function storeReceipt(Request $request): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $data = $request->validate([
            '_operation_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'target_key' => ['required', 'string', 'regex:/^(cashbox|bank_account):[1-9][0-9]*$/'],
            'amount' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'value_date' => ['required', 'date'],
            'reference' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        [$targetType, $targetId] = explode(':', $data['target_key'], 2);
        $this->receipts->createDraft($actor, $context, [
            'idempotency_key' => $data['_operation_key'],
            'target_type' => $targetType,
            'target_id' => (int) $targetId,
            ...collect($data)->except(['_operation_key', 'target_key'])->all(),
        ]);

        return $this->backTo('receipts', $storeId)
            ->with('status', __('enterprise_treasury.flash.receipt_created'));
    }

    public function postReceipt(Request $request, int $receipt): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->receipts->post($actor, $context, $receipt);

        return $this->backTo('receipts', $storeId)
            ->with('status', __('enterprise_treasury.flash.receipt_posted'));
    }

    public function cancelReceipt(Request $request, int $receipt): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->receipts->cancel($actor, $context, $receipt);

        return $this->backTo('receipts', $storeId)
            ->with('status', __('enterprise_treasury.flash.receipt_cancelled'));
    }

    public function storePayment(Request $request): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $data = $request->validate([
            '_operation_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'target_key' => ['required', 'string', 'regex:/^(cashbox|bank_account):[1-9][0-9]*$/'],
            'amount' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'value_date' => ['required', 'date'],
            'reference' => ['required', 'string', 'max:120'],
            'beneficiary' => ['nullable', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        [$targetType, $targetId] = explode(':', $data['target_key'], 2);
        $this->payments->createDraft($actor, $context, [
            'idempotency_key' => $data['_operation_key'],
            'target_type' => $targetType,
            'target_id' => (int) $targetId,
            ...collect($data)->except(['_operation_key', 'target_key'])->all(),
        ]);

        return $this->backTo('payments', $storeId)
            ->with('status', __('enterprise_treasury.flash.payment_created'));
    }

    public function postPayment(Request $request, int $payment): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->payments->post($actor, $context, $payment);

        return $this->backTo('payments', $storeId)
            ->with('status', __('enterprise_treasury.flash.payment_posted'));
    }

    public function cancelPayment(Request $request, int $payment): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->payments->cancel($actor, $context, $payment);

        return $this->backTo('payments', $storeId)
            ->with('status', __('enterprise_treasury.flash.payment_cancelled'));
    }

    public function storeCheque(Request $request): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $data = $request->validate([
            '_operation_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'direction' => ['required', Rule::in(['issued', 'received'])],
            'treasury_reference_key' => ['required', 'string', 'regex:/^(payment|receipt):[1-9][0-9]*$/'],
            'bank_account_id' => ['required', 'integer', 'min:1'],
            'instrument_number' => ['required', 'string', 'max:80'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'payee_or_drawer' => ['nullable', 'string', 'max:190'],
        ]);

        [$referenceType, $referenceId] = explode(':', $data['treasury_reference_key'], 2);
        $this->cheques->createInstrument($actor, $context, [
            'idempotency_key' => $data['_operation_key'],
            'treasury_reference_type' => $referenceType,
            'treasury_reference_id' => (int) $referenceId,
            ...collect($data)->except(['_operation_key', 'treasury_reference_key'])->all(),
        ]);

        return $this->backTo('cheques', $storeId)
            ->with('status', __('enterprise_treasury.flash.cheque_created'));
    }

    public function transitionCheque(Request $request, int $cheque): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $data = $request->validate([
            '_operation_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'to_status' => ['required', Rule::in([
                'issued',
                'received',
                'presented',
                'deposited',
                'cleared',
                'bounced',
                'cancelled',
            ])],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->cheques->transition($actor, $context, $cheque, [
            'idempotency_key' => $data['_operation_key'],
            'to_status' => $data['to_status'],
            'reason' => $data['reason'] ?? null,
        ]);

        return $this->backTo('cheques', $storeId)
            ->with('status', __('enterprise_treasury.flash.cheque_transitioned'));
    }

    public function storeReconciliation(Request $request): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $data = $request->validate([
            '_operation_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'bank_account_id' => ['required', 'integer', 'min:1'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'statement_receipts_total' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'statement_payments_total' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
        ]);

        $this->reconciliation->createReconciliationDraft($actor, $context, [
            'idempotency_key' => $data['_operation_key'],
            ...collect($data)->except('_operation_key')->all(),
        ]);

        return $this->backTo('reconciliation', $storeId)
            ->with('status', __('enterprise_treasury.flash.reconciliation_created'));
    }

    public function addReconciliationMatch(Request $request, int $reconciliation): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $data = $request->validate([
            'transaction_key' => ['required', 'string', 'regex:/^(receipt|payment):[1-9][0-9]*$/'],
        ]);

        [$transactionType, $transactionId] = explode(':', $data['transaction_key'], 2);
        $this->reconciliation->addMatch(
            $actor,
            $context,
            $reconciliation,
            $transactionType,
            (int) $transactionId,
        );

        return $this->backTo('reconciliation', $storeId)
            ->with('status', __('enterprise_treasury.flash.reconciliation_match_added'));
    }

    public function closeReconciliation(Request $request, int $reconciliation): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->reconciliation->closeReconciliation($actor, $context, $reconciliation);

        return $this->backTo('reconciliation', $storeId)
            ->with('status', __('enterprise_treasury.flash.reconciliation_closed'));
    }

    public function storeCashClosing(Request $request): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $data = $request->validate([
            '_operation_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'cashbox_id' => ['required', 'integer', 'min:1'],
            'business_date' => ['required', 'date'],
            'opening_amount' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'declared_closing_amount' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
        ]);

        $this->reconciliation->createCashClosingDraft($actor, $context, [
            'idempotency_key' => $data['_operation_key'],
            ...collect($data)->except('_operation_key')->all(),
        ]);

        return $this->backTo('cash_closing', $storeId)
            ->with('status', __('enterprise_treasury.flash.cash_closing_created'));
    }

    public function closeCashClosing(Request $request, int $cashClosing): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $this->reconciliation->closeCashClosing($actor, $context, $cashClosing);

        return $this->backTo('cash_closing', $storeId)
            ->with('status', __('enterprise_treasury.flash.cash_closing_closed'));
    }

    public function storeFacility(Request $request): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $data = $request->validate([
            '_operation_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'code' => ['required', 'string', 'max:64'],
            'lender_name' => ['required', 'string', 'max:190'],
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'facility_limit' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'annual_rate' => ['required', 'regex:/^\d{1,3}(?:\.\d{1,6})?$/'],
            'margin_rate' => ['required', 'regex:/^\d{1,3}(?:\.\d{1,6})?$/'],
            'start_date' => ['required', 'date'],
            'maturity_date' => ['required', 'date', 'after_or_equal:start_date'],
            'finance_journal_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $this->planning->createFacility($actor, $context, [
            'idempotency_key' => $data['_operation_key'],
            ...collect($data)->except('_operation_key')->all(),
        ]);

        return $this->backTo('financing', $storeId)
            ->with('status', __('enterprise_treasury.flash.facility_created'));
    }

    public function storeDrawdown(Request $request): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $data = $request->validate([
            '_operation_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'facility_id' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:80'],
            'principal_amount' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'scheduled_interest_total' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'drawdown_date' => ['required', 'date'],
            'maturity_date' => ['required', 'date', 'after:drawdown_date'],
            'installment_count' => ['required', 'integer', 'between:1,120'],
            'finance_journal_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $this->planning->createDrawdown($actor, $context, [
            'idempotency_key' => $data['_operation_key'],
            ...collect($data)->except('_operation_key')->all(),
        ]);

        return $this->backTo('financing', $storeId)
            ->with('status', __('enterprise_treasury.flash.drawdown_created'));
    }

    public function markRepaymentPaid(Request $request, int $repayment): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $data = $request->validate([
            'finance_journal_id' => ['required', 'integer', 'min:1'],
        ]);
        $this->planning->markRepaymentPaid(
            $actor,
            $context,
            $repayment,
            (int) $data['finance_journal_id'],
        );

        return $this->backTo('financing', $storeId)
            ->with('status', __('enterprise_treasury.flash.repayment_paid'));
    }

    public function storeInvestment(Request $request): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $data = $request->validate([
            '_operation_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'code' => ['required', 'string', 'max:64'],
            'institution' => ['required', 'string', 'max:190'],
            'instrument_type' => ['required', 'string', 'max:100'],
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'principal_amount' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'annual_yield_rate' => ['required', 'regex:/^\d{1,3}(?:\.\d{1,6})?$/'],
            'expected_return_amount' => ['required', 'regex:/^\d{1,15}(?:\.\d{1,3})?$/'],
            'placement_date' => ['required', 'date'],
            'maturity_date' => ['required', 'date', 'after:placement_date'],
            'finance_journal_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $this->planning->createInvestment($actor, $context, [
            'idempotency_key' => $data['_operation_key'],
            ...collect($data)->except('_operation_key')->all(),
        ]);

        return $this->backTo('investments', $storeId)
            ->with('status', __('enterprise_treasury.flash.investment_created'));
    }

    public function settleInvestment(Request $request, int $investment): RedirectResponse
    {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $data = $request->validate([
            'settlement_reference' => ['required', 'string', 'max:120'],
            'finance_journal_id' => ['required', 'integer', 'min:1'],
        ]);
        $this->planning->settleInvestment(
            $actor,
            $context,
            $investment,
            (string) $data['settlement_reference'],
            (int) $data['finance_journal_id'],
        );

        return $this->backTo('investments', $storeId)
            ->with('status', __('enterprise_treasury.flash.investment_settled'));
    }

    public function requestReportExport(
        Request $request,
        ReportRegistry $registry,
        ReportExportManager $exports,
    ): RedirectResponse {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $data = $request->validate([
            '_operation_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'report_code' => ['required', Rule::in(array_keys(TreasuryAnalyticsService::REPORTS))],
            'from' => ['nullable', 'date', 'required_with:to'],
            'to' => ['nullable', 'date', 'required_with:from', 'after_or_equal:from'],
        ]);
        $definition = $registry->require((string) $data['report_code']);
        $query = ReportQuery::fromArray($definition, [
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
        ]);
        $rows = $this->analytics->reportRowEstimate($actor, $context, (string) $data['report_code'], $data);
        $exports->request(
            $actor,
            $query,
            new EntitlementScope(EntitlementScope::STORE, $storeId),
            $rows,
            (string) $data['_operation_key'],
        );

        return $this->backTo('reports', $storeId)
            ->with('status', __('enterprise_treasury.flash.report_export_queued'));
    }

    public function scheduleReport(
        Request $request,
        ReportRegistry $registry,
        ScheduledReportManager $schedules,
    ): RedirectResponse {
        [$actor, $context, $storeId] = $this->mutationContext($request);
        $data = $request->validate([
            '_operation_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'report_code' => ['required', Rule::in(array_keys(TreasuryAnalyticsService::REPORTS))],
            'from' => ['nullable', 'date', 'required_with:to'],
            'to' => ['nullable', 'date', 'required_with:from', 'after_or_equal:from'],
            'next_run_at' => ['required', 'date', 'after:now'],
            'interval_minutes' => ['required', 'integer', 'between:60,525600'],
        ]);
        $definition = $registry->require((string) $data['report_code']);
        $query = ReportQuery::fromArray($definition, [
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
        ]);
        $rows = $this->analytics->reportRowEstimate($actor, $context, (string) $data['report_code'], $data);
        $schedules->schedule(
            $actor,
            $query,
            new EntitlementScope(EntitlementScope::STORE, $storeId),
            $rows,
            CarbonImmutable::parse((string) $data['next_run_at']),
            (int) $data['interval_minutes'],
            (string) $data['_operation_key'],
        );

        return $this->backTo('reports', $storeId)
            ->with('status', __('enterprise_treasury.flash.report_scheduled'));
    }

    /** @return array{User,ResolvedTenantContext,int} */
    private function mutationContext(Request $request): array
    {
        $actor = $this->actor($request);
        abort_unless($this->modules->isEnabled('treasury'), 404, 'Treasury module is disabled.');
        [$context, , $storeId] = $this->context($request, $actor);
        abort_unless($this->allowed($actor, $storeId, 'finance.manage'), 403);

        return [$actor, $context, $storeId];
    }

    /** @return array{ResolvedTenantContext,list<array{id:int,name:string,code:string,channel:string}>,int} */
    private function context(Request $request, User $actor): array
    {
        $options = $this->storeOptions($actor);
        abort_if($options === [], 403);

        $requested = $request->integer('store_id');
        $selected = collect($options)->firstWhere('id', $requested > 0 ? $requested : $options[0]['id']);
        abort_unless(is_array($selected), 403);
        $storeId = (int) $selected['id'];

        $context = $selected['channel'] === 'b2c'
            ? $this->tenants->retail($actor, $storeId, $actor->hasRole('SUPER_ADMIN'), $request)
            : $this->tenants->wholesale($actor, $storeId);

        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');

        return [$context, $options, $storeId];
    }

    /** @return list<array{id:int,name:string,code:string,channel:string}> */
    private function storeOptions(User $actor): array
    {
        $retailIds = $this->tenants->retailStoreIds($actor);
        $globalWholesale = $actor->roles()
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global')
            ->whereIn('roles.code', (array) config('admin.channels.b2b.global_roles', []))
            ->exists();

        if (! $actor->hasRole('SUPER_ADMIN') && $retailIds === [] && ! $globalWholesale) {
            return [];
        }

        return DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.is_active', true)
            ->where(function ($query) use ($actor, $retailIds, $globalWholesale): void {
                if ($actor->hasRole('SUPER_ADMIN')) {
                    $query->whereIn('store_types.code', ['B2B', 'B2C']);

                    return;
                }

                $query->where(function ($scoped) use ($retailIds, $globalWholesale): void {
                    if ($retailIds !== []) {
                        $scoped->where(function ($retail) use ($retailIds): void {
                            $retail->where('store_types.code', 'B2C')->whereIn('stores.id', $retailIds);
                        });
                    }
                    if ($globalWholesale) {
                        $method = $retailIds === [] ? 'where' : 'orWhere';
                        $scoped->{$method}('store_types.code', 'B2B');
                    }
                });
            })
            ->orderBy('store_types.code')
            ->orderBy('stores.name')
            ->get(['stores.id', 'stores.name', 'stores.code', 'store_types.code as channel'])
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'code' => (string) $row->code,
                'channel' => strtolower((string) $row->channel),
            ])
            ->values()
            ->all();
    }

    private function allowed(User $actor, int $storeId, string $permission): bool
    {
        return $actor->hasRole('SUPER_ADMIN') || $actor->hasPermission($permission, $storeId);
    }

    private function backTo(string $tab, int $storeId): RedirectResponse
    {
        return redirect()->route('admin.erp.treasury.index', [
            'tab' => $tab,
            'store_id' => $storeId,
        ]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
