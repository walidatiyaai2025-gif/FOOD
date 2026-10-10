<?php

namespace Tests\Feature;

use App\Models\B2bCustomer;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Models\VanVisit;
use App\Services\InvoiceService;
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

    public function test_van_order_feed_uses_active_order_ownership_without_visit_scope(): void
    {
        $actorA = $this->vanActor();
        [$ownedOrder] = $this->assignedB2bOrder($actorA, 'A');

        $actorB = $this->vanActor();
        [$otherOrder] = $this->assignedB2bOrder($actorB, 'B');

        Sanctum::actingAs($actorA, ['app:van']);

        $this->getJson('/api/v1/van/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownedOrder->id)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.scope', 'active_van_assignment');

        $this->assertNotSame($ownedOrder->id, $otherOrder->id);
    }

    public function test_exact_van_order_detail_exposes_commercial_finance_collection_and_timeline_context(): void
    {
        $actor = $this->vanActor();
        [$order, $b2bCustomerId, $storeId, $assignmentId, $vanId] = $this->assignedB2bOrder($actor, 'DETAIL');
        $productId = $this->product($storeId, 'W03-DETAIL-PRODUCT', 10.000);

        DB::table('orders')->where('id', $order->id)->update([
            'address_id' => 77,
            'delivery_address_snapshot' => json_encode([
                'version' => 1,
                'address_id' => 77,
                'label' => 'Warehouse gate',
                'recipient_name' => 'W03 Customer DETAIL',
                'delivery_phone' => '+96550000077',
                'line1' => 'Block 3',
                'street' => 'Street 17',
                'area' => 'Shuwaikh',
                'city' => 'Kuwait City',
                'country_code' => 'KW',
                'latitude' => 29.3375,
                'longitude' => 47.6581,
            ], JSON_THROW_ON_ERROR),
            'delivery_latitude' => 29.3375,
            'delivery_longitude' => 47.6581,
            'updated_at' => now(),
        ]);

        $order->refresh();

        DB::table('order_items')->insert([
            'order_id' => $order->id,
            'product_id' => $productId,
            'sku_snapshot' => 'W03-DETAIL-PRODUCT',
            'name_snapshot' => 'W03 Detail Product',
            'quantity' => 2,
            'quantity_conversion_factor' => 1,
            'unit_price' => 10,
            'line_total' => 20,
            'currency' => 'EGP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $paymentId = (int) DB::table('payments')->insertGetId([
            'order_id' => $order->id,
            'invoice_id' => null,
            'provider' => 'cash_on_delivery',
            'provider_reference' => 'W03-PAYMENT',
            'status' => 'paid',
            'amount' => 8,
            'currency' => 'EGP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invoice = app(InvoiceService::class)->issueForOrder($order, $actor);

        $accountId = (int) DB::table('collection_accounts')->insertGetId([
            'actor_type' => 'van',
            'actor_id' => $vanId,
            'store_id' => $storeId,
            'currency' => 'EGP',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $transactionId = (int) DB::table('collection_transactions')->insertGetId([
            'collection_account_id' => $accountId,
            'payment_id' => $paymentId,
            'idempotency_key' => 'w03-detail-collection-'.$order->id,
            'type' => 'collection',
            'status' => 'posted',
            'amount' => 8,
            'currency' => 'EGP',
            'source' => 'van',
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('collection_allocations')->insert([
            'collection_transaction_id' => $transactionId,
            'invoice_id' => $invoice->id,
            'amount' => 8,
            'currency' => 'EGP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_status_history')->insert([
            'order_id' => $order->id,
            'store_id' => $storeId,
            'user_id' => $actor->id,
            'from_status' => 'pending',
            'to_status' => 'confirmed',
            'note' => 'W03 detail timeline',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/van/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.customer.id', $b2bCustomerId)
            ->assertJsonPath('data.customer.name', 'W03 Customer DETAIL')
            ->assertJsonPath('data.address_id', 77)
            ->assertJsonPath(
                'data.delivery_address.formatted',
                'Warehouse gate, Street 17, Shuwaikh, Kuwait City, KW',
            )
            ->assertJsonPath('data.delivery_address.latitude', 29.3375)
            ->assertJsonPath('data.delivery_address.longitude', 47.6581)
            ->assertJsonPath('data.delivery_address.has_coordinates', true)
            ->assertJsonPath('data.items.0.sku', 'W03-DETAIL-PRODUCT')
            ->assertJsonPath('data.invoice.id', $invoice->id)
            ->assertJsonPath('data.payments.0.id', $paymentId)
            ->assertJsonPath('data.collections.0.amount', 8)
            ->assertJsonPath('data.van_execution_status', 'assigned')
            ->assertJsonFragment([
                'source' => 'van_assignment',
                'order_van_assignment_id' => $assignmentId,
                'van_id' => $vanId,
            ]);
    }

    public function test_van_order_detail_rejects_cross_van_stale_and_tampered_context(): void
    {
        $actorA = $this->vanActor();
        [$order, , $storeId, $assignmentId] = $this->assignedB2bOrder($actorA, 'SECURITY');

        $actorB = $this->vanActor();

        $this->getJson('/api/v1/van/orders/'.$order->id)
            ->assertNotFound();

        Sanctum::actingAs($actorA, ['app:van']);

        $this->getJson('/api/v1/van/orders/'.$order->id.'?store_id='.($storeId + 999))
            ->assertNotFound();

        $this->getJson('/api/v1/van/orders/'.$order->id.'?channel=b2c')
            ->assertNotFound();

        DB::table('order_van_assignments')
            ->where('id', $assignmentId)
            ->update([
                'status' => 'reassigned',
                'ended_at' => now(),
                'updated_at' => now(),
            ]);

        $this->getJson('/api/v1/van/orders/'.$order->id)
            ->assertNotFound();

        $this->assertNotSame($actorA->id, $actorB->id);
    }

    /** @return array{0:Order,1:int,2:int,3:int,4:int} */
    private function assignedB2bOrder(User $actor, string $suffix): array
    {
        $storeId = $this->store('B2B', 'W03-'.$suffix);
        $legacy = Customer::query()->create([
            'name' => 'W03 Customer '.$suffix,
            'type' => 'b2b',
            'email' => strtolower($suffix).'@w03.example.test',
        ]);
        $b2bCustomerId = (int) DB::table('b2b_customers')->insertGetId([
            'legacy_customer_id' => $legacy->id,
            'name' => 'W03 Customer '.$suffix,
            'email' => strtolower($suffix).'@w03.example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = Order::query()->create([
            'store_id' => $storeId,
            'customer_id' => $legacy->id,
            'b2b_customer_id' => $b2bCustomerId,
            'order_number' => 'W03-'.$suffix.'-'.$actor->id,
            'channel' => 'b2b',
            'status' => 'pending',
            'currency' => 'EGP',
            'subtotal' => 20,
            'discount_total' => 0,
            'delivery_total' => 0,
            'tax_total' => 0,
            'grand_total' => 20,
            'payment_method' => 'cash_on_delivery',
        ]);

        $vanId = (int) DB::table('van_assignments')
            ->where('representative_user_id', $actor->id)
            ->where('status', 'active')
            ->value('van_id');

        $assignmentId = (int) DB::table('order_van_assignments')->insertGetId([
            'order_id' => $order->id,
            'van_id' => $vanId,
            'van_assignment_id' => DB::table('van_assignments')
                ->where('representative_user_id', $actor->id)
                ->where('van_id', $vanId)
                ->where('status', 'active')
                ->value('id'),
            'status' => 'active',
            'source' => 'smart_routing',
            'reason' => 'w03_test_fixture',
            'decision_key' => hash('sha256', 'w03-'.$suffix.'-'.$order->id.'-'.$vanId),
            'assigned_by' => $actor->id,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_van_execution_states')->insert([
            'order_van_assignment_id' => $assignmentId,
            'order_id' => $order->id,
            'van_id' => $vanId,
            'status' => 'assigned',
            'version' => 1,
            'last_transition_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$order, $b2bCustomerId, $storeId, $assignmentId, $vanId];
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
