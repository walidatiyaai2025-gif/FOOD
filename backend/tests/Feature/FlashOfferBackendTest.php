<?php

namespace Tests\Feature;

use App\Models\FlashOffer;
use App\Models\FlashOfferProduct;
use App\Models\User;
use App\Services\FlashOfferService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FlashOfferBackendTest extends TestCase
{
    use RefreshDatabase;

    private int $storeId;

    private int $productId;

    private int $inventoryId;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        $this->user = User::factory()->create(['is_active' => true]);

        $b2cTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'FLASH-EA',
            'name' => 'Flash Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'FLASH-B2C',
            'name' => 'Flash Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $this->storeId,
            'channel' => 'b2c',
            'code' => 'flash',
            'name' => 'Flash Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoryId = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalogId,
            'name' => 'Flash',
            'slug' => 'flash',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'sku' => 'FLASH-001',
            'name' => 'Flash Product',
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

        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $this->storeId,
            'code' => 'FLASH-WH',
            'name' => 'Flash Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->inventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouseId,
            'product_id' => $this->productId,
            'quantity' => 100,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_reservation_is_atomic_idempotent_and_releases_stock(): void
    {
        [$offer, $product] = $this->offer();

        $service = app(FlashOfferService::class);
        $first = $service->reserve($this->user->id, $product->id, 2, 'customer', 'idem-1');
        $second = $service->reserve($this->user->id, $product->id, 2, 'customer', 'idem-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(20.0, (float) $first->reserved_base_quantity);
        $this->assertSame(20.0, (float) DB::table('inventories')->where('id', $this->inventoryId)->value('reserved_quantity'));
        $this->assertDatabaseCount('flash_reservations', 1);

        $service->release($first->id, $this->user->id);

        $this->assertSame(0.0, (float) DB::table('inventories')->where('id', $this->inventoryId)->value('reserved_quantity'));
        $this->assertDatabaseHas('flash_reservations', ['id' => $first->id, 'status' => 'released']);
        $this->assertDatabaseHas('flash_offer_events', ['flash_offer_id' => $offer->id, 'event' => 'reservation_released']);
    }

    public function test_expiry_is_timestamp_authoritative_and_returns_inventory(): void
    {
        [, $product] = $this->offer(reservationSeconds: 30);
        $service = app(FlashOfferService::class);

        $reservation = $service->reserve($this->user->id, $product->id, 1, 'customer', 'idem-expire');
        DB::table('flash_reservations')->where('id', $reservation->id)->update(['expires_at' => now()->subSecond()]);

        $this->assertSame(1, $service->expireDue());
        $this->assertDatabaseHas('flash_reservations', ['id' => $reservation->id, 'status' => 'expired']);
        $this->assertSame(0.0, (float) DB::table('inventories')->where('id', $this->inventoryId)->value('reserved_quantity'));
    }

    public function test_offer_cannot_reserve_after_end_even_when_stored_status_is_active(): void
    {
        [, $product] = $this->offer();
        DB::table('flash_offers')->update(['ends_at' => now()->subSecond(), 'status' => 'active']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('FLASH_NOT_ACTIVE');

        app(FlashOfferService::class)->reserve(
            $this->user->id,
            $product->id,
            1,
            'customer',
            'idem-ended',
        );
    }

    /** @return array{FlashOffer,FlashOfferProduct} */
    private function offer(int $reservationSeconds = 300): array
    {
        $offer = FlashOffer::query()->create([
            'store_id' => $this->storeId,
            'name' => 'Critical scenario',
            'title_ar' => 'عرض سريع',
            'title_en' => 'Flash Offer',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'timezone' => 'Asia/Kuwait',
            'channels' => ['customer', 'van'],
            'allocation_mode' => 'shared',
            'total_allocation_base' => 1000,
            'per_customer_limit_base' => 20,
            'reservation_seconds' => $reservationSeconds,
            'retry_count' => 2,
            'cooldown_seconds' => 60,
            'priority' => 10,
            'popup_frequency' => 'once_per_session',
            'counts_toward_normal_quota' => true,
            'stackable' => false,
            'kill_switch' => false,
        ]);

        $product = FlashOfferProduct::query()->create([
            'flash_offer_id' => $offer->id,
            'product_id' => $this->productId,
            'selling_unit_code' => 'CARTON',
            'conversion_factor' => 10,
            'flash_price' => 7,
            'allocation_base' => 1000,
        ]);

        return [$offer, $product];
    }
}
