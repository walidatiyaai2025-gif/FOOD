<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2UserPermissionInheritModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_inherit_clears_only_global_user_overrides_and_follows_the_live_role_template(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN');
        $dispatch = Permission::query()->where('code', 'orders.dispatch')->firstOrFail();
        $manage = Permission::query()->where('code', 'orders.manage')->firstOrFail();

        $role = Role::query()->create([
            'code' => 'V2_INHERIT_TEMPLATE',
            'name' => 'Inheritance example',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach([$dispatch->id, $manage->id]);
        $target = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $peer = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $target->roles()->attach($role);
        $peer->roles()->attach($role);

        $this->actingAs($admin)
            ->put(route('admin.security.users.permissions', $target), [
                'scope_type' => 'global',
                'permission_ids' => [$manage->id],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $target->id,
            'permission_id' => $dispatch->id,
            'scope_key' => 'global',
            'effect' => 'deny',
        ]);
        $this->assertFalse($target->fresh()->hasPermission('orders.dispatch'));

        // Submitted checkboxes are ignored by explicit INHERIT: only the live
        // role baseline for the authorized scope determines the resulting state.
        $this->actingAs($admin)
            ->put(route('admin.security.users.permissions', $target), [
                'scope_type' => 'global',
                'inherit' => '1',
                'permission_ids' => [],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('user_permission_overrides', [
            'user_id' => $target->id,
            'scope_key' => 'global',
        ]);
        $this->assertFalse((bool) $target->fresh()->has_permission_overrides);
        $this->assertTrue($target->fresh()->hasPermission('orders.dispatch'));
        $this->assertTrue($peer->fresh()->hasPermission('orders.dispatch'));

        $role->permissions()->detach($dispatch->id);
        $this->assertFalse($target->fresh()->hasPermission('orders.dispatch'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.permissions_changed',
            'user_id' => $admin->id,
            'auditable_id' => $target->id,
        ]);
    }

    public function test_store_inherit_is_store_scoped_and_preserves_other_store_denials_in_both_locales(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN');
        $storeA = $this->createRetailStore('V2-INHERIT-A');
        $storeB = $this->createRetailStore('V2-INHERIT-B');
        $target = User::factory()->create(['is_active' => true]);
        $retailRole = Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail();

        foreach ([$storeA, $storeB] as $storeId) {
            DB::table('user_store_roles')->insert([
                'user_id' => $target->id,
                'store_id' => $storeId,
                'role_id' => $retailRole->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $manage = Permission::query()->where('code', 'orders.manage')->firstOrFail();
        foreach ([$storeA, $storeB] as $storeId) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $target->id,
                'permission_id' => $manage->id,
                'scope_key' => 'store:'.$storeId,
                'store_id' => $storeId,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $target->forceFill(['has_permission_overrides' => true])->save();
        $this->assertFalse($target->fresh()->hasPermission('orders.manage', $storeA));
        $this->assertFalse($target->fresh()->hasPermission('orders.manage', $storeB));

        App::setLocale('ar');
        $admin->forceFill(['locale' => 'ar'])->save();
        $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'users']))
            ->assertOk()
            ->assertSee(__('security_permissions.user_permissions_inherit'));

        $this->actingAs($admin)
            ->put(route('admin.security.users.permissions', $target), [
                'scope_type' => 'store',
                'store_id' => $storeA,
                'inherit' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('user_permission_overrides', [
            'user_id' => $target->id,
            'scope_key' => 'store:'.$storeA,
        ]);
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $target->id,
            'scope_key' => 'store:'.$storeB,
            'permission_id' => $manage->id,
            'effect' => 'deny',
        ]);
        $this->assertTrue((bool) $target->fresh()->has_permission_overrides);
        $this->assertTrue($target->fresh()->hasPermission('orders.manage', $storeA));
        $this->assertFalse($target->fresh()->hasPermission('orders.manage', $storeB));

        App::setLocale('en');
        $admin->forceFill(['locale' => 'en'])->save();
        $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'users']))
            ->assertOk()
            ->assertSee(__('security_permissions.user_permissions_inherit'));
    }

    public function test_inherit_rejects_out_of_scope_store_and_non_security_actor_without_touching_denials(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN');
        $operator = $this->userWithRole('B2B_ADMIN');
        $assigned = $this->createRetailStore('V2-INHERIT-OWN');
        $foreign = $this->createRetailStore('V2-INHERIT-OTHER');
        $target = User::factory()->create(['is_active' => true]);
        $retailRole = Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail();
        $permission = Permission::query()->where('code', 'orders.manage')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $target->id,
            'store_id' => $assigned,
            'role_id' => $retailRole->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $target->id,
            'permission_id' => $permission->id,
            'scope_key' => 'store:'.$assigned,
            'store_id' => $assigned,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $target->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($admin)
            ->put(route('admin.security.users.permissions', $target), [
                'scope_type' => 'store',
                'store_id' => $foreign,
                'inherit' => '1',
            ])
            ->assertSessionHasErrors('store_id');

        $this->actingAs($operator)
            ->put(route('admin.security.users.permissions', $target), [
                'scope_type' => 'store',
                'store_id' => $assigned,
                'inherit' => '1',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $target->id,
            'scope_key' => 'store:'.$assigned,
            'permission_id' => $permission->id,
            'effect' => 'deny',
        ]);
        $this->assertFalse($target->fresh()->hasPermission('orders.manage', $assigned));
    }

    private function userWithRole(string $code): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }

    private function createRetailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
