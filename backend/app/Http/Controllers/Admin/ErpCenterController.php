<?php

namespace App\Http\Controllers\Admin;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminNavigation;
use App\Support\TenantContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

final class ErpCenterController extends Controller
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly ModuleRegistry $modules,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly TenantContextResolver $tenants,
    ) {}

    public function __invoke(Request $request): View
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $cards = array_values(array_filter([
            $this->moduleCard(
                $actor,
                'procurement',
                'procurement',
                ['procurement.suppliers.view', 'procurement.purchase_requests.view', 'procurement.purchase_orders.view'],
                'admin.action-center.index',
                ['module' => 'procurement'],
            ),
            $this->moduleCard(
                $actor,
                'wms',
                'inventory_wms',
                ['inventory.view'],
                'admin.enterprise-workspaces.index',
                ['workspace' => 'warehouse'],
            ),
            $this->moduleCard(
                $actor,
                'finance',
                'finance',
                ['finance.view'],
                'admin.enterprise-workspaces.index',
                ['workspace' => 'finance'],
            ),
            $this->moduleCard(
                $actor,
                'treasury',
                'treasury',
                ['finance.view'],
                'admin.treasury.master.index',
            ),
            $this->routeCard(
                $actor,
                'customer_service',
                ['customers.view', 'b2b.accounts.view'],
                'admin.customer-360.index',
            ),
            $this->moduleCard(
                $actor,
                'bi',
                'enterprise_bi',
                ['reports.view'],
                'admin.reports.index',
            ),
            $this->routeCard(
                $actor,
                'my_work',
                [],
                'admin.action-center.index',
            ),
        ]));

        $summary = [
            'available' => count(array_filter($cards, static fn (array $card): bool => $card['status'] === 'available')),
            'disabled' => count(array_filter($cards, static fn (array $card): bool => $card['status'] === 'disabled')),
            'restricted' => count(array_filter($cards, static fn (array $card): bool => $card['status'] === 'not_entitled')),
        ];

        return view('admin.erp-center', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'erp_center',
            'cards' => $cards,
            'summary' => $summary,
            'selectedModule' => $request->string('module')->toString(),
        ]);
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

        $enabled = $this->modules->state($moduleCode) === ModuleState::ENABLED;
        $entitled = $enabled && $this->isEntitled($actor, $moduleCode);
        $status = ! $enabled ? 'disabled' : ($entitled ? 'available' : 'not_entitled');

        return $this->card(
            $key,
            $status,
            $status === 'available' && Route::has($route) ? route($route, $params) : null,
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
    private function card(string $key, string $status, ?string $url): array
    {
        return [
            'key' => $key,
            'title' => __('admin.erp_center.modules.'.$key.'.title'),
            'description' => __('admin.erp_center.modules.'.$key.'.description'),
            'status' => $status,
            'url' => $url,
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
}
