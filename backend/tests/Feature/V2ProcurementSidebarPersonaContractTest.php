<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #2712 / T353R3: Procurement is an authorized persona, not a Sidebar grant.
 */
final class V2ProcurementSidebarPersonaContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        config()->set('enterprise_module_readiness.certified_versions', []);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_procurement_role_exposes_only_existing_scoped_informational_navigation_while_uncertified(): void
    {
        $manager = $this->userWithRoles(['PROCUREMENT_MANAGER']);

        $this->assertTrue($manager->hasPermission('procurement.suppliers.view'));
        $this->assertTrue($manager->hasPermission('reports.view'));
        $this->assertFalse($manager->hasPermission('inventory.view'));
        $this->assertFalse($manager->hasPermission('finance.view'));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $manager->forceFill(['locale' => $locale])->save();

            $items = $this->navigationItems($manager->fresh());

            $this->assertArrayHasKey('enterprise_action_center', $items);
            $this->assertArrayHasKey('erp_center', $items);
            $this->assertArrayHasKey('reports_center', $items);
            $this->assertArrayNotHasKey('erp_procurement', $items);
            $this->assertArrayNotHasKey('erp_wms', $items);
            $this->assertArrayNotHasKey('erp_finance', $items);
            $this->assertArrayNotHasKey('b2c_inventory', $items);
            $this->assertArrayNotHasKey('b2c_products', $items);

            $this->actingAs($manager->fresh())
                ->get(route('admin.erp.index'))
                ->assertOk()
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false);

            $this->actingAs($manager->fresh())
                ->get(route('admin.erp.procurement.index'))
                ->assertForbidden();
        }
    }

    public function test_procurement_link_requires_exact_certified_healthy_module_entitlement_and_real_permission(): void
    {
        $actor = $this->userWithRoles(['B2B_ADMIN', 'PROCUREMENT_MANAGER']);
        $denied = $this->userWithRoles(['FINANCE']);
        $store = $this->wholesaleStore();

        app(ModuleRegistry::class)->syncManifest('procurement');
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLED);

        $this->assertArrayNotHasKey('erp_procurement', $this->navigationItems($actor->fresh()));

        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );

        $this->assertArrayNotHasKey('erp_procurement', $this->navigationItems($actor->fresh()));
        $version = app(ModuleRegistry::class)->manifest('procurement')->version;
        config()->set('enterprise_module_readiness.certified_versions.procurement', $version + 1);
        $this->assertArrayNotHasKey('erp_procurement', $this->navigationItems($actor->fresh()));

        config()->set('enterprise_module_readiness.certified_versions.procurement', $version);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $denied->forceFill(['locale' => $locale])->save();

            $item = $this->navigationItems($actor->fresh())['erp_procurement'] ?? null;
            $this->assertNotNull($item);
            $this->assertSame('admin.erp.procurement.index', $item['route']);
            $this->assertSame([], $item['params']);
            $this->assertArrayNotHasKey('erp_procurement', $this->navigationItems($denied->fresh()));

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.procurement.index', ['store_id' => $store, 'tab' => 'suppliers']))
                ->assertOk()
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false);

            $this->actingAs($denied->fresh())
                ->get(route('admin.erp.procurement.index', ['store_id' => $store]))
                ->assertForbidden();
        }
    }

    public function test_retail_only_role_cannot_inherit_procurement_or_wholesale_navigation(): void
    {
        $retail = $this->userWithRoles(['B2C_STORE_ADMIN']);
        $manager = $this->userWithRoles(['PROCUREMENT_MANAGER']);

        foreach ([$retail, $manager] as $actor) {
            $items = $this->navigationItems($actor->fresh());
            $this->assertArrayNotHasKey('erp_procurement', $items);
        }

        $this->assertArrayNotHasKey('b2b_products', $this->navigationItems($retail->fresh()));
        $this->assertArrayNotHasKey('b2c_products', $this->navigationItems($manager->fresh()));
        $this->actingAs($retail->fresh())
            ->get(route('admin.erp.procurement.index'))
            ->assertForbidden();
    }

    /** @return array<string, array<string, mixed>> */
    private function navigationItems(User $user): array
    {
        return collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->keyBy('key')
            ->all();
    }

    /** @param list<string> $roleCodes */
    private function userWithRoles(array $roleCodes): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(
            Role::query()->whereIn('code', $roleCodes)->get()->modelKeys(),
        );

        return $user;
    }

    private function wholesaleStore(): int
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'V2-2712-WHOLESALE',
            'name' => 'V2 Procurement Persona Wholesale',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore(
            Store::query()->findOrFail($storeId),
        );

        return $storeId;
    }
}
