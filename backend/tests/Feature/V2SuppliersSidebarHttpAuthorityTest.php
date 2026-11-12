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
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P239 / #2569: Suppliers is the real protected Procurement Workbench tab,
 * not a second unguarded dashboard page or cosmetic Sidebar permission.
 */
final class V2SuppliersSidebarHttpAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        config()->set('enterprise_module_readiness.certified_versions', []);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_suppliers_navigation_and_http_are_hidden_until_module_is_certified_and_entitled(): void
    {
        $store = $this->wholesaleStore();
        $buyer = $this->actor('PROCUREMENT_MANAGER');

        $this->assertTrue(Route::has('admin.erp.procurement.index'));
        $this->assertTrue(Route::has('admin.erp.procurement.suppliers.store'));
        $this->assertFalse($this->navigationHas($buyer, 'erp_procurement'));
        $this->actingAs($buyer)
            ->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'suppliers',
            ], false))
            ->assertForbidden();

        $this->enableProcurement();

        $this->assertTrue($buyer->fresh()->hasPermission('procurement.suppliers.view', $store->id));
        $this->assertTrue($this->navigationHas($buyer->fresh(), 'erp_procurement'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();

            $this->actingAs($buyer->fresh())
                ->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'suppliers',
                ], false))
                ->assertOk()
                ->assertViewHas('activeTab', 'suppliers')
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_supplier_viewer_has_real_read_only_tab_but_cannot_create_supplier_by_direct_post(): void
    {
        $store = $this->wholesaleStore();
        $this->enableProcurement();
        $viewer = $this->actor('PROCUREMENT_APPROVER');

        $this->assertTrue($viewer->hasPermission('procurement.suppliers.view', $store->id));
        $this->assertFalse($viewer->hasPermission('procurement.suppliers.manage', $store->id));

        $this->actingAs($viewer)
            ->get(route('admin.erp.procurement.index', [
                'tab' => 'suppliers',
                'store_id' => $store->id,
            ], false))
            ->assertOk()
            ->assertViewHas('can', static fn (array $abilities): bool => $abilities['supplier_manage'] === false);

        $this->actingAs($viewer)
            ->post(route('admin.erp.procurement.suppliers.store', [], false), [
                'store_id' => $store->id,
                'scope_type' => 'store',
                'code' => 'P239-DENIED',
                'name_en' => 'Denied supplier',
                'name_ar' => 'مورد غير مصرح',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('enterprise_suppliers', ['code' => 'P239-DENIED']);
    }

    public function test_authorized_manager_can_create_and_open_exact_supplier_without_widening_company_scope(): void
    {
        $store = $this->wholesaleStore();
        $this->enableProcurement();
        $manager = $this->actor('PROCUREMENT_MANAGER');

        $this->assertTrue($manager->hasPermission('procurement.suppliers.manage', $store->id));

        $this->actingAs($manager)
            ->post(route('admin.erp.procurement.suppliers.store', [], false), [
                'store_id' => $store->id,
                'scope_type' => 'store',
                'code' => 'P239-OWNED',
                'name_en' => 'P239 Test Supplier',
                'name_ar' => 'مورد اختبار P239',
                'payment_terms_days' => 30,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $supplierId = (int) DB::table('enterprise_suppliers')
            ->where('code', 'P239-OWNED')
            ->value('id');

        $this->assertGreaterThan(0, $supplierId);
        $this->assertDatabaseHas('enterprise_suppliers', [
            'id' => $supplierId,
            'store_id' => $store->id,
            'code' => 'P239-OWNED',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.supplier.created',
            'auditable_id' => $supplierId,
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $manager->forceFill(['locale' => $locale])->save();

            $this->actingAs($manager->fresh())
                ->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'suppliers',
                    'supplier' => $supplierId,
                ], false))
                ->assertOk()
                ->assertViewHas('detail', static fn (array $detail): bool => (int) $detail['supplier']->id === $supplierId)
                ->assertSee('dir="'.$direction.'"', false);
        }

        $this->actingAs($manager->fresh())
            ->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'suppliers',
                'supplier' => 999999,
            ], false))
            ->assertNotFound();
    }

    public function test_retail_driver_and_roleless_actor_cannot_open_wholesale_suppliers_tab(): void
    {
        $store = $this->wholesaleStore();
        $this->enableProcurement();

        foreach ([$this->actor('B2C_DRIVER'), User::factory()->create(['is_active' => true])] as $outsider) {
            $this->assertFalse($this->navigationHas($outsider, 'erp_procurement'));
            $this->actingAs($outsider)
                ->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'suppliers',
                ], false))
                ->assertForbidden();
        }
    }

    private function enableProcurement(): void
    {
        app(ModuleRegistry::class)->syncManifest('procurement');
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLED);
        app(ModuleEntitlementManager::class)->set('procurement', EntitlementScope::platform(), true);
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
    }

    private function wholesaleStore(): Store
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'V2-P239-SUPPLIERS',
            'name' => 'V2 P239 wholesale suppliers',
            'is_active' => true,
        ]);

        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user->fresh();
    }

    private function navigationHas(User $actor, string $key): bool
    {
        app()->forgetInstance(AdminNavigation::class);

        return collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->contains('key', $key);
    }
}
