<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2PushDeviceAppRegistrationAbilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_app_token_cannot_register_van_device_for_same_van_authorized_user(): void
    {
        $user = $this->multiPersonaUser();
        $this->assertTrue($user->hasPermission('van.login'));

        Sanctum::actingAs($user, ['app:customer']);
        $this->postJson('/api/v1/push/devices', $this->payload('van', 'cross-app-van'))
            ->assertForbidden();
        $this->assertDatabaseCount('push_device_tokens', 0);

        Sanctum::actingAs($user, ['app:van']);
        $this->postJson('/api/v1/push/devices', $this->payload('van', 'matching-app-van'))
            ->assertCreated()
            ->assertJsonPath('data.app', 'van');
        $this->assertDatabaseHas('push_device_tokens', [
            'user_id' => $user->id,
            'app' => 'van',
            'token_hash' => hash('sha256', 'matching-app-van'),
            'revoked_at' => null,
        ]);
    }

    public function test_van_app_token_cannot_register_or_replace_customer_device_for_same_customer(): void
    {
        $user = $this->multiPersonaUser();

        Sanctum::actingAs($user, ['app:customer']);
        $deviceId = $this->postJson('/api/v1/push/devices', $this->payload('customer', 'matching-app-customer'))
            ->assertCreated()
            ->json('data.id');

        Sanctum::actingAs($user, ['app:van']);
        $this->postJson('/api/v1/push/devices', $this->payload('customer', 'foreign-app-customer'))
            ->assertForbidden();
        $this->postJson('/api/v1/push/devices', $this->payload('customer', 'matching-app-customer'))
            ->assertForbidden();

        $this->assertDatabaseCount('push_device_tokens', 1);
        $this->assertDatabaseHas('push_device_tokens', [
            'id' => $deviceId,
            'user_id' => $user->id,
            'app' => 'customer',
            'token_hash' => hash('sha256', 'matching-app-customer'),
            'revoked_at' => null,
        ]);
        $this->assertDatabaseMissing('push_device_tokens', [
            'token_hash' => hash('sha256', 'foreign-app-customer'),
        ]);
    }

    public function test_missing_app_ability_and_driver_only_token_cannot_register_customer_device(): void
    {
        $user = $this->multiPersonaUser();

        Sanctum::actingAs($user, []);
        $this->postJson('/api/v1/push/devices', $this->payload('customer', 'no-app-ability'))
            ->assertForbidden();

        Sanctum::actingAs($user, ['app:driver']);
        $this->postJson('/api/v1/push/devices', $this->payload('customer', 'driver-as-customer'))
            ->assertForbidden();

        // A Driver token without the authoritative Retail driver profile is still denied.
        $this->postJson('/api/v1/push/devices', $this->payload('driver', 'unscoped-driver'))
            ->assertForbidden();

        $this->assertDatabaseCount('push_device_tokens', 0);
    }

    public function test_guest_customer_registration_stays_available_but_guest_van_and_protected_route_are_denied(): void
    {
        $this->postJson('/api/v1/push/devices', $this->payload('customer', 'unauthenticated-customer'))
            ->assertUnauthorized();

        $guestPayload = $this->payload('customer', 'anonymous-customer-token');
        $guestPayload['install_id'] = 'v2-guest-customer-install';

        $this->postJson('/api/v1/push/devices/guest', $guestPayload)
            ->assertCreated()
            ->assertJsonPath('data.anonymous', true);

        $guestPayload['app'] = 'van';
        $guestPayload['token'] = 'anonymous-van-token';
        $this->postJson('/api/v1/push/devices/guest', $guestPayload)
            ->assertUnauthorized();

        $this->assertDatabaseCount('push_device_tokens', 1);
        $this->assertDatabaseHas('push_device_tokens', [
            'user_id' => null,
            'app' => 'customer',
            'token_hash' => hash('sha256', 'anonymous-customer-token'),
        ]);
    }

    private function multiPersonaUser(): User
    {
        $this->seed(CoreReferenceSeeder::class);
        $user = User::factory()->create(['is_active' => true, 'locale' => 'ar']);

        Customer::query()->create([
            'user_id' => $user->id,
            'type' => 'b2c',
            'name' => $user->name,
            'email' => $user->email,
        ]);

        $user->roles()->attach(Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail());

        return $user;
    }

    private function payload(string $app, string $token): array
    {
        return [
            'app' => $app,
            'platform' => 'android',
            'environment' => 'staging',
            'token' => $token,
        ];
    }
}
