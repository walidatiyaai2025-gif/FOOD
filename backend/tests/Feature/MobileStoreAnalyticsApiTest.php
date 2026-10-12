<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileStoreAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        $this->now = CarbonImmutable::parse('2026-10-12 21:00:00', 'Asia/Kuwait');
        CarbonImmutable::setTestNow($this->now);
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_mobile_store_analytics_requires_authentication_and_retail_entitlement(): void
    {
        $this->getJson('/api/v1/mobile/store-analytics')
            ->assertUnauthorized();

        $user = $this->user('not-a-merchant@example.test');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/mobile/store-analytics')
            ->assertForbidden();
    }

    public function test_authorized_retail_merchant_receives_exact_egp_store_analytics_contract(): void
    {
        $user = $this->user('analytics-manager@example.test');
        $store = $this->retailStore('ANALYTICS-A', 'Analytics A', 'storage/stores/analytics-a/logo.png');
        $product = $this->product($store, 'TOP-SKU', 10, 5, 5, $this->now->subDays(40));
        $this->assignManager($user, $store);

        $this->order(
            $store,
            $product,
            'CURRENT-DELIVERED',
            'delivered',
            100,
            10,
            $this->now->setTime(20, 15),
        );
        $this->order(
            $store,
            $product,
            'CURRENT-CANCELLED',
            'cancelled',
            50,
            5,
            $this->now->setTime(18, 10),
        );
        $this->order(
            $store,
            $product,
            'PREVIOUS-DELIVERED',
            'delivered',
            80,
            8,
            $this->now->subDays(7)->setTime(13, 0),
        );

        Sanctum::actingAs($user);

        $response = $this->getJson(
            '/api/v1/mobile/store-analytics'
            .'?retail_store_id='.$store
            .'&from=2026-10-06&to=2026-10-12',
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.store.id', $store)
            ->assertJsonPath('data.store.name', 'Analytics A')
            ->assertJsonPath('data.store.logo_url', url('/storage/stores/analytics-a/logo.png'))
            ->assertJsonPath('data.store.currency', 'EGP')
            ->assertJsonPath('data.period.from', '2026-10-06')
            ->assertJsonPath('data.period.to', '2026-10-12')
            ->assertJsonPath('data.previous_period.from', '2026-09-29')
            ->assertJsonPath('data.previous_period.to', '2026-10-05')
            ->assertJsonPath('data.metrics.revenue.value', 100)
            ->assertJsonPath('data.metrics.revenue.previous_value', 80)
            ->assertJsonPath('data.metrics.revenue.delta_percent', 25)
            ->assertJsonPath('data.metrics.orders.value', 2)
            ->assertJsonPath('data.metrics.orders.previous_value', 1)
            ->assertJsonPath('data.metrics.orders.delta_percent', 100)
            ->assertJsonPath('data.metrics.average_order_value.value', 100)
            ->assertJsonPath('data.metrics.average_order_value.previous_value', 80)
            ->assertJsonPath('data.metrics.average_order_value.delta_percent', 25)
            ->assertJsonPath('data.order_facts.completed.value', 1)
            ->assertJsonPath('data.order_facts.cancelled.value', 1)
            ->assertJsonPath('data.order_facts.late.supported', false)
            ->assertJsonPath('data.order_facts.late.value', null)
            ->assertJsonCount(7, 'data.daily_series')
            ->assertJsonPath('data.top_products.0.product_id', $product)
            ->assertJsonPath('data.top_products.0.revenue', 100)
            ->assertJsonPath('data.top_products.0.contribution_percent', 100)
            ->assertJsonPath('data.top_products.0.currency', 'EGP')
            ->assertJsonPath('data.peak_activity.peak_window.start_hour', 20)
            ->assertJsonPath('data.peak_activity.peak_window.source', 'orders.created_at')
            ->assertJsonPath('data.peak_activity.peak_window.currency', 'EGP')
            ->assertJsonPath('data.freshness.is_stale', false)
            ->assertJsonPath('data.insight_state.has_new_insights', true)
            ->assertJsonPath('data.insight_state.lifecycle.request_parameter', 'seen_insight_version')
            ->assertJsonPath('data.data_state', 'ready');

        $payload = $response->json('data');
        $this->assertIsArray($payload);
        $this->assertNotEmpty($payload['insights']);
        $this->assertSame(64, strlen((string) $payload['insight_state']['version']));
        $this->assertSame(
            count($payload['insights']),
            $payload['insight_state']['unread_count'],
        );

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('"currency":"KWD"', $encoded);
        $this->assertStringNotContainsString('"wholesale_account"', $encoded);
        $this->assertStringNotContainsString('"purchase_plan"', $encoded);
        $this->assertStringNotContainsString('"commercial"', $encoded);
        $this->assertStringNotContainsString('"mapping"', $encoded);
    }

    public function test_seen_insight_version_clears_new_badge_without_mutating_server_state(): void
    {
        $user = $this->user('analytics-badge@example.test');
        $store = $this->retailStore('ANALYTICS-BADGE', 'Analytics Badge');
        $product = $this->product($store, 'BADGE-SKU', 10, 5, 4, $this->now->subDays(40));
        $this->assignManager($user, $store);
        $this->order(
            $store,
            $product,
            'BADGE-ORDER',
            'delivered',
            40,
            4,
            $this->now->setTime(19, 0),
        );

        Sanctum::actingAs($user);

        $first = $this->getJson('/api/v1/mobile/store-analytics?retail_store_id='.$store)
            ->assertOk()
            ->assertJsonPath('data.insight_state.has_new_insights', true);

        $version = (string) $first->json('data.insight_state.version');

        $this->getJson(
            '/api/v1/mobile/store-analytics?retail_store_id='.$store
            .'&seen_insight_version='.$version,
        )
            ->assertOk()
            ->assertJsonPath('data.insight_state.version', $version)
            ->assertJsonPath('data.insight_state.has_new_insights', false)
            ->assertJsonPath('data.insight_state.unread_count', 0);
    }

    public function test_multi_store_context_is_explicit_and_cross_store_requests_are_forbidden(): void
    {
        $user = $this->user('analytics-multi@example.test');
        $other = $this->user('analytics-other@example.test');
        $storeA = $this->retailStore('ANALYTICS-MULTI-A', 'Multi A');
        $storeB = $this->retailStore('ANALYTICS-MULTI-B', 'Multi B');
        $storeC = $this->retailStore('ANALYTICS-OTHER', 'Other Merchant');

        $this->assignManager($user, $storeA);
        $this->assignManager($user, $storeB);
        $this->assignManager($other, $storeC);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/mobile/store-analytics')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('retail_store_id');

        $this->getJson('/api/v1/mobile/store-analytics?retail_store_id='.$storeC)
            ->assertForbidden();

        $this->getJson('/api/v1/mobile/store-analytics?retail_store_id='.$storeB)
            ->assertOk()
            ->assertJsonPath('data.store.id', $storeB);
    }

    public function test_legacy_wholesale_store_id_cannot_override_server_resolved_retail_context(): void
    {
        $user = $this->user('analytics-context@example.test');
        $retail = $this->retailStore('ANALYTICS-RETAIL', 'Retail Authority');
        $wholesale = $this->store('B2B', 'ANALYTICS-WHOLESALE', 'Wholesale Context');
        $this->assignManager($user, $retail);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/mobile/store-analytics?store_id='.$wholesale)
            ->assertOk()
            ->assertJsonPath('data.store.id', $retail)
            ->assertJsonPath('data.store.currency', 'EGP');
    }

    public function test_empty_and_stale_states_are_explicit(): void
    {
        $emptyUser = $this->user('analytics-empty@example.test');
        $emptyStore = $this->retailStore('ANALYTICS-EMPTY', 'Empty Analytics');
        $this->assignManager($emptyUser, $emptyStore);

        Sanctum::actingAs($emptyUser);

        $this->getJson('/api/v1/mobile/store-analytics?retail_store_id='.$emptyStore)
            ->assertOk()
            ->assertJsonPath('data.data_state', 'no_data')
            ->assertJsonPath('data.freshness.state', 'no_data')
            ->assertJsonPath('data.metrics.revenue.value', 0)
            ->assertJsonPath('data.metrics.orders.value', 0)
            ->assertJsonCount(0, 'data.top_products');

        $staleUser = $this->user('analytics-stale@example.test');
        $staleStore = $this->retailStore('ANALYTICS-STALE', 'Stale Analytics');
        $this->assignManager($staleUser, $staleStore);
        $this->product(
            $staleStore,
            'STALE-SKU',
            12,
            6,
            2,
            $this->now->subDays(40),
            $this->now->subDays(2),
        );

        Sanctum::actingAs($staleUser);

        $this->getJson('/api/v1/mobile/store-analytics?retail_store_id='.$staleStore)
            ->assertOk()
            ->assertJsonPath('data.freshness.is_stale', true)
            ->assertJsonPath('data.freshness.state', 'stale')
            ->assertJsonPath('data.data_state', 'stale');
    }

    public function test_date_range_validation_rejects_reverse_and_overlong_windows(): void
    {
        $user = $this->user('analytics-range@example.test');
        $store = $this->retailStore('ANALYTICS-RANGE', 'Range Analytics');
        $this->assignManager($user, $store);
        Sanctum::actingAs($user);

        $this->getJson(
            '/api/v1/mobile/store-analytics?retail_store_id='.$store
            .'&from=2026-10-12&to=2026-10-01',
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from');

        $this->getJson(
            '/api/v1/mobile/store-analytics?retail_store_id='.$store
            .'&from=2026-08-01&to=2026-10-12',
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from');
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => $email,
            'email' => $email,
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }

    private function assignManager(User $user, int $storeId): void
    {
        $roleId = (int) DB::table('roles')
            ->where('code', 'B2C_STORE_ADMIN')
            ->where('is_active', true)
            ->value('id');

        DB::table('user_store_roles')->updateOrInsert(
            [
                'user_id' => $user->getKey(),
                'store_id' => $storeId,
                'role_id' => $roleId,
            ],
            [
                'created_at' => $this->now->utc()->toDateTimeString(),
                'updated_at' => $this->now->utc()->toDateTimeString(),
            ],
        );
    }

    private function retailStore(
        string $code,
        string $name,
        ?string $logoPath = null,
    ): int {
        return $this->store('B2C', $code, $name, $logoPath);
    }

    private function store(
        string $type,
        string $code,
        string $name,
        ?string $logoPath = null,
    ): int {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')
                ->where('code', $type)
                ->value('id'),
            'code' => $code,
            'name' => $name,
            'logo_path' => $logoPath,
            'is_active' => true,
            'created_at' => $this->now->utc()->toDateTimeString(),
            'updated_at' => $this->now->utc()->toDateTimeString(),
        ]);
    }

    private function product(
        int $storeId,
        string $sku,
        float $price,
        ?float $cost,
        float $quantity,
        CarbonImmutable $listedAt,
        ?CarbonImmutable $inventoryUpdatedAt = null,
    ): int {
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'catalog-'.strtolower($sku),
            'name' => $sku.' Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => $listedAt->utc()->toDateTimeString(),
            'updated_at' => $listedAt->utc()->toDateTimeString(),
        ]);
        $unitId = (int) DB::table('units')->orderBy('id')->value('id');
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => $sku,
            'name' => $sku,
            'is_active' => true,
            'created_at' => $listedAt->utc()->toDateTimeString(),
            'updated_at' => $listedAt->utc()->toDateTimeString(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => $price,
            'cost_price' => $cost,
            'is_active' => true,
            'created_at' => $listedAt->utc()->toDateTimeString(),
            'updated_at' => $listedAt->utc()->toDateTimeString(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WH-'.$sku,
            'name' => 'Warehouse '.$sku,
            'is_active' => true,
            'created_at' => $listedAt->utc()->toDateTimeString(),
            'updated_at' => $listedAt->utc()->toDateTimeString(),
        ]);
        $inventoryAt = ($inventoryUpdatedAt ?? $this->now)->utc()->toDateTimeString();
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => $quantity,
            'reserved_quantity' => 0,
            'created_at' => $inventoryAt,
            'updated_at' => $inventoryAt,
        ]);

        return $productId;
    }

    private function order(
        int $storeId,
        int $productId,
        string $number,
        string $status,
        float $grandTotal,
        float $quantity,
        CarbonImmutable $at,
    ): int {
        $timestamp = $at->utc()->toDateTimeString();
        $customerId = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => $number.' Customer',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $orderId = (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'order_number' => $number.'-'.$storeId,
            'channel' => 'b2c',
            'status' => $status,
            'currency' => 'KWD',
            'subtotal' => $grandTotal,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => $grandTotal,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $productId,
            'sku_snapshot' => 'SKU-'.$productId,
            'name_snapshot' => 'Product '.$productId,
            'quantity' => $quantity,
            'unit_price' => $quantity > 0 ? $grandTotal / $quantity : 0,
            'line_total' => $grandTotal,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $orderId;
    }
}
