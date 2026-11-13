<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class V2DriverRetailStoreSessionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_disabled_retail_store_rejects_new_driver_login_and_existing_driver_token_without_mutation(): void
    {
        [$user, $storeId, $driver] = $this->retailDriver('v2-driver-inactive-store@example.test');

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'app' => 'driver',
        ])->assertOk()
            ->assertJsonPath('user.driver_scope.driver_id', $driver->getKey());

        $token = (string) $login->json('token');
        $this->assertSame(['app:driver'], PersonalAccessToken::findToken($token)?->abilities);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        DB::table('stores')->where('id', $storeId)->update(['is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'app' => 'driver',
        ])->assertForbidden();

        // A valid bearer issued before store suspension is not a lease to
        // keep executing: middleware must recheck current store readiness.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/driver/wallet')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/driver/assignments')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/driver/location/heartbeat', [
            'latitude' => 29.3759,
            'longitude' => 47.9774,
            'captured_at' => now()->toISOString(),
        ])->assertForbidden();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('driver_current_locations', 0);
        $this->assertDatabaseCount('remittances', 0);
        $this->assertDatabaseHas('drivers', [
            'id' => $driver->getKey(),
            'user_id' => $user->getKey(),
            'is_active' => true,
        ]);

        DB::table('stores')->where('id', $storeId)->update(['is_active' => true]);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson('/api/v1/driver/wallet')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_retail_driver_profile_mislinked_to_wholesale_store_fails_closed_for_login_and_token(): void
    {
        [$user, $storeId] = $this->retailDriver('v2-driver-mismatched-store@example.test');

        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'app' => 'driver',
        ])->assertOk()->json('token');

        $b2bTypeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        DB::table('stores')->where('id', $storeId)->update(['store_type_id' => $b2bTypeId]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'app' => 'driver',
        ])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/driver/wallet')->assertForbidden();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('remittances', 0);
    }

    public function test_driver_username_trial_checks_the_same_live_retail_store_authority(): void
    {
        config()->set('foodex.mobile_trial_username_login', true);

        [$user, $storeId] = $this->retailDriver('v2-driver-trial-store@example.test', 'v2.driver.retail');

        $trial = $this->postJson('/api/v1/auth/mobile-trial', [
            'username' => 'v2.driver.retail',
            'app' => 'driver',
        ])->assertOk()->assertJsonPath('trial_username_login', true);

        $token = (string) $trial->json('token');
        $this->assertSame(['app:driver'], PersonalAccessToken::findToken($token)?->abilities);

        DB::table('stores')->where('id', $storeId)->update(['is_active' => false]);

        $this->postJson('/api/v1/auth/mobile-trial', [
            'username' => 'v2.driver.retail',
            'app' => 'driver',
        ])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/driver/wallet')->assertForbidden();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('users', [
            'id' => $user->getKey(),
            'is_active' => true,
        ]);
    }

    /** @return array{0:User,1:int,2:Driver} */
    private function retailDriver(string $email, ?string $username = null): array
    {
        $retailTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $retailTypeId,
            'code' => 'V2-DRIVER-RETAIL-'.strtoupper(substr(sha1($email), 0, 10)),
            'name' => 'V2 Retail Session Boundary',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::query()->create([
            'name' => 'V2 Retail Driver',
            'username' => $username,
            'email' => $email,
            'password' => Hash::make('correct-password'),
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'B2C_DRIVER')->firstOrFail());

        $driver = Driver::query()->create([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        return [$user, $storeId, $driver];
    }
}
