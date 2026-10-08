<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\DashboardOperationalNotifier;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantOperationalIsolationTest extends TestCase
{
    use RefreshDatabase;

    private int $storeA;

    private int $storeB;

    private int $b2bStore;

    private int $productA;

    private int $productB;

    private int $b2bProduct;

    private int $warehouseA;

    private int $warehouseB;

    private int $b2bWarehouse;

    private int $inventoryA;

    private User $storeAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        $this->storeA = $this->store('B2C', 'TENANT-A');
        $this->storeB = $this->store('B2C', 'TENANT-B');
        $this->b2bStore = $this->store('B2B', 'WHOLESALE');

        $unit = (int) DB::table('units')->insertGetId([
            'code' => 'TENANT-EA',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$this->productA, $this->warehouseA, $this->inventoryA] = $this->operationalFixture(
            $this->storeA,
            'b2c',
            $unit,
            'TEN-A-P',
            10,
        );
        [$this->productB, $this->warehouseB] = $this->operationalFixture(
            $this->storeB,
            'b2c',
            $unit,
            'TEN-B-P',
            99,
        );
        [$this->b2bProduct, $this->b2bWarehouse] = $this->operationalFixture(
            $this->b2bStore,
            'b2b',
            $unit,
            'WHOLE-P',
            7,
        );

        $this->storeAdmin = $this->userWithRole('B2C_STORE_ADMIN', 'tenant-a-admin@example.test');
        DB::table('user_store_roles')->insert([
            'user_id' => $this->storeAdmin->id,
            'store_id' => $this->storeA,
            'role_id' => (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_store_admin_cannot_bind_foreign_product_or_manage_foreign_marketing(): void
    {
        DB::table('promotions')->insert([
            ['store_id' => $this->storeA, 'name' => 'Mine Promo', 'type' => 'percentage', 'value' => 5, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['store_id' => $this->storeB, 'name' => 'Other Promo', 'type' => 'percentage', 'value' => 5, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $foreignPromotion = (int) DB::table('promotions')->where('store_id', $this->storeB)->value('id');

        $foreignBanner = (int) DB::table('banners')->insertGetId([
            'store_id' => $this->storeB,
            'title' => 'Other Banner',
            'image_path' => '/other.jpg',
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->storeAdmin)
            ->get('/admin/b2c/inventory')
            ->assertOk()
            ->assertSee('TEN-A-P')
            ->assertDontSee('TEN-B-P');

        $this->actingAs($this->storeAdmin)
            ->post('/admin/business/inventory', [
                'warehouse_id' => $this->warehouseA,
                'product_id' => $this->productB,
                'quantity' => 3,
            ])
            ->assertSessionHasErrors('product_id');

        $this->actingAs($this->storeAdmin)
            ->get('/admin/b2c/promotions')
            ->assertOk()
            ->assertSee('Mine Promo')
            ->assertDontSee('Other Promo');

        $this->actingAs($this->storeAdmin)
            ->patch('/admin/business/promotions/'.$foreignPromotion, [
                'store_id' => $this->storeB,
                'name' => 'Tampered',
                'type' => 'percentage',
                'value' => 9,
                'is_active' => 1,
            ])
            ->assertNotFound();

        $this->actingAs($this->storeAdmin)
            ->delete('/admin/business/banners/'.$foreignBanner)
            ->assertNotFound();

        $this->assertDatabaseHas('promotions', ['id' => $foreignPromotion, 'name' => 'Other Promo']);
        $this->assertDatabaseHas('banners', ['id' => $foreignBanner, 'title' => 'Other Banner']);
    }

    public function test_delivery_driver_is_bound_to_one_store_and_cross_store_assignment_is_hidden(): void
    {
        $driverUser = $this->userWithRole('B2C_DRIVER', 'tenant-driver@example.test');
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $this->storeA,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        $orderA = $this->order($this->storeA, 'TEN-A-ORDER');
        $orderB = $this->order($this->storeB, 'TEN-B-ORDER');

        Sanctum::actingAs($this->storeAdmin);
        $created = $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $driver->id,
            'order_id' => $orderA->id,
        ])->assertCreated();

        $this->assertDatabaseHas('driver_assignments', [
            'id' => $created->json('data.id'),
            'store_id' => $this->storeA,
            'driver_id' => $driver->id,
            'order_id' => $orderA->id,
        ]);

        $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $driver->id,
            'order_id' => $orderB->id,
        ])->assertNotFound();

        Sanctum::actingAs($driverUser, ['app:driver']);
        $this->getJson('/api/v1/driver/assignments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.store_id', $this->storeA);
    }

    public function test_b2b_channel_reports_do_not_aggregate_retail_inventory_or_products(): void
    {
        $admin = $this->userWithRole('B2B_ADMIN', 'wholesale-report@example.test');
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/reports/operations?from=2026-01-01&to=2026-12-31')
            ->assertOk()
            ->assertJsonPath('data.filters.channel', 'b2b')
            ->assertJsonPath('data.kpis.inventory_quantity', 7);

        $this->getJson('/api/v1/admin/reports/products?from=2026-01-01&to=2026-12-31')
            ->assertOk()
            ->assertJsonPath('data.filters.channel', 'b2b')
            ->assertJsonPath('data.kpis.zero_sale_products', 1)
            ->assertJsonPath('data.zero_sales.0.sku', 'WHOLE-P');
    }

    public function test_operational_notifications_and_audit_persist_store_context(): void
    {
        $otherAdmin = $this->userWithRole('B2C_STORE_ADMIN', 'tenant-b-admin@example.test');
        DB::table('user_store_roles')->insert([
            'user_id' => $otherAdmin->id,
            'store_id' => $this->storeB,
            'role_id' => (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = $this->order($this->storeA, 'TEN-NOTIFY');
        app(DashboardOperationalNotifier::class)->orderCreated($order);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->storeAdmin->id,
            'store_id' => $this->storeA,
            'target_channel' => 'b2c',
            'type' => 'order.created',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $otherAdmin->id,
            'type' => 'order.created',
        ]);

        Sanctum::actingAs($this->storeAdmin);
        $this->postJson('/api/v1/admin/inventory/'.$this->inventoryA.'/adjust', [
            'quantity_delta' => 1,
            'reason' => 'tenant audit',
        ])->assertOk();

        $this->assertDatabaseHas('stock_movements', [
            'inventory_id' => $this->inventoryA,
            'store_id' => $this->storeA,
            'type' => 'adjustment',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'inventory.adjusted',
            'store_id' => $this->storeA,
        ]);
    }

    private function store(string $type, string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', $type)->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{0:int,1:int,2:int} */
    private function operationalFixture(int $storeId, string $channel, int $unitId, string $sku, float $quantity): array
    {
        $catalog = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => $channel,
            'code' => 'default',
            'name' => $sku.' Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog,
            'unit_id' => $unitId,
            'sku' => $sku,
            'name' => $sku,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouse = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WH-'.$sku,
            'name' => 'Warehouse '.$sku,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inventory = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouse,
            'product_id' => $product,
            'quantity' => $quantity,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$product, $warehouse, $inventory];
    }

    private function order(int $storeId, string $number): Order
    {
        $customer = Customer::query()->create([
            'type' => 'b2c',
            'name' => $number.' Customer',
        ]);

        return Order::query()->create([
            'store_id' => $storeId,
            'customer_id' => $customer->id,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 1,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 1,
        ]);
    }

    private function userWithRole(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
