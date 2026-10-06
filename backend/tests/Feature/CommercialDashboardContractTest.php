<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
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
            ->assertSee('Customer Popup Preview')
            ->assertSee('Product Card Preview')
            ->assertSee('Notification Preview')
            ->assertSee('Dashboard Flash')
            ->assertSee('Flash Product');

        $this->actingAs($manager)
            ->get(route('admin.commercial.flash-offers.analytics', $scope))
            ->assertOk()
            ->assertSee('Flash Analytics')
            ->assertSee('confirmed')
            ->assertSee('reservation_confirmed')
            ->assertSee('10.000');
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

        return $productId;
    }

    /** @return array{0:User,1:int} */
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
