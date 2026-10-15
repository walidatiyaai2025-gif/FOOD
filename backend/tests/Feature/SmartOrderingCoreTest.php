<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Contracts\OrderNotificationBridge;
use App\Models\Order;
use App\Models\Role;
use App\Models\SmartOrderingBranch;
use App\Models\SmartOrderingOrderContext;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SmartOrderingCoreTest extends TestCase
{
    use RefreshDatabase;

    private int $storeId;

    private int $productId;

    private int $extraProductId;

    private int $warehouseId;

    private SmartOrderingBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);

        $b2cTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-SMART-ORDERING',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'SMART-ORDERING-B2C',
            'name' => 'Smart Ordering Restaurant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $this->storeId,
            'channel' => 'b2c',
            'code' => 'smart-ordering',
            'name' => 'Smart Ordering Menu',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalogId,
            'name' => 'Meals',
            'slug' => 'smart-ordering-meals',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->productId = $this->product($catalogId, $categoryId, $unitId, 'SO-MEAL', 'Meal', 1.250);
        $this->extraProductId = $this->product($catalogId, $categoryId, $unitId, 'SO-EXTRA', 'Extra', 0.500);

        $this->warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $this->storeId,
            'code' => 'SO-WH',
            'name' => 'Smart Ordering Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$this->productId, $this->extraProductId] as $productId) {
            DB::table('inventories')->insert([
                'warehouse_id' => $this->warehouseId,
                'product_id' => $productId,
                'quantity' => 50,
                'reserved_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->branch = SmartOrderingBranch::query()->create([
            'public_id' => (string) Str::uuid(),
            'store_id' => $this->storeId,
            'name' => 'Kuwait City Branch',
            'timezone' => 'Asia/Kuwait',
            'is_active' => true,
            'accepting_orders' => true,
            'supports_dine_in' => true,
            'supports_pickup' => true,
            'supports_delivery' => true,
            'allow_scheduled' => true,
            'pickup_lead_minutes' => 0,
            'max_schedule_days' => 7,
            'service_charge' => 0,
        ]);
    }

    public function test_checkout_uses_authoritative_server_prices_and_server_priced_extras(): void
    {
        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 2, [
            'extras' => [['product_id' => $this->extraProductId, 'quantity' => 1, 'price' => 0.001]],
            'client_modifier_total' => 0.001,
        ]);

        $response = $this->checkout($cart, $token, 'smart-ordering-authority-0001', [
            'fulfillment_mode' => 'pickup',
            'subtotal' => 0.001,
            'grand_total' => 0.001,
        ])->assertCreated();

        $this->assertSame(3.5, (float) $response->json('data.subtotal'));
        $this->assertSame(3.5, (float) $response->json('data.grand_total'));
        $this->assertDatabaseHas('order_items', [
            'product_id' => $this->productId,
            'quantity' => 2,
            'unit_price' => 1.250,
        ]);
        $this->assertDatabaseHas('order_items', [
            'product_id' => $this->extraProductId,
            'quantity' => 2,
            'unit_price' => 0.500,
        ]);
    }

    public function test_checkout_rejects_closed_branch_and_revalidates_operational_policy(): void
    {
        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 1);

        DB::table('smart_ordering_branch_hours')->insert([
            'branch_id' => $this->branch->id,
            'day_of_week' => now('Asia/Kuwait')->dayOfWeek,
            'is_closed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->checkout($cart, $token, 'smart-ordering-closed-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertConflict();

        $this->assertDatabaseCount('orders', 0);

        DB::table('smart_ordering_branch_hours')->delete();
        $this->branch->update(['accepting_orders' => false]);

        $this->checkout($cart, $token, 'smart-ordering-paused-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertConflict();
    }

    public function test_delivery_zone_fee_and_minimum_are_enforced(): void
    {
        DB::table('smart_ordering_delivery_zones')->insert([
            'branch_id' => $this->branch->id,
            'name' => 'Central Kuwait',
            'center_latitude' => 29.3759000,
            'center_longitude' => 47.9774000,
            'radius_km' => 5,
            'delivery_fee' => 0.750,
            'minimum_order' => 2.000,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$smallCart, $smallToken] = $this->cart();
        $this->addItem($smallCart, $smallToken, 1);
        $this->checkout($smallCart, $smallToken, 'smart-ordering-zone-min-0001', [
            'fulfillment_mode' => 'delivery',
            'delivery_address' => 'Kuwait City',
            'delivery_latitude' => 29.3759,
            'delivery_longitude' => 47.9774,
        ])->assertConflict();

        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 2);
        $this->checkout($cart, $token, 'smart-ordering-zone-ok-0001', [
            'fulfillment_mode' => 'delivery',
            'delivery_address' => 'Kuwait City',
            'delivery_latitude' => 29.3759,
            'delivery_longitude' => 47.9774,
        ])
            ->assertCreated()
            ->assertJsonPath('data.delivery_total', 0.75)
            ->assertJsonPath('data.grand_total', 3.25);

        [$outsideCart, $outsideToken] = $this->cart();
        $this->addItem($outsideCart, $outsideToken, 2);
        $this->checkout($outsideCart, $outsideToken, 'smart-ordering-zone-out-0001', [
            'fulfillment_mode' => 'delivery',
            'delivery_address' => 'Outside zone',
            'delivery_latitude' => 29.0,
            'delivery_longitude' => 48.5,
        ])->assertConflict();
    }

    public function test_dine_in_requires_valid_branch_table_token(): void
    {
        $tablePublicId = (string) Str::uuid();
        DB::table('smart_ordering_tables')->insert([
            'public_id' => $tablePublicId,
            'branch_id' => $this->branch->id,
            'label' => 'T12',
            'token_hash' => hash('sha256', 'valid-table-token'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 1);

        $this->checkout($cart, $token, 'smart-ordering-table-bad-0001', [
            'fulfillment_mode' => 'dine_in',
            'table_public_id' => $tablePublicId,
            'table_token' => 'wrong-table-token',
        ])->assertNotFound();

        $this->checkout($cart, $token, 'smart-ordering-table-ok-0001', [
            'fulfillment_mode' => 'dine_in',
            'table_public_id' => $tablePublicId,
            'table_token' => 'valid-table-token',
        ])->assertCreated();
    }

    public function test_duplicate_checkout_replays_same_order_without_duplicate_rows(): void
    {
        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 1);
        $key = 'smart-ordering-idempotent-0001';

        $first = $this->checkout($cart, $token, $key, ['fulfillment_mode' => 'pickup'])
            ->assertCreated();
        $reference = (string) $first->json('data.reference');

        $this->checkout($cart, $token, $key, ['fulfillment_mode' => 'pickup'])
            ->assertOk()
            ->assertJsonPath('data.reference', $reference)
            ->assertJsonPath('replayed', true);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('smart_ordering_order_contexts', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_checkout_rejects_stale_sold_out_cart(): void
    {
        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 2);

        DB::table('inventories')
            ->where('warehouse_id', $this->warehouseId)
            ->where('product_id', $this->productId)
            ->update(['reserved_quantity' => 50]);

        $this->checkout($cart, $token, 'smart-ordering-stale-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertConflict();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_lifecycle_validates_transitions_is_idempotent_and_consumes_stock_on_completion(): void
    {
        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 2);
        $checkout = $this->checkout($cart, $token, 'smart-ordering-lifecycle-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertCreated();
        $reference = (string) $checkout->json('data.reference');

        $admin = $this->storeAdmin($this->storeId);

        $this->actingAs($admin)->post(route('admin.smart-ordering.orders.transition', $reference), [
            'status' => 'PREPARING',
            'transition_key' => 'invalid-direct-preparing',
        ])->assertSessionHasErrors('status');

        foreach (['CONFIRMED', 'PREPARING', 'READY', 'COMPLETED'] as $index => $status) {
            $this->actingAs($admin)->post(route('admin.smart-ordering.orders.transition', $reference), [
                'status' => $status,
                'transition_key' => 'valid-transition-'.$index,
            ])->assertRedirect();
        }

        $this->assertDatabaseHas('smart_ordering_order_contexts', [
            'public_reference' => $reference,
            'lifecycle_status' => 'COMPLETED',
        ]);
        $context = SmartOrderingOrderContext::query()->where('public_reference', $reference)->firstOrFail();
        $this->assertSame('delivered', (string) Order::query()->findOrFail($context->order_id)->status);
        $this->assertDatabaseHas('inventories', [
            'warehouse_id' => $this->warehouseId,
            'product_id' => $this->productId,
            'quantity' => 48,
            'reserved_quantity' => 0,
        ]);

        $eventCount = DB::table('smart_ordering_order_events')->where('order_context_id', $context->id)->count();
        $this->actingAs($admin)->post(route('admin.smart-ordering.orders.transition', $reference), [
            'status' => 'COMPLETED',
            'transition_key' => 'duplicate-terminal',
        ])->assertRedirect();
        $this->assertSame($eventCount, DB::table('smart_ordering_order_events')->where('order_context_id', $context->id)->count());
    }

    public function test_admin_cannot_manage_foreign_tenant_branch(): void
    {
        $admin = $this->storeAdmin($this->storeId);
        $b2cTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $foreignStore = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'SMART-ORDERING-FOREIGN',
            'name' => 'Foreign Restaurant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignBranch = SmartOrderingBranch::query()->create([
            'public_id' => (string) Str::uuid(),
            'store_id' => $foreignStore,
            'name' => 'Foreign Branch',
            'timezone' => 'Asia/Kuwait',
            'is_active' => true,
            'accepting_orders' => true,
            'supports_pickup' => true,
            'pickup_lead_minutes' => 0,
            'max_schedule_days' => 7,
            'service_charge' => 0,
        ]);

        $this->actingAs($admin)->put(route('admin.smart-ordering.branches.update', $foreignBranch->public_id), [
            'name' => 'Hacked Branch',
            'timezone' => 'Asia/Kuwait',
            'is_active' => 1,
            'accepting_orders' => 1,
            'supports_pickup' => 1,
            'pickup_lead_minutes' => 0,
            'max_schedule_days' => 7,
            'service_charge' => 0,
        ])->assertNotFound();

        $this->assertDatabaseHas('smart_ordering_branches', [
            'id' => $foreignBranch->id,
            'name' => 'Foreign Branch',
        ]);
    }

    public function test_notification_failure_never_rolls_back_valid_order(): void
    {
        $this->app->bind(OrderNotificationBridge::class, function () {
            return new class implements OrderNotificationBridge {
                public function notify(Order $order, SmartOrderingOrderContext $context): void
                {
                    throw new \RuntimeException('Simulated downstream WhatsApp failure.');
                }
            };
        });

        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 1);

        $response = $this->checkout($cart, $token, 'smart-ordering-notify-fail-0001', [
            'fulfillment_mode' => 'pickup',
        ])->assertCreated();

        $this->assertDatabaseHas('smart_ordering_order_contexts', [
            'public_reference' => $response->json('data.reference'),
            'notification_status' => 'failed',
        ]);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_public_tracking_exposes_safe_reference_and_timeline_without_customer_pii(): void
    {
        [$cart, $token] = $this->cart();
        $this->addItem($cart, $token, 1);

        $checkout = $this->checkout($cart, $token, 'smart-ordering-track-0001', [
            'fulfillment_mode' => 'pickup',
            'name' => 'Tracking Secret Name',
            'phone' => '+96555555555',
        ])->assertCreated();

        $reference = (string) $checkout->json('data.reference');
        $tracking = $this->getJson('/api/smart-ordering/orders/'.$reference)
            ->assertOk()
            ->assertJsonPath('data.reference', $reference)
            ->assertJsonPath('data.status', 'NEW')
            ->assertJsonPath('data.fulfillment_mode', 'pickup');

        $body = $tracking->getContent();
        $this->assertStringNotContainsString('Tracking Secret Name', $body);
        $this->assertStringNotContainsString('+96555555555', $body);
        $this->assertStringNotContainsString('"customer_id"', $body);
    }

    private function product(
        int $catalogId,
        int $categoryId,
        int $unitId,
        string $sku,
        string $name,
        float $price,
    ): int {
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'sku' => $sku,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $this->storeId,
            'product_id' => $productId,
            'price' => $price,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $productId;
    }

    /** @return array{0:string,1:string} */
    private function cart(): array
    {
        $response = $this->postJson('/api/smart-ordering/carts', [
            'branch_public_id' => (string) $this->branch->public_id,
        ])->assertCreated();

        return [(string) $response->json('cart.public_id'), (string) $response->json('cart.token')];
    }

    /** @param array<string,mixed> $selection */
    private function addItem(string $cart, string $token, float $quantity, array $selection = []): void
    {
        $this->withHeader('X-Smart-Cart-Token', $token)
            ->postJson('/api/smart-ordering/carts/'.$cart.'/items', [
                'product_id' => $this->productId,
                'quantity' => $quantity,
                'selection' => $selection,
            ])
            ->assertCreated();
    }

    /** @param array<string,mixed> $overrides */
    private function checkout(string $cart, string $token, string $key, array $overrides)
    {
        return $this->withHeaders([
            'X-Smart-Cart-Token' => $token,
            'Idempotency-Key' => $key,
        ])->postJson('/api/smart-ordering/carts/'.$cart.'/checkout', [
            'fulfillment_mode' => 'pickup',
            'name' => 'Smart Guest',
            'phone' => '+96550000000',
            'email' => 'smart-guest@example.test',
            ...$overrides,
        ]);
    }

    private function storeAdmin(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Smart Ordering Admin',
            'email' => 'smart-admin-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
