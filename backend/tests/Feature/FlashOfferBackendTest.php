<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\B2cCustomer;
use App\Models\Customer;
use App\Models\FlashOffer;
use App\Models\FlashOfferProduct;
use App\Models\User;
use App\Models\VanVisit;
use App\Services\FlashOfferService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

        foreach ([
            'commercial_rules_enabled',
            'flash_offers_enabled',
            'customer_flash_popup_enabled',
            'van_offers_enabled',
        ] as $flag) {
            $this->flag($flag, true);
        }
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

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('FLASH_NOT_ACTIVE');

        app(FlashOfferService::class)->reserve(
            $this->user->id,
            $product->id,
            1,
            'customer',
            'idem-ended',
        );
    }

    public function test_customer_flash_contract_converts_reservation_to_order_invoice_quota_and_order_inventory_reservation(): void
    {
        [$offer, $offerProduct] = $this->offer();
        $legacyCustomer = Customer::query()->create([
            'user_id' => $this->user->id,
            'type' => 'b2c',
            'name' => 'Flash Customer',
            'email' => $this->user->email,
        ]);
        $domainCustomer = B2cCustomer::query()->create([
            'legacy_customer_id' => $legacyCustomer->id,
            'store_id' => $this->storeId,
            'user_id' => $this->user->id,
            'name' => 'Flash Customer',
            'email' => $this->user->email,
        ]);
        $address = Address::query()->create([
            'customer_id' => $legacyCustomer->id,
            'b2c_customer_id' => $domainCustomer->id,
            'label' => 'Home',
            'line1' => 'Flash Street 1',
            'city' => 'Kuwait City',
            'country_code' => 'KW',
            'is_default' => true,
        ]);

        Sanctum::actingAs($this->user);

        $this->withHeaders([
            'X-FOODEX-Store-ID' => (string) $this->storeId,
            'X-FOODEX-Customer-Domain' => 'b2c',
        ])->getJson('/api/v1/flash-offers?store_id='.$this->storeId.'&channel=customer')
            ->assertOk()
            ->assertJsonPath('data.0.id', $offer->id)
            ->assertJsonPath('data.0.selling_units.0.id', $offerProduct->id)
            ->assertJsonPath('data.0.flash_price', 7)
            ->assertJsonPath('data.0.normal_price', 10)
            ->assertJsonPath('data.0.eligible', true);

        $reserve = $this->withHeaders([
            'X-FOODEX-Store-ID' => (string) $this->storeId,
            'X-FOODEX-Customer-Domain' => 'b2c',
        ])->postJson('/api/v1/flash-offers/products/'.$offerProduct->id.'/reserve', [
            'store_id' => $this->storeId,
            'channel' => 'customer',
            'quantity' => 1,
            'idempotency_key' => 'customer-flash-reserve-0001',
        ])->assertCreated()
            ->assertJsonPath('offer_id', $offer->id)
            ->assertJsonPath('reserved_base_quantity', 10);

        $reservationId = (string) $reserve->json('id');
        $this->assertNotSame('', $reservationId);

        $this->withHeaders([
            'X-FOODEX-Store-ID' => (string) $this->storeId,
            'X-FOODEX-Customer-Domain' => 'b2c',
        ])->getJson('/api/v1/flash-reservations/active?store_id='.$this->storeId.'&channel=customer')
            ->assertOk()
            ->assertJsonPath('data.id', $reservationId);

        $confirmHeaders = [
            'Idempotency-Key' => 'customer-flash-checkout-0001',
            'X-FOODEX-Store-ID' => (string) $this->storeId,
            'X-FOODEX-Customer-Domain' => 'b2c',
        ];
        $confirmPayload = [
            'store_id' => $this->storeId,
            'channel' => 'customer',
            'address_id' => $address->id,
            'payment_method' => 'cash_on_delivery',
        ];

        $checkout = $this->withHeaders($confirmHeaders)
            ->postJson('/api/v1/flash-reservations/'.$reservationId.'/confirm', $confirmPayload)
            ->assertCreated()
            ->assertJsonPath('store_id', $this->storeId)
            ->assertJsonPath('status', 'pending');

        $orderId = (int) $checkout->json('id');
        $this->assertGreaterThan(0, $orderId);

        $this->assertDatabaseHas('flash_reservations', [
            'id' => $reservationId,
            'status' => 'confirmed',
            'order_id' => $orderId,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'b2c_customer_id' => $domainCustomer->id,
            'checkout_idempotency_key' => 'customer-flash-checkout-0001',
            'subtotal' => 10,
            'discount_total' => 3,
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'product_id' => $this->productId,
            'selling_unit_code_snapshot' => 'CARTON',
            'selling_unit_quantity' => 1,
            'base_quantity' => 10,
            'conversion_factor_snapshot' => 10,
            'unit_price' => 7,
        ]);
        $this->assertDatabaseHas('commercial_quota_reservations', [
            'order_id' => $orderId,
            'product_id' => $this->productId,
            'customer_id' => $legacyCustomer->id,
            'base_quantity' => 10,
            'status' => 'RESERVED',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'order',
            'reference_id' => $orderId,
            'type' => 'reserve',
            'quantity' => 10,
            'reason' => 'flash_checkout_claim',
        ]);
        $this->assertSame(10.0, (float) DB::table('inventories')->where('id', $this->inventoryId)->value('reserved_quantity'));

        $invoiceId = (int) DB::table('invoices')->where('order_id', $orderId)->value('id');
        $this->assertGreaterThan(0, $invoiceId);
        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoiceId,
            'product_id' => $this->productId,
            'quantity' => 1,
            'quantity_conversion_factor' => 10,
            'unit_price' => 7,
        ]);
        $this->assertDatabaseHas('payments', [
            'order_id' => $orderId,
            'invoice_id' => $invoiceId,
            'provider' => 'cash_on_delivery',
            'status' => 'pending',
        ]);

        $pricing = json_decode((string) DB::table('orders')->where('id', $orderId)->value('pricing_snapshot'), true);
        $this->assertSame('flash_offer_v1', $pricing['source']);
        $this->assertSame($offer->id, $pricing['flash_offer']['id']);
        $this->assertSame($reservationId, $pricing['flash_offer']['reservation_id']);

        $this->withHeaders($confirmHeaders)
            ->postJson('/api/v1/flash-reservations/'.$reservationId.'/confirm', $confirmPayload)
            ->assertOk()
            ->assertJsonPath('id', $orderId);
        $this->assertSame(1, DB::table('orders')->where('b2c_customer_id', $domainCustomer->id)->count());
    }

    public function test_confirming_expired_reservation_commits_expiry_and_releases_inventory(): void
    {
        [, $product] = $this->offer(reservationSeconds: 30);
        $service = app(FlashOfferService::class);
        $reservation = $service->reserve($this->user->id, $product->id, 1, 'customer', 'confirm-expired-1');
        DB::table('flash_reservations')->where('id', $reservation->id)->update(['expires_at' => now()->subSecond()]);

        try {
            $service->confirm($reservation->id, $this->user->id);
            $this->fail('Expired Flash reservation confirmation must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame('FLASH_RESERVATION_EXPIRED', $exception->getMessage());
        }

        $this->assertDatabaseHas('flash_reservations', ['id' => $reservation->id, 'status' => 'expired']);
        $this->assertSame(0.0, (float) DB::table('inventories')->where('id', $this->inventoryId)->value('reserved_quantity'));
    }

    public function test_flash_flag_defaults_off_and_blocks_new_reservations(): void
    {
        DB::table('settings')->whereNull('store_id')->where('key', 'flash_offers_enabled')->delete();
        [, $product] = $this->offer();

        $this->assertCount(0, app(FlashOfferService::class)->activeOffers($this->storeId, 'customer', $this->user->id));

        try {
            app(FlashOfferService::class)->reserve($this->user->id, $product->id, 1, 'customer', 'flag-off');
            $this->fail('Flash reservation must be blocked while the canonical flag is disabled.');
        } catch (HttpException $exception) {
            $this->assertSame('FLASH_NOT_ACTIVE', $exception->getMessage());
        }
    }

    public function test_flash_audience_customer_group_region_and_route_is_applied_to_feed_and_reservation(): void
    {
        $legacy = Customer::query()->create([
            'user_id' => $this->user->id,
            'type' => 'b2c',
            'name' => 'Eligible Flash Customer',
        ]);
        $domain = B2cCustomer::query()->create([
            'legacy_customer_id' => $legacy->id,
            'store_id' => $this->storeId,
            'user_id' => $this->user->id,
            'name' => 'Eligible Flash Customer',
        ]);
        DB::table('addresses')->insert([
            'customer_id' => $legacy->id,
            'label' => 'Home',
            'line1' => 'Audience Street',
            'city' => 'Kuwait City',
            'area' => 'Salmiya',
            'country_code' => 'KW',
            'governorate' => 'Hawalli',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $groupId = (int) DB::table('commercial_customer_groups')->insertGetId([
            'store_id' => $this->storeId,
            'name' => 'Audience Group',
            'priority' => 5,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('commercial_customer_group_members')->insert([
            'customer_group_id' => $groupId,
            'customer_id' => $legacy->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor = User::factory()->create(['is_active' => true]);
        VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2c',
            'customer_id' => $domain->id,
            'store_id' => $this->storeId,
            'status' => 'planned',
            'idempotency_key' => 'audience-route-visit',
            'metadata' => ['route_code' => 'ROUTE-7'],
        ]);

        $outsider = User::factory()->create(['is_active' => true]);
        Customer::query()->create([
            'user_id' => $outsider->id,
            'type' => 'b2c',
            'name' => 'Outsider',
        ]);

        [$offer, $product] = $this->offer();
        $offer->update([
            'audience_customer_ids' => [$legacy->id],
            'audience_customer_group_ids' => [$groupId],
            'audience_regions' => ['Hawalli'],
            'audience_routes' => ['ROUTE-7'],
        ]);

        $service = app(FlashOfferService::class);
        $this->assertCount(1, $service->activeOffers($this->storeId, 'customer', $this->user->id));
        $this->assertCount(0, $service->activeOffers($this->storeId, 'customer', $outsider->id));

        $reservation = $service->reserve($this->user->id, $product->id, 1, 'customer', 'audience-ok');
        $this->assertSame($this->user->id, (int) $reservation->user_id);
        $service->release($reservation->id, $this->user->id);

        try {
            $service->reserve($outsider->id, $product->id, 1, 'customer', 'audience-denied');
            $this->fail('Out-of-audience customer must be denied.');
        } catch (HttpException $exception) {
            $this->assertSame('CUSTOMER_NOT_ELIGIBLE', $exception->getMessage());
        }
    }

    private function flag(string $key, bool $enabled): void
    {
        DB::table('settings')->updateOrInsert(
            ['store_id' => null, 'key' => $key],
            [
                'value' => json_encode($enabled, JSON_THROW_ON_ERROR),
                'is_secret' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
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
