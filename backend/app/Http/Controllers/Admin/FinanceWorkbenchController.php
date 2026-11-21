<?php

namespace App\Http\Controllers\Admin;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Enterprise\Finance\AccountsPayableService;
use App\Enterprise\Finance\AccountsReceivableService;
use App\Enterprise\Finance\CollectionAccountSettlementMappingService;
use App\Enterprise\Finance\FinanceAnalyticsService;
use App\Enterprise\Finance\FinanceApprovalService;
use App\Enterprise\Finance\FinanceAssetService;
use App\Enterprise\Finance\FinanceCreditTermsService;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Finance\FinancePeriodCloseService;
use App\Enterprise\Finance\FinancePlanningService;
use App\Enterprise\Finance\FinanceWorkbenchAccess;
use App\Enterprise\Finance\FinancialStatementService;
use App\Enterprise\Finance\GeneralLedgerService;
use App\Enterprise\Finance\OpeningBalanceService;
use App\Enterprise\Modules\ModuleRegistry;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EnterpriseAccountsReceivableAllocation;
use App\Models\EnterpriseAccountsReceivableDocument;
use App\Models\EnterpriseFinanceAccount;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FinanceWorkbenchController extends Controller
{
    /** @var list<string> */
    private const TABS = [
        'overview',
        'accounts',
        'cost_centers',
        'profit_centers',
        'periods',
        'journals',
        'opening_balances',
        'receivables',
        'payables',
        'credit_terms',
        'budgets',
        'tax',
        'allocations',
        'assets',
        'statements',
        'reports',
    ];

    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly TenantContextResolver $tenants,
        private readonly ModuleRegistry $modules,
        private readonly FinanceWorkbenchAccess $financeAccess,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly FinanceFoundationService $finance,
        private readonly GeneralLedgerService $ledger,
        private readonly OpeningBalanceService $openingBalances,
        private readonly AccountsReceivableService $receivables,
        private readonly CollectionAccountSettlementMappingService $collectionMappings,
        private readonly AccountsPayableService $payables,
        private readonly FinanceCreditTermsService $creditTerms,
        private readonly FinancialStatementService $statements,
        private readonly FinanceApprovalService $approvals,
        private readonly FinancePlanningService $planning,
        private readonly FinanceAssetService $assets,
        private readonly FinancePeriodCloseService $periodClose,
        private readonly FinanceAnalyticsService $analytics,
    ) {}

    public function index(Request $request): View|StreamedResponse
    {
        $actor = $this->actor($request);
        abort_unless($this->modules->isEnabled(FinanceFoundationService::MODULE), 403);
        [$requestedStoreId, $requestedStoreCode] = $this->validatedStoreSelectors($request);

        // Do not expose Finance context choices to operational roles lacking
        // Finance read permission. Authorize before enterprise scope resolution
        // so a service-only actor receives 403, not a misleading 409.
        $assignedContexts = $this->contextOptions($actor, $requestedStoreId, $requestedStoreCode);
        $contexts = array_values(array_filter(
            $assignedContexts,
            fn (array $option): bool => $this->financeAccess->canRead($actor, (int) $option['id']),
        ));

        // A Retail store assigned through an actual store-scoped role but
        // denied Finance is forbidden (403). Global Wholesale roles enumerate
        // B2B contexts without store assignment: preserve their 404 when denied.
        // Never resolve a denied store's enterprise data scope or borrow access
        // from the user's other authorized Retail store.
        $assignedRetailStoreIds = array_column(
            array_filter($assignedContexts, static fn (array $option): bool => $option['channel'] === 'b2c'),
            'id',
        );
        if ($requestedStoreId !== null
            && in_array($requestedStoreId, $assignedRetailStoreIds, true)
            && ! in_array($requestedStoreId, array_column($contexts, 'id'), true)) {
            abort(403);
        }

        if ($requestedStoreId === null && $requestedStoreCode !== '') {
            $requestedRetail = collect($assignedContexts)->first(
                fn (array $option): bool => $option['channel'] === 'b2c'
                    && strcasecmp($option['code'], $requestedStoreCode) === 0,
            );
            if (is_array($requestedRetail)
                && ! in_array($requestedRetail['id'], array_column($contexts, 'id'), true)) {
                abort(403);
            }
        }

        if (($requestedStoreId !== null || $requestedStoreCode !== '') && $contexts === []) {
            $otherAuthorizedContexts = array_filter(
                $this->contextOptions($actor),
                fn (array $option): bool => $this->financeAccess->canRead($actor, (int) $option['id']),
            );
            if ($otherAuthorizedContexts !== []) {
                abort(404);
            }
        }

        if ($contexts === []) {
            // Only a privileged platform operator with an actual platform
            // Finance entitlement can view the non-data setup context.
            // Missing/denied store contexts never grant ledger access.
            $setupAccess = $this->navigation->canAccessPlatformErp($actor)
                && ($actor->isPlatformOwner() || $actor->hasRole('SUPER_ADMIN'))
                && $actor->hasPermission('finance.view')
                && $this->entitlements->allows(FinanceFoundationService::MODULE, EntitlementScope::platform());
            abort_unless($setupAccess, 403);
        }

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
            'profit_centers' => $this->profitCenterData($request, $actor, $context),
            'periods' => $this->periodData($request, $actor, $context),
            'journals' => $this->journalData($request, $actor, $context),
            'opening_balances' => $this->openingBalanceData($request, $actor, $context),
            'receivables' => $this->receivableData($request, $actor, $context, $access),
            'payables' => $this->payableData($request, $actor, $context),
            'credit_terms' => $this->creditTermData($request, $actor, $context),
            'budgets' => $this->budgetData($request, $actor, $context),
            'tax' => $this->taxData($actor, $context),
            'allocations' => $this->allocationData($actor, $context),
            'assets' => $this->assetData($request, $actor, $context),
            'statements' => $this->statementData($request, $actor, $context),
            'reports' => $this->reportData($request, $actor, $context),
        };
        // Keep the Blade view contract stable on every authorized tab. AR/AP
        // only readers do not have foundation access, so this fallback must
        // remain empty and must not query fiscal periods without entitlement.
        $data['periods'] ??= collect();

        if ($request->query->has('download')) {
            abort_unless($request->query('download') === 'csv' && in_array($tab, ['reports', 'statements'], true), 404);
            abort_if(($tab === 'reports' ? $data['report'] : $data['statement']) === null, 404);

            return $this->downloadFinancialCsv($tab, $data);
        }

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

    public function proposeCollectionMapping(Request $request): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $action = $request->validate(['mapping_action' => ['sometimes', 'in:test,save']]);
        $attributes = $request->only([
            'request_id', 'collection_account_id', 'finance_account_id',
        ]);

        if (($action['mapping_action'] ?? 'save') === 'test') {
            $this->collectionMappings->testProposal($actor, $context, $attributes);

            return $this->redirectTo($request, 'receivables', 'mapping_tested', ['finance_panel' => 'settlements']);
        }

        $this->collectionMappings->propose($actor, $context, $attributes);

        return $this->redirectTo($request, 'receivables', 'mapping_proposed', ['finance_panel' => 'settlements']);
    }

    public function reviewCollectionMapping(Request $request, int $mapping): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $this->collectionMappings->review(
            $actor, $context, $mapping, (string) $request->input('decision', ''),
        );

        return $this->redirectTo($request, 'receivables', 'mapping_reviewed', ['finance_panel' => 'settlements']);
    }

    public function storeReceivableSettlement(Request $request, int $document): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);

        // The same legal entity may serve several Retail stores. Store-scoped
        // operators may only settle a document belonging to the selected store.
        if ($context->isRetail()) {
            abort_unless(
                EnterpriseAccountsReceivableDocument::query()
                    ->whereKey($document)
                    ->where('store_id', $context->requiresStore())
                    ->exists(),
                404,
            );
        }

        // The canonical AR service owns permission, legal-entity scope,
        // entitlement, idempotency and over-allocation validation.
        $this->receivables->createAllocation(
            $actor,
            $context,
            $document,
            $request->only([
                'idempotency_key',
                'allocation_date',
                'amount',
                'settlement_account_id',
                'source_collection_allocation_id',
                'reference',
            ]),
        );

        return $this->redirectTo($request, 'receivables', 'ar_settlement_created', [
            'settlement_document_id' => $document,
        ]);
    }

    public function postReceivableSettlement(Request $request, int $allocation): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);

        if ($context->isRetail()) {
            abort_unless(
                EnterpriseAccountsReceivableAllocation::query()
                    ->whereKey($allocation)
                    ->whereHas('document', fn ($query) => $query->where('store_id', $context->requiresStore()))
                    ->exists(),
                404,
            );
        }

        // Never allow a typed free-text reference to attest a collected
        // receipt. The canonical AR service checks posting authority first
        // and fails closed until P303 supports a verified source link.
        $posted = $this->receivables->postAllocation(
            $actor,
            $context,
            $allocation,
            requiresVerifiedCollectionSource: true,
        );

        return $this->redirectTo($request, 'receivables', 'ar_settlement_posted', [
            'settlement_document_id' => (int) $posted->ar_document_id,
        ]);
    }

    /**
     * Download the exact report already authorized by the canonical Finance tab.
     * No second route, entitlement decision or unscoped export query is introduced.
     *
     * @param  array<string, mixed>  $data
     */
    private function downloadFinancialCsv(string $tab, array $data): StreamedResponse
    {
        $report = $tab === 'reports' ? $data['report'] : $data['statement'];
        $lines = $report['lines'];
        $columns = $tab === 'reports'
            ? array_keys($lines[0] ?? ['code' => null])
            : ['code', 'name', 'type', 'debit_mills', 'credit_mills', 'balance_mills', 'comparative_balance_mills'];
        $labels = $tab === 'reports'
            ? array_map(
                static fn (string $key): string => $key === 'code'
                    ? __('finance_workbench.common.code')
                    : __('finance_workbench.report_columns.'.$key),
                $columns,
            )
            : [
                __('finance_workbench.common.code'),
                __('finance_workbench.statements.account'),
                __('finance_workbench.common.type'),
                __('finance_workbench.common.debit'),
                __('finance_workbench.common.credit'),
                __('finance_workbench.statements.balance'),
                __('finance_workbench.statements.comparative'),
            ];
        $reportName = $tab === 'reports' ? $report['code'] : $data['statementType'];
        $filename = sprintf('foodex-finance-%s-%s-period-%d.csv', $tab, $reportName, $data['periodId']);

        return response()->streamDownload(static function () use ($lines, $columns, $labels, $tab): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                return;
            }

            // UTF-8 BOM makes Arabic headings readable in spreadsheet programs.
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $labels, ',', '"', '');

            foreach ($lines as $line) {
                fputcsv($stream, array_map(static function (string $column) use ($line, $tab): string|int|float {
                    $value = $line[$column] ?? null;
                    if ($value === null) {
                        return '';
                    }
                    if ($tab === 'statements' && str_ends_with($column, '_mills') && is_numeric($value)) {
                        // Statements persist integer milli-units, but CSV must
                        // show readable currency amounts, not values 1000x too high.
                        $mills = (int) $value;
                        $absolute = abs($mills);

                        return ($mills < 0 ? '-' : '').intdiv($absolute, 1000).'.'.str_pad((string) ($absolute % 1000), 3, '0', STR_PAD_LEFT);
                    }
                    if (is_bool($value)) {
                        return $value ? __('finance_workbench.common.yes') : __('finance_workbench.common.no');
                    }
                    if (is_int($value) || is_float($value)) {
                        return $value;
                    }

                    $text = is_array($value)
                        ? (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')
                        : (string) $value;

                    // Neutralize spreadsheet formulas, including prefixed whitespace.
                    return preg_match('/^\\s*[=+\\-@]/u', $text) ? "'".$text : $text;
                }, $columns), ',', '"', '');
            }

            fclose($stream);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store, no-cache, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
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

    public function storeProfitCenter(Request $request): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $this->planning->createProfitCenter($actor, $context, $request->only(['parent_id', 'code', 'name']));

        return $this->redirectTo($request, 'profit_centers', 'profit_center_created');
    }

    public function updateProfitCenterStatus(Request $request, int $profitCenter): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $status = (string) $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ])['status'];
        $this->planning->setProfitCenterStatus($actor, $context, $profitCenter, $status);

        return $this->redirectTo($request, 'profit_centers', 'profit_center_status_updated');
    }

    public function updateCashFlowClassification(Request $request, int $account): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $classification = $request->validate([
            'classification' => ['nullable', Rule::in(['operating', 'investing', 'financing'])],
        ])['classification'] ?? null;
        $this->planning->setCashFlowClassification($actor, $context, $account, $classification);

        return $this->redirectTo($request, 'accounts', 'cash_flow_classification_updated');
    }

    public function storeBudget(Request $request): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $budget = $this->planning->createBudget(
            $actor,
            $context,
            $request->only(['fiscal_year_id', 'name', 'currency_code', 'revision_note']),
        );

        return $this->redirectTo($request, 'budgets', 'budget_created', ['budget' => $budget->id]);
    }

    public function storeBudgetLine(Request $request, int $budget): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $this->planning->saveBudgetLine(
            $actor,
            $context,
            $budget,
            $request->only(['fiscal_period_id', 'account_id', 'cost_center_id', 'profit_center_id', 'amount', 'notes']),
        );

        return $this->redirectTo($request, 'budgets', 'budget_line_saved', ['budget' => $budget]);
    }

    public function transitionBudget(Request $request, int $budget): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $action = (string) $request->validate([
            'action' => ['required', Rule::in(['submit', 'approve', 'lock'])],
        ])['action'];
        $this->planning->transitionBudget($actor, $context, $budget, $action);

        return $this->redirectTo($request, 'budgets', 'budget_transitioned', ['budget' => $budget]);
    }

    public function reviseBudget(Request $request, int $budget): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $data = $request->validate(['revision_note' => ['required', 'string', 'max:2000']]);
        $revision = $this->planning->reviseBudget($actor, $context, $budget, (string) $data['revision_note']);

        return $this->redirectTo($request, 'budgets', 'budget_revised', ['budget' => $revision->id]);
    }

    public function storeTaxCode(Request $request): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $this->planning->createTaxCode(
            $actor,
            $context,
            $request->only([
                'code', 'name', 'rate_percent', 'direction', 'recoverable_percent',
                'input_account_id', 'output_account_id', 'effective_from', 'effective_to',
            ]),
        );

        return $this->redirectTo($request, 'tax', 'tax_code_created');
    }

    public function storeAllocationRule(Request $request): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $this->planning->createAllocationRule(
            $actor,
            $context,
            $request->only([
                'code', 'name', 'account_id', 'source_cost_center_id', 'source_profit_center_id',
                'target_cost_center_id', 'target_profit_center_id', 'allocation_percent', 'basis_description',
            ]),
        );

        return $this->redirectTo($request, 'allocations', 'allocation_rule_created');
    }

    public function applyAllocationRule(Request $request, int $allocationRule): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $journalId = $this->planning->applyAllocationRule(
            $actor,
            $context,
            $allocationRule,
            $request->only(['entry_date', 'base_amount', 'idempotency_key']),
        );

        return $this->redirectTo($request, 'journals', 'allocation_journal_created', ['journal' => $journalId]);
    }

    public function storeConsolidationElimination(Request $request): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $this->planning->createElimination(
            $actor,
            $context,
            $request->only([
                'fiscal_year_code', 'period_code', 'account_type',
                'debit', 'credit', 'description',
            ]),
        );

        return $this->redirectTo($request, 'reports', 'consolidation_elimination_created', [
            'report' => 'consolidated',
        ]);
    }

    public function storeAssetCategory(Request $request): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $this->assets->createCategory(
            $actor,
            $context,
            $request->only([
                'code', 'name', 'depreciation_method', 'default_useful_life_months',
                'default_declining_rate_percent', 'asset_account_id',
                'accumulated_depreciation_account_id', 'depreciation_expense_account_id',
                'disposal_gain_account_id', 'disposal_loss_account_id',
            ]),
        );

        return $this->redirectTo($request, 'assets', 'asset_category_created');
    }

    public function storeAsset(Request $request): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $asset = $this->assets->createAsset(
            $actor,
            $context,
            $request->only([
                'category_id', 'asset_code', 'name', 'acquisition_date', 'capitalization_date',
                'acquisition_amount', 'salvage_value', 'useful_life_months', 'declining_rate_percent',
                'cost_center_id', 'profit_center_id', 'acquisition_offset_account_id', 'idempotency_key',
            ]),
        );

        return $this->redirectTo($request, 'assets', 'asset_created', ['asset' => $asset->id]);
    }

    public function createAssetDepreciation(Request $request, int $asset): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $data = $request->validate([
            'fiscal_period_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ]);
        $run = $this->assets->createDepreciationJournal(
            $actor,
            $context,
            $asset,
            (int) $data['fiscal_period_id'],
            (string) $data['idempotency_key'],
        );

        return $this->redirectTo($request, 'journals', 'asset_depreciation_created', ['journal' => $run->journal_id]);
    }

    public function prepareAssetDisposal(Request $request, int $asset): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $model = $this->assets->prepareDisposal(
            $actor,
            $context,
            $asset,
            $request->only(['disposal_date', 'disposal_proceeds', 'settlement_account_id', 'idempotency_key']),
        );

        return $this->redirectTo($request, 'assets', 'asset_disposal_prepared', ['asset' => $model->id]);
    }

    public function evaluatePeriodClose(Request $request, int $fiscalPeriod): RedirectResponse
    {
        [$actor, $context] = $this->mutationContext($request);
        $this->periodClose->evaluate($actor, $context, $fiscalPeriod);

        return $this->redirectTo($request, 'periods', 'period_close_evaluated', ['period_id' => $fiscalPeriod]);
    }

    /** @return array<string, mixed> */
    private function overviewData(User $actor, ResolvedTenantContext $context, array $access): array
    {
        if (! $access['foundation_read']) {
            return ['state' => 'permission', 'period' => null, 'metrics' => [], 'trend' => [], 'aging' => ['ar' => [], 'ap' => []], 'expenses' => [], 'exceptions' => []];
        }

        $overview = $this->analytics->overview($actor, $context, request()->integer('period_id') ?: null);
        $overview['periods'] = $this->finance->paginateFiscalPeriods($actor, $context, [], 100)->getCollection();

        return $overview;
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
    private function profitCenterData(Request $request, User $actor, ResolvedTenantContext $context): array
    {
        $filters = $request->only(['q', 'status']);
        $profitCenters = $this->planning->paginateProfitCenters($actor, $context, $filters, 25);
        $parents = $this->planning->paginateProfitCenters($actor, $context, ['status' => 'active'], 100)->getCollection();

        return compact('profitCenters', 'parents', 'filters');
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
    private function receivableData(
        Request $request,
        User $actor,
        ResolvedTenantContext $context,
        array $access,
    ): array {
        $filters = $request->only(['customer_id', 'status', 'date_from', 'date_to']);
        $documents = $this->receivables->paginate($actor, $context, $filters, 25);
        $documents->getCollection()->load('customer');
        $customerId = $request->filled('customer_id') ? $request->integer('customer_id') : null;
        $aging = $this->receivables->paginateAging($actor, $context, $customerId, 25);
        $agingTotals = $this->receivables->agingTotals($actor, $context, $customerId);

        // Keep custody-account GL mapping in the existing Receivables
        // workbench. It is maker/checker configuration, not a cash posting
        // or a replacement for verified Treasury receipt provenance.
        $mappingRevisions = $this->collectionMappings->revisions($actor, $context);
        $mappingCollectors = collect();
        $mappingFinanceAccounts = collect();
        if (($access['ar_manage'] ?? false) || ($access['approval_decide'] ?? false)) {
            $scope = $context->enterpriseScope;
            abort_if($scope === null, 409);
            $mappingCollectors = DB::table('collection_accounts as collector')
                ->leftJoin('drivers as driver', function ($join): void {
                    $join->on('driver.id', '=', 'collector.actor_id')
                        ->where('collector.actor_type', 'driver');
                })
                ->leftJoin('users as driver_user', 'driver_user.id', '=', 'driver.user_id')
                ->leftJoin('users as van_user', function ($join): void {
                    $join->on('van_user.id', '=', 'collector.actor_id')
                        ->where('collector.actor_type', 'van_rep');
                })
                ->where('collector.store_id', $context->requiresStore())
                ->where('collector.status', 'active')
                ->where('collector.actor_type', $context->isRetail() ? 'driver' : 'van_rep')
                ->orderBy('collector.id')
                ->limit(100)
                ->get(['collector.id', 'collector.currency', 'collector.actor_type',
                    DB::raw('COALESCE(driver_user.name, van_user.name) as collector_name')]);
            $mappingFinanceAccounts = EnterpriseFinanceAccount::query()
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('legal_entity_id', $scope->legalEntityId)
                ->where('status', 'active')
                ->where('type', 'asset')
                ->where('normal_balance', 'debit')
                ->where('allow_posting', true)
                ->where('is_control', false)
                ->orderBy('code')
                ->limit(100)
                ->get();
        }

        // Show exactly one selected AR document and a bounded, separate
        // settlement paginator; never eager-load an unlimited allocation list
        // for every document on the main AR page.
        $settlementDocument = null;
        $settlements = null;
        $settlementAccounts = collect();
        $settlementCreators = collect();
        $receiptCandidates = collect();
        $sourceReceiptReferences = collect();
        if ($request->filled('settlement_document_id')) {
            $validated = $request->validate([
                'settlement_document_id' => ['required', 'integer', 'min:1'],
            ]);
            $scope = $context->enterpriseScope;
            abort_if($scope === null, 409);

            $settlementDocument = EnterpriseAccountsReceivableDocument::query()
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('legal_entity_id', $scope->legalEntityId)
                ->when($context->isRetail(), fn ($query) => $query->where('store_id', $context->requiresStore()))
                ->when($context->isWholesale(), fn ($query) => $query->whereIn('store_id', DB::table('stores as store')
                    ->join('store_types as type', 'type.id', '=', 'store.store_type_id')
                    ->where('type.code', 'B2B')
                    ->select('store.id')))
                ->whereKey((int) $validated['settlement_document_id'])
                ->firstOrFail();

            $settlements = EnterpriseAccountsReceivableAllocation::query()
                ->where('ar_document_id', $settlementDocument->id)
                ->orderByDesc('id')
                ->paginate(25, ['*'], 'settlement_page')
                ->withQueryString();

            $settlementCreators = User::query()
                ->whereIn('id', $settlements->getCollection()->pluck('created_by')->filter()->all())
                ->pluck('name', 'id');

            $linkedSourceIds = $settlements->getCollection()
                ->pluck('source_collection_allocation_id')->filter()->all();
            if ($linkedSourceIds !== []) {
                $sourceReceiptReferences = DB::table('collection_allocations as source')
                    ->join('collection_transactions as receipt', 'receipt.id', '=', 'source.collection_transaction_id')
                    ->whereIn('source.id', $linkedSourceIds)
                    ->pluck('receipt.created_at', 'source.id');
            }

            if ($access['ar_manage'] ?? false) {
                $settlementAccounts = EnterpriseFinanceAccount::query()
                    ->where('organization_id', $scope->organizationId)
                    ->where('company_id', $scope->companyId)
                    ->where('legal_entity_id', $scope->legalEntityId)
                    ->where('status', 'active')
                    ->where('allow_posting', true)
                    ->where('is_control', false)
                    ->orderBy('code')
                    ->limit(100)
                    ->get();

                // Selection is a bounded, store/invoice-scoped preview only.
                // The canonical AR service row-locks and validates the receipt
                // again when drafting; no UI option can authorize GL posting.
                if ($settlementDocument->source_invoice_id !== null) {
                    $receiptCandidates = DB::table('collection_allocations as source')
                        ->join('collection_transactions as receipt', 'receipt.id', '=', 'source.collection_transaction_id')
                        ->join('collection_accounts as custody', 'custody.id', '=', 'receipt.collection_account_id')
                        ->join('payments as payment', 'payment.id', '=', 'receipt.payment_id')
                        ->leftJoin('enterprise_ar_allocations as claimed', 'claimed.source_collection_allocation_id', '=', 'source.id')
                        ->whereNull('claimed.id')
                        ->where('source.invoice_id', (int) $settlementDocument->source_invoice_id)
                        ->where('source.currency', (string) $settlementDocument->currency_code)
                        ->where('custody.store_id', (int) $settlementDocument->store_id)
                        ->where('custody.currency', (string) $settlementDocument->currency_code)
                        ->where('custody.actor_type', $context->isRetail() ? 'driver' : 'van_rep')
                        ->where('custody.status', 'active')
                        ->where('receipt.type', 'collection')
                        ->where('receipt.status', 'posted')
                        // Preview only the source emitted by the correct
                        // Driver/Van channel; service revalidates under locks.
                        ->where('receipt.source', $context->isRetail() ? 'driver_app' : 'van_app')
                        ->where('receipt.currency', (string) $settlementDocument->currency_code)
                        ->where('payment.status', 'paid')
                        ->where('payment.provider', 'field_collection')
                        ->where('payment.currency', (string) $settlementDocument->currency_code)
                        // Preview must not advertise inconsistent Payment/receipt
                        // totals; the AR service rechecks exact mills on POST.
                        ->whereColumn('payment.amount', 'receipt.amount')
                        ->whereColumn('payment.provider_reference', 'receipt.idempotency_key')
                        ->where(function ($query) use ($settlementDocument): void {
                            $query->whereNull('payment.invoice_id')
                                ->orWhere('payment.invoice_id', (int) $settlementDocument->source_invoice_id);
                        })
                        ->whereNotExists(function ($query): void {
                            $query->selectRaw('1')
                                ->from('collection_transactions as reversal')
                                ->whereColumn('reversal.reversal_of_id', 'receipt.id')
                                ->where('reversal.status', 'posted');
                        })
                        ->where('source.amount', '>', 0)
                        ->orderByDesc('source.id')
                        ->limit(50)
                        ->get(['source.id', 'source.amount', 'source.currency', 'receipt.created_at as collected_at']);
                }
            }
        }

        return compact('documents', 'aging', 'agingTotals', 'filters', 'settlementDocument', 'settlements', 'settlementAccounts', 'settlementCreators', 'receiptCandidates', 'sourceReceiptReferences', 'mappingRevisions', 'mappingCollectors', 'mappingFinanceAccounts');
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
    private function budgetData(Request $request, User $actor, ResolvedTenantContext $context): array
    {
        $budgets = $this->planning->paginateBudgets($actor, $context, 25);
        $selectedBudget = null;
        $budgetId = $request->integer('budget');
        if ($budgetId > 0) {
            $selectedBudget = $this->planning->budgetDetail($actor, $context, $budgetId);
        }

        return [
            'budgets' => $budgets,
            'selectedBudget' => $selectedBudget,
            'years' => $this->finance->paginateFiscalYears($actor, $context, [], 100)->getCollection(),
            'periods' => $this->finance->paginateFiscalPeriods($actor, $context, [], 100)->getCollection(),
            'accounts' => $this->postingAccounts($actor, $context),
            'costCenters' => $this->finance->paginateCostCenters($actor, $context, ['status' => 'active'], 100)->getCollection(),
            'profitCenters' => $this->planning->paginateProfitCenters($actor, $context, ['status' => 'active'], 100)->getCollection(),
        ];
    }

    /** @return array<string, mixed> */
    private function taxData(User $actor, ResolvedTenantContext $context): array
    {
        return [
            'taxCodes' => $this->planning->paginateTaxCodes($actor, $context, 25),
            'accounts' => $this->postingAccounts($actor, $context),
        ];
    }

    /** @return array<string, mixed> */
    private function allocationData(User $actor, ResolvedTenantContext $context): array
    {
        return [
            'rules' => $this->planning->paginateAllocationRules($actor, $context, 25),
            'accounts' => $this->postingAccounts($actor, $context),
            'costCenters' => $this->finance->paginateCostCenters($actor, $context, ['status' => 'active'], 100)->getCollection(),
            'profitCenters' => $this->planning->paginateProfitCenters($actor, $context, ['status' => 'active'], 100)->getCollection(),
        ];
    }

    /** @return array<string, mixed> */
    private function assetData(Request $request, User $actor, ResolvedTenantContext $context): array
    {
        $filters = $request->only(['q', 'status', 'category_id']);

        return [
            'assets' => $this->assets->paginateAssets($actor, $context, $filters, 25),
            'categories' => $this->assets->categories($actor, $context),
            'accounts' => $this->postingAccounts($actor, $context),
            'costCenters' => $this->finance->paginateCostCenters($actor, $context, ['status' => 'active'], 100)->getCollection(),
            'profitCenters' => $this->planning->paginateProfitCenters($actor, $context, ['status' => 'active'], 100)->getCollection(),
            'periods' => $this->finance->paginateFiscalPeriods($actor, $context, [], 100)->getCollection(),
            'filters' => $filters,
        ];
    }

    /** @return array<string, mixed> */
    private function reportData(Request $request, User $actor, ResolvedTenantContext $context): array
    {
        $periods = $this->finance->paginateFiscalPeriods($actor, $context, [], 100)->getCollection();
        $reportType = (string) $request->query('report', 'cash_flow');
        $periodId = $request->integer('period_id');
        $report = $periodId > 0
            ? $this->analytics->report($actor, $context, $reportType, $periodId)
            : null;

        return compact('periods', 'reportType', 'periodId', 'report');
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
        $storeId = $context->requiresStore();

        // Require the ledger's legal-entity capability as well as its exact
        // store-effective capability (including child store overrides).
        $entitled = fn (string $capability): bool => $this->entitlements->allows(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
            $capability,
        ) && $this->financeAccess->allowsAtStore($storeId, $capability);
        // The same server-scoped Finance access verdict governs every visible tab
        // and direct HTTP mutation, including restricted combined duty roles.
        $permitted = fn (string $permission, string $capability): bool => $this->canPerformFinanceAction(
            $actor, $storeId, $permission, $capability,
        );

        return [
            'foundation_read' => $permitted('finance.view', 'finance.foundation.read') && $entitled('finance.foundation.read'),
            // Posted GL has legal-entity, not immutable Retail store, source scope.
            // Report/statement tab visibility must match server-side 403 checks.
            'reports_read' => ! $context->isRetail() && $permitted('finance.view', 'finance.foundation.read') && $entitled('finance.foundation.read'),
            'statements_read' => ! $context->isRetail() && $permitted('finance.view', 'finance.gl.read') && $entitled('finance.gl.read'),
            'foundation_manage' => $permitted('finance.manage', 'finance.foundation.manage') && $entitled('finance.foundation.manage'),
            'gl_read' => $permitted('finance.view', 'finance.gl.read') && $entitled('finance.gl.read'),
            'gl_manage' => $permitted('finance.manage', 'finance.gl.manage') && $entitled('finance.gl.manage'),
            'gl_post' => $permitted('finance.post', 'finance.gl.post') && $entitled('finance.gl.post'),
            'opening_read' => $permitted('finance.view', 'finance.opening_balances.read') && $entitled('finance.opening_balances.read'),
            'opening_manage' => $permitted('finance.manage', 'finance.opening_balances.manage') && $entitled('finance.opening_balances.manage'),
            'opening_post' => $permitted('finance.post', 'finance.opening_balances.post') && $entitled('finance.opening_balances.post'),
            'ar_read' => $permitted('finance.view', 'finance.ar.read') && $entitled('finance.ar.read'),
            'ar_manage' => $permitted('finance.manage', 'finance.ar.manage') && $entitled('finance.ar.manage'),
            'ar_post' => $permitted('finance.post', 'finance.ar.post') && $entitled('finance.ar.post'),
            'ap_read' => $permitted('finance.view', 'finance.ap.read') && $entitled('finance.ap.read'),
            'credit_read' => $permitted('finance.view', 'finance.credit_terms.read') && $entitled('finance.credit_terms.read'),
            'credit_manage' => $permitted('finance.manage', 'finance.credit_terms.manage') && $entitled('finance.credit_terms.manage'),
            'approval_request' => $permitted('finance.manage', 'finance.approvals.manage') && $entitled('finance.approvals.manage'),
            'approval_decide' => $permitted('finance.approve', 'finance.approvals.approve') && $entitled('finance.approvals.approve'),
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
            ['key' => 'profit_centers', 'label' => 'finance_workbench.tabs.profit_centers', 'available' => $available('foundation_read')],
            ['key' => 'periods', 'label' => 'finance_workbench.tabs.periods', 'available' => $available('foundation_read')],
            ['key' => 'journals', 'label' => 'finance_workbench.tabs.journals', 'available' => $available('gl_read')],
            ['key' => 'opening_balances', 'label' => 'finance_workbench.tabs.opening_balances', 'available' => $available('opening_read')],
            ['key' => 'receivables', 'label' => 'finance_workbench.tabs.receivables', 'available' => $available('ar_read')],
            ['key' => 'payables', 'label' => 'finance_workbench.tabs.payables', 'available' => $available('ap_read')],
            ['key' => 'credit_terms', 'label' => 'finance_workbench.tabs.credit_terms', 'available' => $available('credit_read')],
            ['key' => 'budgets', 'label' => 'finance_workbench.tabs.budgets', 'available' => $available('foundation_read')],
            ['key' => 'tax', 'label' => 'finance_workbench.tabs.tax', 'available' => $available('foundation_read')],
            ['key' => 'allocations', 'label' => 'finance_workbench.tabs.allocations', 'available' => $available('foundation_read')],
            ['key' => 'assets', 'label' => 'finance_workbench.tabs.assets', 'available' => $available('foundation_read')],
            ['key' => 'statements', 'label' => 'finance_workbench.tabs.statements', 'available' => $available('statements_read')],
            ['key' => 'reports', 'label' => 'finance_workbench.tabs.reports', 'available' => $available('reports_read')],
        ];
    }

    /**
     * @return list<array{id:int,code:string,name:string,channel:string}>
     */
    private function contextOptions(User $actor, ?int $selectedStoreId = null, string $selectedStoreCode = ''): array
    {
        $retailIds = $this->tenants->retailStoreIds($actor);
        $canWholesale = $this->navigation->canAccess($actor, 'b2b');

        if (! $canWholesale && $retailIds === []) {
            return [];
        }

        if ($selectedStoreId !== null) {
            $isAssignedRetailStore = in_array($selectedStoreId, $retailIds, true);
            if (! $isAssignedRetailStore && ! $canWholesale) {
                return [];
            }

            $store = DB::table('stores as store')
                ->join('store_types as type', 'type.id', '=', 'store.store_type_id')
                ->where('store.is_active', true)
                ->where('store.id', $selectedStoreId)
                ->where('type.code', $isAssignedRetailStore ? 'B2C' : 'B2B')
                ->select(['store.id', 'store.code', 'store.name', 'type.code as channel'])
                ->first();

            return $store === null ? [] : [[
                'id' => (int) $store->id,
                'code' => (string) $store->code,
                'name' => (string) $store->name,
                'channel' => strtolower((string) $store->channel),
            ]];
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
            ->orderBy('store.code');

        // Keep the unselected chooser bounded, while allowing a directly
        // selected, otherwise-authorized store beyond the first 100 options.
        if ($selectedStoreCode !== '') {
            $query->whereRaw('LOWER(store.code) = ?', [strtolower($selectedStoreCode)]);
        } else {
            $query->limit(100);
        }

        $stores = $query->get();

        return $stores->map(static fn ($store): array => [
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
        [$storeId, $storeCode] = $this->validatedStoreSelectors($request);

        if ($storeId === null && $storeCode === '') {
            return null;
        }

        // When both selectors are supplied, they must identify the SAME
        // authorized context. A valid code must never override a forged or
        // stale store_id (or vice versa) and silently switch the ledger scope.
        foreach ($contexts as $context) {
            if (($storeId === null || $context['id'] === $storeId)
                && ($storeCode === '' || strcasecmp($context['code'], $storeCode) === 0)) {
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

    /**
     * A forged context selector must never be silently coerced into an absent
     * selector. Parse before querying a store or resolving a Finance ledger.
     *
     * @return array{?int, string}
     */
    private function validatedStoreSelectors(Request $request): array
    {
        $rawStoreId = $request->input('store_id');
        $storeId = null;
        if ($rawStoreId !== null && $rawStoreId !== '') {
            abort_unless(is_int($rawStoreId) || is_string($rawStoreId), 404);
            $text = (string) $rawStoreId;
            abort_unless(preg_match('/^[1-9][0-9]*$/D', $text) === 1, 404);

            $id = filter_var($text, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                abort(404);
            }

            $storeId = $id;
        }

        $rawStoreCode = $request->input('store_code', '');
        abort_unless(is_string($rawStoreCode), 404);

        return [$storeId, trim($rawStoreCode)];
    }

    /** @return array{User, ResolvedTenantContext} */
    private function mutationContext(Request $request): array
    {
        $actor = $this->actor($request);
        abort_unless($this->modules->isEnabled(FinanceFoundationService::MODULE), 403);
        [$requestedStoreId, $requestedStoreCode] = $this->validatedStoreSelectors($request);

        $contexts = $this->contextOptions($actor, $requestedStoreId, $requestedStoreCode);
        $selected = $this->selectedContextOption($request, $contexts);
        abort_if($selected === null, 422);

        // Journal and other Finance mutations must not resolve sensitive
        // enterprise scope for an actor without any Finance write capability.
        abort_unless(
            $actor->hasPermission('finance.manage', (int) $selected['id'])
                || $actor->hasPermission('finance.post', (int) $selected['id'])
                || $actor->hasPermission('finance.approve', (int) $selected['id']),
            403,
        );

        $context = $this->resolveContext($request, $actor, $selected);
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');

        // Preserve the canonical 404 for an actual foreign Finance resource
        // before applying an additional store-specific capability gate.
        // Do not disclose whether the referenced journal/batch exists.
        $action = $request->route()?->getActionMethod();
        if (in_array($action, ['requestJournalApproval', 'postJournal'], true)) {
            $this->scopedJournal($context, (int) $request->route('journal'));
        } elseif (in_array($action, ['requestOpeningBalanceApproval', 'postOpeningBalance', 'cancelOpeningBalance'], true)) {
            $this->scopedOpeningBalance($context, (int) $request->route('batch'));
        }

        // An approved legal-entity action can still be revoked at a narrower
        // STORE scope. Domain services independently enforce finance SoD.
        [$permission, $capability] = $this->mutationEntitlement($request);
        abort_unless(
            $this->canPerformFinanceAction($actor, (int) $selected['id'], $permission, $capability),
            403,
        );

        return [$actor, $context];
    }

    /**
     * #2633 controller boundary: a second Finance role must not enlarge an
     * AR_OFFICER's receivables duty into GL, AP, Treasury or approval actions.
     * The shared FinanceWorkbenchAccess service still owns the effective
     * permission and legal/store entitlement; this guard only narrows it.
     * Reconcile with #2696's original canonical role-capability service when
     * those original PRs are integrated.
     */
    private function canPerformFinanceAction(
        User $actor,
        int $storeId,
        string $permission,
        string $capability,
    ): bool {
        if (! $actor->is_active || $actor->archived_at !== null) {
            return false;
        }

        if ($actor->hasRole('AR_OFFICER')) {
            $arRead = $capability === 'finance.ar.read' && $permission === 'finance.view';
            $arManage = $capability === 'finance.ar.manage' && $permission === 'finance.manage';

            if (! $arRead && ! $arManage) {
                return false;
            }

            // Do not allow AR drafts after its read entitlement is revoked.
            if ($arManage && ! $this->financeAccess->allowsAtStore($storeId, 'finance.ar.read')) {
                return false;
            }
        }

        return $this->financeAccess->canPerform($actor, $storeId, $permission, $capability);
    }

    /**
     * Each Finance mutation must satisfy the same capability at the actual
     * store as at its authoritative legal entity. Unknown routes fail closed.
     *
     * @return array{string, string}
     */
    private function mutationEntitlement(Request $request): array
    {
        $action = $request->route()?->getActionMethod();

        return match ($action) {
            'storeAccount', 'updateAccountStatus', 'storeCostCenter',
            'updateCostCenterStatus', 'storeFiscalYear', 'updateFiscalYearState',
            'storeFiscalPeriod', 'updateFiscalPeriodState', 'storeProfitCenter',
            'updateProfitCenterStatus', 'updateCashFlowClassification',
            'storeBudget', 'storeBudgetLine', 'reviseBudget', 'storeTaxCode',
            'storeAllocationRule', 'applyAllocationRule', 'storeAssetCategory',
            'storeAsset', 'createAssetDepreciation', 'prepareAssetDisposal' => ['finance.manage', 'finance.foundation.manage'],
            'storeJournal' => ['finance.manage', 'finance.gl.manage'],
            'requestJournalApproval', 'requestOpeningBalanceApproval' => ['finance.manage', 'finance.approvals.manage'],
            'postJournal' => ['finance.post', 'finance.gl.post'],
            'storeOpeningBalance', 'cancelOpeningBalance' => ['finance.manage', 'finance.opening_balances.manage'],
            'postOpeningBalance' => ['finance.post', 'finance.opening_balances.post'],
            'storeCreditTerm', 'updateCreditTermStatus' => ['finance.manage', 'finance.credit_terms.manage'],
            'proposeCollectionMapping', 'storeReceivableSettlement' => ['finance.manage', 'finance.ar.manage'],
            'reviewCollectionMapping' => ['finance.approve', 'finance.approvals.approve'],
            'postReceivableSettlement' => ['finance.post', 'finance.ar.post'],
            'transitionBudget' => $request->input('action') === 'submit'
                    ? ['finance.manage', 'finance.foundation.manage']
                    : ['finance.approve', 'finance.approvals.approve'],
            'storeConsolidationElimination' => ['finance.approve', 'finance.approvals.approve'],
            'evaluatePeriodClose' => ['finance.view', 'finance.foundation.read'],
            default => abort(403),
        };
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
