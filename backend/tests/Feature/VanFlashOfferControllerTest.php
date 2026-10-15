<?php

namespace Tests\Feature;

use App\Models\B2bCustomer;
use App\Models\Customer;
use App\Models\FlashOffer;
use App\Models\FlashOfferProduct;
use App\Models\Role;
use App\Models\User;
use App\Models\VanVisit;
use App\Services\VanRegistryService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class VanFlashOfferControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_flash_offer_feed_exposes_authoritative_business_labels(): void
    {
        $actor = $this->vanActor();

        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'VAN-FLASH-LABELS',
            'name' => 'Van Flash Labels',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2b',
            'code' => 'van-flash-labels',
            'name' => 'Van Flash Labels',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-VAN-FLASH-LABELS',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'VAN-FLASH-LABELS-001',
            'name' => 'Sparkling Water',
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
        $sellingUnitId = (int) DB::table('product_selling_units')->insertGetId([
            'product_id' => $productId,
            'unit_id' => $unitId,
            'code' => 'CARTON',
            'name' => 'Carton',
            'conversion_factor' => 10,
            'price' => 7,
            'sku' => 'VAN-FLASH-CARTON',
            'barcode' => null,
            'is_base' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerUser = User::factory()->create(['is_active' => true]);
        $legacyCustomer = Customer::query()->create([
            'user_id' => $customerUser->id,
            'type' => 'b2b',
            'name' => 'Flash Buyer',
            'email' => 'flash-buyer@example.test',
        ]);
        $customer = B2bCustomer::query()->create([
            'legacy_customer_id' => $legacyCustomer->id,
            'store_id' => $storeId,
            'user_id' => $customerUser->id,
            'name' => 'Flash Buyer',
            'email' => 'flash-buyer@example.test',
        ]);
        VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => $customer->id,
            'store_id' => $storeId,
            'status' => 'planned',
        ]);

        foreach (['flash_offers_enabled', 'van_offers_enabled'] as $key) {
            DB::table('settings')->updateOrInsert(
                ['store_id' => null, 'key' => $key],
                [
                    'value' => json_encode(true, JSON_THROW_ON_ERROR),
                    'is_secret' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        $offer = FlashOffer::query()->create([
            'store_id' => $storeId,
            'name' => 'Business labels',
            'title_ar' => 'عرض أسماء',
            'title_en' => 'Business labels',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'timezone' => 'Asia/Kuwait',
            'channels' => ['van'],
            'allocation_mode' => 'shared',
            'reservation_seconds' => 300,
            'retry_count' => 0,
            'cooldown_seconds' => 0,
            'priority' => 10,
            'popup_frequency' => 'once_per_session',
            'counts_toward_normal_quota' => true,
            'stackable' => false,
            'kill_switch' => false,
        ]);
        $offerProduct = FlashOfferProduct::query()->create([
            'flash_offer_id' => $offer->id,
            'product_id' => $productId,
            'selling_unit_id' => $sellingUnitId,
            'selling_unit_code' => 'CARTON',
            'conversion_factor' => 10,
            'flash_price' => 7,
            'allocation_base' => 1000,
        ]);

        $this->getJson(
            '/api/v1/van/customers/b2b/'.$customer->id.'/flash-offers?store_id='.$storeId,
        )
            ->assertOk()
            ->assertJsonPath('data.0.products.0.id', $offerProduct->id)
            ->assertJsonPath('data.0.products.0.product_id', $productId)
            ->assertJsonPath('data.0.products.0.product_name', 'Sparkling Water')
            ->assertJsonPath('data.0.products.0.selling_unit_code', 'CARTON')
            ->assertJsonPath('data.0.products.0.selling_unit_name', 'Carton');
    }

    private function vanActor(): User
    {
        $this->seed(CoreReferenceSeeder::class);

        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail());

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'FLASH-LABEL-VAN-'.$actor->id]);
        $registry->assign($actor, $van, [
            'representative_user_id' => $actor->id,
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);

        Sanctum::actingAs($actor, ['app:van']);

        return $actor;
    }
}
