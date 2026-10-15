<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\FeedbackService;
use App\Domain\SmartOrdering\LoyaltyAdminService;
use App\Domain\SmartOrdering\LoyaltyService;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\Module;
use App\Domain\SmartOrdering\RepeatOrderService;
use App\Domain\SmartOrdering\RestaurantCustomerService;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Domain\WhatsApp\MarketingConsentService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SmartOrderingCustomerGrowthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_customer_profile_is_restaurant_scoped_and_same_phone_never_merges_tenants(): void
    {
        [$storeA, $adminA, $siteA] = $this->restaurant('CRM-A');
        [$storeB, , $siteB] = $this->restaurant('CRM-B');

        $customerA = $this->customer($storeA, 'Customer A', '+96550000001');
        $customerB = $this->customer($storeB, 'Customer B', '+96550000001');
        $orderA = $this->order($storeA, $customerA['legacy_id'], $customerA['b2c_id'], 'COMPLETED', 12.500);
        $this->order($storeB, $customerB['legacy_id'], $customerB['b2c_id'], 'COMPLETED', 99.000);

        $profile = app(RestaurantCustomerService::class)->profile($siteA, $customerA['legacy_id']);
        $this->assertSame('Customer A', $profile['customer']['name']);
        $this->assertSame(1, $profile['order_count']);
        $this->assertSame(12.5, $profile['lifetime_spend']);
        $this->assertSame($orderA, DB::table('orders')->where('store_id', $storeA)->value('id'));

        try {
            app(RestaurantCustomerService::class)->profile($siteA, $customerB['legacy_id']);
            $this->fail('Cross-tenant customer access must fail even when the phone number matches.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertSame(1, app(RestaurantCustomerService::class)->profile($siteB, $customerB['legacy_id'])['order_count']);

        $this->actingAs($adminA)
            ->getJson(route('admin.smart-ordering.customers.show', [$siteA, $customerB['legacy_id']]))
            ->assertNotFound();
    }

    public function test_repeat_order_uses_live_price_instead_of_historical_snapshot(): void
    {
        [$store, , $site] = $this->restaurant('REPEAT-PRICE');
        $customer = $this->customer($store, 'Repeat Customer', '+96550000002');
        $product = $this->sellableProduct($store, 'REPEAT-01', 'Repeat Meal', 4.750, 20);
        $order = $this->order($store, $customer['legacy_id'], $customer['b2c_id'], 'COMPLETED', 2.000);
        $itemId = $this->orderItem($order, $product, 'Repeat Meal', 2, 1.000);

        $draft = app(RepeatOrderService::class)->createDraft($site, $customer['legacy_id'], $order, [$itemId]);

        $this->assertSame('LIVE_PRICE_AND_AVAILABILITY_REVALIDATED', $draft['pricing_notice']);
        $this->assertFalse($draft['has_unavailable_items']);
        $this->assertSame(4.75, (float) $draft['quote']['items'][0]['unit_price']);
        $this->assertSame(9.5, (float) $draft['quote']['items'][0]['line_total']);
        $this->assertNotSame(1.0, (float) $draft['quote']['items'][0]['unit_price']);
        $this->assertDatabaseHas('smart_ordering_repeat_drafts', [
            'site_id' => $site->id,
            'customer_id' => $customer['legacy_id'],
            'source_order_id' => $order,
            'status' => 'DRAFT',
        ]);
    }

    public function test_repeat_order_warns_for_sold_out_historical_item(): void
    {
        [$store, , $site] = $this->restaurant('REPEAT-SOLDOUT');
        $customer = $this->customer($store, 'Sold Out Customer', '+96550000003');
        $product = $this->sellableProduct($store, 'SOLD-01', 'Sold Item', 3.000, 0);
        $order = $this->order($store, $customer['legacy_id'], $customer['b2c_id'], 'COMPLETED', 3.000);
        $this->orderItem($order, $product, 'Sold Item', 1, 3.000);

        $draft = app(RepeatOrderService::class)->createDraft($site, $customer['legacy_id'], $order);

        $this->assertTrue($draft['has_unavailable_items']);
        $this->assertTrue($draft['requires_customer_confirmation']);
        $this->assertSame($product, $draft['unavailable_items'][0]['product_id']);
        $this->assertFalse((bool) $draft['quote']['items'][0]['is_available']);
    }

    public function test_loyalty_redemption_is_idempotent_locked_and_never_goes_negative(): void
    {
        [$store, $admin, $site] = $this->restaurant('LOYALTY-SPEND');
        $customer = $this->customer($store, 'Loyal Customer', '+96550000004');
        $this->enable($admin, $site, Module::LOYALTY);

        app(LoyaltyService::class)->adjust($admin, $site, $customer['legacy_id'], 100, 'Opening test balance');
        $reward = app(LoyaltyAdminService::class)->createReward(
            $admin,
            $site,
            'Free Dessert',
            'حلوى مجانية',
            80,
        );

        $first = app(LoyaltyService::class)->redeemReward(
            $site,
            $customer['legacy_id'],
            $reward['public_id'],
            null,
            'checkout-redemption-1',
        );
        $again = app(LoyaltyService::class)->redeemReward(
            $site,
            $customer['legacy_id'],
            $reward['public_id'],
            null,
            'checkout-redemption-1',
        );

        $this->assertSame(20, $first['balance']);
        $this->assertSame(20, $again['balance']);
        $this->assertTrue($again['idempotent']);
        $this->assertSame(1, DB::table('smart_ordering_loyalty_ledger')->where('type', 'REDEEM')->count());

        try {
            app(LoyaltyService::class)->redeemReward(
                $site,
                $customer['legacy_id'],
                $reward['public_id'],
                null,
                'checkout-redemption-2',
            );
            $this->fail('A second redemption must not overspend the locked balance.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->assertSame(20, app(LoyaltyService::class)->balance($site, $customer['legacy_id'])['balance']);
    }

    public function test_cancelled_order_earning_reversal_is_idempotent_and_balance_stays_non_negative(): void
    {
        [$store, $admin, $site] = $this->restaurant('LOYALTY-REVERSAL');
        $customer = $this->customer($store, 'Refund Customer', '+96550000005');
        $this->enable($admin, $site, Module::LOYALTY);
        app(LoyaltyAdminService::class)->configureRule($admin, $site, 2.0, 0, null);

        $order = $this->order($store, $customer['legacy_id'], $customer['b2c_id'], 'COMPLETED', 10.000);
        $earned = app(LoyaltyService::class)->earnForCompletedOrder($site, $order);
        $this->assertSame(20, $earned['points']);
        $this->assertSame(20, $earned['balance']);

        DB::table('orders')->where('id', $order)->update(['status' => 'CANCELLED', 'updated_at' => now()]);
        $reversed = app(LoyaltyService::class)->reverseOrder($site, $order);
        $again = app(LoyaltyService::class)->reverseOrder($site, $order);

        $this->assertSame(20, $reversed['points_reversed']);
        $this->assertSame(0, $reversed['balance']);
        $this->assertSame(0, $again['balance']);
        $this->assertSame(1, DB::table('smart_ordering_loyalty_ledger')->where('type', 'REVERSAL')->count());
        $this->assertGreaterThanOrEqual(0, app(LoyaltyService::class)->balance($site, $customer['legacy_id'])['balance']);
    }

    public function test_low_rating_creates_internal_service_recovery_case_without_review_reward_manipulation(): void
    {
        [$store, $admin, $site] = $this->restaurant('REVIEWS-LOW');
        $customer = $this->customer($store, 'Review Customer', '+96550000006');
        $this->enable($admin, $site, Module::REVIEWS);
        $order = $this->order($store, $customer['legacy_id'], $customer['b2c_id'], 'COMPLETED', 8.000);

        app(FeedbackService::class)->configure(
            $admin,
            $site,
            true,
            2,
            4,
            'https://example.test/review-us',
        );

        $result = app(FeedbackService::class)->submit(
            $site,
            $customer['legacy_id'],
            $order,
            2,
            'Food arrived cold.',
        );

        $this->assertNotNull($result['service_recovery']);
        $this->assertSame('OPEN', $result['service_recovery']['status']);
        $this->assertNull($result['external_review_url']);
        $this->assertFalse($result['reward_conditioned_on_review']);
        $this->assertDatabaseHas('smart_ordering_service_recovery_cases', [
            'site_id' => $site->id,
            'order_id' => $order,
            'customer_id' => $customer['legacy_id'],
            'status' => 'OPEN',
            'reason' => 'LOW_RATING',
        ]);
    }

    public function test_marketing_opt_out_is_respected_for_service_recovery_followup(): void
    {
        [$store, $admin, $site] = $this->restaurant('CONSENT-OPT');
        $customer = $this->customer($store, 'Opt Out Customer', '+96550000007');
        $this->enable($admin, $site, Module::REVIEWS);
        $order = $this->order($store, $customer['legacy_id'], $customer['b2c_id'], 'COMPLETED', 15.000);

        $consent = app(MarketingConsentService::class);
        $consent->record($customer['platform_id'], MarketingConsentService::GRANTED, 'test');
        $this->assertTrue(app(RestaurantCustomerService::class)->marketingEligible($site, $customer['legacy_id']));

        $consent->record($customer['platform_id'], MarketingConsentService::OPTED_OUT, 'customer_request');
        $this->assertFalse(app(RestaurantCustomerService::class)->marketingEligible($site, $customer['legacy_id']));

        $result = app(FeedbackService::class)->submit(
            $site,
            $customer['legacy_id'],
            $order,
            1,
            'Please contact me only about this order.',
        );

        $this->assertFalse($result['marketing_followup_allowed']);
        $this->assertDatabaseHas('whats_app_marketing_preferences', [
            'platform_customer_id' => $customer['platform_id'],
            'state' => MarketingConsentService::OPTED_OUT,
        ]);
    }

    /** @return array{0:int,1:User,2:SmartOrderingSite} */
    private function restaurant(string $code): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = User::query()->create([
            'name' => $code.' Admin',
            'email' => strtolower($code).'-'.Str::lower(Str::random(6)).'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $site = app(SmartOrderingSiteService::class)->ensureForStore($storeId, $admin);

        return [$storeId, $admin, $site];
    }

    /** @return array{legacy_id:int,b2c_id:int,platform_id:int,user_id:int} */
    private function customer(int $storeId, string $name, string $phone): array
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => 'customer-'.Str::lower(Str::random(10)).'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $legacyId = (int) DB::table('customers')->insertGetId([
            'user_id' => $user->id,
            'type' => 'b2c',
            'name' => $name,
            'phone' => $phone,
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $b2cId = (int) DB::table('b2c_customers')->insertGetId([
            'legacy_customer_id' => $legacyId,
            'store_id' => $storeId,
            'user_id' => $user->id,
            'name' => $name,
            'phone' => $phone,
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $platformId = (int) DB::table('platform_customers')->insertGetId([
            'user_id' => $user->id,
            'legacy_customer_id' => $legacyId,
            'name' => $name,
            'phone' => $phone,
            'email' => $user->email,
            'is_active' => true,
            'origin_channel' => 'b2c',
            'origin_store_id' => $storeId,
            'registration_source' => 'smart_ordering_test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'legacy_id' => $legacyId,
            'b2c_id' => $b2cId,
            'platform_id' => $platformId,
            'user_id' => (int) $user->id,
        ];
    }

    private function order(
        int $storeId,
        int $legacyCustomerId,
        int $b2cCustomerId,
        string $status,
        float $grandTotal,
    ): int {
        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $legacyCustomerId,
            'b2b_customer_id' => null,
            'b2c_customer_id' => $b2cCustomerId,
            'address_id' => null,
            'order_number' => 'SO-GROWTH-'.Str::upper(Str::random(12)),
            'channel' => 'b2c',
            'status' => $status,
            'currency' => 'KWD',
            'subtotal' => $grandTotal,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => $grandTotal,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function orderItem(int $orderId, int $productId, string $name, float $quantity, float $unitPrice): int
    {
        return (int) DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'product_id' => $productId,
            'sku_snapshot' => 'HIST-'.$productId,
            'name_snapshot' => $name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => round($quantity * $unitPrice, 3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function sellableProduct(
        int $storeId,
        string $sku,
        string $name,
        float $price,
        float $inventoryQuantity,
    ): int {
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-'.Str::upper(Str::random(8)),
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'growth-'.Str::lower(Str::random(8)),
            'name' => 'Growth Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalogId,
            'name' => 'Meals',
            'slug' => 'meals-'.Str::lower(Str::random(8)),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => $price,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WH-'.Str::upper(Str::random(8)),
            'name' => 'Growth Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => $inventoryQuantity,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $productId;
    }

    private function enable(User $admin, SmartOrderingSite $site, Module $module): void
    {
        app(SmartOrderingSiteService::class)->setEntitlement($admin, $site, $module, true);
    }
}
