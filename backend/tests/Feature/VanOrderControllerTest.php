<?php

namespace Tests\Feature;

use App\Models\B2bCustomer;
use App\Models\Role;
use App\Models\User;
use App\Models\VanVisit;
use App\Services\PlatformCustomerService;
use App\Services\VanRegistryService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VanOrderControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_van_catalog_rejects_customer_outside_actor_visit_scope(): void
    {
        $actor = $this->vanActor();

        $this->getJson('/api/v1/van/customers/b2b/999/catalog?store_id=7')
            ->assertNotFound();
    }

    public function test_van_b2b_catalog_uses_platform_customer_base_price_fallback_and_explicit_tier_override(): void
    {
        $actor = $this->vanActor();
        $wholesaleStoreId = $this->store('B2B', 'VAN-1168-WHOLESALE');
        $retailStoreId = $this->store('B2C', 'VAN-1168-RETAIL');
        $productId = $this->product($wholesaleStoreId, 'VAN-1168-PRODUCT', 12.500);

        config(['foodex.platform_wholesale_store_code' => 'VAN-1168-WHOLESALE']);

        $buyer = app(PlatformCustomerService::class)->register([
            'name' => 'Van Catalog Buyer',
            'email' => 'van-catalog-1168@example.test',
            'phone' => '+201000001168',
            'password' => 'Password123!',
            'locale' => 'en',
            'store_id' => $retailStoreId,
        ]);
        $customer = B2bCustomer::query()
            ->where('user_id', $buyer->id)
            ->firstOrFail();

        VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => $customer->id,
            'store_id' => $wholesaleStoreId,
            'status' => 'planned',
        ]);

        $this->assertDatabaseMissing('b2b_price_rules', [
            'store_id' => $wholesaleStoreId,
            'product_id' => $productId,
        ]);

        $this->getJson(
            '/api/v1/van/customers/b2b/'.$customer->id.'/catalog?store_id='.$wholesaleStoreId,
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $productId)
            ->assertJsonPath('data.0.unit_price', 12.5)
            ->assertJsonPath('data.0.minimum_quantity', 1)
            ->assertJsonPath('data.0.ordering_increment', 1)
            ->assertJsonPath('currency', 'EGP');

        $tierId = (int) DB::table('b2b_accounts')
            ->where('b2b_customer_id', $customer->id)
            ->value('price_tier_id');

        DB::table('b2b_price_rules')->insert([
            'price_tier_id' => $tierId,
            'store_id' => $wholesaleStoreId,
            'product_id' => $productId,
            'unit_price' => 9.250,
            'minimum_quantity' => 3,
            'ordering_increment' => 2,
            'pack_size' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson(
            '/api/v1/van/customers/b2b/'.$customer->id.'/catalog?store_id='.$wholesaleStoreId,
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.unit_price', 9.25)
            ->assertJsonPath('data.0.minimum_quantity', 3)
            ->assertJsonPath('data.0.ordering_increment', 2);
    }

    public function test_van_order_creation_requires_idempotency_key_before_checkout(): void
    {
        $actor = $this->vanActor();

        VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => 999,
            'store_id' => 7,
            'status' => 'planned',
        ]);

        $this->postJson('/api/v1/van/customers/b2b/999/orders', [
            'store_id' => 7,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['Idempotency-Key']);
    }

    public function test_van_order_feed_is_empty_without_actor_customer_scope(): void
    {
        $actor = $this->vanActor();

        $this->getJson('/api/v1/van/orders')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    private function store(string $type, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(int $storeId, string $sku, float $price): int
    {
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2b',
            'code' => 'van-1168',
            'name' => 'Van 1168 Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-VAN-1168',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => $sku,
            'barcode' => '116800000001',
            'name' => 'Van Catalog Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => $price,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WH-VAN-1168',
            'name' => 'Van 1168 Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 100,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $productId;
    }

    private function vanActor(): User
    {
        $this->seed(CoreReferenceSeeder::class);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail());

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'ORDER-VAN-'.$actor->id]);
        $registry->assign($actor, $van, [
            'representative_user_id' => $actor->id,
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);

        Sanctum::actingAs($actor, ['app:van']);

        return $actor;
    }
}
