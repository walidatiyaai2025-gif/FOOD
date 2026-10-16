<?php

namespace App\Http\Controllers\Admin;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Enterprise\Finance\AccountsPayableService;
use App\Enterprise\Finance\AccountsReceivableService;
use App\Enterprise\Finance\FinanceApprovalService;
use App\Enterprise\Finance\FinanceCreditTermsService;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Finance\FinancialStatementService;
use App\Enterprise\Finance\GeneralLedgerService;
use App\Enterprise\Finance\OpeningBalanceService;
use App\Enterprise\Modules\ModuleRegistry;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EnterpriseFinanceJournal;
use App\Models\EnterpriseFinanceLedgerEntry;
use App\Models\EnterpriseFinanceOpeningBalanceBatch;
use App\Models\EnterpriseFinanceSourcePosting;
use App\Models\EnterpriseWorkflowRequest;
use App\Models\User;
use App\Support\AdminNavigation;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class FinanceWorkbenchController extends Controller
{
    /** @var list<string> */
    private const TABS = [
        'overview',
        'accounts',
        'cost_centers',
        'periods',
        'journals',
        'opening_balances',
        'receivables',
        'payables',
        'credit_terms',
        'statements',
    ];

    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly TenantContextResolver $tenants,
        private readonly ModuleRegistry $modules,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly FinanceFoundationService $finance,
        private readonly GeneralLedgerService $ledger,
        private readonly OpeningBalanceService $openingBalances,
        private readonly AccountsReceivableService $receivables,
        private readonly AccountsPayableService $payables,
        private readonly FinanceCreditTermsService $creditTerms,
        private readonly FinancialStatementService $statements,
        private readonly FinanceApprovalService $approvals,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        abort_unless($this->modules->isEnabled(FinanceFoundationService::MODULE), 403);

        $contexts = $this->contextOptions($actor);
        abort_if($contexts === [], 403);

        $selected = $this->selectedContextOption($request, $contexts);
        if ($selected === null && count($contexts) === 1) {
            $selected = $contexts[0];
        }

        if ($selected === null) {
            return view('admin.finance-workbench', [
                'user' => $actor,
                'navGroups' => $this->navigation->groupsFor($actor),
                'navContext' => 'erp_finance',
                'contexts' => $contexts,
                'selectedContext' => null,
                'access' => [],
                'tabs' => $this->tabDefinitions([]),
                'tab' => 'overview',
                'data' => [],
                'needsContext' => true,
            ]);
        }

        $context = $this->resolveContext($request, $actor, $selected);
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');

        $access = $this->accessMatrix($actor, $context);
        abort_unless($access['foundation_read'] || $access['gl_read'] || $access['ar_read'] || $access['ap_read'], 403);

        $tab = (string) $request->query('tab', 'overview');
        abort_unless(in_array($tab, self::TABS, true), 404);

        $tabs = $this->tabDefinitions($access);
        $definition = collect($tabs)->firstWhere('key', $tab);
        abort_if(! is_array($definition), 404);
        abort_unless($definition['available'], 403);

        $data = match ($tab) {
            'overview' => $this->overviewData($actor, $context, $access),
            'accounts' => $this->accountData($request, $actor, $context),
            'cost_centers' => $this->costCenterData($request, $actor, $context),
            'periods' => $this->periodData($request, $actor, $context),
            'journals' => $this->journalData($request, $actor, $context),
            'opening_balances' => $this->openingBalanceData($request, $actor, $context),
            'receivables' => $this->receivableData($request, $actor, $context),
            'payables' => $this->payableData($request, $actor, $context),
            'credit_terms' => $this->creditTermData($request, $actor, $context),
            'statements' => $this->statementData($request, $actor, $context),
        };

        return view('admin.finance-workbench', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'erp_finance',
            'contexts' => $contexts,
            'selectedContext' => $selected,
            'access' => $access,
            'tabs' => $tabs,
            'tab' => $tab,
            'data' => $data,
            'needsContext' => false,
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);

        $this->finance->createAccount($actor, $context, [
            ...$request->only(['parent_id', 'code', 'name', 'type', 'normal_balance', 'status']),
            'is_control' => $request->boolean('is_control'),
            'allow_posting' => $request->boolean('allow_posting'),
        ]);

        return $this->redirectTo($request, 'accounts', 'account_created');
    }

    public function updateAccountStatus(Request $request, int $account): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $validated = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);

        $this->finance->setAccountStatus($actor, $context, $account, (string) $validated['status']);

        return $this->redirectTo($request, 'accounts', 'account_status_updated');
    }

    public function storeCostCenter(Request $request): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);

        $this->finance->createCostCenter(
            $actor,
            $context,
            $request->only(['parent_id', 'code', 'name', 'status']),
        );

        return $this->redirectTo($request, 'cost_centers', 'cost_center_created');
    }

    public function updateCostCenterStatus(Request $request, int $costCenter): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $validated = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);

        $this->finance->setCostCenterStatus($actor, $context, $costCenter, (string) $validated['status']);

        return $this->redirectTo($request, 'cost_centers', 'cost_center_status_updated');
    }

    public function storeFiscalYear(Request $request): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $this->finance->createFiscalYear(
            $actor,
            $context,
            $request->only(['code', 'name', 'starts_on', 'ends_on']),
        );

        return $this->redirectTo($request, 'periods', 'fiscal_year_created');
    }

    public function updateFiscalYearState(Request $request, int $fiscalYear): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $state = (string) $request->validate([
            'state' => ['required', Rule::in(['open', 'closed'])],
        ])['state'];

        if ($state === 'closed') {
            $this->finance->closeFiscalYear($actor, $context, $fiscalYear);
        } else {
            $this->finance->reopenFiscalYear($actor, $context, $fiscalYear);
        }

        return $this->redirectTo($request, 'periods', 'fiscal_year_state_updated');
    }

    public function storeFiscalPeriod(Request $request, int $fiscalYear): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $this->finance->createFiscalPeriod(
            $actor,
            $context,
            $fiscalYear,
            $request->only(['code', 'name', 'sequence', 'starts_on', 'ends_on']),
        );

        return $this->redirectTo($request, 'periods', 'fiscal_period_created');
    }

    public function updateFiscalPeriodState(Request $request, int $fiscalPeriod): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $state = (string) $request->validate([
            'state' => ['required', Rule::in(['open', 'closed'])],
        ])['state'];

        if ($state === 'closed') {
            $this->finance->closeFiscalPeriod($actor, $context, $fiscalPeriod);
        } else {
            $this->finance->reopenFiscalPeriod($actor, $context, $fiscalPeriod);
        }

        return $this->redirectTo($request, 'periods', 'fiscal_period_state_updated');
    }

    public function storeJournal(Request $request): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);

        $journal = $this->ledger->createDraft($actor, $context, [
            ...$request->only(['idempotency_key', 'entry_date', 'description']),
            'lines' => $request->input('lines', []),
        ]);

        return $this->redirectTo(
            $request,
            'journals',
            'journal_created',
            ['journal' => $journal->id],
        );
    }

    public function requestJournalApproval(Request $request, int $journal): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $model = $this->scopedJournal($context, $journal);

        $this->approvals->requestApproval(
            $actor,
            $context,
            FinanceApprovalService::ACTION_GL_POST,
            'enterprise_finance_journal',
            (string) $model->id,
            (string) $model->total_debit,
        );

        return $this->redirectTo(
            $request,
            'journals',
            'approval_requested',
            ['journal' => $model->id],
        );
    }

    public function postJournal(Request $request, int $journal): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $this->ledger->post($actor, $context, $journal);

        return $this->redirectTo(
            $request,
            'journals',
            'journal_posted',
            ['journal' => $journal],
        );
    }

    public function storeOpeningBalance(Request $request): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);

        $batch = $this->openingBalances->createDraft($actor, $context, [
            ...$request->only(['idempotency_key', 'fiscal_period_id', 'entry_date', 'description']),
            'lines' => $request->input('lines', []),
        ]);

        return $this->redirectTo(
            $request,
            'opening_balances',
            'opening_balance_created',
            ['batch' => $batch->id],
        );
    }

    public function requestOpeningBalanceApproval(Request $request, int $batch): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $model = $this->scopedOpeningBalance($context, $batch);

        $this->approvals->requestApproval(
            $actor,
            $context,
            FinanceApprovalService::ACTION_OPENING_BALANCE_POST,
            'enterprise_finance_opening_balance',
            (string) $model->id,
            (string) $model->total_debit,
        );

        return $this->redirectTo(
            $request,
            'opening_balances',
            'approval_requested',
            ['batch' => $model->id],
        );
    }

    public function postOpeningBalance(Request $request, int $batch): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $this->openingBalances->post($actor, $context, $batch);

        return $this->redirectTo($request, 'opening_balances', 'opening_balance_posted');
    }

    public function cancelOpeningBalance(Request $request, int $batch): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $this->openingBalances->cancel($actor, $context, $batch);

        return $this->redirectTo($request, 'opening_balances', 'opening_balance_cancelled');
    }

    public function storeCreditTerm(Request $request): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $this->creditTerms->create(
            $actor,
            $context,
            $request->only([
                'subject_type',
                'subject_reference',
                'credit_limit',
                'currency_code',
                'payment_terms_days',
                'valid_from',
                'valid_until',
                'status',
            ]),
        );

        return $this->redirectTo($request, 'credit_terms', 'credit_term_created');
    }

    public function updateCreditTermStatus(Request $request, int $creditTerm): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $status = (string) $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ])['status'];

        $this->creditTerms->setStatus($actor, $context, $creditTerm, $status);

        return $this->redirectTo($request, 'credit_terms', 'credit_term_status_updated');
    }

    /** @return array<string, mixed> */
    private function overviewData(User $actor, ResolvedTenantContext $context, array $access): array
    {
        $metrics = [
            'accounts' => null,
            'journals' => null,
            'receivables_open' => null,
            'payables_open' => null,
        ];

        if ($access['foundation_read']) {
            $metrics['accounts'] = $this->finance->paginateAccounts($actor, $context, [], 10)->total();
        }
        if ($access['gl_read']) {
            $metrics['journals'] = $this->ledger->paginateJournals($actor, $context, [], 10)->total();
        }
        if ($access['ar_read']) {
            $metrics['receivables_open'] = $this->receivables->aging($actor, $context, null, 100)->count();
        }
        if ($access['ap_read']) {
            $metrics['payables_open'] = $this->payables->aging($actor, $context, null, 100)->count();
        }

        return ['metrics' => $metrics];
    }

    /** @return array<string, mixed> */
    private function accountData(Request $request, User $actor, ResolvedTenantContext $context): array
    {
        $filters = $request->only(['q', 'type', 'status']);
        $accounts = $this->finance->paginateAccounts($actor, $context, $filters, 25);
        $parents = $this->finance->paginateAccounts(
            $actor,
            $context,
            ['status' => 'active'],
            100,
        )->getCollection()->filter(fn ($account): bool => (bool) $account->is_control)->values();

        return compact('accounts', 'parents', 'filters');
    }

    /** @return array<string, mixed> */
    private function costCenterData(Request $request, User $actor, ResolvedTenantContext $context): array
    {
        $filters = $request->only(['q', 'status']);
        $costCenters = $this->finance->paginateCostCenters($actor, $context, $filters, 25);
        $parents = $this->finance->paginateCostCenters(
            $actor,
            $context,
            ['status' => 'active'],
            100,
        )->getCollection();

        return compact('costCenters', 'parents', 'filters');
    }

    /** @return array<string, mixed> */
    private function periodData(Request $request, User $actor, ResolvedTenantContext $context): array
    {
        $filters = $request->only(['q', 'status']);
        $fiscalYears = $this->finance->paginateFiscalYears($actor, $context, $filters, 25);
        $fiscalPeriods = $this->finance->paginateFiscalPeriods($actor, $context, $filters, 25);
        $yearOptions = $this->finance->paginateFiscalYears($actor, $context, ['status' => 'open'], 100)
            ->getCollection();

        return compact('fiscalYears', 'fiscalPeriods', 'yearOptions', 'filters');
    }

    /** @return array<string, mixed> */
    private function journalData(Request $request, User $actor, ResolvedTenantContext $context): array
    {
        $filters = $request->only(['q', 'status', 'fiscal_period_id', 'date_from', 'date_to']);
        $journals = $this->ledger->paginateJournals($actor, $context, $filters, 25);
        $postingAccounts = $this->postingAccounts($actor, $context);

        $selectedJournal = null;
        $sourcePosting = null;
        $ledgerEntries = collect();
        $audit = collect();
        $approval = null;

        $journalId = $request->integer('journal');
        if ($journalId > 0) {
            $selectedJournal = $this->scopedJournal($context, $journalId)
                ->load(['lines.account', 'fiscalPeriod']);

            $scope = $context->enterpriseScope;
            abort_if($scope === null, 409);

            $sourcePosting = EnterpriseFinanceSourcePosting::query()
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('legal_entity_id', $scope->legalEntityId)
                ->where('journal_id', $selectedJournal->id)
                ->first();

            $ledgerEntries = EnterpriseFinanceLedgerEntry::query()
                ->with('account')
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('legal_entity_id', $scope->legalEntityId)
                ->where('journal_id', $selectedJournal->id)
                ->orderBy('id')
                ->limit(100)
                ->get();

            $audit = AuditLog::query()
                ->where('auditable_type', EnterpriseFinanceJournal::class)
                ->where('auditable_id', $selectedJournal->id)
                ->orderByDesc('id')
                ->limit(25)
                ->get();

            $approval = EnterpriseWorkflowRequest::query()
                ->with('steps')
                ->where('entity_type', 'enterprise_finance_journal')
                ->where('entity_id', (string) $selectedJournal->id)
                ->where('scope_type', 'store')
                ->where('scope_id', $scope->storeId)
                ->orderByDesc('id')
                ->first();
        }

        return compact(
            'journals',
            'postingAccounts',
            'filters',
            'selectedJournal',
            'sourcePosting',
            'ledgerEntries',
            'audit',
            'approval',
        );
    }

    /** @return array<string, mixed> */
    private function openingBalanceData(Request $request, User $actor, ResolvedTenantContext $context): array
    {
        $filters = $request->only(['q', 'status', 'fiscal_period_id', 'date_from', 'date_to']);
        $batches = $this->openingBalances->paginate($actor, $context, $filters, 25);
        $postingAccounts = $this->postingAccounts($actor, $context);
        $periodOptions = $this->finance->paginateFiscalPeriods($actor, $context, ['status' => 'open'], 100)
            ->getCollection();
        $costCenterOptions = $this->finance->paginateCostCenters($actor, $context, ['status' => 'active'], 100)
            ->getCollection();

        $selectedBatch = null;
        $approval = null;
        $batchId = $request->integer('batch');
        if ($batchId > 0) {
            $selectedBatch = $this->scopedOpeningBalance($context, $batchId)
                ->load(['lines', 'fiscalPeriod', 'journal']);
            $scope = $context->enterpriseScope;
            abort_if($scope === null, 409);

            $approval = EnterpriseWorkflowRequest::query()
                ->with('steps')
                ->where('entity_type', 'enterprise_finance_opening_balance')
                ->where('entity_id', (string) $selectedBatch->id)
                ->where('scope_type', 'store')
                ->where('scope_id', $scope->storeId)
                ->orderByDesc('id')
                ->first();
        }

        return compact(
            'batches',
            'postingAccounts',
            'periodOptions',
            'costCenterOptions',
            'filters',
            'selectedBatch',
            'approval',
        );
    }

    /** @return array<string, mixed> */
    private function receivableData(Request $request, User $actor, ResolvedTenantContext $context): array
    {
        $filters = $request->only(['customer_id', 'status', 'date_from', 'date_to']);
        $documents = $this->receivables->paginate($actor, $context, $filters, 25);
        $documents->getCollection()->load('customer');
        $aging = $this->receivables->aging(
            $actor,
            $context,
            $request->filled('customer_id') ? $request->integer('customer_id') : null,
            100,
        );

        return compact('documents', 'aging', 'filters');
    }

    /** @return array<string, mixed> */
    private function payableData(Request $request, User $actor, ResolvedTenantContext $context): array
    {
        $filters = $request->only(['supplier_reference', 'status', 'date_from', 'date_to']);
        $documents = $this->payables->paginate($actor, $context, $filters, 25);
        $aging = $this->payables->aging(
            $actor,
            $context,
            $request->filled('supplier_reference') ? (string) $request->input('supplier_reference') : null,
            100,
        );

        return compact('documents', 'aging', 'filters');
    }

    /** @return array<string, mixed> */
    private function creditTermData(Request $request, User $actor, ResolvedTenantContext $context): array
    {
        $filters = $request->only(['subject_type', 'subject_reference', 'status']);
        $policies = $this->creditTerms->paginate($actor, $context, $filters, 25);

        return compact('policies', 'filters');
    }

    /** @return array<string, mixed> */
    private function statementData(Request $request, User $actor, ResolvedTenantContext $context): array
    {
        $periods = $this->finance->paginateFiscalPeriods($actor, $context, [], 100)->getCollection();
        $costCenters = $this->finance->paginateCostCenters($actor, $context, ['status' => 'active'], 100)
            ->getCollection();

        $statement = null;
        $statementType = (string) $request->query('statement', 'trial_balance');
        abort_unless(in_array($statementType, ['trial_balance', 'profit_and_loss', 'balance_sheet'], true), 404);

        $periodId = $request->integer('period_id');
        $comparativePeriodId = $request->integer('comparative_period_id');
        $comparativePeriodId = $comparativePeriodId > 0 ? $comparativePeriodId : null;

        if ($periodId > 0) {
            $statement = match ($statementType) {
                'trial_balance' => $this->statements->trialBalance(
                    $actor,
                    $context,
                    $periodId,
                    $comparativePeriodId,
                ),
                'profit_and_loss' => $this->statements->profitAndLoss(
                    $actor,
                    $context,
                    $periodId,
                    $comparativePeriodId,
                    $request->integer('cost_center_id') > 0 ? $request->integer('cost_center_id') : null,
                ),
                'balance_sheet' => $this->statements->balanceSheet(
                    $actor,
                    $context,
                    $periodId,
                    $comparativePeriodId,
                ),
            };
        }

        return compact(
            'periods',
            'costCenters',
            'statement',
            'statementType',
            'periodId',
            'comparativePeriodId',
        );
    }

    /** @return Collection<int, mixed> */
    private function postingAccounts(User $actor, ResolvedTenantContext $context)
    {
        return $this->finance->paginateAccounts(
            $actor,
            $context,
            ['status' => 'active'],
            100,
        )->getCollection()
            ->filter(fn ($account): bool => (bool) $account->allow_posting && ! (bool) $account->is_control)
            ->values();
    }

    /** @return array<string, bool> */
    private function accessMatrix(User $actor, ResolvedTenantContext $context): array
    {
        $scope = $context->enterpriseScope;
        abort_if($scope === null, 409);

        $entitled = fn (string $capability): bool => $this->entitlements->allows(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
            $capability,
        );
        $permitted = fn (string $permission): bool => $actor->hasPermission($permission, $context->storeId);

        return [
            'foundation_read' => $permitted('finance.view') && $entitled('finance.foundation.read'),
            'foundation_manage' => $permitted('finance.manage') && $entitled('finance.foundation.manage'),
            'gl_read' => $permitted('finance.view') && $entitled('finance.gl.read'),
            'gl_manage' => $permitted('finance.manage') && $entitled('finance.gl.manage'),
            'gl_post' => $permitted('finance.post') && $entitled('finance.gl.post'),
            'opening_read' => $permitted('finance.view') && $entitled('finance.opening_balances.read'),
            'opening_manage' => $permitted('finance.manage') && $entitled('finance.opening_balances.manage'),
            'opening_post' => $permitted('finance.post') && $entitled('finance.opening_balances.post'),
            'ar_read' => $permitted('finance.view') && $entitled('finance.ar.read'),
            'ap_read' => $permitted('finance.view') && $entitled('finance.ap.read'),
            'credit_read' => $permitted('finance.view') && $entitled('finance.credit_terms.read'),
            'credit_manage' => $permitted('finance.manage') && $entitled('finance.credit_terms.manage'),
            'approval_request' => $permitted('finance.manage') && $entitled('finance.approvals.manage'),
            'approval_decide' => $permitted('finance.approve') && $entitled('finance.approvals.approve'),
        ];
    }

    /**
     * @param  array<string, bool>  $access
     * @return list<array{key:string,label:string,available:bool}>
     */
    private function tabDefinitions(array $access): array
    {
        $available = static fn (string $key): bool => $access === [] ? false : (bool) ($access[$key] ?? false);

        return [
            ['key' => 'overview', 'label' => 'finance_workbench.tabs.overview', 'available' => $access !== []],
            ['key' => 'accounts', 'label' => 'finance_workbench.tabs.accounts', 'available' => $available('foundation_read')],
            ['key' => 'cost_centers', 'label' => 'finance_workbench.tabs.cost_centers', 'available' => $available('foundation_read')],
            ['key' => 'periods', 'label' => 'finance_workbench.tabs.periods', 'available' => $available('foundation_read')],
            ['key' => 'journals', 'label' => 'finance_workbench.tabs.journals', 'available' => $available('gl_read')],
            ['key' => 'opening_balances', 'label' => 'finance_workbench.tabs.opening_balances', 'available' => $available('opening_read')],
            ['key' => 'receivables', 'label' => 'finance_workbench.tabs.receivables', 'available' => $available('ar_read')],
            ['key' => 'payables', 'label' => 'finance_workbench.tabs.payables', 'available' => $available('ap_read')],
            ['key' => 'credit_terms', 'label' => 'finance_workbench.tabs.credit_terms', 'available' => $available('credit_read')],
            ['key' => 'statements', 'label' => 'finance_workbench.tabs.statements', 'available' => $available('gl_read')],
        ];
    }

    /**
     * @return list<array{id:int,code:string,name:string,channel:string}>
     */
    private function contextOptions(User $actor): array
    {
        $retailIds = $this->tenants->retailStoreIds($actor);
        $canWholesale = $this->navigation->canAccess($actor, 'b2b');

        if (! $canWholesale && $retailIds === []) {
            return [];
        }

        $query = DB::table('stores as store')
            ->join('store_types as type', 'type.id', '=', 'store.store_type_id')
            ->where('store.is_active', true)
            ->where(function ($scope) use ($canWholesale, $retailIds): void {
                if ($canWholesale) {
                    $scope->where('type.code', 'B2B');
                }

                if ($retailIds !== []) {
                    $method = $canWholesale ? 'orWhere' : 'where';
                    $scope->{$method}(function ($retail) use ($retailIds): void {
                        $retail->where('type.code', 'B2C')
                            ->whereIn('store.id', $retailIds);
                    });
                }
            })
            ->select(['store.id', 'store.code', 'store.name', 'type.code as channel'])
            ->orderBy('type.code')
            ->orderBy('store.code')
            ->limit(100)
            ->get();

        return $query->map(static fn ($store): array => [
            'id' => (int) $store->id,
            'code' => (string) $store->code,
            'name' => (string) $store->name,
            'channel' => strtolower((string) $store->channel),
        ])->values()->all();
    }

    /**
     * @param  list<array{id:int,code:string,name:string,channel:string}>  $contexts
     * @return array{id:int,code:string,name:string,channel:string}|null
     */
    private function selectedContextOption(Request $request, array $contexts): ?array
    {
        $storeId = (int) $request->input('store_id', 0);
        $storeCode = trim((string) $request->input('store_code', ''));

        if ($storeId < 1 && $storeCode === '') {
            return null;
        }

        foreach ($contexts as $context) {
            if ($storeId > 0 && $context['id'] === $storeId) {
                return $context;
            }
            if ($storeCode !== '' && strcasecmp($context['code'], $storeCode) === 0) {
                return $context;
            }
        }

        abort(404);
    }

    /**
     * @param  array{id:int,code:string,name:string,channel:string}  $selected
     */
    private function resolveContext(Request $request, User $actor, array $selected): ResolvedTenantContext
    {
        if ($selected['channel'] === 'b2b') {
            return $this->tenants->wholesale($actor, $selected['id']);
        }

        return $this->tenants->retail(
            $actor,
            $selected['id'],
            $actor->hasRole('SUPER_ADMIN'),
            $request,
        );
    }

    /** @return array{User, ResolvedTenantContext} */
    private function mutationContext(Request $request): array
    {
        $actor = $this->actor($request);
        abort_unless($this->modules->isEnabled(FinanceFoundationService::MODULE), 403);

        $contexts = $this->contextOptions($actor);
        $selected = $this->selectedContextOption($request, $contexts);
        abort_if($selected === null, 422);

        $context = $this->resolveContext($request, $actor, $selected);
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');

        return [$actor, $context];
    }

    private function scopedJournal(ResolvedTenantContext $context, int $journal): EnterpriseFinanceJournal
    {
        $scope = $context->enterpriseScope;
        abort_if($scope === null, 409);

        return EnterpriseFinanceJournal::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->whereKey($journal)
            ->firstOrFail();
    }

    private function scopedOpeningBalance(
        ResolvedTenantContext $context,
        int $batch,
    ): EnterpriseFinanceOpeningBalanceBatch {
        $scope = $context->enterpriseScope;
        abort_if($scope === null, 409);

        return EnterpriseFinanceOpeningBalanceBatch::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->whereKey($batch)
            ->firstOrFail();
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    /**
     * @param  array<string, int|string>  $extra
     */
    private function redirectTo(
        Request $request,
        string $tab,
        string $message,
        array $extra = [],
    ): RedirectResponse {
        return redirect()
            ->route('admin.erp.finance.index', [
                'store_id' => (int) $request->input('store_id'),
                'tab' => $tab,
                ...$extra,
            ])
            ->with('success', __('finance_workbench.messages.'.$message));
    }
}
