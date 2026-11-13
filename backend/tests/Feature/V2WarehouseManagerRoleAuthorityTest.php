<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Wms\WarehouseScope;
use App\Models\Permission;
use App\Models\Store;
use App\Models\Role;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\TestCase;

/**
 * T122R11 / #2691: the Warehouse Manager receives WMS capabilities only
 * while its required module is enabled; no administrative or retail grant.
 */
final class V2WarehouseManagerRoleAuthorityTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.inventory_wms',
            app(ModuleRegistry::class)->manifest('inventory_wms')->version,
        );
    }

    public function test_canonical_warehouse_manager_role_is_bilingual_and_does_not_include_finance_or_order_permissions(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $definition = $catalog->definition('WAREHOUSE_MANAGER');
        $permissions = ['inventory.view', 'inventory.adjust', 'inventory.manage', 'catalog.view', 'reports.view'];

        $this->assertIsArray($definition);
        $this->assertSame('inventory', $definition['domain']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame(['inventory_wms'], $definition['required_modules']);
        $this->assertSame(['procurement'], $definition['optional_modules']);
        $this->assertSame($permissions, $definition['permissions']);

        $role = Role::query()->where('code', 'WAREHOUSE_MANAGER')->firstOrFail();
        $this->assertTrue((bool) $role->is_system);
        $this->assertEqualsCanonicalizing($permissions, $role->permissions()->pluck('code')->all());

        foreach (['ar' => 'مدير المستودع', 'en' => 'Warehouse Manager'] as $locale => $expectedName) {
            app()->setLocale($locale);
            $presentation = $catalog->presentation('WAREHOUSE_MANAGER');
            $this->assertSame($expectedName, $presentation['name']);
            $this->assertNotEmpty($presentation['description']);
            $this->assertNotEmpty($presentation['access_boundaries']);
        }
    }

    public function test_disabled_wms_revokes_effective_inventory_access_without_deleting_the_assignment(): void
    {
        $actor = $this->manager();
        $this->disableWms();

        $this->assertFalse(app(BuiltInJobRoleCatalog::class)->isAvailable('WAREHOUSE_MANAGER'));
        $this->assertFalse($actor->fresh()->hasPermission('inventory.view'));
        $this->assertFalse($actor->fresh()->hasPermission('inventory.manage'));
        $this->assertFalse($actor->fresh()->hasPermission('reports.view'));
        $this->assertFalse(app(BuiltInJobRoleCatalog::class)->isAvailable('WAREHOUSE_MANAGER', 1));
        $this->assertDatabaseHas('role_user', [
            'user_id' => $actor->id,
            'role_id' => Role::query()->where('code', 'WAREHOUSE_MANAGER')->firstOrFail()->id,
        ]);

        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.wms.index', [], false))
            ->assertForbidden();
    }

    public function test_enabled_warehouse_manager_retains_only_authorized_permission_bundle_with_explicit_deny_priority(): void
    {
        $this->enableWms();
        $actor = $this->manager();
        $catalog = app(BuiltInJobRoleCatalog::class);

        $this->assertTrue($catalog->isAvailable('WAREHOUSE_MANAGER'));
        $this->assertFalse($catalog->isAvailable('WAREHOUSE_MANAGER', 1));

        foreach (['inventory.view', 'inventory.adjust', 'inventory.manage', 'catalog.view', 'reports.view'] as $code) {
            $this->assertTrue($actor->fresh()->hasPermission($code), $code);
        }
        foreach (['finance.view', 'finance.manage', 'orders.dispatch', 'orders.manage', 'security.view', 'roles.manage', 'users.status.manage'] as $code) {
            $this->assertFalse($actor->fresh()->hasPermission($code), $code);
        }

        $this->actingAs($actor->fresh())
            ->get(route('admin.security.index', ['section' => 'users'], false))
            ->assertForbidden();
        Sanctum::actingAs($actor->fresh(), ['*']);
        $this->postJson('/api/v1/admin/security/roles', [])->assertForbidden();

        $permissionId = Permission::query()->where('code', 'inventory.view')->firstOrFail()->id;
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permissionId,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($actor->fresh()->hasPermission('inventory.view'));
        $this->assertTrue($actor->fresh()->hasPermission('inventory.adjust'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.manage'));
    }

    public function test_warehouse_manager_can_enter_wholesale_wms_but_never_retail_or_another_warehouse(): void
    {
        $actor = $this->manager();
        $storeId = app(WholesalePrincipal::class)->storeId();

        // This existing global role must be admitted only to Wholesale, with
        // the WMS module and an actual store entitlement still authoritative.
        $this->assertContains('WAREHOUSE_MANAGER', config('admin.channels.b2b.global_roles'));
        $this->assertNotContains('WAREHOUSE_MANAGER', config('admin.channels.b2c.global_roles'));
        $this->assertFalse(app(AdminNavigation::class)->canAccess($actor, 'b2c'));

        $this->enableWms();
        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'store_id' => $storeId,
        ]))->assertNotFound();

        $this->enableEnterpriseWmsForStore($storeId);
        $this->assertTrue(app(AdminNavigation::class)->canAccess($actor->fresh(), 'b2b'));
        $this->assertTrue($actor->fresh()->hasPermission('inventory.view', $storeId));
        $this->assertTrue($actor->fresh()->hasPermission('inventory.manage', $storeId));
        $this->assertFalse($actor->fresh()->hasPermission('finance.approve', $storeId));

        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'V2-2691-WHOLESALE',
            'name' => 'Main wholesale warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())->get(route('admin.erp.wms.index', [
                'store_id' => $storeId,
                'warehouse_id' => $warehouseId,
                'tab' => 'warehouses',
            ]))
                ->assertOk()
                ->assertSee('data-enterprise-wms="true"', false)
                ->assertSee('dir="'.$direction.'"', false);
        }

        $otherWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'V2-2691-SCOPED-FOREIGN',
            'name' => 'Unassigned wholesale warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('enterprise_warehouse_user_scopes')->insert([
            'user_id' => $actor->id,
            'warehouse_id' => $warehouseId,
            'can_manage' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A global Warehouse Manager role cannot bypass an explicit
        // warehouse-level read-only assignment to manage or enumerate peers.
        $this->assertSame([], app(WarehouseScope::class)->authorizedWarehouseIds(
            $actor->fresh(),
            $storeId,
            'inventory.manage',
        ));
        $this->actingAs($actor->fresh())->get(route('admin.erp.wms.index', [
            'store_id' => $storeId,
            'warehouse_id' => $warehouseId,
            'tab' => 'warehouses',
        ]))->assertOk();
        $this->actingAs($actor->fresh())->get(route('admin.erp.wms.index', [
            'store_id' => $storeId,
            'warehouse_id' => $otherWarehouseId,
            'tab' => 'warehouses',
        ]))->assertNotFound();

        $retail = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-2691-FOREIGN-RETAIL',
            'name' => 'Retail warehouse boundary',
            'is_active' => true,
        ]);
        $this->enableEnterpriseWmsForStore($retail->id);
        $this->assertFalse($actor->fresh()->hasPermission('inventory.view', $retail->id));
        $this->actingAs($actor->fresh())->get(route('admin.erp.wms.index', [
            'store_id' => $retail->id,
            'tab' => 'warehouses',
        ]))->assertNotFound();
        $this->assertFalse(app(AdminNavigation::class)->canAccess($actor->fresh(), 'b2c'));
    }

    private function manager(): User
    {
        $user = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'WAREHOUSE_MANAGER')->firstOrFail());

        return $user->fresh();
    }

    private function disableWms(): void
    {
        $registry = app(ModuleRegistry::class);
        $registry->syncManifest('inventory_wms');

        if ($registry->state('inventory_wms') !== ModuleState::DISABLED) {
            app(ModuleLifecycleManager::class)->transition('inventory_wms', ModuleState::DISABLED);
        }
    }

    private function enableWms(): void
    {
        $this->disableWms();
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('inventory_wms', ModuleState::ENABLING);
        $lifecycle->transition('inventory_wms', ModuleState::ENABLED);
    }
}
