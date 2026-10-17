<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\CartService;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\SupplyIntelligenceService;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\RetailWholesaleAccountService;
use App\Services\SmartOrderingAttributionService;
use App\Services\WholesalePrincipal;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SmartOrderingSupplyIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_completed_orders_drive_estimated_consumption_with_conversion_waste_and_partial_coverage(): void
    {
        $f = $this->fixture();
        $supply = app(SupplyIntelligenceService::class);

        $supply->createMapping($f['actor'], $f['site'], [
            'menu_item_public_id' => $f['menu_item_public_id'],
            'foodex_product_id' => $f['wholesale_product_id'],
            'quantity_per_item' => 0.5,
            'waste_factor' => 0.10,
            'effective_from' => now()->subDay(),
        ]);

        $unmapped = (string) Str::uuid();
        $this->smartOrder($f, 'delivered', 'COMPLETED', [
            ['menu_item_public_id' => $f['menu_item_public_id'], 'product_id' => $f['restaurant_product_id'], 'quantity' => 2, 'selection' => []],
            ['menu_item_public_id' => $unmapped, 'product_id' => $f['restaurant_product_id'], 'quantity' => 1, 'selection' => []],
        ], now()->subHour());

        $this->smartOrder($f, 'cancelled', 'CANCELLED', [
            ['menu_item_public_id' => $f['menu_item_public_id'], 'product_id' => $f['restaurant_product_id'], 'quantity' => 99, 'selection' => []],
        ], now()->subMinutes(45));

        $this->smartOrder($f, 'refunded', 'COMPLETED', [
            ['menu_item_public_id' => $f['menu_item_public_id'], 'product_id' => $f['restaurant_product_id'], 'quantity' => 50, 'selection' => []],
        ], now()->subMinutes(30));

        $result = $supply->consumption(
            $f['site'],
            CarbonImmutable::now('Asia/Kuwait')->subDays(2),
            CarbonImmutable::now('Asia/Kuwait'),
        );

        $this->assertSame(1, $result['total_completed_orders']);
        $this->assertSame(2, $result['total_lines']);
        $this->assertSame(1, $result['mapped_lines']);
        $this->assertSame(0.5, $result['coverage']);
        $this->assertSame('low', $result['confidence']);
        $this->assertCount(1, $result['products']);
        $this->assertEqualsWithDelta(1.1, $result['products'][0]['estimated_usage'], 0.000001);
        $this->assertStringContainsString('not counted or physical inventory', $result['disclaimer']);
    }

    public function test_consumption_respects_branch_scope_and_rejects_foreign_branch_mapping(): void
    {
        $f = $this->fixture();
        $supply = app(SupplyIntelligenceService::class);

        $supply->createMapping($f['actor'], $f['site'], [
            'menu_item_public_id' => $f['menu_item_public_id'],
            'foodex_product_id' => $f['wholesale_product_id'],
            'quantity_per_item' => 1,
            'waste_factor' => 0,
            'effective_from' => now()->subDay(),
        ]);

        $secondaryBranchId = $this->smartBranch($f['site_id'], 'SECONDARY');
        $this->smartOrder($f, 'delivered', 'COMPLETED', [
            ['menu_item_public_id' => $f['menu_item_public_id'], 'product_id' => $f['restaurant_product_id'], 'quantity' => 5, 'selection' => []],
        ], now()->subHours(2));
        $secondary = $f;
        $secondary['branch_id'] = $secondaryBranchId;
        $this->smartOrder($secondary, 'delivered', 'COMPLETED', [
            ['menu_item_public_id' => $f['menu_item_public_id'], 'product_id' => $f['restaurant_product_id'], 'quantity' => 7, 'selection' => []],
        ], now()->subHour());

        $from = CarbonImmutable::now('Asia/Kuwait')->subDay();
        $to = CarbonImmutable::now('Asia/Kuwait');

        $main = $supply->consumption($f['site'], $from, $to, $f['branch_id']);
        $second = $supply->consumption($f['site'], $from, $to, $secondaryBranchId);

        $this->assertSame(1, $main['total_completed_orders']);
        $this->assertEqualsWithDelta(5.0, $main['products'][0]['estimated_usage'], 0.000001);
        $this->assertSame(1, $second['total_completed_orders']);
        $this->assertEqualsWithDelta(7.0, $second['products'][0]['estimated_usage'], 0.000001);

        $foreignStoreId = $this->store('B2C', 'SO-FOREIGN-'.Str::upper(Str::random(6)));
        $foreignSiteId = (int) DB::table('smart_ordering_sites')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'store_id' => $foreignStoreId,
            'slug' => 'foreign-'.Str::lower(Str::random(10)),
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignBranchId = $this->smartBranch($foreignSiteId, 'FOREIGN');

        try {
            $supply->createMapping($f['actor'], $f['site'], [
                'menu_item_public_id' => $f['menu_item_public_id'],
                'branch_id' => $foreignBranchId,
                'foodex_product_id' => $f['wholesale_product_id'],
                'quantity_per_item' => 1,
                'waste_factor' => 0,
                'effective_from' => now(),
            ]);
            $this->fail('A foreign-tenant branch must not be accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_reorder_is_deterministic_uses_authoritative_stock_only_when_present_and_blocks_stale_sku(): void
    {
        $f = $this->fixture();
        $supply = app(SupplyIntelligenceService::class);

        $supply->createMapping($f['actor'], $f['site'], [
            'menu_item_public_id' => $f['menu_item_public_id'],
            'foodex_product_id' => $f['wholesale_product_id'],
            'quantity_per_item' => 1,
            'waste_factor' => 0,
            'effective_from' => now()->subDay(),
        ]);
        $this->smartOrder($f, 'delivered', 'COMPLETED', [
            ['menu_item_public_id' => $f['menu_item_public_id'], 'product_id' => $f['restaurant_product_id'], 'quantity' => 28, 'selection' => []],
        ], now()->subHour());

        $trend = $supply->recommendations(
            $f['site'],
            $f['branch_id'],
            CarbonImmutable::now('Asia/Kuwait')->subDays(27),
            CarbonImmutable::now('Asia/Kuwait'),
        )['rows'][0];

        $this->assertSame('consumption_trend_only', $trend['reorder_basis']);
        $this->assertNull($trend['days_of_supply']);
        $this->assertGreaterThan(0, $trend['recommended_quantity']);
        $this->assertSame('ready_for_draft', $trend['state']);

        $supply->setAuthoritativeStock(
            $f['actor'],
            $f['site'],
            $f['wholesale_product_id'],
            $f['branch_id'],
            2,
            'physical_count',
            CarbonImmutable::now('Asia/Kuwait'),
        );

        $stockBased = $supply->recommendations(
            $f['site'],
            $f['branch_id'],
            CarbonImmutable::now('Asia/Kuwait')->subDays(27),
            CarbonImmutable::now('Asia/Kuwait'),
        )['rows'][0];

        $this->assertSame('authoritative_stock_plus_consumption', $stockBased['reorder_basis']);
        $this->assertNotNull($stockBased['days_of_supply']);
        $this->assertSame('physical_count', $stockBased['authoritative_stock']['source']);
        $this->assertStringContainsString('authoritative_on_hand', $stockBased['explanation']['formula']);

        DB::table('products')->where('id', $f['wholesale_product_id'])->update(['is_active' => false]);

        $stale = $supply->recommendations(
            $f['site'],
            $f['branch_id'],
            CarbonImmutable::now('Asia/Kuwait')->subDays(27),
            CarbonImmutable::now('Asia/Kuwait'),
        )['rows'][0];

        $this->assertSame('stale_or_inactive_sku', $stale['state']);
        $this->assertFalse($stale['is_executable']);
    }

    public function test_one_click_reorder_only_updates_b2b_draft_cart_revalidates_and_exactly_attributes_later_checkout(): void
    {
        $f = $this->fixture();
        $supply = app(SupplyIntelligenceService::class);

        $supply->createMapping($f['actor'], $f['site'], [
            'menu_item_public_id' => $f['menu_item_public_id'],
            'foodex_product_id' => $f['wholesale_product_id'],
            'quantity_per_item' => 1,
            'waste_factor' => 0,
            'effective_from' => now()->subDay(),
        ]);
        $this->smartOrder($f, 'delivered', 'COMPLETED', [
            ['menu_item_public_id' => $f['menu_item_public_id'], 'product_id' => $f['restaurant_product_id'], 'quantity' => 28, 'selection' => []],
        ], now()->subHour());

        $beforeOrders = DB::table('orders')->count();
        $recommendation = $supply->recommendations(
            $f['site'],
            $f['branch_id'],
            CarbonImmutable::now('Asia/Kuwait')->subDays(27),
            CarbonImmutable::now('Asia/Kuwait'),
        )['rows'][0];

        $draft = $supply->createDraft(
            $f['actor'],
            $f['site'],
            [$f['wholesale_product_id'] => $recommendation['recommended_quantity']],
            $f['branch_id'],
        );

        $this->assertFalse($draft['order_created']);
        $this->assertSame($beforeOrders, DB::table('orders')->count());
        $this->assertDatabaseHas('carts', [
            'id' => $draft['cart_id'],
            'store_id' => $f['wholesale_store_id'],
            'b2b_customer_id' => $f['b2b_customer_id'],
            'channel' => 'b2b',
        ]);
        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $draft['cart_id'],
            'product_id' => $f['wholesale_product_id'],
            'quantity' => $recommendation['recommended_quantity'],
        ]);
        $this->assertDatabaseHas('smart_ordering_supply_reorder_drafts', [
            'public_id' => $draft['draft_public_id'],
            'cart_id' => $draft['cart_id'],
            'converted_order_id' => null,
        ]);

        $legacyCustomerId = (int) DB::table('b2b_customers')
            ->where('id', $f['b2b_customer_id'])
            ->value('legacy_customer_id');
        $order = Order::query()->create([
            'store_id' => $f['wholesale_store_id'],
            'customer_id' => $legacyCustomerId,
            'b2b_customer_id' => $f['b2b_customer_id'],
            'order_number' => 'SO-SUPPLY-B2B-'.Str::upper(Str::random(8)),
            'channel' => 'b2b',
            'status' => 'pending',
            'currency' => 'EGP',
            'subtotal' => $draft['assisted_value'],
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => $draft['assisted_value'],
        ]);

        app(SmartOrderingAttributionService::class)->attachOrderFromCart(
            Cart::query()->findOrFail($draft['cart_id']),
            $order,
        );

        $this->assertDatabaseHas('smart_ordering_supply_reorder_drafts', [
            'public_id' => $draft['draft_public_id'],
            'converted_order_id' => $order->id,
        ]);
        $commercial = $supply->commercialInsight($f['site']);
        $this->assertSame(1, $commercial['converted_assisted_orders']);
        $this->assertEqualsWithDelta($draft['assisted_value'], $commercial['attributed_foodex_sales_value'], 0.001);
    }

    public function test_cart_snapshot_preserves_menu_item_identity_for_unambiguous_recipe_consumption(): void
    {
        $f = $this->fixture();
        $carts = app(CartService::class);
        $created = $carts->create((string) $f['branch_public_id'], (string) $f['revision_public_id']);
        $cart = $created['cart'];

        $carts->add($cart, [
            'product_id' => $f['restaurant_product_id'],
            'menu_item_public_id' => $f['menu_item_public_id'],
            'quantity' => 1,
            'selection' => [],
        ]);

        $snapshot = $carts->cartSnapshot($cart->fresh());

        $this->assertSame($f['menu_item_public_id'], $snapshot['items'][0]['menu_item_public_id']);
    }

    public function test_recipe_cost_waste_and_stock_count_variance_are_auditable_without_replacing_inventory_truth(): void
    {
        $f = $this->fixture();
        $supply = app(SupplyIntelligenceService::class);

        $supply->createMapping($f['actor'], $f['site'], [
            'menu_item_public_id' => $f['menu_item_public_id'],
            'foodex_product_id' => $f['wholesale_product_id'],
            'quantity_per_item' => 1,
            'waste_factor' => 0,
            'effective_from' => now()->subDay(),
        ]);

        $from = CarbonImmutable::now('Asia/Kuwait')->subHours(3);
        $to = CarbonImmutable::now('Asia/Kuwait')->addMinute();

        $supply->setAuthoritativeStock(
            $f['actor'],
            $f['site'],
            $f['wholesale_product_id'],
            $f['branch_id'],
            10,
            'opening_count',
            CarbonImmutable::now('Asia/Kuwait')->subHours(2),
        );

        $this->smartOrder($f, 'delivered', 'COMPLETED', [
            ['menu_item_public_id' => $f['menu_item_public_id'], 'product_id' => $f['restaurant_product_id'], 'quantity' => 2, 'selection' => []],
        ], now()->subHour());

        $wasteId = $supply->recordWaste(
            $f['actor'],
            $f['site'],
            $f['wholesale_product_id'],
            $f['branch_id'],
            0.5,
            'prep_loss',
            CarbonImmutable::now('Asia/Kuwait')->subMinutes(30),
        );

        $supply->setAuthoritativeStock(
            $f['actor'],
            $f['site'],
            $f['wholesale_product_id'],
            $f['branch_id'],
            7,
            'closing_count',
            CarbonImmutable::now('Asia/Kuwait'),
        );

        $this->assertDatabaseCount('smart_ordering_supply_stock_snapshots', 2);
        $this->assertDatabaseHas('smart_ordering_supply_waste_events', [
            'public_id' => $wasteId,
            'site_id' => $f['site_id'],
            'branch_id' => $f['branch_id'],
            'quantity' => 0.5,
            'reason' => 'prep_loss',
        ]);

        $costs = $supply->recipeCosts($f['site'], $f['branch_id']);
        $this->assertCount(1, $costs);
        $this->assertTrue($costs[0]['cost_complete']);
        $this->assertEqualsWithDelta(5.0, $costs[0]['estimated_cost'], 0.001);

        $variance = collect($supply->stockVariance($f['site'], $from, $to, $f['branch_id']))
            ->firstWhere('foodex_product_id', $f['wholesale_product_id']);

        $this->assertNotNull($variance);
        $this->assertEqualsWithDelta(10.0, $variance['opening_on_hand'], 0.000001);
        $this->assertEqualsWithDelta(7.0, $variance['closing_on_hand'], 0.000001);
        $this->assertEqualsWithDelta(3.0, $variance['observed_stock_delta'], 0.000001);
        $this->assertEqualsWithDelta(2.0, $variance['theoretical_usage'], 0.000001);
        $this->assertEqualsWithDelta(0.5, $variance['recorded_waste'], 0.000001);
        $this->assertEqualsWithDelta(1.0, $variance['variance'], 0.000001);
        $this->assertSame('snapshot_delta_only', $variance['evidence']);
    }

    /** @return array<string,mixed> */
    private function fixture(): array
    {
        $actor = User::query()->create([
            'name' => 'Supply Owner',
            'email' => 'supply-owner-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $restaurantStoreId = $this->store('B2C', 'SO-SUPPLY-'.Str::upper(Str::random(6)));
        $restaurantProductId = $this->product(
            $restaurantStoreId,
            'b2c',
            'SO-MEAL-'.Str::upper(Str::random(6)),
            10,
            100,
        );

        $siteId = (int) DB::table('smart_ordering_sites')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'store_id' => $restaurantStoreId,
            'slug' => 'supply-'.Str::lower(Str::random(10)),
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $site = SmartOrderingSite::query()->findOrFail($siteId);

        DB::table('smart_ordering_entitlements')->insert([
            ['site_id' => $siteId, 'branch_id' => null, 'scope_key' => 'site', 'module' => 'ordering', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            ['site_id' => $siteId, 'branch_id' => null, 'scope_key' => 'site', 'module' => 'foodex_supply_intelligence', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $revisionPublicId = (string) Str::uuid();
        $payload = json_encode(['schema_version' => 1], JSON_THROW_ON_ERROR);
        $revisionId = (int) DB::table('smart_ordering_revisions')->insertGetId([
            'public_id' => $revisionPublicId,
            'site_id' => $siteId,
            'status' => 'published',
            'schema_version' => 1,
            'payload' => $payload,
            'checksum' => hash('sha256', $payload),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $branchPublicId = (string) Str::uuid();
        $branchId = (int) DB::table('smart_ordering_branches')->insertGetId([
            'public_id' => $branchPublicId,
            'site_id' => $siteId,
            'code' => 'MAIN',
            'name_en' => 'Main Branch',
            'timezone' => 'Asia/Kuwait',
            'is_default' => true,
            'is_active' => true,
            'accepting_orders' => true,
            'supports_dine_in' => true,
            'supports_pickup' => true,
            'supports_delivery' => true,
            'allow_scheduled' => true,
            'pickup_lead_minutes' => 0,
            'max_schedule_days' => 7,
            'service_charge' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $menuId = (int) DB::table('smart_ordering_menus')->insertGetId([
            'revision_id' => $revisionId,
            'site_id' => $siteId,
            'public_id' => (string) Str::uuid(),
            'name_en' => 'Main',
            'is_default' => true,
            'is_visible' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = (int) DB::table('smart_ordering_menu_categories')->insertGetId([
            'revision_id' => $revisionId,
            'site_id' => $siteId,
            'menu_id' => $menuId,
            'public_id' => (string) Str::uuid(),
            'name_en' => 'Meals',
            'is_visible' => true,
            'sold_out' => false,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $menuItemPublicId = (string) Str::uuid();
        DB::table('smart_ordering_menu_items')->insert([
            'revision_id' => $revisionId,
            'site_id' => $siteId,
            'category_id' => $categoryId,
            'public_id' => $menuItemPublicId,
            'source_product_id' => $restaurantProductId,
            'name_en' => 'Burger Meal',
            'is_visible' => true,
            'sold_out' => false,
            'spicy_level' => 0,
            'allow_customer_notes' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $principal = app(WholesalePrincipal::class);
        $wholesaleStoreId = $principal->storeId();
        DB::table('settings')->updateOrInsert(
            ['store_id' => $wholesaleStoreId, 'key' => 'b2b.pricing_tiers_enabled'],
            ['value' => json_encode(false), 'is_secret' => false, 'created_at' => now(), 'updated_at' => now()],
        );
        $wholesaleProductId = $this->product(
            $wholesaleStoreId,
            'b2b',
            'FDX-ING-'.Str::upper(Str::random(6)),
            5,
            500,
        );

        $tierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');
        if ($tierId <= 0) {
            $tierId = (int) DB::table('b2b_price_tiers')->insertGetId([
                'code' => 'STANDARD',
                'name' => 'Standard',
                'priority' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $customer = app(RetailWholesaleAccountService::class)->ensureForStore(
            Store::query()->findOrFail($restaurantStoreId),
            $tierId,
            $actor,
        );
        DB::table('b2b_accounts')
            ->where('b2b_customer_id', $customer->getKey())
            ->update(['credit_limit' => 10000, 'status' => 'active', 'updated_at' => now()]);

        return [
            'actor' => $actor,
            'site' => $site,
            'site_id' => $siteId,
            'branch_id' => $branchId,
            'branch_public_id' => $branchPublicId,
            'revision_public_id' => $revisionPublicId,
            'menu_item_public_id' => $menuItemPublicId,
            'restaurant_store_id' => $restaurantStoreId,
            'restaurant_product_id' => $restaurantProductId,
            'wholesale_store_id' => $wholesaleStoreId,
            'wholesale_product_id' => $wholesaleProductId,
            'b2b_customer_id' => (int) $customer->getKey(),
        ];
    }

    private function smartBranch(int $siteId, string $code): int
    {
        return (int) DB::table('smart_ordering_branches')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'site_id' => $siteId,
            'code' => $code,
            'name_en' => $code,
            'timezone' => 'Asia/Kuwait',
            'is_default' => false,
            'is_active' => true,
            'accepting_orders' => true,
            'supports_dine_in' => true,
            'supports_pickup' => true,
            'supports_delivery' => true,
            'allow_scheduled' => true,
            'pickup_lead_minutes' => 0,
            'max_schedule_days' => 7,
            'service_charge' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function store(string $type, string $code): int
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($type === 'B2C') {
            $this->enableRestaurantSaasForTestStore($storeId);
        }

        return $storeId;
    }

    private function product(int $storeId, string $channel, string $sku, float $price, float $quantity): int
    {
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => $channel,
            'code' => 'CAT-'.$sku,
            'name' => $sku.' Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->orderBy('id')->value('id');
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => $sku,
            'name' => $sku,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => $price,
            'cost_price' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WH-'.$sku,
            'name' => 'Warehouse '.$sku,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => $quantity,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $productId;
    }

    /** @param list<array<string,mixed>> $items */
    private function smartOrder(
        array $f,
        string $orderStatus,
        string $lifecycleStatus,
        array $items,
        \DateTimeInterface $at,
    ): int {
        $customerId = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => 'Smart Ordering Guest',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $orderId = (int) DB::table('orders')->insertGetId([
            'store_id' => $f['restaurant_store_id'],
            'customer_id' => $customerId,
            'order_number' => 'SO-'.Str::upper(Str::random(12)),
            'channel' => 'b2c',
            'status' => $orderStatus,
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 10,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $contextId = (int) DB::table('smart_ordering_order_contexts')->insertGetId([
            'order_id' => $orderId,
            'public_reference' => (string) Str::uuid(),
            'branch_id' => $f['branch_id'],
            'revision_public_id' => $f['revision_public_id'],
            'fulfillment_mode' => 'pickup',
            'lifecycle_status' => $lifecycleStatus,
            'idempotency_key_hash' => hash('sha256', (string) Str::uuid()),
            'request_hash' => hash('sha256', (string) Str::uuid()),
            'cart_snapshot' => json_encode(['items' => $items], JSON_THROW_ON_ERROR),
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        DB::table('smart_ordering_order_events')->insert([
            'order_context_id' => $contextId,
            'from_status' => null,
            'to_status' => $lifecycleStatus,
            'event_key' => 'test-'.Str::lower(Str::random(10)),
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return $orderId;
    }
}
