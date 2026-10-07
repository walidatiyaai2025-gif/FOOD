<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\CommercialFeatureFlags;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommercialDashboardContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_products_workspace_exposes_server_authoritative_sales_control_contract(): void
    {
        [$manager, $storeId] = $this->retailManager();

        $this->actingAs($manager)
            ->get(route('admin.b2c.module', ['module' => 'products', 'store_id' => $storeId]))
            ->assertOk()
            ->assertSee('data-commercial-admin-surface="canonical-commercial-policy"', false)
            ->assertSee('Product Sales Control')
            ->assertSee('evaluate_policy')
            ->assertSee('remaining_quota')
            ->assertSee('PRODUCT_CLOSED')
            ->assertSee('Canonical backend contract live');
    }

    public function test_promotions_workspace_exposes_flash_contract_without_duplicate_mutation_logic(): void
    {
        [$manager, $storeId] = $this->retailManager();

        $response = $this->actingAs($manager)
            ->get(route('admin.b2c.module', ['module' => 'promotions', 'store_id' => $storeId]));

        $response
            ->assertOk()
            ->assertSee('data-commercial-admin-surface="canonical-flash-offer"', false)
            ->assertSee('Flash Offers')
            ->assertSee('reserve_flash_offer')
            ->assertSee('release_reservation')
            ->assertSee('FLASH_RESERVATION_EXPIRED')
            ->assertSee('Create/edit/activate mutations are wired');
    }

    public function test_flash_preview_and_analytics_are_executable_against_canonical_flash_tables(): void
    {
        [$manager, $storeId] = $this->retailManager();
        $productId = $this->flashProduct($storeId);

        $offerId = (int) DB::table('flash_offers')->insertGetId([
            'store_id' => $storeId,
            'name' => 'Dashboard Flash',
            'title_ar' => 'عرض لوحة التحكم',
            'title_en' => 'Dashboard Flash',
            'body_ar' => 'تفاصيل العرض',
            'body_en' => 'Offer details',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'timezone' => 'Asia/Kuwait',
            'channels' => json_encode(['customer', 'van'], JSON_THROW_ON_ERROR),
            'allocation_mode' => 'shared',
            'total_allocation_base' => 100,
            'per_customer_limit_base' => 20,
            'reservation_seconds' => 300,
            'retry_count' => 2,
            'cooldown_seconds' => 60,
            'priority' => 10,
            'popup_frequency' => 'once_per_session',
            'counts_toward_normal_quota' => true,
            'stackable' => false,
            'kill_switch' => false,
            'created_by' => $manager->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $offerProductId = (int) DB::table('flash_offer_products')->insertGetId([
            'flash_offer_id' => $offerId,
            'product_id' => $productId,
            'selling_unit_code' => 'CARTON',
            'conversion_factor' => 10,
            'flash_price' => 7,
            'allocation_base' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reservationId = (string) Str::uuid();
        DB::table('flash_reservations')->insert([
            'id' => $reservationId,
            'flash_offer_id' => $offerId,
            'flash_offer_product_id' => $offerProductId,
            'user_id' => $manager->id,
            'channel' => 'customer',
            'selling_quantity' => 1,
            'reserved_base_quantity' => 10,
            'unit_price' => 7,
            'status' => 'confirmed',
            'idempotency_key' => 'dashboard-analytics',
            'inventory_allocations' => json_encode([], JSON_THROW_ON_ERROR),
            'expires_at' => now()->addMinutes(5),
            'confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('flash_offer_events')->insert([
            'flash_offer_id' => $offerId,
            'flash_reservation_id' => $reservationId,
            'user_id' => $manager->id,
            'event' => 'reservation_confirmed',
            'channel' => 'customer',
            'metadata' => json_encode(['order_id' => 123], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scope = ['store_id' => $storeId, 'offer' => $offerId];

        $this->actingAs($manager)
            ->get(route('admin.commercial.flash-offers.preview', $scope))
            ->assertOk()
            ->assertSee('data-commercial-preview', false)
            ->assertSee('data-foodex-sidebar-toggle', false)
            ->assertSee('Customer Popup Preview')
            ->assertSee('Product Card Preview')
            ->assertSee('Notification Preview')
            ->assertSee('Dashboard Flash')
            ->assertSee('Flash Product')
            ->assertSee('Carton')
            ->assertDontSee('Flash Offer Preview #'.$offerId);

        $this->actingAs($manager)
            ->get(route('admin.commercial.flash-offers.analytics', $scope))
            ->assertOk()
            ->assertSee('data-commercial-analytics', false)
            ->assertSee('data-foodex-sidebar-toggle', false)
            ->assertSee('Flash Analytics')
            ->assertSee('Confirmed')
            ->assertSee('Reservation confirmed')
            ->assertSee('Customer')
            ->assertSee('10.000')
            ->assertDontSee('reservation_confirmed')
            ->assertDontSee('Flash Analytics · #'.$offerId);

        $manager->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($manager->fresh())
            ->get(route('admin.commercial.flash-offers.preview', $scope))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('معاينة العرض السريع')
            ->assertSee('معاينة نافذة العميل')
            ->assertSee('اشترِ الآن')
            ->assertSee('وحدة البيع');

        $this->actingAs($manager->fresh())
            ->get(route('admin.commercial.flash-offers.analytics', $scope))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('تحليلات العرض السريع')
            ->assertSee('مؤكد')
            ->assertSee('تم تأكيد الحجز')
            ->assertSee('العميل')
            ->assertDontSee('reservation_confirmed');
    }

    public function test_sales_control_uses_premium_dashboard_shell_instead_of_legacy_standalone_surface(): void
    {
        [$manager, $storeId] = $this->retailManager();
        $this->flashProduct($storeId);

        $response = $this->actingAs($manager)
            ->get(route('admin.commercial.sales-control', ['store_id' => $storeId]));

        $response
            ->assertOk()
            ->assertSee('commercial-admin-layout', false)
            ->assertSee('data-foodex-sidebar-toggle', false)
            ->assertSee('Commercial & Sales')
            ->assertSee('Product policies')
            ->assertSee('Availability & channels')
            ->assertSee('Selling unit & break-pack')
            ->assertSee('data-break-pack-selling-unit', false)
            ->assertSee('data-selling-unit-editor', false)
            ->assertSee('data-availability-editor', false)
            ->assertSee('data-targeting-rule-editor', false)
            ->assertSee('data-selling-units-json', false)
            ->assertSee('data-availability-json', false)
            ->assertSee('data-rules-json', false)
            ->assertSee('Default quotas')
            ->assertDontSee('data-privileged-commercial-json', false)
            ->assertDontSee('Selling units (JSON)')
            ->assertDontSee('Channels JSON');

        $manager->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($manager->fresh())
            ->get(route('admin.commercial.sales-control', ['store_id' => $storeId]))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('وحدات البيع')
            ->assertSee('نوافذ الإتاحة')
            ->assertSee('قواعد الاستهداف والحصص');
    }

    public function test_platform_admin_keeps_advanced_json_behind_privileged_section(): void
    {
        [, $storeId] = $this->retailManager();
        $this->flashProduct($storeId);

        $superAdmin = User::query()->create([
            'name' => 'Commercial Platform Advanced',
            'email' => 'commercial-advanced@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $superAdmin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $this->actingAs($superAdmin)
            ->get(route('admin.commercial.sales-control', ['store_id' => $storeId]))
            ->assertOk()
            ->assertSee('data-selling-unit-editor', false)
            ->assertSee('data-availability-editor', false)
            ->assertSee('data-targeting-rule-editor', false)
            ->assertSee('data-privileged-commercial-json', false)
            ->assertSee('data-commercial-advanced-json="selling_units_json"', false)
            ->assertSee('data-commercial-advanced-json="availability_windows_json"', false)
            ->assertSee('data-commercial-advanced-json="rules_json"', false);
    }

    public function test_van_commercial_parity_is_explicit_in_sales_control_and_flash_offer_authoring(): void
    {
        [$manager, $storeId] = $this->retailManager();
        $this->flashProduct($storeId);

        $this->actingAs($manager)
            ->get(route('admin.commercial.sales-control', ['store_id' => $storeId]))
            ->assertOk()
            ->assertSee('data-commercial-channel-picker', false)
            ->assertSee('value="van" data-commercial-channel', false);

        $this->actingAs($manager)
            ->get(route('admin.commercial.flash-offers', ['store_id' => $storeId]))
            ->assertOk()
            ->assertSee('name="channels[]" value="van"', false)
            ->assertSee('data-flash-product-builder', false);
    }

    private function flashProduct(int $storeId): int
    {
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'COMM-FLASH-EA',
            'name' => 'Commercial Flash Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'commercial-flash',
            'name' => 'Commercial Flash',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoryId = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalogId,
            'name' => 'Flash',
            'slug' => 'commercial-flash',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'sku' => 'COMM-FLASH-001',
            'name' => 'Flash Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_selling_units')->insert([
            [
                'product_id' => $productId,
                'unit_id' => $unitId,
                'code' => 'PIECE',
                'name' => 'Piece',
                'conversion_factor' => 1,
                'price' => 10,
                'sku' => 'COMM-FLASH-001',
                'barcode' => null,
                'is_base' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_id' => $productId,
                'unit_id' => $unitId,
                'code' => 'CARTON',
                'name' => 'Carton',
                'conversion_factor' => 10,
                'price' => 90,
                'sku' => 'COMM-FLASH-001-C',
                'barcode' => null,
                'is_base' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        return $productId;
    }

    public function test_flash_offers_share_the_authoritative_foodex_admin_shell(): void
    {
        [$manager, $storeId] = $this->retailManager();
        $this->flashProduct($storeId);

        $response = $this->actingAs($manager)
            ->get(route('admin.commercial.flash-offers', ['store_id' => $storeId]));

        $response
            ->assertOk()
            ->assertSee('commercial-admin-layout', false)
            ->assertSee('data-foodex-sidebar-toggle', false)
            ->assertSee('Commercial & Sales')
            ->assertSee('Flash Offers')
            ->assertDontSee('<html class="legacy-commercial-shell"', false);
    }

    public function test_flash_offer_workspace_uses_structured_business_controls_instead_of_raw_json_or_ids(): void
    {
        [$manager, $storeId] = $this->retailManager();
        $this->flashProduct($storeId);

        $response = $this->actingAs($manager)
            ->get(route('admin.commercial.flash-offers', ['store_id' => $storeId]));

        $response
            ->assertOk()
            ->assertSee('data-flash-offer-form', false)
            ->assertSee('data-flash-product-builder', false)
            ->assertSee('name="channels[]"', false)
            ->assertSee('name="products[0][product_id]"', false)
            ->assertSee('name="products[0][selling_unit_code]"', false)
            ->assertDontSee('Channels JSON')
            ->assertDontSee('Products JSON')
            ->assertDontSee('Offer ID (blank = new)')
            ->assertDontSee('Audience customer IDs JSON');
    }

    public function test_flash_offer_rows_use_one_compact_action_menu_and_catalog_localization(): void
    {
        [$manager, $storeId] = $this->retailManager();

        DB::table('flash_offers')->insert([
            'store_id' => $storeId,
            'name' => 'Compact Actions Flash',
            'title_ar' => 'عرض إجراءات مدمجة',
            'title_en' => 'Compact Actions Flash',
            'body_ar' => 'اختبار واجهة الإجراءات',
            'body_en' => 'Compact action UI test',
            'status' => 'draft',
            'starts_at' => now()->addMinutes(5),
            'ends_at' => now()->addHour(),
            'timezone' => 'Asia/Kuwait',
            'channels' => json_encode(['customer', 'van'], JSON_THROW_ON_ERROR),
            'allocation_mode' => 'shared',
            'total_allocation_base' => 100,
            'per_customer_limit_base' => 10,
            'reservation_seconds' => 300,
            'retry_count' => 0,
            'cooldown_seconds' => 0,
            'priority' => 0,
            'popup_frequency' => 'once_per_session',
            'counts_toward_normal_quota' => true,
            'stackable' => false,
            'kill_switch' => false,
            'created_by' => $manager->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($manager)
            ->get(route('admin.commercial.flash-offers', ['store_id' => $storeId]));

        $response
            ->assertOk()
            ->assertSee('data-flash-offer-actions', false)
            ->assertSee('aria-label="Offer actions"', false)
            ->assertSee('Existing normal promotions')
            ->assertDontSee('العروض العادية الحالية');

        $view = file_get_contents(resource_path('views/admin/commercial-dashboard.blade.php'));
        $this->assertStringNotContainsString('{{ $ar ? \'العروض العادية الحالية\' : \'Existing normal promotions\' }}', $view);
        $this->assertStringNotContainsString('{{ $promotion->is_active ? ($ar ?', $view);
    }

    public function test_sales_control_rejects_targeting_customer_outside_authoritative_store_scope(): void
    {
        [$manager, $storeId] = $this->retailManager();
        $productId = $this->flashProduct($storeId);

        $this->actingAs($manager)
            ->from(route('admin.commercial.sales-control', ['store_id' => $storeId]))
            ->put(route('admin.commercial.sales-control.save', [
                'store_id' => $storeId,
                'product' => $productId,
            ]), [
                'status' => 'OPEN',
                'channels_json' => '["customer","van"]',
                'break_pack_policy' => 'mixed',
                'business_timezone' => 'Asia/Kuwait',
                'week_starts_on' => 1,
                'selling_units_json' => '[{"code":"PIECE","name":"Piece","conversion_factor":1,"is_base":true,"is_active":true}]',
                'availability_windows_json' => '[]',
                'rules_json' => '[{"customer_id":999999,"channel":"customer","is_allowed":true}]',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['rules_json.0.customer_id']);
    }

    public function test_flash_offer_rejects_audience_values_outside_authoritative_store_lookups(): void
    {
        [$manager, $storeId] = $this->retailManager();
        $productId = $this->flashProduct($storeId);

        $this->actingAs($manager)
            ->from(route('admin.commercial.flash-offers', ['store_id' => $storeId]))
            ->post(route('admin.commercial.flash-offers.save', ['store_id' => $storeId]), [
                'name' => 'Invalid Audience Flash',
                'title_ar' => 'جمهور غير صالح',
                'title_en' => 'Invalid Audience',
                'status' => 'draft',
                'starts_at' => now()->addMinutes(5)->format('Y-m-d H:i:s'),
                'ends_at' => now()->addHour()->format('Y-m-d H:i:s'),
                'timezone' => 'Asia/Kuwait',
                'channels' => ['customer', 'van'],
                'audience_customer_ids' => [999999],
                'audience_customer_group_ids' => [999998],
                'audience_regions' => ['OUTSIDE-REGION'],
                'audience_routes' => ['OUTSIDE-ROUTE'],
                'allocation_mode' => 'shared',
                'reservation_seconds' => 300,
                'retry_count' => 0,
                'cooldown_seconds' => 0,
                'priority' => 0,
                'popup_frequency' => 'once_per_session',
                'products' => [[
                    'product_id' => $productId,
                    'selling_unit_code' => 'CARTON',
                    'flash_price' => 7,
                    'allocation_base' => 100,
                ]],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors([
                'audience_customer_ids',
                'audience_customer_group_ids',
                'audience_regions',
                'audience_routes',
            ]);
    }

    public function test_final_gate_configuration_persists_break_pack_audience_and_authoritative_flags(): void
    {
        [$manager, $storeId] = $this->retailManager();
        $productId = $this->flashProduct($storeId);
        [$audienceCustomerId, $audienceGroupId, $audienceRegion, $audienceRoute] =
            $this->commercialAudienceFixture($storeId, $manager);

        $this->actingAs($manager)
            ->put(route('admin.commercial.sales-control.save', [
                'store_id' => $storeId,
                'product' => $productId,
            ]), [
                'status' => 'OPEN',
                'channels_json' => '["customer","van"]',
                'break_pack_policy' => 'one-unit-type',
                'break_pack_unit_code' => 'CASE12',
                'business_timezone' => 'Asia/Kuwait',
                'week_starts_on' => 1,
                'selling_units_json' => '[{"code":"PIECE","name":"Piece","conversion_factor":1,"is_base":true,"is_active":true},{"code":"CASE12","name":"Case 12","conversion_factor":12,"is_base":false,"is_active":true}]',
                'availability_windows_json' => '[{"recurrence":"yearly","start_month":1,"start_day":1,"end_month":12,"end_day":31,"is_active":true}]',
                'rules_json' => '[{"customer_id":'.$audienceCustomerId.',"channel":"van","is_allowed":null,"max_per_day":25}]',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('product_commercial_policies', [
            'product_id' => $productId,
            'break_pack_policy' => 'one-unit-type',
            'break_pack_unit_code' => 'CASE12',
        ]);
        $this->assertDatabaseHas('product_selling_units', [
            'product_id' => $productId,
            'code' => 'CASE12',
            'conversion_factor' => 12,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('product_availability_windows', [
            'product_id' => $productId,
            'recurrence' => 'yearly',
            'start_month' => 1,
            'start_day' => 1,
            'end_month' => 12,
            'end_day' => 31,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('product_commercial_rules', [
            'product_id' => $productId,
            'customer_id' => $audienceCustomerId,
            'customer_group_id' => null,
            'channel' => 'van',
            'is_allowed' => null,
            'max_per_day' => 25,
        ]);

        $this->actingAs($manager)
            ->post(route('admin.commercial.flash-offers.save', ['store_id' => $storeId]), [
                'name' => 'Targeted Flash',
                'title_ar' => 'عرض مستهدف',
                'title_en' => 'Targeted Flash',
                'status' => 'draft',
                'starts_at' => now()->addMinutes(5)->format('Y-m-d H:i:s'),
                'ends_at' => now()->addHour()->format('Y-m-d H:i:s'),
                'timezone' => 'Asia/Kuwait',
                'channels' => ['customer', 'van'],
                'audience_customer_ids' => [$audienceCustomerId],
                'audience_customer_group_ids' => [$audienceGroupId],
                'audience_regions' => [$audienceRegion],
                'audience_routes' => [$audienceRoute],
                'allocation_mode' => 'shared',
                'reservation_seconds' => 300,
                'retry_count' => 1,
                'cooldown_seconds' => 60,
                'priority' => 10,
                'popup_frequency' => 'once_per_session',
                'products' => [[
                    'product_id' => $productId,
                    'selling_unit_code' => 'CASE12',
                    'flash_price' => 7,
                    'allocation_base' => 100,
                ]],
            ])
            ->assertRedirect();

        $offer = DB::table('flash_offers')
            ->where('store_id', $storeId)
            ->where('name', 'Targeted Flash')
            ->first();

        $this->assertNotNull($offer);
        $this->assertSame([$audienceCustomerId], json_decode((string) $offer->audience_customer_ids, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame([$audienceGroupId], json_decode((string) $offer->audience_customer_group_ids, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame([$audienceRegion], json_decode((string) $offer->audience_regions, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame([$audienceRoute], json_decode((string) $offer->audience_routes, true, 512, JSON_THROW_ON_ERROR));

        $flags = [
            'commercial_rules_enabled' => true,
            'flash_offers_enabled' => true,
            'customer_flash_popup_enabled' => false,
            'van_offers_enabled' => true,
        ];

        $this->actingAs($manager)
            ->put(route('admin.commercial.feature-flags.save'), $flags)
            ->assertForbidden();

        $superAdmin = User::query()->create([
            'name' => 'Commercial Platform Admin',
            'email' => 'commercial-platform@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $superAdmin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $this->actingAs($superAdmin)
            ->put(route('admin.commercial.feature-flags.save'), $flags)
            ->assertRedirect();

        $this->assertSame($flags, app(CommercialFeatureFlags::class)->snapshot());
    }

    /** @return array{0:int,1:int,2:string,3:string} */
    private function commercialAudienceFixture(int $storeId, User $manager): array
    {
        $customerId = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => 'Commercial Audience Customer',
            'phone' => '55501036',
            'email' => 'commercial-audience@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('b2c_customers')->insert([
            'legacy_customer_id' => $customerId,
            'store_id' => $storeId,
            'user_id' => null,
            'name' => 'Commercial Audience Customer',
            'phone' => '55501036',
            'email' => 'commercial-audience@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $groupId = (int) DB::table('commercial_customer_groups')->insertGetId([
            'store_id' => $storeId,
            'name' => 'Commercial Audience Group',
            'priority' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('addresses')->insert([
            'customer_id' => $customerId,
            'label' => 'Commercial Audience',
            'line1' => 'Commercial Street',
            'city' => 'Kuwait City',
            'area' => 'Hawalli',
            'country_code' => 'KW',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('van_visits')->insert([
            'actor_user_id' => $manager->id,
            'customer_type' => 'b2c',
            'customer_id' => $customerId,
            'store_id' => $storeId,
            'status' => 'planned',
            'metadata' => json_encode(['route_code' => 'ROUTE-A'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$customerId, $groupId, 'Hawalli', 'ROUTE-A'];
    }

    /** @return array{0:User, 1:int} */
    private function retailManager(): array
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'COMMERCIAL-UI',
            'name' => 'Commercial UI Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manager = User::query()->create([
            'name' => 'Commercial Manager',
            'email' => 'commercial-dashboard@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);

        DB::table('user_store_roles')->insert([
            'user_id' => $manager->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$manager, $storeId];
    }
}
