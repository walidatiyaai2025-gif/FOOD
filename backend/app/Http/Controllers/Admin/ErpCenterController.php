<?php

namespace App\Http\Controllers\Admin;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Enterprise\Health\ModuleHealthService;
use App\Enterprise\Modules\ModuleOperationalReadiness;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Http\Controllers\Controller;
use App\Models\EnterpriseBankAccount;
use App\Models\EnterpriseCashbox;
use App\Models\EnterpriseModuleEntitlement;
use App\Models\Store;
use App\Models\User;
use App\Services\EnterpriseActionCenter;
use App\Services\EnterpriseModuleAdministration;
use App\Services\EnterpriseMyWork;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class ErpCenterController extends Controller
{
    private const WHOLESALE_CHANNEL = 'B2B';

    private const LOCAL_SUPER_ADMIN_MODULES = [
        'procurement',
        'inventory_wms',
        'finance',
        'treasury',
        'enterprise_bi',
    ];

    private const CARD_STATUSES = [
        'available',
        'degraded',
        'setup_required',
        'disabled',
        'not_entitled',
    ];

    private const WORKLOAD_MODULES = [
        'procurement' => ['card' => 'procurement', 'permission' => 'procurement.purchase_requests.view'],
        'wms' => ['card' => 'wms', 'permission' => 'inventory.view'],
        'finance' => ['card' => 'finance', 'permission' => 'finance.view'],
        'treasury' => ['card' => 'treasury', 'permission' => 'finance.view'],
        'customer_service' => ['card' => 'customer_service', 'permission' => 'whatsapp.conversations.view'],
    ];

    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly ModuleRegistry $modules,
        private readonly EnterpriseModuleAdministration $moduleAdministration,
        private readonly ModuleEntitlementManager $entitlementManager,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly OperationalTenantScope $operationalTenants,
        private readonly ModuleHealthService $health,
        private readonly ModuleOperationalReadiness $readiness,
        private readonly EnterpriseActionCenter $actionCenter,
        private readonly EnterpriseMyWork $myWork,
    ) {}

    public function __invoke(Request $request): View
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        // The ERP Center is a platform workspace, not a Retail-store shortcut.
        // Enforce the same global-role admission used by the canonical Sidebar
        // at the HTTP boundary; a hidden menu alone must never grant access.
        abort_unless($this->navigation->canAccessPlatformErp($actor), 403);

        $this->bootstrapLocalSuperAdminModules($actor);

        // Match the P118 Wholesale journey used by the Sidebar registry:
        // customer work → purchasing → warehouse → finance → treasury →
        // analytics → approval/action controls. Keep all existing guards.
        $cards = array_values(array_filter([
            $this->routeCard(
                $actor,
                'customer_service',
                ['customers.view', 'b2b.accounts.view'],
                'admin.erp.customer-service',
            ),
            $this->moduleCard(
                $actor,
                'procurement',
                'procurement',
                ['procurement.suppliers.view', 'procurement.purchase_requests.view', 'procurement.purchase_orders.view'],
                'admin.erp.procurement.index',
            ),
            $this->moduleCard(
                $actor,
                'wms',
                'inventory_wms',
                ['inventory.view'],
                'admin.erp.wms.index',
            ),
            $this->moduleCard(
                $actor,
                'finance',
                'finance',
                ['finance.view'],
                'admin.erp.finance.index',
            ),
            $this->moduleCard(
                $actor,
                'treasury',
                'treasury',
                ['finance.view'],
                'admin.erp.treasury.index',
            ),
            $this->moduleCard(
                $actor,
                'bi',
                'enterprise_bi',
                ['reports.view'],
                'admin.erp.bi',
            ),
            $this->routeCard(
                $actor,
                'my_work',
                [],
                'admin.action-center.index',
                ['store_channel' => 'b2b'],
            ),
        ]));

        $selectedStatus = $request->string('status')->toString();
        if (! in_array($selectedStatus, self::CARD_STATUSES, true)) {
            $selectedStatus = '';
        }

        $visibleCards = $selectedStatus === ''
            ? $cards
            : array_values(array_filter(
                $cards,
                static fn (array $card): bool => $card['status'] === $selectedStatus,
            ));

        $work = $this->myWork->page(
            $actor,
            '',
            'all',
            'all',
            'all',
            0,
            1,
            route('admin.action-center.index', ['store_channel' => 'b2b']),
            ['store_channel' => self::WHOLESALE_CHANNEL],
        );
        $approvals = $this->actionCenter->page(
            $actor,
            '',
            'all',
            1,
            route('admin.action-center.index', ['store_channel' => 'b2b']),
            self::WHOLESALE_CHANNEL,
        );
        $failedReports = $this->failedReportJobs($actor, $cards);
        $healthSummary = $this->healthSummary($cards);
        $workload = $this->workloadByModule($actor, $cards);

        $summary = collect(self::CARD_STATUSES)
            ->mapWithKeys(fn (string $status): array => [
                $status => count(array_filter(
                    $cards,
                    static fn (array $card): bool => $card['status'] === $status,
                )),
            ])
            ->all();

        $healthUrl = Route::has('admin.enterprise-system-health.index')
            && ($actor->hasRole('SUPER_ADMIN') || $actor->hasPermission('platform.manage'))
                ? route('admin.enterprise-system-health.index')
                : route('admin.erp.index', ['status' => 'degraded']).'#erp-modules';

        $kpis = [
            [
                'key' => 'pending_approvals',
                'value' => $approvals['actions']->total(),
                'available' => true,
                'bounded' => $approvals['truncated'],
                'url' => route('admin.action-center.index', ['store_channel' => 'b2b']),
            ],
            [
                'key' => 'overdue_work',
                'value' => $work['summary']['overdue'],
                'available' => true,
                'bounded' => false,
                'url' => route('admin.action-center.index', ['store_channel' => 'b2b', 'work_state' => 'overdue']),
            ],
            [
                'key' => 'open_exceptions',
                'value' => $work['summary']['attention'],
                'available' => true,
                'bounded' => false,
                'url' => route('admin.action-center.index', ['store_channel' => 'b2b', 'work_state' => 'attention']),
            ],
            [
                'key' => 'failed_reports',
                'value' => $failedReports['count'],
                'available' => $failedReports['available'],
                'bounded' => false,
                'url' => $failedReports['url'],
            ],
            [
                'key' => 'module_health',
                'value' => $healthSummary['total'] > 0 ? $healthSummary['issues'] : null,
                'available' => $healthSummary['total'] > 0,
                'bounded' => false,
                'url' => $healthSummary['total'] > 0 ? $healthUrl : null,
                'healthy' => $healthSummary['healthy'],
                'total' => $healthSummary['total'],
            ],
        ];

        return view('admin.erp-center', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'erp_center',
            'cards' => $cards,
            'visibleCards' => $visibleCards,
            'summary' => $summary,
            'kpis' => $kpis,
            'workload' => $workload,
            'myWorkItems' => collect($work['items']->items())->take(6)->values(),
            'activity' => $work['activity']->take(8)->values(),
            'selectedModule' => $request->string('module')->toString(),
            'selectedStatus' => $selectedStatus,
            'treasuryReadinessSteps' => $this->treasuryReadinessSteps($actor, $visibleCards),
            'moduleReadinessReports' => $this->moduleReadinessReports($actor, $visibleCards),
        ]);
    }

    /** @param list<array<string,mixed>> $cards
     * @return array<string,list<array{key:string,title:string,complete:bool,detail:string,url:?string}>>
     */
    private function moduleReadinessReports(User $actor, array $cards): array
    {
        $healthUrl = Route::has('admin.enterprise-system-health.index')
            && ($actor->hasRole('SUPER_ADMIN') || $actor->hasPermission('platform.manage'))
                ? route('admin.enterprise-system-health.index')
                : null;
        $availableTables = array_fill_keys(Schema::getTableListing(), true);
        $moduleTitle = static fn (string $key): string => __('admin.erp_center.module_readiness.common.'.$key.'.title');
        $specs = [
            'procurement' => [
                'module' => 'procurement', 'view' => 'procurement.purchase_requests.view', 'manage' => 'procurement.purchase_requests.manage',
                'route' => 'admin.erp.procurement.index',
                'tables' => ['enterprise_suppliers', 'enterprise_purchase_requests', 'enterprise_purchase_orders', 'enterprise_goods_receipts', 'enterprise_purchase_invoices'],
                'checks' => [
                    ['key' => 'suppliers', 'table' => 'enterprise_suppliers', 'scope' => 'store', 'column' => 'status', 'value' => 'active', 'tab' => 'suppliers'],
                    ['key' => 'approved_requests', 'table' => 'enterprise_purchase_requests', 'scope' => 'store', 'column' => 'status', 'value' => 'approved', 'tab' => 'requests'],
                    ['key' => 'issued_orders', 'table' => 'enterprise_purchase_orders', 'scope' => 'store', 'column' => 'status', 'value' => 'issued', 'tab' => 'orders'],
                    ['key' => 'posted_receipts', 'table' => 'enterprise_goods_receipts', 'scope' => 'store', 'column' => 'status', 'value' => 'posted', 'tab' => 'receipts'],
                    ['key' => 'handed_off_invoices', 'table' => 'enterprise_purchase_invoices', 'scope' => 'store', 'column' => 'status', 'value' => 'handed_off', 'tab' => 'invoices'],
                ],
            ],
            'wms' => [
                'module' => 'inventory_wms', 'view' => 'inventory.view', 'manage' => 'inventory.manage',
                'route' => 'admin.erp.wms.index',
                'tables' => ['warehouses', 'inventories', 'stock_movements', 'enterprise_inventory_counts', 'enterprise_warehouse_transfers'],
                'checks' => [
                    ['key' => 'warehouses', 'table' => 'warehouses', 'scope' => 'store', 'column' => 'is_active', 'value' => true, 'tab' => 'warehouses'],
                    ['key' => 'inventory_balances', 'table' => 'inventories', 'scope' => 'inventory', 'tab' => 'balances'],
                    ['key' => 'stock_movements', 'table' => 'stock_movements', 'scope' => 'movement', 'tab' => 'movements'],
                    ['key' => 'completed_counts', 'table' => 'enterprise_inventory_counts', 'scope' => 'store', 'column' => 'status', 'value' => 'completed', 'tab' => 'counts'],
                    ['key' => 'received_transfers', 'table' => 'enterprise_warehouse_transfers', 'scope' => 'store', 'column' => 'status', 'value' => 'received', 'tab' => 'transfers'],
                ],
            ],
            'finance' => [
                'module' => 'finance', 'view' => 'finance.view', 'manage' => 'finance.manage',
                'route' => 'admin.erp.finance.index',
                'tables' => ['enterprise_finance_accounts', 'enterprise_fiscal_years', 'enterprise_fiscal_periods', 'enterprise_finance_journals', 'enterprise_finance_ledger_entries'],
                'checks' => [
                    ['key' => 'accounts', 'table' => 'enterprise_finance_accounts', 'scope' => 'legal_entity', 'column' => 'status', 'value' => 'active', 'tab' => 'accounts'],
                    ['key' => 'fiscal_periods', 'table' => 'enterprise_fiscal_periods', 'scope' => 'legal_entity', 'column' => 'status', 'value' => 'open', 'tab' => 'periods'],
                    ['key' => 'posted_journals', 'table' => 'enterprise_finance_journals', 'scope' => 'legal_entity', 'column' => 'status', 'value' => 'posted', 'tab' => 'journals'],
                    ['key' => 'ledger_entries', 'table' => 'enterprise_finance_ledger_entries', 'scope' => 'legal_entity', 'tab' => 'statements'],
                ],
            ],
            'bi' => [
                'module' => 'enterprise_bi', 'view' => 'reports.view', 'manage' => 'reports.export',
                'route' => 'admin.erp.bi',
                'tables' => ['enterprise_report_exports', 'enterprise_scheduled_reports'],
                'checks' => [
                    ['key' => 'completed_exports', 'table' => 'enterprise_report_exports', 'scope' => 'report_store', 'column' => 'status', 'value' => 'completed', 'tab' => null],
                ],
            ],
        ];

        $reports = [];
        foreach ($specs as $key => $spec) {
            $card = collect($cards)->firstWhere('key', $key);
            if (! is_array($card)) {
                continue;
            }

            // A diagnostic does not grant access to privileged admin pages or
            // to a business route when module readiness/entitlement denies it.
            $canManagePlatform = Gate::forUser($actor)->allows('platform.manage');
            $moduleDetailUrl = $canManagePlatform && Route::has('admin.enterprise-modules.show')
                ? route('admin.enterprise-modules.show', ['module' => $spec['module']])
                : null;
            $moduleUrl = is_string($card['url']) ? $card['url'] : null;
            $steps = [];
            $steps[] = [
                'key' => 'lifecycle',
                'title' => $moduleTitle('lifecycle'),
                'complete' => in_array($card['status'] ?? '', ['available', 'degraded'], true),
                'detail' => __('admin.erp_center.module_readiness.common.lifecycle_detail', ['state' => __('admin.erp_center.statuses.'.($card['status'] ?? 'disabled'))]),
                'url' => $moduleDetailUrl,
            ];

            $manifestVersion = config('enterprise_modules.modules.'.$spec['module'].'.version');
            $certifiedVersion = config('enterprise_module_readiness.certified_versions.'.$spec['module']);
            $steps[] = [
                'key' => 'certification',
                'title' => $moduleTitle('certification'),
                'complete' => is_int($manifestVersion) && $certifiedVersion === $manifestVersion,
                'detail' => __('admin.erp_center.module_readiness.common.certification_detail', [
                    'current' => (string) $manifestVersion,
                    'certified' => $certifiedVersion === null ? __('admin.erp_center.module_readiness.common.none') : (string) $certifiedVersion,
                ]),
                'url' => $moduleDetailUrl,
            ];

            $viewStoreIds = $this->operationalTenants->allowedStoreIds($actor, $spec['view'], self::WHOLESALE_CHANNEL);
            $manageStoreIds = $this->operationalTenants->allowedStoreIds($actor, $spec['manage'], self::WHOLESALE_CHANNEL);
            $scopeTablesReady = isset($availableTables['enterprise_store_scopes'])
                && isset($availableTables['enterprise_business_units'])
                && isset($availableTables['enterprise_legal_entities'])
                && isset($availableTables['enterprise_companies']);
            $scopeCount = $scopeTablesReady && $viewStoreIds !== []
                ? (int) DB::table('enterprise_store_scopes')
                    ->join('enterprise_business_units', 'enterprise_business_units.id', '=', 'enterprise_store_scopes.business_unit_id')
                    ->join('enterprise_legal_entities', 'enterprise_legal_entities.id', '=', 'enterprise_business_units.legal_entity_id')
                    ->join('enterprise_companies', 'enterprise_companies.id', '=', 'enterprise_legal_entities.company_id')
                    ->whereIn('enterprise_store_scopes.store_id', $viewStoreIds)
                    ->distinct('enterprise_store_scopes.store_id')
                    ->count('enterprise_store_scopes.store_id')
                : 0;
            $steps[] = [
                'key' => 'scope',
                'title' => $moduleTitle('scope'),
                'complete' => $viewStoreIds !== [] && $scopeCount === count($viewStoreIds),
                'detail' => __('admin.erp_center.module_readiness.common.scope_detail', ['ready' => $scopeCount, 'total' => count($viewStoreIds)]),
                'url' => $canManagePlatform && Route::has('admin.enterprise-organizations.index')
                    ? route('admin.enterprise-organizations.index') : null,
            ];
            $steps[] = [
                'key' => 'permissions',
                'title' => $moduleTitle('permissions'),
                'complete' => $viewStoreIds !== [] && array_diff($viewStoreIds, $manageStoreIds) === [],
                'detail' => __('admin.erp_center.module_readiness.common.permissions_detail', ['ready' => count(array_intersect($viewStoreIds, $manageStoreIds)), 'total' => count($viewStoreIds)]),
                'url' => Gate::forUser($actor)->allows('security.view') && Route::has('admin.security.index')
                    ? route('admin.security.index') : null,
            ];

            $capabilities = config('enterprise_modules.modules.'.$spec['module'].'.capabilities', []);
            $capabilityTotal = count($viewStoreIds) * (is_array($capabilities) ? count($capabilities) : 0);
            $capabilityGranted = 0;
            foreach ($viewStoreIds as $storeId) {
                foreach (is_array($capabilities) ? $capabilities : [] as $capability) {
                    if (is_string($capability) && $this->entitlements->allows(
                        $spec['module'],
                        new EntitlementScope(EntitlementScope::STORE, (int) $storeId),
                        $capability,
                    )) {
                        $capabilityGranted++;
                    }
                }
            }
            $steps[] = [
                'key' => 'capabilities',
                'title' => $moduleTitle('capabilities'),
                'complete' => $capabilityTotal > 0 && $capabilityGranted === $capabilityTotal,
                'detail' => __('admin.erp_center.module_readiness.common.capabilities_detail', ['ready' => $capabilityGranted, 'total' => $capabilityTotal]),
                'url' => $moduleDetailUrl,
            ];

            $tableCount = count(array_filter($spec['tables'], fn (string $table): bool => isset($availableTables[$table])));
            $steps[] = [
                'key' => 'schema',
                'title' => $moduleTitle('schema'),
                'complete' => $tableCount === count($spec['tables']),
                'detail' => __('admin.erp_center.module_readiness.common.schema_detail', ['ready' => $tableCount, 'total' => count($spec['tables'])]),
                'url' => $healthUrl ?? $moduleDetailUrl,
            ];

            $legalEntityIds = $scopeTablesReady && $viewStoreIds !== []
                ? DB::table('enterprise_store_scopes')
                    ->join('enterprise_business_units', 'enterprise_business_units.id', '=', 'enterprise_store_scopes.business_unit_id')
                    ->join('enterprise_legal_entities', 'enterprise_legal_entities.id', '=', 'enterprise_business_units.legal_entity_id')
                    ->whereIn('enterprise_store_scopes.store_id', $viewStoreIds)
                    ->distinct()->pluck('enterprise_legal_entities.id')->map(static fn ($id): int => (int) $id)->all()
                : [];
            $checks = [];
            foreach ($spec['checks'] as $check) {
                $count = 0;
                if (isset($availableTables[$check['table']])) {
                    $query = DB::table($check['table']);
                    if ($check['scope'] === 'store') {
                        $query->whereIn('store_id', $viewStoreIds);
                    } elseif ($check['scope'] === 'legal_entity') {
                        $query->whereIn('legal_entity_id', $legalEntityIds);
                    } elseif ($check['scope'] === 'report_store') {
                        $query->where(function ($scope) use ($viewStoreIds, $legalEntityIds): void {
                            $scope->where(function ($storeScope) use ($viewStoreIds): void {
                                $storeScope->where('scope_type', 'store')->whereIn('scope_id', $viewStoreIds);
                            });
                            if ($legalEntityIds !== []) {
                                $scope->orWhere(function ($entityScope) use ($legalEntityIds): void {
                                    $entityScope->where('scope_type', 'legal_entity')->whereIn('scope_id', $legalEntityIds);
                                });
                            }
                        })->where('requested_by', $actor->getKey());
                    }
                    if (isset($check['column'])) {
                        $query->where($check['column'], $check['value']);
                    }
                    if ($check['scope'] === 'inventory') {
                        $query->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                            ->whereIn('warehouses.store_id', $viewStoreIds);
                    } elseif ($check['scope'] === 'movement') {
                        $query->join('inventories', 'inventories.id', '=', 'stock_movements.inventory_id')
                            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                            ->whereIn('warehouses.store_id', $viewStoreIds);
                    }
                    $count = (int) $query->count();
                }

                // A step may deep-link only through a business route already
                // admitted by the exact module card; unready modules keep
                // actionable diagnostics instead of dead operational CTAs.
                $url = $moduleUrl !== null && $check['tab'] !== null && Route::has($spec['route'])
                    ? route($spec['route'], ['tab' => $check['tab']])
                    : $moduleUrl;
                $checks[] = [
                    'key' => $check['key'],
                    'title' => __('admin.erp_center.module_readiness.'.$key.'.'.$check['key'].'.title'),
                    'complete' => $count > 0,
                    'detail' => __('admin.erp_center.module_readiness.'.$key.'.'.$check['key'].'.detail', ['count' => $count]),
                    'url' => $url,
                ];
            }
            array_push($steps, ...$checks);
            $steps[] = [
                'key' => 'health',
                'title' => $moduleTitle('health'),
                'complete' => ($card['health'] ?? null) === 'healthy',
                'detail' => __('admin.erp_center.module_readiness.common.health_detail', ['state' => __('admin.erp_center.health_statuses.'.($card['health'] ?? 'unknown'))]),
                'url' => $healthUrl ?? $moduleDetailUrl,
            ];
            $reports[$key] = $steps;
        }

        return $reports;
    }

    /** @param list<array<string,mixed>> $cards
     * @return list<array{key:string,complete:bool,detail:string,url:?string}>
     */
    private function treasuryReadinessSteps(User $actor, array $cards): array
    {
        $card = collect($cards)->firstWhere('key', 'treasury');
        if (! is_array($card)) {
            return [];
        }

        $storeIds = $this->operationalTenants->allowedStoreIds($actor, 'finance.view', self::WHOLESALE_CHANNEL);
        $manageStoreIds = $this->operationalTenants->allowedStoreIds($actor, 'finance.manage', self::WHOLESALE_CHANNEL);
        // Treasury Master is a channel-agnostic Retail/Wholesale workspace,
        // while the ERP readiness report is exclusively Wholesale. Linking
        // there can expose Retail cashboxes/bank accounts in a B2B journey.
        // Do not advertise a cross-channel setup link until that destination
        // accepts and enforces a B2B tenant context server-side.
        $masterUrl = null;
        $treasuryUrl = is_string($card['url']) ? $card['url'] : null;
        $canManagePlatform = Gate::forUser($actor)->allows('platform.manage');
        $organizationUrl = $canManagePlatform && Route::has('admin.enterprise-organizations.index')
            ? route('admin.enterprise-organizations.index') : null;
        $securityUrl = Gate::forUser($actor)->allows('security.view') && Route::has('admin.security.index')
            ? route('admin.security.index') : null;
        $moduleDetailUrl = $canManagePlatform && Route::has('admin.enterprise-modules.show')
            ? route('admin.enterprise-modules.show', ['module' => 'treasury'])
            : ($canManagePlatform && Route::has('admin.enterprise-modules.index') ? route('admin.enterprise-modules.index') : null);
        $healthUrl = Route::has('admin.enterprise-system-health.index')
            && ($actor->hasRole('SUPER_ADMIN') || $actor->hasPermission('platform.manage'))
                ? route('admin.enterprise-system-health.index')
                : null;
        $availableTables = array_fill_keys(Schema::getTableListing(), true);
        $steps = [];

        $manifestVersion = config('enterprise_modules.modules.treasury.version');
        $certifiedVersion = config('enterprise_module_readiness.certified_versions.treasury');
        $steps[] = [
            'key' => 'module_certification',
            'complete' => is_int($manifestVersion) && $certifiedVersion === $manifestVersion,
            'detail' => __('admin.erp_center.treasury_readiness.steps.module_certification.detail', [
                'current' => (string) $manifestVersion,
                'certified' => $certifiedVersion === null ? __('admin.erp_center.treasury_readiness.steps.none') : (string) $certifiedVersion,
            ]),
            'url' => $moduleDetailUrl,
        ];
        $steps[] = [
            'key' => 'module_access',
            'complete' => ($card['status'] ?? null) === 'available',
            'detail' => __('admin.erp_center.treasury_readiness.steps.module_access.detail', [
                'state' => __('admin.erp_center.statuses.'.($card['status'] ?? 'disabled')),
            ]),
            'url' => $moduleDetailUrl,
        ];

        $requiredTables = [
            'enterprise_store_scopes', 'enterprise_companies', 'enterprise_legal_entities',
            'enterprise_business_units', 'enterprise_cashboxes', 'enterprise_bank_accounts',
            'enterprise_treasury_receipts', 'enterprise_treasury_payments',
            'enterprise_treasury_reconciliations', 'enterprise_treasury_reconciliation_lines',
            'enterprise_treasury_cash_closings', 'enterprise_treasury_cheques',
            'enterprise_treasury_cheque_transitions', 'enterprise_treasury_integration_events',
            'enterprise_treasury_facilities', 'enterprise_treasury_drawdowns',
            'enterprise_treasury_repayments', 'enterprise_treasury_investments',
        ];
        $existingTables = count(array_filter($requiredTables, fn (string $table): bool => isset($availableTables[$table])));
        $steps[] = [
            'key' => 'schema',
            'complete' => $existingTables === count($requiredTables),
            'detail' => __('admin.erp_center.treasury_readiness.steps.schema.detail', ['existing' => $existingTables, 'total' => count($requiredTables)]),
            'url' => $healthUrl ?? $moduleDetailUrl,
        ];

        $activeStores = $storeIds === [] ? collect() : Store::query()
            ->whereIn('id', $storeIds)
            ->where('is_active', true)
            ->get(['id', 'name']);
        $hierarchyTablesReady = isset($availableTables['enterprise_store_scopes'])
            && isset($availableTables['enterprise_business_units'])
            && isset($availableTables['enterprise_legal_entities'])
            && isset($availableTables['enterprise_companies']);
        $storesWithHierarchy = $hierarchyTablesReady && $storeIds !== []
            ? (int) DB::table('enterprise_store_scopes')
                ->join('enterprise_business_units', 'enterprise_business_units.id', '=', 'enterprise_store_scopes.business_unit_id')
                ->join('enterprise_legal_entities', 'enterprise_legal_entities.id', '=', 'enterprise_business_units.legal_entity_id')
                ->join('enterprise_companies', 'enterprise_companies.id', '=', 'enterprise_legal_entities.company_id')
                ->whereIn('enterprise_store_scopes.store_id', $storeIds)
                ->distinct('enterprise_store_scopes.store_id')
                ->count('enterprise_store_scopes.store_id')
            : 0;
        $steps[] = [
            'key' => 'organization',
            'complete' => $activeStores->isNotEmpty() && $storesWithHierarchy === $activeStores->count(),
            'detail' => __('admin.erp_center.treasury_readiness.steps.organization.detail', ['count' => $activeStores->count(), 'configured' => $storesWithHierarchy]),
            'url' => $organizationUrl,
        ];

        $steps[] = [
            'key' => 'finance_access',
            'complete' => $storeIds !== [] && array_diff($storeIds, $manageStoreIds) === [],
            'detail' => __('admin.erp_center.treasury_readiness.steps.finance_access.detail'),
            'url' => $securityUrl,
        ];

        $capabilities = config('enterprise_modules.modules.treasury.capabilities', []);
        $capabilityChecks = count($storeIds) * (is_array($capabilities) ? count($capabilities) : 0);
        $grantedCapabilityChecks = 0;
        foreach ($storeIds as $storeId) {
            foreach (is_array($capabilities) ? $capabilities : [] as $capability) {
                if (is_string($capability) && $this->entitlements->allows(
                    'treasury',
                    new EntitlementScope(EntitlementScope::STORE, (int) $storeId),
                    $capability,
                )) {
                    $grantedCapabilityChecks++;
                }
            }
        }
        $steps[] = [
            'key' => 'capabilities',
            'complete' => $capabilityChecks > 0 && $grantedCapabilityChecks === $capabilityChecks,
            'detail' => __('admin.erp_center.treasury_readiness.steps.capabilities.detail', [
                'granted' => $grantedCapabilityChecks,
                'total' => $capabilityChecks,
            ]),
            'url' => $moduleDetailUrl,
        ];

        $cashboxTableReady = isset($availableTables['enterprise_cashboxes']);
        $bankAccountTableReady = isset($availableTables['enterprise_bank_accounts']);
        $storesWithCashbox = $storeIds === [] || ! $cashboxTableReady ? 0 : EnterpriseCashbox::query()
            ->whereIn('store_id', $storeIds)->where('is_active', true)->distinct('store_id')->count('store_id');
        $steps[] = [
            'key' => 'cashbox',
            'complete' => $cashboxTableReady && $storeIds !== [] && $storesWithCashbox === count($storeIds),
            'detail' => __('admin.erp_center.treasury_readiness.steps.cashbox.detail', ['configured' => $storesWithCashbox, 'stores' => count($storeIds)]),
            'url' => $masterUrl,
        ];

        $storesWithBankAccount = $storeIds === [] || ! $bankAccountTableReady ? 0 : EnterpriseBankAccount::query()
            ->whereIn('store_id', $storeIds)->where('is_active', true)->distinct('store_id')->count('store_id');
        $steps[] = [
            'key' => 'bank_account',
            'complete' => $bankAccountTableReady && $storeIds !== [] && $storesWithBankAccount === count($storeIds),
            'detail' => __('admin.erp_center.treasury_readiness.steps.bank_account.detail', ['configured' => $storesWithBankAccount, 'stores' => count($storeIds)]),
            'url' => $masterUrl,
        ];

        $receiptTableReady = isset($availableTables['enterprise_treasury_receipts']);
        $paymentTableReady = isset($availableTables['enterprise_treasury_payments']);
        $reconciliationTableReady = isset($availableTables['enterprise_treasury_reconciliations']);
        $cashClosingTableReady = isset($availableTables['enterprise_treasury_cash_closings']);
        $postedReceipts = $storeIds === [] || ! $receiptTableReady ? 0 : DB::table('enterprise_treasury_receipts')
            ->whereIn('store_id', $storeIds)->where('status', 'posted')->count();
        $postedPayments = $storeIds === [] || ! $paymentTableReady ? 0 : DB::table('enterprise_treasury_payments')
            ->whereIn('store_id', $storeIds)->where('status', 'posted')->count();
        $closedReconciliations = $storeIds === [] || ! $reconciliationTableReady ? 0 : DB::table('enterprise_treasury_reconciliations')
            ->whereIn('store_id', $storeIds)->where('status', 'closed')->count();
        $closedCashClosings = $storeIds === [] || ! $cashClosingTableReady ? 0 : DB::table('enterprise_treasury_cash_closings')
            ->whereIn('store_id', $storeIds)->where('status', 'closed')->count();
        $separatedReceipts = $storeIds === [] || ! $receiptTableReady ? 0 : DB::table('enterprise_treasury_receipts')
            ->whereIn('store_id', $storeIds)->where('status', 'posted')->whereColumn('requested_by', '!=', 'posted_by')->count();
        $separatedPayments = $storeIds === [] || ! $paymentTableReady ? 0 : DB::table('enterprise_treasury_payments')
            ->whereIn('store_id', $storeIds)->where('status', 'posted')->whereColumn('requested_by', '!=', 'posted_by')->count();
        $separatedReconciliations = $storeIds === [] || ! $reconciliationTableReady ? 0 : DB::table('enterprise_treasury_reconciliations')
            ->whereIn('store_id', $storeIds)->where('status', 'closed')->whereColumn('requested_by', '!=', 'closed_by')->count();
        $separatedCashClosings = $storeIds === [] || ! $cashClosingTableReady ? 0 : DB::table('enterprise_treasury_cash_closings')
            ->whereIn('store_id', $storeIds)->where('status', 'closed')->whereColumn('requested_by', '!=', 'closed_by')->count();
        $steps[] = [
            'key' => 'maker_checker',
            'complete' => $separatedReceipts > 0 && $separatedPayments > 0
                && $separatedReconciliations > 0 && $separatedCashClosings > 0,
            'detail' => __('admin.erp_center.treasury_readiness.steps.maker_checker.detail', [
                'receipts' => $separatedReceipts,
                'payments' => $separatedPayments,
                'reconciliations' => $separatedReconciliations,
                'closings' => $separatedCashClosings,
            ]),
            'url' => $securityUrl,
        ];
        $steps[] = [
            'key' => 'receipts_payments',
            'complete' => $receiptTableReady && $paymentTableReady && $postedReceipts > 0 && $postedPayments > 0,
            'detail' => __('admin.erp_center.treasury_readiness.steps.receipts_payments.detail', ['receipts' => $postedReceipts, 'payments' => $postedPayments]),
            'url' => $treasuryUrl ? $treasuryUrl.'?tab=receipts' : null,
        ];
        $steps[] = [
            'key' => 'reconciliation_cash_closing',
            'complete' => $reconciliationTableReady && $cashClosingTableReady && $closedReconciliations > 0 && $closedCashClosings > 0,
            'detail' => __('admin.erp_center.treasury_readiness.steps.reconciliation_cash_closing.detail', ['reconciliations' => $closedReconciliations, 'closings' => $closedCashClosings]),
            'url' => $treasuryUrl ? $treasuryUrl.'?tab=reconciliation' : null,
        ];
        $steps[] = [
            'key' => 'health',
            'complete' => ($card['health'] ?? null) === 'healthy',
            'detail' => __('admin.erp_center.treasury_readiness.steps.health.detail', [
                'state' => __('admin.erp_center.health_statuses.'.($card['health'] ?? 'unknown')),
            ]),
            'url' => $healthUrl ?? $moduleDetailUrl,
        ];

        return $steps;
    }

    private function bootstrapLocalSuperAdminModules(User $actor): void
    {
        if (! app()->environment(['local', 'testing']) || ! $actor->hasRole('SUPER_ADMIN')) {
            return;
        }

        foreach (self::LOCAL_SUPER_ADMIN_MODULES as $moduleCode) {
            if ($this->modules->state($moduleCode) !== ModuleState::ENABLED) {
                $this->moduleAdministration->perform($moduleCode, 'enable', $actor);
            }

            $platformScope = EntitlementScope::platform();
            $hasConfiguredEntitlement = EnterpriseModuleEntitlement::query()
                ->where('module_code', $moduleCode)
                ->where('scope_key', $platformScope->key())
                ->exists();

            if ($hasConfiguredEntitlement) {
                continue;
            }

            $this->entitlementManager->set(
                $moduleCode,
                $platformScope,
                true,
                null,
                $actor,
            );
        }
    }

    /**
     * @param  list<string>  $permissions
     * @param  array<string, string>  $params
     * @return array<string, mixed>|null
     */
    private function moduleCard(
        User $actor,
        string $key,
        string $moduleCode,
        array $permissions,
        string $route,
        array $params = [],
    ): ?array {
        // A platform owner may inspect setup diagnostics without a store,
        // never perform business operations on that basis. Other actors must
        // hold a real authorized Wholesale scope, regardless of broad
        // platform entitlements or unrelated Retail grants.
        $hasWholesaleScope = $this->hasWholesalePermissionInScope($actor, $permissions);
        if (! $hasWholesaleScope && ! $actor->hasRole('SUPER_ADMIN')) {
            return null;
        }

        $state = $this->modules->state($moduleCode);
        $entitled = $state === ModuleState::ENABLED && $this->isEntitled($actor, $moduleCode, $permissions);
        $healthStatus = null;
        $denialReason = null;

        if ($state === ModuleState::ENABLED && $entitled) {
            $healthStatus = strtolower($this->health->report($moduleCode)->status->value);
            $readinessStatus = $this->readiness->status(
                $moduleCode,
                $this->modules->manifest($moduleCode)->version,
                $state,
            );
            $status = $readinessStatus === 'ready' ? 'available' : 'degraded';
            $denialReason = match ($readinessStatus) {
                'ready' => null,
                'uncertified' => 'uncertified',
                default => 'unhealthy',
            };
        } elseif ($state === ModuleState::ENABLED) {
            $status = 'not_entitled';
            // Neither a platform entitlement nor one in the actor's authorized
            // store scope is effective. Do not inspect or disclose other tenants.
            $denialReason = 'entitlement_scope';
        } else {
            $status = match ($state) {
                ModuleState::NOT_INSTALLED, ModuleState::INSTALLED, ModuleState::ENABLING => 'setup_required',
                ModuleState::DISABLED => 'disabled',
                default => 'degraded',
            };
            $denialReason = match ($state) {
                ModuleState::NOT_INSTALLED, ModuleState::INSTALLED, ModuleState::ENABLING => 'module_setup',
                ModuleState::DISABLED => 'module_disabled',
                default => 'module_unavailable',
            };
        }

        $actionable = $status === 'available' && Route::has($route);
        if ($status === 'available' && ! $actionable) {
            // Missing registered HTTP destinations are never operational links.
            $status = 'degraded';
            $denialReason = 'route_unavailable';
        }

        return $this->card(
            $key,
            $status,
            $actionable ? route($route, $params) : null,
            $healthStatus,
            $denialReason,
        );
    }

    /**
     * @param  list<string>  $permissions
     * @param  array<string, string>  $params
     * @return array<string, mixed>|null
     */
    private function routeCard(
        User $actor,
        string $key,
        array $permissions,
        string $route,
        array $params = [],
    ): ?array {
        if ($permissions !== [] && ! $this->hasWholesalePermissionInScope($actor, $permissions)) {
            return null;
        }

        if (! Route::has($route)) {
            return null;
        }

        return $this->card($key, 'available', route($route, $params));
    }

    /** @return array<string, mixed> */
    private function card(
        string $key,
        string $status,
        ?string $url,
        ?string $health = null,
        ?string $denialReason = null,
    ): array {
        return [
            'key' => $key,
            'title' => __('admin.erp_center.modules.'.$key.'.title'),
            'description' => __('admin.erp_center.modules.'.$key.'.description'),
            'status' => $status,
            'url' => $url,
            'health' => $health,
            'denial_reason' => $denialReason,
        ];
    }

    private function isEntitled(User $actor, string $moduleCode, array $permissions): bool
    {
        // A PLATFORM allow is only inherited by an effective STORE scope.
        // Resolve each permitted B2B store: an explicit STORE deny must
        // override PLATFORM allow before any operational link is advertised.
        // No Wholesale store means diagnostics only, including Super Admin.
        foreach ($permissions as $permission) {
            foreach ($this->operationalTenants->allowedStoreIds($actor, $permission, self::WHOLESALE_CHANNEL) as $storeId) {
                if ($this->entitlements->allows(
                    $moduleCode,
                    new EntitlementScope(EntitlementScope::STORE, $storeId),
                )) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<array<string,mixed>> $cards
     * @return array{healthy:int,issues:int,total:int}
     */
    private function healthSummary(array $cards): array
    {
        $health = collect($cards)
            ->pluck('health')
            ->filter(static fn (mixed $status): bool => is_string($status) && $status !== '');

        $total = $health->count();
        $healthy = $health->filter(static fn (string $status): bool => $status === 'healthy')->count();

        return [
            'healthy' => $healthy,
            'issues' => $total - $healthy,
            'total' => $total,
        ];
    }

    /** @param list<array<string,mixed>> $cards
     * @return list<array{key:string,label:string,total:int,exceptions:int,url:string}>
     */
    private function workloadByModule(User $actor, array $cards): array
    {
        $cardsByKey = collect($cards)->keyBy('key');
        $rows = [];

        foreach (self::WORKLOAD_MODULES as $module => $definition) {
            $card = $cardsByKey->get($definition['card']);
            if (! is_array($card)
                || ! in_array($card['status'], ['available', 'degraded'], true)
                || (! $actor->hasRole('SUPER_ADMIN')
                    && ! $this->hasWholesalePermissionInScope($actor, [$definition['permission']]))) {
                continue;
            }

            $result = $this->myWork->page(
                $actor,
                '',
                $module,
                'all',
                'all',
                0,
                1,
                route('admin.action-center.index', ['store_channel' => 'b2b']),
                ['store_channel' => self::WHOLESALE_CHANNEL],
            );

            $rows[] = [
                'key' => $module,
                'label' => __('enterprise_my_work.modules.'.$module),
                'total' => $result['summary']['total'],
                'exceptions' => $result['summary']['overdue'] + $result['summary']['attention'],
                'url' => route('admin.action-center.index', ['store_channel' => 'b2b', 'module' => $module]),
            ];
        }

        return $rows;
    }

    /** @param list<array<string,mixed>> $cards
     * @return array{available:bool,count:?int,url:?string}
     */
    private function failedReportJobs(User $actor, array $cards): array
    {
        $biCard = collect($cards)->firstWhere('key', 'bi');
        $storeIds = $this->operationalTenants->allowedStoreIds($actor, 'reports.view', self::WHOLESALE_CHANNEL);
        $legalEntityIds = $storeIds !== []
            && Schema::hasTable('enterprise_store_scopes')
            && Schema::hasTable('enterprise_business_units')
            && Schema::hasTable('enterprise_legal_entities')
                ? DB::table('enterprise_store_scopes')
                    ->join('enterprise_business_units', 'enterprise_business_units.id', '=', 'enterprise_store_scopes.business_unit_id')
                    ->join('enterprise_legal_entities', 'enterprise_legal_entities.id', '=', 'enterprise_business_units.legal_entity_id')
                    ->whereIn('enterprise_store_scopes.store_id', $storeIds)
                    ->distinct()->pluck('enterprise_legal_entities.id')->map(static fn ($id): int => (int) $id)->all()
                : [];
        // ERP reporting is exclusively Wholesale. An unscoped PLATFORM export
        // has no authoritative channel/store owner and may contain Retail
        // data, so never count it in a B2B-only KPI (even for Super Admin).
        $biOperational = $storeIds !== []
            && is_array($biCard)
            && in_array($biCard['status'], ['available', 'degraded'], true)
            && is_string($biCard['url'])
            && $biCard['url'] !== '';

        if (! $biOperational) {
            return ['available' => false, 'count' => null, 'url' => null];
        }

        $hasExports = Schema::hasTable('enterprise_report_exports');
        $hasSchedules = Schema::hasTable('enterprise_scheduled_reports');
        if (! $hasExports && ! $hasSchedules) {
            return ['available' => false, 'count' => null, 'url' => null];
        }

        $failedExports = $hasExports
            ? (int) DB::table('enterprise_report_exports')
                ->where(function ($scope) use ($storeIds, $legalEntityIds): void {
                    $scope->where(fn ($storeScope) => $storeScope->where('scope_type', 'store')->whereIn('scope_id', $storeIds));
                    if ($legalEntityIds !== []) {
                        $scope->orWhere(fn ($entityScope) => $entityScope->where('scope_type', 'legal_entity')->whereIn('scope_id', $legalEntityIds));
                    }
                })
                ->when(! $actor->hasRole('SUPER_ADMIN'), fn ($query) => $query->where('requested_by', $actor->id))
                ->where('status', 'failed')
                ->count()
            : 0;

        $failedSchedules = $hasSchedules
            ? (int) DB::table('enterprise_scheduled_reports')
                ->where(function ($scope) use ($storeIds, $legalEntityIds): void {
                    $scope->where(fn ($storeScope) => $storeScope->where('scope_type', 'store')->whereIn('scope_id', $storeIds));
                    if ($legalEntityIds !== []) {
                        $scope->orWhere(fn ($entityScope) => $entityScope->where('scope_type', 'legal_entity')->whereIn('scope_id', $legalEntityIds));
                    }
                })
                ->when(! $actor->hasRole('SUPER_ADMIN'), fn ($query) => $query->where('requested_by', $actor->id))
                ->whereNotNull('last_error')
                ->count()
            : 0;

        return [
            'available' => true,
            'count' => $failedExports + $failedSchedules,
            'url' => $biCard['url'],
        ];
    }

    /** @param list<string> $permissions */
    private function hasWholesalePermissionInScope(User $actor, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->operationalTenants->allowedStoreIds($actor, $permission, self::WHOLESALE_CHANNEL) !== []) {
                return true;
            }
        }

        return false;
    }
}
