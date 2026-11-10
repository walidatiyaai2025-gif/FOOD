<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2DenyPrecedenceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_global_deny_revokes_inherited_role_and_real_security_api_access(): void
    {
        $permission = Permission::query()->where('code', 'security.view')->firstOrFail();
        $role = Role::query()->create([
            'code' => 'V2_SECURITY_REVIEWER_2829',
            'name' => 'Security reviewer',
            'scope' => 'global',
            'is_active' => true,
            'is_system' => false,
        ]);
        $role->permissions()->attach($permission->id);

        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach($role->id);

        $this->assertTrue($user->hasPermission('security.view'));
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/admin/security/permissions')->assertOk();

        $this->override($user, $permission, 'global', 'deny');

        $fresh = $user->fresh();
        $this->assertContains('security.view', $fresh->inheritedPermissionCodes());
        $this->assertFalse($fresh->hasPermission('security.view'));
        $this->assertNotContains('security.view', $fresh->effectivePermissionCodes());
        $this->getJson('/api/v1/admin/security/permissions')->assertForbidden();

        // A user override must not mutate the shared role template.
        $this->assertTrue($role->permissions()->where('code', 'security.view')->exists());
    }

    public function test_global_deny_overrides_store_allow_in_permissions_and_direct_retail_url(): void
    {
        $store = $this->retailStore('V2-DENY-2829-A');
        $user = $this->storeAdmin($store);
        $permission = Permission::query()->where('code', 'inventory.view')->firstOrFail();

        $this->assertTrue($user->hasPermission('inventory.view', $store));
        $this->actingAs($user)->get(route('admin.b2c.module', [
            'module' => 'inventory',
            'store_id' => $store,
        ]))->assertOk();

        $this->override($user, $permission, 'global', 'deny');
        $this->override($user, $permission, 'store:'.$store, 'allow', $store);

        $fresh = $user->fresh();
        $this->assertSame('deny', $fresh->permissionOverrideEffects($store)['inventory.view']);
        $this->assertFalse($fresh->hasPermission('inventory.view', $store));
        $this->assertNotContains('inventory.view', $fresh->effectivePermissionCodes($store));

        $this->actingAs($fresh)->get(route('admin.b2c.module', [
            'module' => 'inventory',
            'store_id' => $store,
        ]))->assertForbidden();
    }

    public function test_store_deny_overrides_inheritance_only_for_that_store(): void
    {
        $storeA = $this->retailStore('V2-DENY-2829-B');
        $storeB = $this->retailStore('V2-DENY-2829-C');
        $user = $this->storeAdmin($storeA);
        $this->assignStoreRole($user, $storeB);
        $permission = Permission::query()->where('code', 'inventory.view')->firstOrFail();

        $this->override($user, $permission, 'store:'.$storeA, 'deny', $storeA);

        $fresh = $user->fresh();
        $this->assertSame('deny', $fresh->permissionOverrideEffects($storeA)['inventory.view']);
        $this->assertFalse($fresh->hasPermission('inventory.view', $storeA));
        $this->assertNotContains('inventory.view', $fresh->effectivePermissionCodes($storeA));
        $this->assertTrue($fresh->hasPermission('inventory.view', $storeB));
        $this->assertContains('inventory.view', $fresh->effectivePermissionCodes($storeB));

        $this->actingAs($fresh)
            ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $storeA]))
            ->assertForbidden();
        $this->actingAs($fresh)
            ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $storeB]))
            ->assertOk();
    }

    private function override(User $user, Permission $permission, string $scopeKey, string $effect, ?int $storeId = null): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->id,
            'permission_id' => $permission->id,
            'scope_key' => $scopeKey,
            'store_id' => $storeId,
            'effect' => $effect,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user->forceFill(['has_permission_overrides' => true])->save();
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storeAdmin(int $storeId): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->assignStoreRole($user, $storeId);

        return $user;
    }

    private function assignStoreRole(User $user, int $storeId): void
    {
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
