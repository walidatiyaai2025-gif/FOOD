<?php

namespace Tests\Feature;

use App\Models\B2bCustomer;
use App\Models\Customer;
use App\Models\FlashOffer;
use App\Models\FlashOfferProduct;
use App\Models\User;
use App\Services\FlashOfferService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class WholesaleFlashCustomerFeedTest extends TestCase
{
    use RefreshDatabase;

    private int $storeId;

    private int $productId;

    private FlashOffer $offer;

    private FlashOfferProduct $line;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        $principal = app(WholesalePrincipal::class);
        $this->storeId = $principal->storeId();

        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'WHOLESALE-FLASH-EACH',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $this->storeId,
            'channel' => 'b2b',
            'code' => 'wholesale-flash-customer',
            'name' => 'Wholesale Flash Customer',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'PASTA-FLASH-001',
            'name' => 'مكرونات',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $this->storeId,
            'product_id' => $this->productId,
            'price' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitSellingId = (int) DB::table('product_selling_units')->insertGetId([
            'product_id' => $this->productId,
            'unit_id' => $unitId,
            'code' => 'PIECE',
            'name' => 'قطعة',
            'conversion_factor' => 1,
            'price' => 10,
            'sku' => 'PASTA-FLASH-001',
            'barcode' => null,
            'is_base' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->updateOrInsert(
            ['warehouse_id' => $principal->warehouseId(), 'product_id' => $this->productId],
            [
                'quantity' => 100,
                'reserved_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        foreach (['flash_offers_enabled', 'van_offers_enabled'] as $flag) {
            DB::table('settings')->updateOrInsert(
                ['store_id' => null, 'key' => $flag],
                [
                    'value' => json_encode(true, JSON_THROW_ON_ERROR),
                    'is_secret' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        $this->offer = FlashOffer::query()->create([
            'store_id' => $this->storeId,
            'name' => 'مكرونات',
            'title_ar' => 'عرض مكرونات',
            'title_en' => 'Pasta Flash',
            'status' => 'active',
            'starts_at' => '2026-11-01 21:07:00',
            'ends_at' => '2026-11-30 21:07:00',
            'timezone' => 'Asia/Kuwait',
            'channels' => ['van', 'customer'],
            'allocation_mode' => 'shared',
            'reservation_seconds' => 300,
            'retry_count' => 0,
            'cooldown_seconds' => 0,
            'priority' => 10,
            'popup_frequency' => 'once_per_session',
            'counts_toward_normal_quota' => false,
            'stackable' => false,
            'kill_switch' => false,
        ]);
        $this->line = FlashOfferProduct::query()->create([
            'flash_offer_id' => $this->offer->id,
            'product_id' => $this->productId,
            'selling_unit_id' => $unitSellingId,
            'selling_unit_code' => 'PIECE',
            'conversion_factor' => 1,
            'flash_price' => 8,
            'allocation_base' => 20,
        ]);
    }

    public function test_november_wholesale_flash_is_visible_in_customer_app_but_van_only(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 22:45:00'));

        $this->getJson('/api/v1/b2b/flash-offers/public?store_id='.$this->storeId)
            ->assertOk()
            ->assertJsonPath('fulfillment_channel', 'van')
            ->assertJsonPath('data.0.id', (int) $this->offer->id)
            ->assertJsonPath('data.0.products.0.id', (int) $this->line->id)
            ->assertJsonPath('data.0.products.0.name', 'مكرونات')
            ->assertJsonPath('data.0.products.0.selling_unit_name', 'قطعة')
            ->assertJsonPath('data.0.products.0.flash_price', 8)
            ->assertJsonPath('data.0.products.0.normal_price', 10)
            ->assertJsonPath('data.0.products.0.available_quantity', 20)
            ->assertJsonPath('data.0.fulfillment_channel', 'van');

        $this->travelTo(Carbon::parse('2026-12-01 00:00:00'));
        $this->getJson('/api/v1/b2b/flash-offers/public?store_id='.$this->storeId)
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_customer_app_visibility_can_be_disabled_without_disabling_van_and_never_allows_b2b_checkout(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 22:45:00'));

        // Van remains enabled but the Customer App Offers page must not show it.
        $this->offer->update(['channels' => ['van']]);
        $this->getJson('/api/v1/b2b/flash-offers/public?store_id='.$this->storeId)
            ->assertOk()->assertJsonCount(0, 'data');

        // Display checkbox restores untargeted visibility to every wholesale
        // Customer App visitor without selecting any individual customer.
        $this->offer->update(['channels' => ['van', 'customer']]);
        $this->getJson('/api/v1/b2b/flash-offers/public?store_id='.$this->storeId)
            ->assertOk()
            ->assertJsonPath('data.0.id', (int) $this->offer->id)
            ->assertJsonPath('data.0.fulfillment_channel', 'van');

        $user = User::factory()->create(['is_active' => true]);
        $legacy = Customer::query()->create([
            'user_id' => $user->id,
            'type' => 'b2b',
            'name' => 'Untargeted Wholesale Buyer',
        ]);
        B2bCustomer::query()->create([
            'legacy_customer_id' => $legacy->id,
            'user_id' => $user->id,
            'name' => 'Untargeted Wholesale Buyer',
        ]);
        Sanctum::actingAs($user, ['app:customer']);
        $this->getJson('/api/v1/b2b/flash-offers?store_id='.$this->storeId)
            ->assertOk()
            ->assertJsonPath('data.0.id', (int) $this->offer->id)
            ->assertJsonPath('fulfillment_channel', 'van');

        try {
            app(FlashOfferService::class)->reserve(
                (int) $user->id, (int) $this->line->id, 1, 'customer', 'b2b-display-only',
            );
            $this->fail('Wholesale Customer Flash checkout must never be allowed.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame('WHOLESALE_VAN_ONLY', $exception->getMessage());
        }

        $this->assertDatabaseMissing('flash_reservations', [
            'flash_offer_id' => $this->offer->id,
            'channel' => 'customer',
        ]);
    }

    public function test_existing_van_only_offer_is_backfilled_without_losing_customer_targeting(): void
    {
        $this->offer->update([
            'channels' => ['van'],
            'audience_customer_ids' => [123],
        ]);

        $migration = require database_path(
            'migrations/2026_11_05_092000_enable_existing_wholesale_flash_customer_app_discovery.php',
        );
        $migration->up();
        $this->assertSame(['van', 'customer'], $this->offer->fresh()->channels);
        $this->assertSame([123], $this->offer->fresh()->audience_customer_ids);

        // Safe to retry: never rewrite existing audience or channel choices.
        $migration->up();
        $this->assertSame(['van', 'customer'], $this->offer->fresh()->channels);
        $this->assertSame([123], $this->offer->fresh()->audience_customer_ids);
    }

    public function test_b2b_targeted_offers_are_private_and_stock_exhaustion_is_not_advertised(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 22:45:00'));
        $user = User::factory()->create(['is_active' => true]);
        $legacy = Customer::query()->create([
            'user_id' => $user->id,
            'type' => 'b2b',
            'name' => 'Wholesale Pasta Buyer',
        ]);
        B2bCustomer::query()->create([
            'legacy_customer_id' => $legacy->id,
            'user_id' => $user->id,
            'name' => 'Wholesale Pasta Buyer',
        ]);
        $this->offer->update(['audience_customer_ids' => [(int) $legacy->id]]);

        $this->getJson('/api/v1/b2b/flash-offers/public?store_id='.$this->storeId)
            ->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs($user, ['app:customer']);
        $this->getJson('/api/v1/b2b/flash-offers?store_id='.$this->storeId)
            ->assertOk()
            ->assertJsonPath('data.0.products.0.id', (int) $this->line->id);

        DB::table('inventories')->where('product_id', $this->productId)
            ->update(['reserved_quantity' => 100]);
        $this->getJson('/api/v1/b2b/flash-offers?store_id='.$this->storeId)
            ->assertOk()->assertJsonCount(0, 'data');
    }
}
