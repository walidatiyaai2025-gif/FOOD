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

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);

        $b2bTypeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $b2cTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        DB::table('stores')->insert([
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

    public function test_platform_customer_has_one_cross_channel_address_book_with_precise_location(): void
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
