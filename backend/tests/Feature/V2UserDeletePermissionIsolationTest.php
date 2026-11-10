<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminSecurityPolicy;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2UserDeletePermissionIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_delete_is_registered_but_not_inferred_from_view_or_status_permissions(): void
    {
        $this->assertDatabaseHas('permissions', ['code' => 'users.delete']);
        foreach (['B2B_ADMIN', 'B2C_STORE_ADMIN', 'OPERATIONS', 'CUSTOMER_SERVICE_MANAGER'] as $roleCode) {
            $this->assertNotContains('users.delete', (array) config('permissions.roles.'.$roleCode));
        }

        $actor = $this->actorWithPermissions('V2_USERS_VIEW_STATUS_2812', [
            'users.view', 'users.status.manage',
        ]);

        $this->assertTrue(Gate::forUser($actor)->allows('users.view'));
        $this->assertTrue(Gate::forUser($actor)->allows('users.status.manage'));
        $this->assertFalse(Gate::forUser($actor)->allows('users.delete'));
    }

    public function test_delete_only_role_does_not_get_read_or_status_mutation_access(): void
    {
        $actor = $this->actorWithPermissions('V2_USERS_DELETE_ONLY_2812', ['users.delete']);
        $target = User::factory()->create(['is_active' => true]);

        $this->assertTrue(Gate::forUser($actor)->allows('users.delete'));
        $this->assertFalse(Gate::forUser($actor)->allows('users.view'));
        $this->assertFalse(Gate::forUser($actor)->allows('users.status.manage'));
        $this->assertFalse(Gate::forUser($actor)->allows('users.roles.manage'));
        $this->assertTrue(app(AdminSecurityPolicy::class)->isPrivileged($actor));

        Sanctum::actingAs($actor);
        $this->patchJson("/api/v1/admin/security/users/{$target->id}/status", [
            'is_active' => false,
        ])->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => 1]);
        $this->actingAs($actor)->get(route('admin.security.index'))->assertForbidden();
    }

    public function test_explicit_deny_removes_delete_but_keeps_independent_view_and_status_grants(): void
    {
        $actor = $this->actorWithPermissions('V2_USERS_DELETE_DENY_2812', [
            'users.delete', 'users.view', 'users.status.manage',
        ]);
        $permission = Permission::query()->where('code', 'users.delete')->firstOrFail();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $actor = $actor->fresh();

        $this->assertFalse(Gate::forUser($actor)->allows('users.delete'));
        $this->assertNotContains('users.delete', $actor->effectivePermissionCodes());
        $this->assertTrue(Gate::forUser($actor)->allows('users.view'));
        $this->assertTrue(Gate::forUser($actor)->allows('users.status.manage'));
    }

    public function test_retail_store_allow_override_cannot_escalate_to_platform_user_deletion(): void
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-DELETE-PERMISSION-2812',
            'name' => 'V2 User Permission Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor = User::factory()->create(['is_active' => true]);
        $roleId = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id;
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $permission = Permission::query()->where('code', 'users.delete')->firstOrFail();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'store:'.$storeId,
            'store_id' => $storeId,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $actor = $actor->fresh();

        $this->assertFalse($actor->hasPermission('users.delete'));
        $this->assertFalse($actor->hasPermission('users.delete', $storeId));
        $this->assertNotContains('users.delete', $actor->effectivePermissionCodes($storeId));
    }

    /** @param list<string> $permissionCodes */
    private function actorWithPermissions(string $roleCode, array $permissionCodes): User
    {
        $role = Role::query()->create([
            'code' => $roleCode,
            'name' => $roleCode,
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('code', $permissionCodes)->pluck('id')->all());

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach($role->id);

        return $actor;
    }
}
