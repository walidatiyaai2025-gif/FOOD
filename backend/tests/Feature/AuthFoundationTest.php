<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use App\Services\VanRegistryService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_login_view_profile_and_logout(): void
    {
        $user = User::query()->create([
            'name' => 'FOODEX User',
            'email' => 'user@example.test',
            'password' => Hash::make('correct-password'),
            'locale' => 'ar',
            'is_active' => true,
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.roles', [])
            ->assertJsonPath('user.permissions', [])
            ->assertJsonPath('user.store_ids', [])
            ->assertJsonPath('user.driver_scope', null);

        $token = $login->json('token');

        $this->withToken($token)
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('email', $user->email);

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }

    public function test_driver_login_exposes_persisted_authoritative_scope(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'AUTH-DRIVER-B2C',
            'name' => 'Auth Driver Retail Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::query()->create([
            'name' => 'Scoped Driver',
            'email' => 'scoped-driver@example.test',
            'password' => Hash::make('correct-password'),
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'B2C_DRIVER')->firstOrFail());
        $driver = Driver::query()->create([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk()
            ->assertJsonPath('user.driver_scope.driver_id', $driver->id)
            ->assertJsonPath('user.driver_scope.channel', 'b2c')
            ->assertJsonPath('user.driver_scope.store_id', $storeId);
    }

    public function test_login_exposes_effective_permissions_for_van_authorization(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $user = User::query()->create([
            'name' => 'Van Operator',
            'email' => 'van-operator@example.test',
            'password' => Hash::make('correct-password'),
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk();

        $this->assertContains('van.login', $login->json('user.permissions'));
    }

    public function test_same_driver_account_can_login_to_driver_and_van_with_effective_assignment(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'AUTH-UNIFIED-B2C',
            'name' => 'Unified Driver Van Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::query()->create([
            'name' => 'Unified Operator',
            'email' => 'unified-operator@example.test',
            'password' => Hash::make('correct-password'),
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(
            Role::query()->where('code', 'B2C_DRIVER')->firstOrFail()->id,
        );

        $driver = Driver::query()->create([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'AUTH-VAN-01']);
        $assignment = $registry->assign($user, $van, [
            'driver_id' => $driver->id,
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'app' => 'driver',
        ])->assertOk()
            ->assertJsonPath('user.driver_scope.driver_id', $driver->id);

        $vanLogin = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'app' => 'van',
        ])->assertOk()
            ->assertJsonPath('user.van_scope.van_id', $van->id)
            ->assertJsonPath('user.van_scope.assignment_id', $assignment->id)
            ->assertJsonPath('user.van_context_selection_reason', 'deterministic_primary');

        $this->assertContains('van.login', $vanLogin->json('user.permissions'));

        $this->withToken($vanLogin->json('token'))
            ->getJson('/api/v1/van/no-order-reasons')
            ->assertOk();
    }

    public function test_driver_and_van_tokens_cannot_cross_app_runtime_boundaries(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'AUTH-TOKEN-ISO',
            'name' => 'Token Isolation Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::query()->create([
            'name' => 'Dual App Operator',
            'email' => 'dual-app-operator@example.test',
            'password' => Hash::make('correct-password'),
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach([
            Role::query()->where('code', 'B2C_DRIVER')->firstOrFail()->id,
            Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail()->id,
        ]);
        $driver = Driver::query()->create([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'AUTH-TOKEN-VAN']);
        $registry->assign($user, $van, [
            'driver_id' => $driver->id,
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);

        $driverToken = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'app' => 'driver',
        ])->assertOk()->json('token');

        $vanToken = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'app' => 'van',
        ])->assertOk()->json('token');

        $this->withToken($vanToken)
            ->getJson('/api/v1/driver/wallet')
            ->assertForbidden();

        // Feature tests reuse the same application instance across requests.
        // Forget the resolved guard so the second request is authenticated from
        // its own bearer token rather than the previous request's cached guard.
        $this->app['auth']->forgetGuards();

        $this->withToken($driverToken)
            ->getJson('/api/v1/van/no-order-reasons')
            ->assertForbidden();
    }

    public function test_van_login_requires_permission_and_effective_assignment(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $user = User::query()->create([
            'name' => 'Driver Only',
            'email' => 'driver-only@example.test',
            'password' => Hash::make('correct-password'),
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'B2C_DRIVER')->firstOrFail());

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'app' => 'van',
        ])->assertForbidden();
    }

    public function test_representative_assignment_without_van_permission_remains_forbidden(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $user = User::query()->create([
            'name' => 'Representative Only',
            'email' => 'representative-only@example.test',
            'password' => Hash::make('correct-password'),
            'locale' => 'en',
            'is_active' => true,
        ]);

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'AUTH-REPRESENTATIVE-VAN']);
        $registry->assign($user, $van, [
            'representative_user_id' => $user->id,
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'app' => 'van',
        ])->assertForbidden();
    }

    public function test_invalid_or_inactive_credentials_are_rejected(): void
    {
        $user = User::query()->create([
            'name' => 'Inactive',
            'email' => 'inactive@example.test',
            'password' => Hash::make('correct-password'),
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertUnprocessable();

        $user->update(['is_active' => true]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'unknown@example.test',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@example.test',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    public function test_protected_identity_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/profile')->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    public function test_public_b2b_self_registration_does_not_exist(): void
    {
        $this->postJson('/api/v1/b2b/register', [])->assertNotFound();
        $this->postJson('/api/v1/b2b/signup', [])->assertNotFound();
    }
}
