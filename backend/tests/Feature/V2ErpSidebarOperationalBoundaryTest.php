<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Http\Middleware\EnsureErpOperationalReadiness;
use App\Models\Role;
use App\Models\User;
use App\Services\EnterpriseModuleAdministration;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class V2ErpSidebarOperationalBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('enterprise_module_readiness.certified_versions', []);
    }

    public function test_enabled_entitled_sidebar_module_is_not_a_direct_operational_grant(): void
    {
        $actor = $this->superAdmin('v2-erp-uncertified@example.test');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );

        $center = $this->actingAs($actor)->get(route('admin.erp.index'));
        $center->assertOk();

        $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
        $this->assertIsArray($card);
        $this->assertSame('degraded', $card['status']);
        $this->assertNull($card['url']);

        $this->get(route('admin.erp.procurement.index'))->assertForbidden();
        $this->post(route('admin.erp.procurement.requests.store'), [])->assertForbidden();

        $version = app(ModuleRegistry::class)->manifest('procurement')->version;
        config()->set('enterprise_module_readiness.certified_versions.procurement', $version + 1);
        $this->get(route('admin.erp.procurement.index'))->assertForbidden();
    }

    public function test_uncertified_module_routes_fail_closed_for_all_erp_domains(): void
    {
        $this->actingAs($this->superAdmin('v2-erp-cross-module@example.test'));

        foreach ([
            'admin.erp.wms.index',
            'admin.erp.finance.index',
            'admin.erp.treasury.index',
            'admin.erp.bi',
        ] as $routeName) {
            $this->get(route($routeName))->assertForbidden();
        }
    }

    public function test_every_erp_business_route_is_covered_by_the_server_guard(): void
    {
        foreach ([
            'admin.erp.procurement.index',
            'admin.erp.procurement.requests.store',
            'admin.erp.wms.index',
            'admin.erp.wms.transfers.store',
            'admin.erp.finance.index',
            'admin.erp.finance.journals.post',
            'admin.erp.treasury.index',
            'admin.erp.treasury.payments.post',
            'admin.erp.bi',
            'admin.erp.bi.schedules.store',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, $routeName);
            $this->assertContains(
                EnsureErpOperationalReadiness::class,
                $route->gatherMiddleware(),
                $routeName,
            );
        }

        $this->assertNotNull(Route::getRoutes()->getByName('admin.erp.index'));
    }

    private function superAdmin(string $email): User
    {
        $user = User::query()->create([
            'name' => 'V2 ERP Boundary',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
