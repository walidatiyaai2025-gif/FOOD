<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\TestCase;

/**
 * P251: one authorized Warehouse Transfers tab, with server-enforced store,
 * warehouse, permission and entitlement boundaries on each real mutation.
 */
final class V2WmsTransferWorkbenchAuthorityTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        // This is test-only evidence, not a new operational certification.
        config()->set(
            'enterprise_module_readiness.certified_versions.inventory_wms',
            app(ModuleRegistry::class)->manifest('inventory_wms')->version,
        );
    }

    public function test_bilingual_transfer_tab_posts_real_audited_and_idempotent_stock_movements(): void
    {
        $fixture = $this->fixture();
        $actor = $this->inventoryOperator($fixture['store_id']);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor)->get(route('admin.erp.wms.index', [
                'tab' => 'transfers',
                'store_id' => $fixture['store_id'],
                'warehouse_id' => $fixture['source_warehouse_id'],
            ]))
                ->assertOk()
                ->assertViewIs('admin.enterprise-wms')
                ->assertViewHas('tab', 'transfers')
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('enterprise_wms.transfers.title', [], $locale))
                ->assertSee('data-wms-mutation', false)
                ->assertSee('name="target_warehouse_id"', false);
        }

        $payload = [
            'store_id' => $fixture['store_id'],
            'source_warehouse_id' => $fixture['source_warehouse_id'],
            'target_warehouse_id' => $fixture['target_warehouse_id'],
            'product_id' => $fixture['product_id'],
            'quantity' => 2,
            'request_key' => 'p251-transfer-001',
        ];

        $this->actingAs($actor)
            ->post(route('admin.erp.wms.transfers.store'), $payload)
            ->assertRedirect();
        $this->actingAs($actor)
            ->post(route('admin.erp.wms.transfers.store'), $payload)
            ->assertRedirect();

        $this->assertDatabaseCount('enterprise_warehouse_transfers', 1);
        $transferId = (int) DB::table('enterprise_warehouse_transfers')->value('id');
        $this->assertDatabaseHas('enterprise_warehouse_transfers', [
            'id' => $transferId,
            'store_id' => $fixture['store_id'],
            'status' => 'draft',
        ]);

        $this->actingAs($actor)
            ->post(route('admin.erp.wms.transfers.dispatch', $transferId), [
                'store_id' => $fixture['store_id'],
            ])
            ->assertRedirect();
        $this->actingAs($actor)
            ->post(route('admin.erp.wms.transfers.dispatch', $transferId), [
                'store_id' => $fixture['store_id'],
            ])
            ->assertRedirect();

        $this->assertSame(8.0, (float) DB::table('inventories')
            ->where('id', $fixture['source_inventory_id'])->value('quantity'));
        $this->assertDatabaseHas('stock_movements', [
            'store_id' => $fixture['store_id'],
            'reference_type' => 'enterprise_warehouse_transfer',
            'reference_id' => $transferId,
            'type' => 'warehouse_transfer_out',
            'quantity' => -2,
        ]);

        $this->actingAs($actor)
            ->post(route('admin.erp.wms.transfers.receive', $transferId), [
                'store_id' => $fixture['store_id'],
            ])
            ->assertRedirect();
        $this->actingAs($actor)
            ->post(route('admin.erp.wms.transfers.receive', $transferId), [
                'store_id' => $fixture['store_id'],
            ])
            ->assertRedirect();

        $this->assertSame(5.0, (float) DB::table('inventories')
            ->where('id', $fixture['target_inventory_id'])->value('quantity'));
        $this->assertDatabaseHas('enterprise_warehouse_transfers', [
            'id' => $transferId,
            'status' => 'received',
        ]);
        $this->assertSame(2, DB::table('stock_movements')
            ->where('reference_type', 'enterprise_warehouse_transfer')
            ->where('reference_id', $transferId)
            ->count());
        foreach (['created', 'dispatched', 'received'] as $event) {
            $this->assertDatabaseHas('audit_logs', [
                'event' => 'enterprise.warehouse_transfer.'.$event,
                'store_id' => $fixture['store_id'],
            ]);
        }

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'transfers',
            'store_id' => $fixture['store_id'],
        ]))
            ->assertOk()
            ->assertViewHas('transferRows', fn ($rows): bool => $rows->contains('id', $transferId));
    }

    public function test_cross_store_and_narrow_warehouse_scope_deny_forged_transfer_without_writes(): void
    {
        $fixture = $this->fixture();
        $actor = $this->inventoryOperator($fixture['store_id']);

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'transfers',
            'store_id' => $fixture['other_store_id'],
        ]))->assertNotFound();

        $this->actingAs($actor)
            ->post(route('admin.erp.wms.transfers.store'), [
                'store_id' => $fixture['other_store_id'],
                'source_warehouse_id' => $fixture['other_warehouse_id'],
                'target_warehouse_id' => $fixture['target_warehouse_id'],
                'product_id' => $fixture['product_id'],
                'quantity' => 1,
                'request_key' => 'p251-forged-store',
            ])
            ->assertNotFound();

        $this->actingAs($actor)
            ->post(route('admin.erp.wms.transfers.store'), [
                'store_id' => $fixture['store_id'],
                'source_warehouse_id' => $fixture['source_warehouse_id'],
                'target_warehouse_id' => $fixture['other_warehouse_id'],
                'product_id' => $fixture['product_id'],
                'quantity' => 1,
                'request_key' => 'p251-forged-warehouse',
            ])
            ->assertNotFound();

        DB::table('enterprise_warehouse_user_scopes')->insert([
            'user_id' => $actor->id,
            'warehouse_id' => $fixture['source_warehouse_id'],
            'can_manage' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($actor)
            ->post(route('admin.erp.wms.transfers.store'), [
                'store_id' => $fixture['store_id'],
                'source_warehouse_id' => $fixture['source_warehouse_id'],
                'target_warehouse_id' => $fixture['target_warehouse_id'],
                'product_id' => $fixture['product_id'],
                'quantity' => 1,
                'request_key' => 'p251-forged-target',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('enterprise_warehouse_transfers', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(10.0, (float) DB::table('inventories')
            ->where('id', $fixture['source_inventory_id'])->value('quantity'));
    }

    public function test_roleless_actor_cannot_discover_transfers_or_create_stock_records(): void
    {
        $fixture = $this->fixture();
        $actor = User::query()->create([
            'name' => 'Unauthorized P251',
            'email' => 'p251-no-role@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'transfers',
            'store_id' => $fixture['store_id'],
        ]))->assertForbidden();

        $this->actingAs($actor)->post(route('admin.erp.wms.transfers.store'), [
            'store_id' => $fixture['store_id'],
            'source_warehouse_id' => $fixture['source_warehouse_id'],
            'target_warehouse_id' => $fixture['target_warehouse_id'],
            'product_id' => $fixture['product_id'],
            'quantity' => 1,
            'request_key' => 'p251-roleless',
        ])->assertForbidden();

        $this->assertDatabaseCount('enterprise_warehouse_transfers', 0);
    }

    public function test_disabled_wms_entitlement_blocks_screen_and_mutations_on_server(): void
    {
        $fixture = $this->fixture();
        $actor = $this->inventoryOperator($fixture['store_id']);

        app(ModuleEntitlementManager::class)->set(
            'inventory_wms',
            new EntitlementScope(EntitlementScope::STORE, $fixture['store_id']),
            false,
        );

        $this->actingAs($actor)->get(route('admin.erp.wms.index', [
            'tab' => 'transfers',
            'store_id' => $fixture['store_id'],
        ]))->assertNotFound();

        $this->actingAs($actor)->post(route('admin.erp.wms.transfers.store'), [
            'store_id' => $fixture['store_id'],
            'source_warehouse_id' => $fixture['source_warehouse_id'],
            'target_warehouse_id' => $fixture['target_warehouse_id'],
            'product_id' => $fixture['product_id'],
            'quantity' => 1,
            'request_key' => 'p251-disabled-module',
        ])->assertNotFound();

        $this->assertDatabaseCount('enterprise_warehouse_transfers', 0);
    }

    /** @return array<string,int> */
    private function fixture(): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = $this->createStore($typeId, 'P251-OWN');
        $otherStoreId = $this->createStore($typeId, 'P251-OTHER');
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'P251-EA',
            'name' => 'Each',
            'decimal_places' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'p251',
            'name' => 'P251 Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'P251-SKU',
            'name' => 'Transfer Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sourceId = $this->createWarehouse($storeId, 'P251-SOURCE');
        $targetId = $this->createWarehouse($storeId, 'P251-TARGET');
        $otherId = $this->createWarehouse($otherStoreId, 'P251-OTHER');
        $sourceInventoryId = $this->stock($sourceId, $productId, 10);
        $targetInventoryId = $this->stock($targetId, $productId, 3);

        $this->enableEnterpriseWmsForStore($storeId);
        $this->enableEnterpriseWmsForStore($otherStoreId);

        return [
            'store_id' => $storeId,
            'other_store_id' => $otherStoreId,
            'source_warehouse_id' => $sourceId,
            'target_warehouse_id' => $targetId,
            'other_warehouse_id' => $otherId,
            'source_inventory_id' => $sourceInventoryId,
            'target_inventory_id' => $targetInventoryId,
            'product_id' => $productId,
        ];
    }

    private function createStore(int $typeId, string $code): int
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

    private function createWarehouse(int $storeId, string $code): int
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

    private function stock(int $warehouseId, int $productId, float $quantity): int
    {
        return (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => $quantity,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function inventoryOperator(int $storeId): User
    {
        $actor = User::query()->create([
            'name' => 'P251 Inventory Operator',
            'email' => 'p251-inventory@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $this->grantPlatformErpAccess($actor);
        $role = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
        ]);

        return $actor;
    }
}
