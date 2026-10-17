<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\FeedbackService;
use App\Domain\SmartOrdering\LoyaltyAdminService;
use App\Domain\SmartOrdering\LoyaltyService;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\Module;
use App\Domain\SmartOrdering\RepeatOrderService;
use App\Domain\SmartOrdering\RestaurantCustomerService;
use App\Domain\SmartOrdering\RuntimeState;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Domain\WhatsApp\MarketingConsentService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SmartOrderingCustomerGrowthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('smart_ordering.base_domain', 'foodex.com');
    }

    public function test_verified_customer_api_is_tenant_scoped_and_public_safe(): void
    {
        [$storeA, $adminA, $siteA] = $this->restaurant('PUBLIC-CUSTOMER-A');
        [$storeB, $adminB, $siteB] = $this->restaurant('PUBLIC-CUSTOMER-B');
        $customerA = $this->customer($storeA, 'Public Customer A', '+96550000901');
        $customerB = $this->customer($storeB, 'Public Customer B', '+96550000902');

        $product = $this->sellableProduct($storeA, 'SAFE-SKU-01', 'Safe Meal', 6.500, 20);
        $order = $this->order($storeA, $customerA['legacy_id'], $customerA['b2c_id'], 'COMPLETED', 6.500);
        $this->orderItem($order, $product, 'Safe Meal', 1, 5.000);

        foreach ([Module::PUBLIC_MENU, Module::ORDERING, Module::LOYALTY, Module::REVIEWS] as $module) {
            $this->enable($adminA, $siteA, $module);
        }
        foreach ([Module::PUBLIC_MENU, Module::ORDERING] as $module) {
            $this->enable($adminB, $siteB, $module);
        }
        app(SmartOrderingSiteService::class)->updateRuntime($adminA, $siteA, RuntimeState::ENABLED, true);
        app(SmartOrderingSiteService::class)->updateRuntime($adminB, $siteB, RuntimeState::ENABLED, true);

        Sanctum::actingAs(User::query()->findOrFail($customerA['user_id']));

        $history = $this->getJson('https://'.$siteA->slug.'.foodex.com/api/v1/smart-ordering/customer/orders')
            ->assertOk()
            ->assertJsonPath('data.0.order_number', (string) DB::table('orders')->where('id', $order)->value('order_number'))
            ->assertJsonPath('data.0.items.0.sku', 'HIST-'.$product);

        $historyBody = $history->getContent();
        $this->assertStringNotContainsString('"order_id"', $historyBody);
        $this->assertStringNotContainsString('"customer_id"', $historyBody);
        $this->assertStringNotContainsString('"product_id"', $historyBody);

        $orderNumber = (string) DB::table('orders')->where('id', $order)->value('order_number');
        $repeat = $this->postJson(
            'https://'.$siteA->slug.'.foodex.com/api/v1/smart-ordering/customer/orders/'.$orderNumber.'/repeat',
            ['skus' => ['HIST-'.$product]],
        )->assertCreated();

        $repeatBody = $repeat->getContent();
        $this->assertStringNotContainsString('"source_order_id"', $repeatBody);
        $this->assertStringNotContainsString('"customer_id"', $repeatBody);
        $this->assertStringNotContainsString('"store_id"', $repeatBody);
        $this->assertStringNotContainsString('"product_id"', $repeatBody);
        $this->assertSame(6.5, (float) $repeat->json('data.quote.items.0.unit_price'));

        $this->getJson('https://'.$siteB->slug.'.foodex.com/api/v1/smart-ordering/customer/orders')
            ->assertNotFound();

        $this->assertSame(0, DB::table('orders')->where('store_id', $storeB)->where('customer_id', $customerA['legacy_id'])->count());
        $this->assertNotSame($customerA['legacy_id'], $customerB['legacy_id']);
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
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'smart_ordering.loyalty.adjusted',
            'store_id' => $store,
            'user_id' => $admin->id,
        ]);
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

    public function test_expiry_only_removes_unspent_points_from_the_expiring_earning_lot(): void
    {
        [$store, $admin, $site] = $this->restaurant('LOYALTY-EXPIRY');
        $customer = $this->customer($store, 'Expiry Customer', '+96550000041');
        $this->enable($admin, $site, Module::LOYALTY);
        app(LoyaltyAdminService::class)->configureRule($admin, $site, 1.0, 0, 1);

        $order = $this->order($store, $customer['legacy_id'], $customer['b2c_id'], 'COMPLETED', 100.000);
        app(LoyaltyService::class)->earnForCompletedOrder($site, $order);
        $reward = app(LoyaltyAdminService::class)->createReward($admin, $site, '80 point reward', null, 80);
        app(LoyaltyService::class)->redeemReward(
            $site,
            $customer['legacy_id'],
            $reward['public_id'],
            null,
            'expiry-redemption-1',
        );

        app(LoyaltyService::class)->adjust($admin, $site, $customer['legacy_id'], 50, 'Non-expiring goodwill points');
        $this->assertSame(70, app(LoyaltyService::class)->balance($site, $customer['legacy_id'])['balance']);

        DB::table('smart_ordering_loyalty_lots')
            ->where('account_id', DB::table('smart_ordering_loyalty_accounts')
                ->where('site_id', $site->id)
                ->where('customer_id', $customer['legacy_id'])
                ->value('id'))
            ->update(['expires_at' => now()->subMinute(), 'updated_at' => now()]);

        $expired = app(LoyaltyService::class)->expireDue($site, $customer['legacy_id']);

        $this->assertSame(20, $expired);
        $this->assertSame(50, app(LoyaltyService::class)->balance($site, $customer['legacy_id'])['balance']);
        $this->assertDatabaseHas('smart_ordering_loyalty_ledger', [
            'site_id' => $site->id,
            'customer_id' => $customer['legacy_id'],
            'type' => 'EXPIRY',
            'points' => -20,
            'balance_after' => 50,
        ]);
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

        $queue = app(FeedbackService::class)->recoveryCases($site);
        $this->assertCount(1, $queue);
        $this->assertSame($result['service_recovery']['case_id'], $queue[0]['id']);
        $this->assertSame('Review Customer', $queue[0]['customer']['name']);
        $this->assertSame(2, $queue[0]['rating']);

        $this->actingAs($admin)
            ->getJson(route('admin.smart-ordering.service-recovery.index', $site))
            ->assertOk()
            ->assertJsonPath('cases.0.id', $result['service_recovery']['case_id']);
    }

    public function test_high_rating_can_offer_external_review_without_reward_conditioning(): void
    {
        [$store, $admin, $site] = $this->restaurant('REVIEWS-HIGH');
        $customer = $this->customer($store, 'Happy Customer', '+96550000061');
        $this->enable($admin, $site, Module::REVIEWS);
        $order = $this->order($store, $customer['legacy_id'], $customer['b2c_id'], 'DELIVERED', 22.000);

        app(FeedbackService::class)->configure(
            $admin,
            $site,
            true,
            2,
            4,
            'https://example.test/public-review',
        );

        $result = app(FeedbackService::class)->submit(
            $site,
            $customer['legacy_id'],
            $order,
            5,
            'Excellent.',
        );

        $this->assertSame('https://example.test/public-review', $result['external_review_url']);
        $this->assertNull($result['service_recovery']);
        $this->assertFalse($result['reward_conditioned_on_review']);
        $this->assertDatabaseMissing('smart_ordering_service_recovery_cases', [
            'site_id' => $site->id,
            'order_id' => $order,
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

        $this->enableRestaurantSaasForTestStore($storeId);
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
