<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformCustomerAddressTest extends TestCase
{
    use RefreshDatabase;

    private int $retailStoreId;

    private int $wholesaleStoreId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);

        $b2bTypeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $b2cTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        $this->wholesaleStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2bTypeId,
            'code' => 'MAIN-B2B',
            'name' => 'Main Wholesale',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->retailStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cTypeId,
            'code' => 'ADDRESS-RETAIL',
            'name' => 'Address Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config(['foodex.platform_wholesale_store_code' => 'MAIN-B2B']);
    }

    public function test_platform_customer_has_scoped_b2c_address_book_with_precise_location(): void
    {
        $user = $this->registerPlatformCustomer(
            'address-owner@example.test',
            '+201000001111',
        );
        $platformCustomerId = (int) DB::table('platform_customers')
            ->where('user_id', $user->id)
            ->value('id');

        $first = $this->postJson('/api/v1/profile/addresses', [
            'label' => 'Home',
            'recipient_name' => 'Address Owner',
            'delivery_phone' => '+201000001111',
            'line1' => 'Street 10, Building 5',
            'city' => 'Cairo',
            'area' => 'Nasr City',
            'country_code' => 'eg',
            'country' => 'Egypt',
            'governorate' => 'Cairo',
            'block' => '10',
            'street' => 'Street 10',
            'building' => '5',
            'floor' => '2',
            'apartment' => '7',
            'landmark' => 'Near the park',
            'delivery_notes' => 'Call on arrival',
            'latitude' => 30.044420,
            'longitude' => 31.235712,
            'location_accuracy_meters' => 8.5,
            'location_source' => 'current_location',
        ])->assertCreated()
            ->assertJsonPath('country_code', 'EG')
            ->assertJsonPath('location_source', 'current_location')
            ->assertJsonPath('is_default', true);

        $firstId = (int) $first->json('id');

        $this->assertDatabaseHas('addresses', [
            'id' => $firstId,
            'platform_customer_id' => $platformCustomerId,
            'b2b_customer_id' => null,
            'b2c_customer_id' => null,
            'commerce_channel' => 'b2c',
            'location_source' => 'current_location',
            'is_default' => true,
        ]);

        $this->getJson('/api/v1/profile/addresses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $firstId)
            ->assertJsonPath('data.0.street', 'Street 10');

        $this->withHeader('X-FOODEX-Store-ID', (string) $this->retailStoreId)
            ->getJson('/api/v1/profile/addresses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $firstId);

        $this->getJson("/api/v1/profile/addresses/{$firstId}")
            ->assertOk()
            ->assertJsonPath('id', $firstId)
            ->assertJsonPath('landmark', 'Near the park');

        $second = $this->postJson('/api/v1/profile/addresses', [
            'label' => 'Office',
            'line1' => 'Office Street 2',
            'city' => 'Cairo',
            'country_code' => 'EG',
            'latitude' => 30.050000,
            'longitude' => 31.240000,
            'location_source' => 'map_pin',
            'is_default' => true,
        ])->assertCreated()
            ->assertJsonPath('is_default', true);

        $secondId = (int) $second->json('id');

        $this->assertDatabaseHas('addresses', [
            'id' => $firstId,
            'is_default' => false,
        ]);
        $this->assertDatabaseHas('addresses', [
            'id' => $secondId,
            'is_default' => true,
        ]);

        $this->postJson("/api/v1/profile/addresses/{$firstId}/default")
            ->assertOk()
            ->assertJsonPath('id', $firstId)
            ->assertJsonPath('is_default', true);

        $this->assertDatabaseHas('addresses', [
            'id' => $secondId,
            'is_default' => false,
        ]);

        $this->patchJson("/api/v1/profile/addresses/{$firstId}", [
            'latitude' => 91,
            'longitude' => 31.2,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude']);

        $this->deleteJson("/api/v1/profile/addresses/{$firstId}")
            ->assertNoContent();

        $this->assertSoftDeleted('addresses', ['id' => $firstId]);
        $this->assertDatabaseHas('addresses', [
            'id' => $secondId,
            'is_default' => true,
        ]);
    }

    public function test_b2b_and_b2c_address_books_and_checkout_options_do_not_cross_over(): void
    {
        $this->registerPlatformCustomer(
            'address-context-owner@example.test',
            '+201000001116',
        );

        $retail = $this->postJson('/api/v1/profile/addresses', [
            'label' => 'Retail Home',
            'line1' => 'Retail Street',
            'city' => 'Cairo',
            'country_code' => 'EG',
        ])->assertCreated()
            ->assertJsonPath('commerce_channel', 'b2c');

        $wholesale = $this->withHeader('X-FOODEX-Customer-Domain', 'b2b')
            ->postJson('/api/v1/profile/addresses', [
                'label' => 'Wholesale Office',
                'line1' => 'Wholesale Street',
                'city' => 'Cairo',
                'country_code' => 'EG',
            ])->assertCreated()
            ->assertJsonPath('commerce_channel', 'b2b');

        $retailId = (int) $retail->json('id');
        $wholesaleId = (int) $wholesale->json('id');

        $this->getJson('/api/v1/profile/addresses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $retailId);

        $this->withHeader('X-FOODEX-Customer-Domain', 'b2b')
            ->getJson('/api/v1/profile/addresses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wholesaleId);

        $this->withHeader('X-FOODEX-Customer-Domain', 'b2b')
            ->getJson("/api/v1/profile/addresses/{$retailId}")
            ->assertNotFound();

        $this->getJson("/api/v1/profile/addresses/{$wholesaleId}")
            ->assertNotFound();

        $this->getJson('/api/v1/checkout/options?store_id='.$this->retailStoreId)
            ->assertOk()
            ->assertJsonCount(1, 'addresses')
            ->assertJsonPath('addresses.0.id', $retailId);

        $this->withHeader('X-FOODEX-Customer-Domain', 'b2b')
            ->getJson('/api/v1/b2b/checkout/options?store_id='.$this->wholesaleStoreId)
            ->assertOk()
            ->assertJsonCount(1, 'addresses')
            ->assertJsonPath('addresses.0.id', $wholesaleId);
    }

    public function test_geographic_location_sources_require_real_coordinate_pairs(): void
    {
        $this->registerPlatformCustomer(
            'address-location-validation@example.test',
            '+201000001114',
        );

        $this->postJson('/api/v1/profile/addresses', [
            'label' => 'Pinned without coordinates',
            'line1' => 'Street 1',
            'city' => 'Cairo',
            'country_code' => 'EG',
            'location_source' => 'map_pin',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);

        $created = $this->postJson('/api/v1/profile/addresses', [
            'label' => 'Manual',
            'line1' => 'Street 2',
            'city' => 'Cairo',
            'country_code' => 'EG',
            'location_source' => 'manual',
        ])->assertCreated();

        $addressId = (int) $created->json('id');

        $this->patchJson("/api/v1/profile/addresses/{$addressId}", [
            'location_source' => 'current_location',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);

        $this->patchJson("/api/v1/profile/addresses/{$addressId}", [
            'location_source' => 'current_location',
            'latitude' => 30.04442,
            'longitude' => 31.235712,
            'location_accuracy_meters' => 6.4,
        ])->assertOk()
            ->assertJsonPath('location_source', 'current_location')
            ->assertJsonPath('latitude', 30.04442)
            ->assertJsonPath('longitude', 31.235712);

        $this->patchJson("/api/v1/profile/addresses/{$addressId}", [
            'latitude' => null,
            'longitude' => null,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    public function test_location_accuracy_cannot_exist_without_coordinates(): void
    {
        $this->registerPlatformCustomer(
            'address-accuracy-validation@example.test',
            '+201000001115',
        );

        $this->postJson('/api/v1/profile/addresses', [
            'label' => 'Manual accuracy only',
            'line1' => 'Street 3',
            'city' => 'Cairo',
            'country_code' => 'EG',
            'location_source' => 'manual',
            'location_accuracy_meters' => 12.5,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['location_accuracy_meters']);
    }

    public function test_platform_customer_cannot_access_another_platform_customers_address(): void
    {
        $owner = $this->registerPlatformCustomer(
            'address-owner-a@example.test',
            '+201000001112',
        );

        $foreignUser = $this->registerPlatformCustomer(
            'address-owner-b@example.test',
            '+201000001113',
        );

        $foreign = $this->postJson('/api/v1/profile/addresses', [
            'label' => 'Foreign',
            'line1' => 'Foreign Street',
            'city' => 'Cairo',
            'country_code' => 'EG',
        ])->assertCreated();

        $foreignAddressId = (int) $foreign->json('id');
        $foreignPlatformCustomerId = (int) DB::table('platform_customers')
            ->where('user_id', $foreignUser->id)
            ->value('id');

        $this->assertDatabaseHas('addresses', [
            'id' => $foreignAddressId,
            'platform_customer_id' => $foreignPlatformCustomerId,
        ]);

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/profile/addresses/{$foreignAddressId}")
            ->assertNotFound();

        $this->patchJson("/api/v1/profile/addresses/{$foreignAddressId}", [
            'label' => 'Stolen',
        ])->assertNotFound();

        $this->postJson("/api/v1/profile/addresses/{$foreignAddressId}/default")
            ->assertNotFound();

        $this->deleteJson("/api/v1/profile/addresses/{$foreignAddressId}")
            ->assertNotFound();
    }

    private function registerPlatformCustomer(string $email, string $phone): User
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Platform Customer',
            'email' => $email,
            'phone' => $phone,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'locale' => 'ar',
        ])->assertCreated()
            ->assertJsonPath('platform_customer', true);

        $user = User::query()->where('email', $email)->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }
}
