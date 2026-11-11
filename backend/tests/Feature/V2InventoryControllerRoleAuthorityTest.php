<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Models\Role;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\OperationalTenantScope;
use App\Services\RbacManager;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2InventoryControllerRoleAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_controller_has_only_inventory_read_adjust_and_reports_with_wholesale_scope(): void
    {
        $this->enableWms();
        [$wholesaleStore, $retailStore, $wholesaleInventory] = $this->inventoryFixtures();
        $role = Role::query()->where('code', 'INVENTORY_CONTROLLER')->firstOrFail();
        $user = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $user->roles()->attach($role->id);

        $this->assertEqualsCanonicalizing(
            ['inventory.view', 'inventory.adjust', 'catalog.view', 'reports.view'],
            $role->permissions()->pluck('code')->all(),
        );
        $this->assertTrue($user->hasPermission('inventory.view', $wholesaleStore));
        $this->assertTrue($user->hasPermission('inventory.adjust', $wholesaleStore));
        $this->assertFalse($user->hasPermission('inventory.view', $retailStore));
        $this->assertFalse($user->hasPermission('inventory.manage', $wholesaleStore));
        foreach (['reports.export', 'finance.view', 'finance.approve', 'orders.dispatch', 'catalog.edit', 'users.roles.manage'] as $denied) {
            $this->assertFalse($user->hasPermission($denied, $wholesaleStore), $denied);
        }
        $this->assertSame(
            [$wholesaleStore],
            app(OperationalTenantScope::class)->allowedStoreIds($user, 'inventory.view', 'b2b'),
        );
        $this->assertSame(
            [],
            app(OperationalTenantScope::class)->allowedStoreIds($user, 'inventory.view', 'b2c'),
        );

        foreach (['ar' => 'مراقب المخزون', 'en' => 'Inventory Controller'] as $locale => $label) {
            App::setLocale($locale);
            $this->assertSame($label, app(BuiltInJobRoleCatalog::class)->presentation('INVENTORY_CONTROLLER')['name']);
        }

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/admin/inventory')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wholesaleInventory);

        $this->actingAs($user)
            ->get(route('admin.b2b.module', ['module' => 'inventory']))
            ->assertOk();
    }

    public function test_inventory_adjustment_is_scoped_audited_and_cannot_cross_into_retail(): void
    {
        $this->enableWms();
        [$wholesaleStore, , $wholesaleInventory, $retailInventory] = $this->inventoryFixtures();
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'INVENTORY_CONTROLLER')->firstOrFail());
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/admin/inventory/{$wholesaleInventory}/adjust", [
            'quantity_delta' => -2,
            'reason' => 'Verified cycle count',
            'expected_quantity' => 10,
        ])->assertOk()->assertJsonPath('data.quantity', 8);

        $this->assertDatabaseHas('stock_movements', [
            'inventory_id' => $wholesaleInventory,
            'store_id' => $wholesaleStore,
            'user_id' => $user->id,
            'type' => 'adjustment',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'inventory.adjusted',
            'store_id' => $wholesaleStore,
        ]);

        $this->postJson("/api/v1/admin/inventory/{$wholesaleInventory}/adjust", [
            'quantity_delta' => 1,
            'reason' => 'Stale balance',
            'expected_quantity' => 10,
        ])->assertUnprocessable();

        $this->postJson("/api/v1/admin/inventory/{$retailInventory}/adjust", [
            'quantity_delta' => 1,
            'reason' => 'Cross-channel attempt',
        ])->assertNotFound();
        $this->assertDatabaseHas('inventories', ['id' => $retailInventory, 'quantity' => 10]);
        $this->assertDatabaseMissing('stock_movements', ['inventory_id' => $retailInventory]);
    }

    public function test_disabled_wms_retains_historical_role_but_revokes_runtime_grants_and_new_assignments(): void
    {
        $this->enableWms();
        [$wholesaleStore, , $wholesaleInventory] = $this->inventoryFixtures();
        $role = Role::query()->where('code', 'INVENTORY_CONTROLLER')->firstOrFail();
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role->id);
        $this->assertTrue($user->hasPermission('inventory.adjust', $wholesaleStore));

        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        app(ModuleLifecycleManager::class)->transition('inventory_wms', ModuleState::DISABLED, $admin);

        $this->assertFalse(app(BuiltInJobRoleCatalog::class)->isAvailable('INVENTORY_CONTROLLER'));
        $this->assertFalse($user->fresh()->hasPermission('inventory.view', $wholesaleStore));
        $this->assertFalse($user->fresh()->hasPermission('inventory.adjust', $wholesaleStore));
        $this->assertDatabaseHas('role_user', ['role_id' => $role->id, 'user_id' => $user->id]);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/admin/inventory')->assertForbidden();
        $this->postJson("/api/v1/admin/inventory/{$wholesaleInventory}/adjust", [
            'quantity_delta' => 1,
            'reason' => 'Unavailable module',
        ])->assertNotFound();
        $this->assertDatabaseMissing('stock_movements', ['inventory_id' => $wholesaleInventory]);

        $newUser = User::factory()->create(['is_active' => true]);
        try {
            app(RbacManager::class)->replaceUserRoles(
                $admin,
                $newUser,
                [$role->id],
                [],
                Request::create('/admin/security', 'PUT'),
            );
            $this->fail('An unavailable inventory role must not be assigned.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('global_role_ids', $exception->errors());
        }
        $this->assertDatabaseMissing('role_user', ['role_id' => $role->id, 'user_id' => $newUser->id]);
    }

    private function enableWms(): void
    {
        $registry = app(ModuleRegistry::class);
        if ($registry->registration('inventory_wms') === null) {
            $registry->syncManifest('inventory_wms');
        }

        $state = $registry->state('inventory_wms');
        if ($state === ModuleState::INSTALLED) {
            app(ModuleLifecycleManager::class)->transition('inventory_wms', ModuleState::DISABLED);
            $state = ModuleState::DISABLED;
        }
        if ($state === ModuleState::DISABLED) {
            app(ModuleLifecycleManager::class)->transition('inventory_wms', ModuleState::ENABLING);
            app(ModuleLifecycleManager::class)->transition('inventory_wms', ModuleState::ENABLED);
        }
    }

    /** @return array{0:int,1:int,2:int,3:int} */
    private function inventoryFixtures(): array
    {
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'V2-INV-UNIT',
            'name' => 'Unit',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $stores = [];
        $inventories = [];
        foreach (['B2B' => 'v2-wholesale', 'B2C' => 'v2-retail'] as $channel => $code) {
            $type = (int) DB::table('store_types')->where('code', $channel)->value('id');
            $store = (int) DB::table('stores')->insertGetId([
                'store_type_id' => $type,
                'code' => $code,
                'name' => $code,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $catalog = (int) DB::table('catalogs')->insertGetId([
                'store_id' => $store,
                'channel' => strtolower($channel),
                'code' => 'default',
                'name' => $code.' catalog',
                'is_active' => true,
                'is_migration_quarantine' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $product = (int) DB::table('products')->insertGetId([
                'catalog_id' => $catalog,
                'unit_id' => $unitId,
                'sku' => $code.'-sku',
                'name' => $code.' product',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $warehouse = (int) DB::table('warehouses')->insertGetId([
                'store_id' => $store,
                'code' => 'wh-'.$code,
                'name' => $code.' warehouse',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $inventory = (int) DB::table('inventories')->insertGetId([
                'warehouse_id' => $warehouse,
                'product_id' => $product,
                'quantity' => 10,
                'reserved_quantity' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $stores[$channel] = $store;
            $inventories[$channel] = $inventory;
        }

        return [$stores['B2B'], $stores['B2C'], $inventories['B2B'], $inventories['B2C']];
    }
}
