<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverB2BCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_b2b_driver_cannot_login_to_driver_app_after_van_cutover(): void
    {
        [$user] = $this->legacyB2bDriver('cutover-login@example.test');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'app' => 'driver',
        ])->assertForbidden()
            ->assertSee('not authorized for the Driver app');

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'foodex-driver',
        ]);
    }

    public function test_b2b_driver_cannot_register_driver_push_device_after_van_cutover(): void
    {
        [$user] = $this->legacyB2bDriver('cutover-push@example.test');

        Sanctum::actingAs($user, ['app:driver']);

        $this->postJson('/api/v1/push/devices', [
            'app' => 'driver',
            'platform' => 'android',
            'environment' => 'production',
            'token' => 'legacy-b2b-driver-device-token',
        ])->assertForbidden();

        $this->assertDatabaseMissing('push_device_tokens', [
            'user_id' => $user->id,
            'app' => 'driver',
        ]);
    }

    /** @return array{0: User, 1: Driver} */
    private function legacyB2bDriver(string $email): array
    {
        $this->seed(CoreReferenceSeeder::class);

        $user = User::query()->create([
            'name' => 'Legacy B2B Driver',
            'email' => $email,
            'password' => Hash::make('correct-password'),
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'B2B_DRIVER')->firstOrFail());

        $driver = Driver::query()->create([
            'user_id' => $user->id,
            'store_id' => app(WholesalePrincipal::class)->storeId(),
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);

        return [$user, $driver];
    }
}
