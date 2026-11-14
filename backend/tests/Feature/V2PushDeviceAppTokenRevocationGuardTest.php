<?php

namespace Tests\Feature;

use App\Models\PushDeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2PushDeviceAppTokenRevocationGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_token_cannot_revoke_the_same_users_driver_or_van_devices(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $driver = $this->device($user, 'driver');
        $van = $this->device($user, 'van');

        Sanctum::actingAs($user, ['app:customer']);

        $this->deleteJson("/api/v1/push/devices/{$driver->id}")->assertNotFound();
        $this->deleteJson("/api/v1/push/devices/{$van->id}")->assertNotFound();

        $this->assertNull($driver->fresh()->revoked_at);
        $this->assertNull($van->fresh()->revoked_at);
    }

    public function test_matching_app_token_can_revoke_own_device_without_changing_other_app(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $customer = $this->device($user, 'customer');
        $driver = $this->device($user, 'driver');
        $van = $this->device($user, 'van');

        Sanctum::actingAs($user, ['app:driver']);

        $this->deleteJson("/api/v1/push/devices/{$driver->id}")
            ->assertOk()
            ->assertJsonPath('status', 'revoked');

        $this->assertNotNull($driver->fresh()->revoked_at);
        $this->assertNull($customer->fresh()->revoked_at);
        $this->assertNull($van->fresh()->revoked_at);

        Sanctum::actingAs($user, ['app:customer']);
        $this->deleteJson("/api/v1/push/devices/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('status', 'revoked');
        $this->assertNotNull($customer->fresh()->revoked_at);
        $this->assertNull($van->fresh()->revoked_at);

        Sanctum::actingAs($user, ['app:van']);
        $this->deleteJson("/api/v1/push/devices/{$van->id}")
            ->assertOk()
            ->assertJsonPath('status', 'revoked');
        $this->assertNotNull($van->fresh()->revoked_at);
    }

    public function test_matching_app_ability_never_bypasses_device_user_ownership(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $other = User::factory()->create(['is_active' => true]);
        $device = $this->device($owner, 'driver');

        Sanctum::actingAs($other, ['app:driver']);
        $this->deleteJson("/api/v1/push/devices/{$device->id}")->assertNotFound();
        $this->assertNull($device->fresh()->revoked_at);
    }

    public function test_missing_app_ability_cannot_revoke_own_device(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $device = $this->device($user, 'customer');

        Sanctum::actingAs($user, []);
        $this->deleteJson("/api/v1/push/devices/{$device->id}")->assertNotFound();
        $this->assertNull($device->fresh()->revoked_at);
    }

    public function test_guest_cannot_revoke_any_registered_device(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $device = $this->device($owner, 'customer');

        $this->deleteJson("/api/v1/push/devices/{$device->id}")->assertUnauthorized();
        $this->assertNull($device->fresh()->revoked_at);
    }

    private function device(User $user, string $app): PushDeviceToken
    {
        $token = "v2-app-revoke-{$app}-{$user->getKey()}";

        return PushDeviceToken::query()->create([
            'user_id' => $user->getKey(),
            'app' => $app,
            'platform' => 'android',
            'environment' => 'staging',
            'token_hash' => hash('sha256', $token),
            'token_encrypted' => $token,
            'revoked_at' => null,
        ]);
    }
}
