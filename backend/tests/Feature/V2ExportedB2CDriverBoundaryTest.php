<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * V2 #2908 / historical Table 5 row 3. Synthetic role-class regression only.
 * Never reproduce the historical account's identity or assert its current state.
 */
final class V2ExportedB2CDriverBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_single_retail_driver_grant_is_not_an_admin_retail_store_or_wholesale_grant(): void
    {
        $actor = $this->driverActor();
        $role = Role::query()->where('code', 'B2C_DRIVER')->firstOrFail();

        $this->assertSame('global', $role->scope);
        $this->assertTrue((bool) $role->is_active);
        $this->assertSame(
            ['deliveries.b2c.execute'],
            $role->permissions()->pluck('permissions.code')->sort()->values()->all(),
        );
        $this->assertSame(['deliveries.b2c.execute'], $actor->inheritedPermissionCodes());
        $this->assertTrue($actor->hasPermission('deliveries.b2c.execute'));

        foreach (['deliveries.b2b.execute', 'van.login', 'orders.view', 'inventory.view', 'roles.manage', 'users.delete'] as $code) {
            $this->assertFalse($actor->hasPermission($code), $code.' must not be inherited by a retail driver');
        }

        $scope = app(OperationalTenantScope::class);
        $this->assertSame([], $scope->allowedStoreIds($actor, 'orders.view', 'b2b'));
        $this->assertSame([], $scope->allowedStoreIds($actor, 'orders.view', 'b2c'));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $groups = app(AdminNavigation::class)->groupsFor($actor);
            $this->assertSame([], $groups, 'A driver execution role must not acquire Dashboard navigation');

            $this->actingAs($actor)->get(route('admin.sidebar-access.roles', [], false))
                ->assertForbidden();
            $this->actingAs($actor)->get('/admin/b2b/orders')->assertForbidden();
        }
    }

    public function test_driver_role_without_active_retail_profile_or_with_wholesale_profile_cannot_login_or_execute(): void
    {
        $actor = $this->driverActor();

        $this->postJson('/api/v1/auth/login', $this->loginPayload($actor))->assertForbidden();
        Sanctum::actingAs($actor, ['app:driver']);
        $this->getJson('/api/v1/driver/wallet')->assertForbidden();
        $this->getJson('/api/v1/van/visits')->assertForbidden();

        Driver::query()->create([
            'user_id' => $actor->getKey(),
            'store_id' => (int) app(\App\Services\WholesalePrincipal::class)->storeId(),
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/auth/login', $this->loginPayload($actor))->assertForbidden();
        Sanctum::actingAs($actor, ['app:driver']);
        $this->getJson('/api/v1/driver/wallet')->assertForbidden();
        Sanctum::actingAs($actor, ['app:van']);
        $this->getJson('/api/v1/van/visits')->assertForbidden();

        $this->assertSame(0, DB::table('personal_access_tokens')
            ->where('tokenable_type', $actor->getMorphClass())
            ->where('tokenable_id', $actor->getKey())->count());
        $this->assertDatabaseHas('users', ['id' => $actor->getKey(), 'is_active' => true]);
        $this->assertDatabaseHas('drivers', ['user_id' => $actor->getKey(), 'driver_type' => 'b2b', 'is_active' => true]);
    }

    public function test_active_retail_profile_may_login_but_explicit_deny_blocks_new_and_existing_driver_access(): void
    {
        $actor = $this->driverActor();
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-2908-RETAIL',
            'name' => 'Synthetic Retail Fixture',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Driver::query()->create([
            'user_id' => $actor->getKey(),
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/auth/login', $this->loginPayload($actor))
            ->assertOk()
            ->assertJsonStructure(['token', 'token_type', 'user']);
        $this->assertSame(1, DB::table('personal_access_tokens')
            ->where('tokenable_id', $actor->getKey())
            ->where('name', 'foodex-driver')->count());

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->getKey(),
            'permission_id' => Permission::query()->where('code', 'deliveries.b2c.execute')->firstOrFail()->getKey(),
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $actor = $actor->fresh();
        $this->assertFalse($actor->hasPermission('deliveries.b2c.execute'));

        $this->postJson('/api/v1/auth/login', $this->loginPayload($actor))->assertForbidden();
        Sanctum::actingAs($actor, ['app:driver']);
        $this->getJson('/api/v1/driver/wallet')->assertForbidden();
        $this->assertSame(1, DB::table('personal_access_tokens')
            ->where('tokenable_id', $actor->getKey())
            ->where('name', 'foodex-driver')->count());
        $this->assertDatabaseHas('drivers', ['user_id' => $actor->getKey(), 'store_id' => $storeId, 'driver_type' => 'b2c']);
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $actor->getKey(),
            'scope_key' => 'global',
            'effect' => 'deny',
        ]);
    }

    private function driverActor(): User
    {
        $actor = User::factory()->create([
            'name' => 'Synthetic Retail Driver',
            'email' => 'v2-2908-synthetic@example.test',
            'password' => Hash::make('synthetic-password-only'),
            'is_active' => true,
            'locale' => 'en',
        ]);
        $actor->roles()->attach(Role::query()->where('code', 'B2C_DRIVER')->firstOrFail());

        return $actor->fresh();
    }

    /** @return array{email:string,password:string,app:string} */
    private function loginPayload(User $actor): array
    {
        return ['email' => (string) $actor->email, 'password' => 'synthetic-password-only', 'app' => 'driver'];
    }
}
