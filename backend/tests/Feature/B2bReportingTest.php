<?php

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerDomainResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class B2bReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_b2b_customer_receives_scoped_dashboard_and_purchase_aggregates(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$user, $customer] = $this->buyer('report-buyer@example.test', 'active');
        $other = Customer::query()->create(['type' => 'b2b', 'name' => 'Other']);
        $storeType = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $store = (int) DB::table('stores')->insertGetId(['store_type_id' => $storeType, 'code' => 'REPORT-B2B', 'name' => 'Report Store', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $unit = (int) DB::table('units')->insertGetId(['code' => 'REPORT-EA', 'name' => 'Each', 'decimal_places' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $product = (int) DB::table('products')->insertGetId(['unit_id' => $unit, 'sku' => 'TOP-1', 'name' => 'Top Product', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $order = $this->order($store, $customer->id, 25, 'pending');
        $this->order($store, $customer->id, 9, 'cancelled');
        $this->order($store, $other->id, 100, 'pending');
        DB::table('order_items')->insert(['order_id' => $order, 'product_id' => $product, 'sku_snapshot' => 'TOP-1', 'name_snapshot' => 'Top Product', 'quantity' => 2, 'unit_price' => 12.5, 'line_total' => 25, 'created_at' => now(), 'updated_at' => now()]);
        $invoice = (int) DB::table('invoices')->insertGetId(['customer_id' => $customer->id, 'invoice_number' => 'REP-1', 'status' => 'issued', 'currency' => 'KWD', 'total' => 25, 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payments')->insert(['invoice_id' => $invoice, 'provider' => 'account', 'status' => 'paid', 'amount' => 15, 'currency' => 'KWD', 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/b2b/dashboard')->assertOk()->assertJsonPath('open_orders', 1)->assertJsonPath('purchase_total', 25)->assertJsonPath('outstanding_balance', 10)->assertJsonPath('top_products.0.sku', 'TOP-1');
        $this->getJson('/api/v1/b2b/reports/purchases')->assertOk()->assertJsonPath('data.0.orders_count', 1)->assertJsonPath('data.0.purchase_total', 25);
        $this->getJson('/api/v1/b2b/products/top?limit=5')
            ->assertOk()
            ->assertJsonPath('data.0.rank', 1)
            ->assertJsonPath('data.0.product_id', $product)
            ->assertJsonPath('data.0.sku', 'TOP-1')
            ->assertJsonPath('data.0.quantity', 2)
            ->assertJsonPath('data.0.total', 25)
            ->assertJsonPath('data.0.currency', 'KWD');
    }

    public function test_top_products_use_current_catalog_state_and_store_scoped_pagination(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$user, $customer] = $this->buyer('top-products-current@example.test', 'active');

        $storeType = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $store = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeType,
            'code' => 'TOP-C13',
            'name' => 'Top Products Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalog = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $store,
            'channel' => 'b2b',
            'code' => 'top-c13',
            'name' => 'Top C13 Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unit = (int) DB::table('units')->insertGetId([
            'code' => 'TOP-C13-EA',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog,
            'unit_id' => $unit,
            'sku' => 'TOP-C13-1',
            'name' => 'Ranked Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $store,
            'product_id' => $product,
            'price' => 12,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $tier = (int) DB::table('b2b_price_tiers')->insertGetId([
            'code' => 'TOP-C13-GOLD',
            'name' => 'Top C13 Gold',
            'priority' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('b2b_price_rules')->insert([
            'price_tier_id' => $tier,
            'store_id' => $store,
            'product_id' => $product,
            'unit_price' => 7.25,
            'minimum_quantity' => 5,
            'ordering_increment' => 5,
            'pack_size' => 12,
            'pack_label' => 'Case 12',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouse = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $store,
            'code' => 'TOP-C13-WH',
            'name' => 'Top C13 Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouse,
            'product_id' => $product,
            'quantity' => 20,
            'reserved_quantity' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = $this->order($store, $customer->id, 36.25, 'delivered');
        DB::table('order_items')->insert([
            'order_id' => $order,
            'product_id' => $product,
            'sku_snapshot' => 'TOP-C13-OLD-SKU',
            'name_snapshot' => 'Ranked Product',
            'quantity' => 5,
            'unit_price' => 6,
            'line_total' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $olderOrder = $this->order($store, $customer->id, 10, 'delivered');
        DB::table('order_items')->insert([
            'order_id' => $olderOrder,
            'product_id' => $product,
            'sku_snapshot' => 'TOP-C13-OLDER-SKU',
            'name_snapshot' => 'Ranked Product Old Label',
            'quantity' => 2,
            'unit_price' => 5,
            'line_total' => 10,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $domain = app(CustomerDomainResolver::class)->b2b($user);
        B2bAccount::query()
            ->where('b2b_customer_id', $domain->id)
            ->update(['price_tier_id' => $tier]);

        Sanctum::actingAs($user);
        $from = now()->subDay()->toDateString();
        $to = now()->addDay()->toDateString();

        $this->getJson("/api/v1/b2b/products/top?store_id={$store}&from={$from}&to={$to}&q=Ranked&sort=value&page=1&per_page=1")
            ->assertOk()
            ->assertJsonPath('sort', 'value')
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('data.0.rank', 1)
            ->assertJsonPath('data.0.product_id', $product)
            ->assertJsonPath('data.0.store_id', $store)
            ->assertJsonPath('data.0.sku', 'TOP-C13-1')
            ->assertJsonPath('data.0.quantity', 7)
            ->assertJsonPath('data.0.total', 40)
            ->assertJsonPath('data.0.account_price', 7.25)
            ->assertJsonPath('data.0.minimum_order_quantity', 5)
            ->assertJsonPath('data.0.ordering_increment', 5)
            ->assertJsonPath('data.0.pack_size', 12)
            ->assertJsonPath('data.0.pack_label', 'Case 12')
            ->assertJsonPath('data.0.availability_state', 'AVAILABLE')
            ->assertJsonPath('data.0.can_repurchase', true)
            ->assertJsonPath('data.0.unavailable_reason', null);

        DB::table('inventories')
            ->where('product_id', $product)
            ->update(['reserved_quantity' => 20, 'updated_at' => now()]);

        $this->getJson("/api/v1/b2b/products/top?store_id={$store}&q=Ranked")
            ->assertOk()
            ->assertJsonPath('data.0.account_price', 7.25)
            ->assertJsonPath('data.0.availability_state', 'OUT_OF_STOCK')
            ->assertJsonPath('data.0.can_repurchase', false)
            ->assertJsonPath('data.0.unavailable_reason', 'OUT_OF_STOCK');
    }

    public function test_reporting_rejects_unapproved_account_and_invalid_date_range(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$pending] = $this->buyer('report-pending@example.test', 'pending');
        Sanctum::actingAs($pending);
        $this->getJson('/api/v1/b2b/dashboard')->assertForbidden();

        [$active] = $this->buyer('report-active@example.test', 'active');
        Sanctum::actingAs($active);
        $this->getJson('/api/v1/b2b/reports/purchases?from=2026-09-25&to=2026-09-24')->assertUnprocessable();
        $this->getJson('/api/v1/b2b/products/top?from=2026-09-25&to=2026-09-24')->assertUnprocessable();
    }

    private function buyer(string $email, string $status): array
    {
        $user = User::query()->create(['name' => 'Buyer', 'email' => $email, 'password' => 'password', 'is_active' => true]);
        $customer = Customer::query()->create(['user_id' => $user->id, 'type' => 'b2b', 'name' => 'Buyer', 'email' => $email]);
        B2bAccount::query()->create(['customer_id' => $customer->id, 'company_name' => 'Buyer Co', 'status' => $status]);

        return [$user, $customer];
    }

    private function order(int $storeId, int $customerId, float $total, string $status): int
    {
        return (int) DB::table('orders')->insertGetId(['store_id' => $storeId, 'customer_id' => $customerId, 'order_number' => uniqid('REP-', true), 'channel' => 'b2b', 'status' => $status, 'currency' => 'KWD', 'subtotal' => $total, 'discount_total' => 0, 'delivery_total' => 0, 'grand_total' => $total, 'created_at' => now(), 'updated_at' => now()]);
    }
}
