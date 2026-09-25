<?php

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Customer;
use App\Models\User;
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
        $order = $this->order($store, $customer->id, 25, 'pending');
        $this->order($store, $customer->id, 9, 'cancelled');
        $this->order($store, $other->id, 100, 'pending');
        DB::table('order_items')->insert(['order_id' => $order, 'product_id' => null, 'sku_snapshot' => 'TOP-1', 'name_snapshot' => 'Top Product', 'quantity' => 2, 'unit_price' => 12.5, 'line_total' => 25, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('invoices')->insert(['customer_id' => $customer->id, 'invoice_number' => 'REP-1', 'status' => 'issued', 'currency' => 'KWD', 'total' => 25, 'balance_due' => 10, 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/b2b/dashboard')->assertOk()->assertJsonPath('open_orders', 1)->assertJsonPath('purchase_total', 25)->assertJsonPath('outstanding_balance', 10)->assertJsonPath('top_products.0.sku', 'TOP-1');
        $this->getJson('/api/v1/b2b/reports/purchases')->assertOk()->assertJsonPath('data.0.orders_count', 1)->assertJsonPath('data.0.purchase_total', 25);
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
