<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2CustomerB2bAddressProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private int $registrations = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);

        $wholesaleType = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        DB::table('stores')->insert([
            'store_type_id' => $wholesaleType,
            'code' => 'MAIN-B2B',
            'name' => 'Main Wholesale',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config(['foodex.platform_wholesale_store_code' => 'MAIN-B2B']);
    }

    public function test_foreign_platform_identity_cannot_be_bypassed_with_owned_b2b_foreign_key(): void
    {
        $owner = $this->registerCustomer('v2-address-platform-owner@example.test');
        $addressId = $this->createWholesaleAddress('Original Wholesale');

        $foreign = $this->registerCustomer('v2-address-platform-foreign@example.test');
        $foreignPlatformId = $this->platformId($foreign);

        DB::table('addresses')->where('id', $addressId)->update([
            'platform_customer_id' => $foreignPlatformId,
        ]);

        Sanctum::actingAs($owner);
        $this->assertDeniedOnEveryAddressAction($addressId);

        Sanctum::actingAs($foreign);
        $this->assertDeniedOnEveryAddressAction($addressId);

        $this->assertDatabaseHas('addresses', [
            'id' => $addressId,
            'platform_customer_id' => $foreignPlatformId,
            'label' => 'Original Wholesale',
            'deleted_at' => null,
        ]);
    }

    public function test_foreign_b2b_identity_cannot_be_bypassed_with_owned_platform_foreign_key(): void
    {
        $owner = $this->registerCustomer('v2-address-b2b-owner@example.test');
        $addressId = $this->createWholesaleAddress('Original B2B');

        $foreign = $this->registerCustomer('v2-address-b2b-foreign@example.test');
        $foreignB2bId = $this->b2bId($foreign);

        DB::table('addresses')->where('id', $addressId)->update([
            'b2b_customer_id' => $foreignB2bId,
        ]);

        Sanctum::actingAs($owner);
        $this->assertDeniedOnEveryAddressAction($addressId);

        Sanctum::actingAs($foreign);
        $this->assertDeniedOnEveryAddressAction($addressId);

        $this->assertDatabaseHas('addresses', [
            'id' => $addressId,
            'b2b_customer_id' => $foreignB2bId,
            'label' => 'Original B2B',
            'deleted_at' => null,
        ]);
    }

    public function test_valid_and_legacy_wholesale_addresses_remain_readable_and_mutable(): void
    {
        $owner = $this->registerCustomer('v2-address-legacy-owner@example.test');
        $canonicalId = $this->createWholesaleAddress('Canonical Wholesale');

        $legacyDomainOnlyId = $this->createWholesaleAddress('Legacy B2B Domain');
        DB::table('addresses')->where('id', $legacyDomainOnlyId)->update([
            'platform_customer_id' => null,
            'commerce_channel' => 'b2c',
        ]);

        $legacyPlatformOnlyId = $this->createWholesaleAddress('Legacy Platform');
        DB::table('addresses')->where('id', $legacyPlatformOnlyId)->update([
            'platform_customer_id' => null,
            'b2b_customer_id' => null,
        ]);

        Sanctum::actingAs($owner);
        $this->withHeader('X-FOODEX-Customer-Domain', 'b2b')
            ->getJson('/api/v1/profile/addresses')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        foreach ([$canonicalId, $legacyDomainOnlyId, $legacyPlatformOnlyId] as $addressId) {
            $this->withHeader('X-FOODEX-Customer-Domain', 'b2b')
                ->getJson("/api/v1/profile/addresses/{$addressId}")
                ->assertOk()
                ->assertJsonPath('id', $addressId);
        }

        $this->withHeader('X-FOODEX-Customer-Domain', 'b2b')
            ->patchJson("/api/v1/profile/addresses/{$legacyDomainOnlyId}", [
                'label' => 'Renamed Legacy Wholesale',
            ])->assertOk()
            ->assertJsonPath('label', 'Renamed Legacy Wholesale');

        $this->assertDatabaseHas('addresses', [
            'id' => $legacyDomainOnlyId,
            'label' => 'Renamed Legacy Wholesale',
        ]);

        $this->withHeader('X-FOODEX-Customer-Domain', 'b2b')
            ->getJson("/api/v1/profile/addresses/{$legacyPlatformOnlyId}")
            ->assertOk();
    }

    private function assertDeniedOnEveryAddressAction(int $addressId): void
    {
        $this->withHeader('X-FOODEX-Customer-Domain', 'b2b')
            ->getJson('/api/v1/profile/addresses')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        foreach (['ar', 'en'] as $locale) {
            $this->withHeaders([
                'X-FOODEX-Customer-Domain' => 'b2b',
                'Accept-Language' => $locale,
            ])->getJson("/api/v1/profile/addresses/{$addressId}")
                ->assertNotFound();
        }

        $this->withHeader('X-FOODEX-Customer-Domain', 'b2b')
            ->patchJson("/api/v1/profile/addresses/{$addressId}", ['label' => 'Unauthorized'])
            ->assertNotFound();

        $this->withHeader('X-FOODEX-Customer-Domain', 'b2b')
            ->postJson("/api/v1/profile/addresses/{$addressId}/default")
            ->assertNotFound();

        $this->withHeader('X-FOODEX-Customer-Domain', 'b2b')
            ->deleteJson("/api/v1/profile/addresses/{$addressId}")
            ->assertNotFound();
    }

    private function registerCustomer(string $email): User
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Address Customer',
            'email' => $email,
            'phone' => '+20100000000'.(++$this->registrations),
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'locale' => 'ar',
        ])->assertCreated();

        $user = User::query()->where('email', $email)->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }

    private function createWholesaleAddress(string $label): int
    {
        $response = $this->withHeader('X-FOODEX-Customer-Domain', 'b2b')
            ->postJson('/api/v1/profile/addresses', [
                'label' => $label,
                'line1' => '10 Wholesale Street',
                'city' => 'Kuwait City',
                'country_code' => 'KW',
            ])->assertCreated()
            ->assertJsonPath('commerce_channel', 'b2b');

        return (int) $response->json('id');
    }

    private function platformId(User $user): int
    {
        return (int) DB::table('platform_customers')
            ->where('user_id', $user->getKey())
            ->value('id');
    }

    private function b2bId(User $user): int
    {
        return (int) DB::table('b2b_customers')
            ->where('user_id', $user->getKey())
            ->value('id');
    }
}
