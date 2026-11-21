<?php

namespace App\Http\Controllers\Admin;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Enterprise\Finance\FinanceWorkbenchAccess;
use App\Enterprise\Health\ModuleHealthService;
use App\Enterprise\Modules\ModuleOperationalReadiness;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Http\Controllers\Controller;
use App\Models\EnterpriseModuleEntitlement;
use App\Models\User;
use App\Services\EnterpriseActionCenter;
use App\Services\EnterpriseModuleAdministration;
use App\Services\EnterpriseMyWork;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class ErpCenterController extends Controller
{
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
            route('admin.action-center.index'),
        );
        $approvals = $this->actionCenter->page(
            $actor,
            '',
            'all',
            1,
            route('admin.action-center.index'),
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
                'url' => route('admin.action-center.index'),
            ],
            [
                'key' => 'overdue_work',
                'value' => $work['summary']['overdue'],
                'available' => true,
                'bounded' => false,
                'url' => route('admin.action-center.index', ['work_state' => 'overdue']),
            ],
            [
                'key' => 'open_exceptions',
                'value' => $work['summary']['attention'],
                'available' => true,
                'bounded' => false,
                'url' => route('admin.action-center.index', ['work_state' => 'attention']),
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
            'myWorkItems' => $this->safePreviewReferences(collect($work['items']->items())->take(6)->values()),
            'activity' => $this->safePreviewReferences($work['activity']->take(8)->values()),
            'selectedModule' => $request->string('module')->toString(),
            'selectedStatus' => $selectedStatus,
        ]);
    }

    /**
     * ERP Center displays compact user-facing references, not internal UUIDs
     * or serialized payloads. Keep the canonical work item and deep link
     * unchanged; this projection only sanitizes the two summary previews.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function safePreviewReferences(Collection $items): Collection
    {
        // Operate only on the bounded, detached preview collection. A plain
        // loop preserves its invariant generic type; map()/transform() can
        // infer a non-empty-array subtype that PHPStan cannot covary.
        foreach ($items as $index => $item) {
            $value = is_string($item['reference'] ?? null)
                ? trim($item['reference'])
                : '';
            $serialized = $value !== ''
                && in_array($value[0], ['{', '['], true)
                && json_decode($value, true) !== null;

            if ($value === '' || Str::isUuid($value) || $serialized) {
                $item['reference'] = '—';
                $items->put($index, $item);
            }
        }

        return $items;
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
        if (! $this->navigation->hasAnyPermissionInChannel($actor, $permissions, 'b2b')) {
            return null;
        }

        $state = $this->modules->state($moduleCode);
        // Finance requires a real legal-entity and store-effective HTTP read
        // context, not a global-role or platform-entitlement-only projection.
        $financeStoreId = $state === ModuleState::ENABLED && $moduleCode === 'finance'
            ? $this->navigation->firstAuthorizedFinanceStoreId($actor)
            : null;
        $needsOperationalStore = in_array($moduleCode, ['procurement', 'inventory_wms', 'treasury'], true);
        $operationalStoreId = $state === ModuleState::ENABLED && $needsOperationalStore
            ? $this->firstAuthorizedOperationalStoreId($actor, $moduleCode)
            : null;
        $entitled = $state === ModuleState::ENABLED && ($moduleCode === 'finance'
            ? $financeStoreId !== null
            : ($this->isEntitled($actor, $moduleCode) || $operationalStoreId !== null));
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

            if ($status === 'available' && $needsOperationalStore && $operationalStoreId === null) {
                // An enabled module without a readable, authorized store is
                // never an HTTP Workbench destination. For Treasury, a STORE
                // grant restricted to manage-only capabilities is a missing
                // read entitlement, not a missing store/hierarchy. Distinguish
                // that from a valid read grant lacking an enterprise scope.
                $status = 'not_entitled';
                $denialReason = 'missing_scope';
                if ($moduleCode === 'treasury'
                    && $this->firstAuthorizedOperationalStoreId($actor, $moduleCode, false) === null
                    && app(OperationalTenantScope::class)->allowedStoreIds($actor, 'finance.view', 'b2b') !== []) {
                    $denialReason = 'entitlement_scope';
                }
            }
        } elseif ($state === ModuleState::ENABLED) {
            $status = 'not_entitled';
            // Distinguish absent grants from granted modules with unusable
            // Finance legal/store scope; neither grants an HTTP action.
            $denialReason = ($moduleCode === 'finance' && $this->hasUnusableFinanceScope($actor))
                || ($needsOperationalStore
                    && $this->firstAuthorizedOperationalStoreId($actor, $moduleCode, false) !== null)
                    ? 'missing_scope'
                    : 'entitlement_scope';
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

        // A READY Finance card must link to the *same* store that its real
        // Workbench controller and FinanceWorkbenchAccess accept.
        if ($actionable && $moduleCode === 'finance' && $financeStoreId !== null) {
            $params['store_id'] = (string) $financeStoreId;
            // ERP Center must link to an actual authorized Finance tab. An
            // AR-only reader cannot render the foundation-only Overview.
            $tab = $this->firstFinanceReadTabForStore($actor, $financeStoreId);
            if ($tab === null) {
                $actionable = false;
                $status = 'not_entitled';
                $denialReason = 'entitlement_scope';
            } elseif ($tab !== 'overview') {
                $params['tab'] = $tab;
            }
        }
        if ($actionable && $needsOperationalStore && $operationalStoreId !== null) {
            $params['store_id'] = (string) $operationalStoreId;
            if ($moduleCode === 'treasury') {
                // The Sidebar and Treasury HTTP support payment-only (and
                // other read-only) capabilities. Link to the same authorized
                // tab instead of incorrectly marking the entire module denied.
                // Receipts remains the default URL for a receipts reader.
                $readTab = $this->firstTreasuryReadTabForStore($operationalStoreId);
                if ($readTab !== null && $readTab !== 'receipts') {
                    $params['tab'] = $readTab;
                }
            }
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
        if ($permissions !== [] && ! $this->navigation->hasAnyPermissionInChannel($actor, $permissions, 'b2b')) {
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

    private function isEntitled(User $actor, string $moduleCode): bool
    {
        if ($this->entitlements->allows($moduleCode, EntitlementScope::platform())) {
            return true;
        }

        // ERP workspaces are Wholesale-only: only a server-authorized B2B
        // store may satisfy a scoped module entitlement in the Center.
        $permission = match ($moduleCode) {
            'procurement' => 'procurement.suppliers.view',
            'inventory_wms' => 'inventory.view',
            'treasury' => 'finance.view',
            'enterprise_bi' => 'reports.view',
            default => null,
        };
        if ($permission !== null) {
            foreach (app(OperationalTenantScope::class)->allowedStoreIds($actor, $permission, 'b2b') as $storeId) {
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

    /**
     * Return an existing operational store allowed by the same permission
     * candidate set as its HTTP workbench. No store/scope is created. Scope
     * and entitlement checks fail closed without exposing probe internals.
     */
    private function firstAuthorizedOperationalStoreId(
        User $actor,
        string $moduleCode,
        bool $requireHierarchy = true,
    ): ?int {
        $permissions = match ($moduleCode) {
            'procurement' => [
                'procurement.suppliers.view',
                'procurement.purchase_requests.view',
                'procurement.purchase_orders.view',
            ],
            'inventory_wms' => ['inventory.view'],
            'treasury' => ['finance.view'],
            default => [],
        };

        // User::hasPermission() grants SUPER_ADMIN before processing per-user
        // overrides. A visible ERP destination must nevertheless honor an
        // explicit GLOBAL/STORE DENY for the concrete operational permission,
        // just like the issue-owned Procurement HTTP authority contract.
        $overrideEffectsByStore = [];
        $checkSuperAdminDenials = $actor->has_permission_overrides && $actor->hasRole('SUPER_ADMIN');

        foreach ($permissions as $permission) {
            foreach (app(OperationalTenantScope::class)->allowedStoreIds($actor, $permission, 'b2b') as $storeId) {
                if ($checkSuperAdminDenials) {
                    $overrideEffectsByStore[$storeId] ??= $actor->permissionOverrideEffects($storeId);
                    if (($overrideEffectsByStore[$storeId][$permission] ?? null) === 'deny') {
                        continue;
                    }
                }

                // Treasury chooses the same first read-capable tab as the
                // existing Sidebar. A broad module grant alone is not a
                // usable tab; the read capability must match the exact store.
                $canRead = $moduleCode === 'treasury'
                    ? $this->firstTreasuryReadTabForStore($storeId) !== null
                    : $this->entitlements->allows(
                        $moduleCode,
                        new EntitlementScope(EntitlementScope::STORE, $storeId),
                    );
                if (! $actor->hasPermission($permission, $storeId) || ! $canRead) {
                    continue;
                }

                if (! $requireHierarchy) {
                    return $storeId;
                }

                try {
                    if (app(OrganizationHierarchyService::class)->scopeForStore($storeId) !== null) {
                        return $storeId;
                    }
                } catch (Throwable) {
                    // Missing or invalid hierarchy never creates a live link.
                }
            }
        }

        return null;
    }

    /**
     * Select the first existing Finance workbench tab with effective
     * legal-entity plus store permission. The destination must be the same
     * permission/capability verdict enforced by FinanceWorkbenchController.
     */
    private function firstFinanceReadTabForStore(User $actor, int $storeId): ?string
    {
        if (! $actor->is_active
            || $actor->archived_at !== null
            || ! $actor->hasPermission('finance.view', $storeId)) {
            return null;
        }

        $access = app(FinanceWorkbenchAccess::class);
        if (! $access->canRead($actor, $storeId)) {
            return null;
        }

        // A Finance entitlement belongs to the legal store, not to every
        // role sharing finance.view. An AR_OFFICER with an additional Finance
        // role must never receive an ERP link to GL/Foundation/AP data.
        // This is a restrictive projection, not a new channel or role grant.
        foreach ([
            'overview' => 'finance.foundation.read',
            'journals' => 'finance.gl.read',
            'receivables' => 'finance.ar.read',
            'payables' => 'finance.ap.read',
        ] as $tab => $capability) {
            if ($actor->hasRole('AR_OFFICER') && $capability !== 'finance.ar.read') {
                continue;
            }

            if ($access->canPerform($actor, $storeId, 'finance.view', $capability)) {
                return $tab;
            }
        }

        return null;
    }

    /**
     * Mirror the already-authorized Treasury Sidebar tab order, without
     * changing the HTTP/service entitlement or the selected store.
     * No read capability means no Treasury operational destination.
     */
    private function firstTreasuryReadTabForStore(int $storeId): ?string
    {
        $scope = new EntitlementScope(EntitlementScope::STORE, $storeId);
        foreach ([
            'receipts' => 'treasury.receipts.read',
            'payments' => 'treasury.payments.read',
            'reconciliation' => 'treasury.reconciliation.read',
            'cash_closing' => 'treasury.cash_closing.read',
            'cheques' => 'treasury.cheques.read',
        ] as $tab => $capability) {
            if ($this->entitlements->allows('treasury', $scope, $capability)) {
                return $tab;
            }
        }

        return null;
    }

    /**
     * Read-only diagnostic distinction: an effective grant without an
     * authorized Finance legal/store context cannot create a Workbench URL.
     */
    private function hasUnusableFinanceScope(User $actor): bool
    {
        if ($this->isEntitled($actor, 'finance')) {
            return true;
        }

        foreach (app(OperationalTenantScope::class)->allowedStoreIds($actor, 'finance.view', 'b2b') as $storeId) {
            if ($actor->hasPermission('finance.view', $storeId)
                && $this->entitlements->allows(
                    'finance',
                    new EntitlementScope(EntitlementScope::STORE, $storeId),
                )) {
                return true;
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
                || ! $this->navigation->hasAnyPermissionInChannel($actor, [$definition['permission']], 'b2b')) {
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
                route('admin.action-center.index'),
            );

            $rows[] = [
                'key' => $module,
                'label' => __('enterprise_my_work.modules.'.$module),
                'total' => $result['summary']['total'],
                'exceptions' => $result['summary']['overdue'] + $result['summary']['attention'],
                'url' => route('admin.action-center.index', ['module' => $module]),
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
        $biOperational = is_array($biCard)
            && in_array($biCard['status'], ['available', 'degraded'], true)
            && is_string($biCard['url'])
            && $biCard['url'] !== '';

        if (! $biOperational
            || ! ($actor->hasRole('SUPER_ADMIN') || $actor->hasPermission('reports.view'))) {
            return ['available' => false, 'count' => null, 'url' => null];
        }

        $hasExports = Schema::hasTable('enterprise_report_exports');
        $hasSchedules = Schema::hasTable('enterprise_scheduled_reports');
        if (! $hasExports && ! $hasSchedules) {
            return ['available' => false, 'count' => null, 'url' => null];
        }

        $failedExports = $hasExports
            ? (int) DB::table('enterprise_report_exports')
                ->when(! $actor->hasRole('SUPER_ADMIN'), fn ($query) => $query->where('requested_by', $actor->id))
                ->where('status', 'failed')
                ->count()
            : 0;

        $failedSchedules = $hasSchedules
            ? (int) DB::table('enterprise_scheduled_reports')
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
}
