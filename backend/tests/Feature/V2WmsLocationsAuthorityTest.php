<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\TestCase;

/**
 * P255: locations reuse ERP WMS, with the same effective store/warehouse
 * authority for the visible create forms and their real HTTP mutations.
 */
final class V2WmsLocationsAuthorityTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);

        // Synthetic test-only certified version, never a production attestation.
        config()->set(
            'enterprise_module_readiness.certified_versions.inventory_wms',
            app(ModuleRegistry::class)->manifest('inventory_wms')->version,
        );
    }

    public function test_locations_are_a_localized_existing_tab_and_read_only_role_never_sees_mutation_forms(): void
    {
        $fixture = $this->fixture();
        $viewer = $this->operator(
            $fixture['store_id'],
            ['inventory.view'],
            'P255-VIEW',
        );

        foreach ([
            'admin.erp.wms.index',
            'admin.erp.wms.locations.zones.store',
            'admin.erp.wms.locations.bins.store',
            'admin.erp.wms.locations.assignments.store',
        ] as $route) {
            $this->assertTrue(Route::has($route), "Unregistered WMS locations route {$route}");
        }

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $viewer->forceFill(['locale' => $locale])->save();

            $this->actingAs($viewer->fresh())->get(route('admin.erp.wms.index', [
                'tab' => 'locations',
                'store_id' => $fixture['store_id'],
                'warehouse_id' => $fixture['warehouse_id'],
            ]))
                ->assertOk()
                ->assertViewIs('admin.enterprise-wms')
                ->assertViewHas('tab', 'locations')
                ->assertViewHas('canManageLocations', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('enterprise_wms.locations.title'))
                ->assertSee('data-wms-locations', false)
                ->assertDontSee('data-wms-mutation', false);

            $this->actingAs($viewer->fresh())->post(route('admin.erp.wms.locations.zones.store'), [
                'store_id' => $fixture['store_id'],
                'warehouse_id' => $fixture['warehouse_id'],
                'code' => 'P255-VIEW-DENY',
                'name' => 'Never created',
            ])->assertNotFound();
        }

        $this->assertDatabaseMissing('enterprise_warehouse_zones', ['code' => 'P255-VIEW-DENY']);
    }

    public function test_manager_creates_zone_bin_and_inventory_assignment_without_mutating_stock(): void
    {
        $fixture = $this->fixture();
        $manager = $this->operator(
            $fixture['store_id'],
            ['inventory.view', 'inventory.manage'],
            'P255-MANAGER',
        );

        $this->actingAs($manager)->get(route('admin.erp.wms.index', [
            'tab' => 'locations',
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
        ]))
            ->assertOk()
            ->assertViewHas('canManageLocations', true)
            ->assertSee('data-wms-mutation', false)
            ->assertSee(__('enterprise_wms.locations.new_zone'))
            ->assertSee(__('enterprise_wms.locations.new_bin'));

        $this->actingAs($manager)->post(route('admin.erp.wms.locations.zones.store'), [
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
            'code' => 'P255-ZONE',
            'name' => 'Inbound Zone',
            'is_active' => 1,
        ])->assertRedirect();

        $zoneId = (int) DB::table('enterprise_warehouse_zones')
            ->where('code', 'P255-ZONE')->value('id');
        $this->assertGreaterThan(0, $zoneId);
        $this->assertDatabaseHas('enterprise_warehouse_zones', [
            'id' => $zoneId,
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
            'is_active' => 1,
        ]);

        $this->actingAs($manager)->post(route('admin.erp.wms.locations.bins.store'), [
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
            'zone_id' => $zoneId,
            'code' => 'P255-BIN',
            'name' => 'A1-01',
            'is_active' => 1,
        ])->assertRedirect();

        $binId = (int) DB::table('enterprise_warehouse_bins')
            ->where('code', 'P255-BIN')->value('id');
        $this->assertGreaterThan(0, $binId);
        $this->assertDatabaseHas('enterprise_warehouse_bins', [
            'id' => $binId,
            'zone_id' => $zoneId,
            'warehouse_id' => $fixture['warehouse_id'],
        ]);

        $this->actingAs($manager)->post(route('admin.erp.wms.locations.assignments.store'), [
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
            'inventory_id' => $fixture['inventory_id'],
            'bin_id' => $binId,
        ])->assertRedirect();

        $this->assertDatabaseHas('enterprise_inventory_bin_assignments', [
            'inventory_id' => $fixture['inventory_id'],
            'warehouse_id' => $fixture['warehouse_id'],
            'bin_id' => $binId,
        ]);
        $this->assertSame(8.0, (float) DB::table('inventories')
            ->where('id', $fixture['inventory_id'])->value('quantity'));
        $this->assertDatabaseCount('stock_movements', 0);

        foreach (['enterprise.wms.zone.created', 'enterprise.wms.bin.created', 'enterprise.wms.inventory_bin.assigned'] as $event) {
            $this->assertDatabaseHas('audit_logs', [
                'event' => $event,
                'store_id' => $fixture['store_id'],
            ]);
        }

        $this->actingAs($manager)->get(route('admin.erp.wms.index', [
            'tab' => 'locations',
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
        ]))
            ->assertOk()
            ->assertSee('P255-ZONE')
            ->assertSee('P255-BIN');
    }

    public function test_cross_store_and_cross_warehouse_forgery_cannot_read_or_create_locations(): void
    {
        $fixture = $this->fixture();
        $actor = $this->operator(
            $fixture['store_id'],
            ['inventory.view', 'inventory.manage'],
            'P255-SCOPE',
        );

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'locations',
            'store_id' => $fixture['other_store_id'],
            'warehouse_id' => $fixture['other_warehouse_id'],
        ]))->assertNotFound();

        // Even an actor legitimately managing both stores must not spoof a
        // different warehouse against the submitted store context.
        $otherStoreRole = $this->roleWithPermissions(
            ['inventory.view', 'inventory.manage'],
            'P255-DUAL',
        );
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $fixture['other_store_id'],
            'role_id' => $otherStoreRole->id,
        ]);

        $this->actingAs($actor)->post(route('admin.erp.wms.locations.zones.store'), [
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['other_warehouse_id'],
            'code' => 'P255-DUAL-FORGED',
            'name' => 'Dual store spoof',
        ])->assertNotFound();

        $this->actingAs($actor)->post(route('admin.erp.wms.locations.bins.store'), [
            'store_id' => $fixture['other_store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
            'code' => 'P255-DUAL-BIN',
            'name' => 'Dual store spoof',
        ])->assertNotFound();

        $this->assertDatabaseMissing('enterprise_warehouse_zones', ['code' => 'P255-DUAL-FORGED']);
        $this->assertDatabaseMissing('enterprise_warehouse_bins', ['code' => 'P255-DUAL-BIN']);

        $otherWarehouseInSameStore = $this->warehouse($fixture['store_id'], 'P255-WH-2');
        $otherInventory = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $otherWarehouseInSameStore,
            'product_id' => (int) DB::table('inventories')
                ->where('id', $fixture['inventory_id'])->value('product_id'),
            'quantity' => 5,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($actor)->post(route('admin.erp.wms.locations.assignments.store'), [
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
            'inventory_id' => $otherInventory,
            'bin_id' => 1,
        ])->assertNotFound();

        $this->assertDatabaseMissing('enterprise_inventory_bin_assignments', [
            'inventory_id' => $otherInventory,
        ]);

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'locations',
            'store_id' => $fixture['other_store_id'],
            'warehouse_id' => $fixture['other_warehouse_id'],
        ]))->assertOk();

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'locations',
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['other_warehouse_id'],
        ]))->assertNotFound();

        $this->actingAs($actor)->post(route('admin.erp.wms.locations.zones.store'), [
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['other_warehouse_id'],
            'code' => 'P255-FORGED-ZONE',
            'name' => 'Foreign Warehouse',
        ])->assertNotFound();

        $this->assertDatabaseMissing('enterprise_warehouse_zones', ['code' => 'P255-FORGED-ZONE']);

        // A configured warehouse read scope must not imply manage authority.
        DB::table('enterprise_warehouse_user_scopes')->insert([
            'user_id' => $actor->id,
            'warehouse_id' => $fixture['warehouse_id'],
            'can_manage' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'locations',
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
        ]))
            ->assertOk()
            ->assertViewHas('canManageLocations', false)
            ->assertDontSee('data-wms-mutation', false);

        $this->actingAs($actor)->post(route('admin.erp.wms.locations.bins.store'), [
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
            'code' => 'P255-SCOPE-DENY',
            'name' => 'Denied Warehouse',
        ])->assertNotFound();

        $this->assertDatabaseMissing('enterprise_warehouse_bins', ['code' => 'P255-SCOPE-DENY']);
    }

    public function test_explicit_store_deny_wins_over_two_assigned_roles_and_preserves_view(): void
    {
        $fixture = $this->fixture();
        $actor = $this->operator($fixture['store_id'], ['inventory.view'], 'P255-ROLES');
        $managerRole = $this->roleWithPermissions(['inventory.manage'], 'P255-EXTRA');
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $fixture['store_id'],
            'role_id' => $managerRole->id,
        ]);

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'locations',
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
        ]))->assertViewHas('canManageLocations', true);

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'inventory.manage')->firstOrFail()->id,
            'scope_key' => 'store:'.$fixture['store_id'],
            'store_id' => $fixture['store_id'],
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($actor->fresh())->get(route('admin.erp.wms.index', [
            'tab' => 'locations',
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
        ]))
            ->assertOk()
            ->assertViewHas('canManageLocations', false)
            ->assertDontSee('data-wms-mutation', false);

        $this->actingAs($actor->fresh())->post(route('admin.erp.wms.locations.zones.store'), [
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
            'code' => 'P255-ROLE-DENY',
            'name' => 'Denied',
        ])->assertNotFound();

        $this->assertDatabaseMissing('enterprise_warehouse_zones', ['code' => 'P255-ROLE-DENY']);
    }

    public function test_disabled_wms_entitlement_and_roleless_actor_fail_closed_without_writes(): void
    {
        $fixture = $this->fixture();
        $actor = $this->operator(
            $fixture['store_id'],
            ['inventory.view', 'inventory.manage'],
            'P255-DISABLED',
        );

        app(ModuleEntitlementManager::class)->set(
            'inventory_wms',
            new EntitlementScope(EntitlementScope::STORE, $fixture['store_id']),
            false,
        );

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'locations',
            'store_id' => $fixture['store_id'],
        ]))->assertNotFound();

        $this->actingAs($actor)->post(route('admin.erp.wms.locations.zones.store'), [
            'store_id' => $fixture['store_id'],
            'warehouse_id' => $fixture['warehouse_id'],
            'code' => 'P255-OFF',
            'name' => 'Should Not Exist',
        ])->assertNotFound();

        $roleless = User::factory()->create([
            'email' => 'p255-roleless@example.test',
            'is_active' => true,
        ]);
        $this->actingAs($roleless)->get(route('admin.erp.wms.index', [
            'tab' => 'locations',
            'store_id' => $fixture['store_id'],
        ]))->assertForbidden();

        $this->assertDatabaseMissing('enterprise_warehouse_zones', ['code' => 'P255-OFF']);
    }

    /** @return array{store_id:int,other_store_id:int,warehouse_id:int,other_warehouse_id:int,inventory_id:int} */
    private function fixture(): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = $this->store($typeId, 'P255-STORE-A');
        $other = $this->store($typeId, 'P255-STORE-B');
        $warehouse = $this->warehouse($store, 'P255-WH-A');
        $otherWarehouse = $this->warehouse($other, 'P255-WH-B');

        $unit = (int) DB::table('units')->insertGetId([
            'code' => 'P255-EACH',
            'name' => 'Each',
            'decimal_places' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalog = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $store,
            'channel' => 'b2c',
            'code' => 'p255',
            'name' => 'P255 Location Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog,
            'unit_id' => $unit,
            'sku' => 'P255-SKU',
            'name' => 'P255 Test Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inventory = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouse,
            'product_id' => $product,
            'quantity' => 8,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->enableEnterpriseWmsForStore($store);
        $this->enableEnterpriseWmsForStore($other);

        return [
            'store_id' => $store,
            'other_store_id' => $other,
            'warehouse_id' => $warehouse,
            'other_warehouse_id' => $otherWarehouse,
            'inventory_id' => $inventory,
        ];
    }

    private function store(int $typeId, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function warehouse(int $storeId, string $code): int
    {
        return (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param list<string> $permissions */
    private function operator(int $storeId, array $permissions, string $code): User
    {
        $actor = User::factory()->create([
            'email' => strtolower($code).'@example.test',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $this->grantPlatformErpAccess($actor);
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $this->roleWithPermissions($permissions, $code)->id,
        ]);

        return $actor;
    }

    /** @param list<string> $permissions */
    private function roleWithPermissions(array $permissions, string $code): Role
    {
        $role = Role::query()->create([
            'code' => 'V2_2585_'.str_replace('-', '_', $code),
            'name' => 'V2 Locations '.$code,
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        foreach ($permissions as $ability) {
            $permission = Permission::query()->firstOrCreate(
                ['code' => $ability],
                ['name' => $ability],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return $role;
    }
}
