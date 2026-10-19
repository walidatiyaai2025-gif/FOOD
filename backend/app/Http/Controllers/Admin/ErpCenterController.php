<?php

namespace App\Http\Controllers\Admin;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Enterprise\Health\ModuleHealthService;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Http\Controllers\Controller;
use App\Models\EnterpriseModuleEntitlement;
use App\Models\User;
use App\Services\EnterpriseActionCenter;
use App\Services\EnterpriseModuleAdministration;
use App\Services\EnterpriseMyWork;
use App\Support\AdminNavigation;
use App\Support\TenantContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

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
        private readonly TenantContextResolver $tenants,
        private readonly ModuleHealthService $health,
        private readonly EnterpriseActionCenter $actionCenter,
        private readonly EnterpriseMyWork $myWork,
    ) {}

    public function __invoke(Request $request): View
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $this->bootstrapLocalSuperAdminModules($actor);

        $cards = array_values(array_filter([
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
            $this->routeCard(
                $actor,
                'customer_service',
                ['customers.view', 'b2b.accounts.view'],
                'admin.erp.customer-service',
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
            'myWorkItems' => collect($work['items']->items())->take(6)->values(),
            'activity' => $work['activity']->take(8)->values(),
            'selectedModule' => $request->string('module')->toString(),
            'selectedStatus' => $selectedStatus,
        ]);
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
        if (! $this->navigation->hasAnyPermissionInScope($actor, $permissions)) {
            return null;
        }

        $state = $this->modules->state($moduleCode);
        $entitled = $state === ModuleState::ENABLED && $this->isEntitled($actor, $moduleCode);
        $healthStatus = null;

        if ($state === ModuleState::ENABLED && $entitled) {
            $healthStatus = strtolower($this->health->report($moduleCode)->status->value);
            $status = $healthStatus === 'healthy' ? 'available' : 'degraded';
        } elseif ($state === ModuleState::ENABLED) {
            $status = 'not_entitled';
        } else {
            $status = match ($state) {
                ModuleState::NOT_INSTALLED, ModuleState::INSTALLED, ModuleState::ENABLING => 'setup_required',
                ModuleState::DISABLED => 'disabled',
                default => 'degraded',
            };
        }

        $actionable = $state === ModuleState::ENABLED
            && $entitled
            && Route::has($route);

        return $this->card(
            $key,
            $status,
            $actionable ? route($route, $params) : null,
            $healthStatus,
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
        if ($permissions !== [] && ! $this->navigation->hasAnyPermissionInScope($actor, $permissions)) {
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
    ): array {
        return [
            'key' => $key,
            'title' => __('admin.erp_center.modules.'.$key.'.title'),
            'description' => __('admin.erp_center.modules.'.$key.'.description'),
            'status' => $status,
            'url' => $url,
            'health' => $health,
        ];
    }

    private function isEntitled(User $actor, string $moduleCode): bool
    {
        if ($this->entitlements->allows($moduleCode, EntitlementScope::platform())) {
            return true;
        }

        foreach ($this->tenants->retailStoreIds($actor) as $storeId) {
            if ($this->entitlements->allows(
                $moduleCode,
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
                || ! $this->navigation->hasAnyPermissionInScope($actor, [$definition['permission']])) {
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
