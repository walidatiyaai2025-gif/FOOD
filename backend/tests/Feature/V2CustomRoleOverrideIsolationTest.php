<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2CustomRoleOverrideIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_editing_custom_role_permissions_preserves_one_users_deny_without_affecting_other_members(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN');
        $dispatch = Permission::query()->where('code', 'orders.dispatch')->firstOrFail();
        $manage = Permission::query()->where('code', 'orders.manage')->firstOrFail();

        $role = Role::query()->create([
            'code' => 'V2_CUSTOM_DISPATCH',
            'name' => 'Custom dispatch',
            'description' => 'Isolated test role',
            'scope' => 'global',
            'is_active' => true,
            'is_system' => false,
        ]);
        $role->permissions()->attach($dispatch->id);

        $userWithOverride = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $ordinaryMember = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $userWithOverride->roles()->attach($role->id);
        $ordinaryMember->roles()->attach($role->id);

        $this->actingAs($admin)
            ->put(route('admin.security.users.permissions', $userWithOverride), [
                'scope_type' => 'global',
                'permission_ids' => [],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $userWithOverride->id,
            'permission_id' => $dispatch->id,
            'scope_key' => 'global',
            'effect' => 'deny',
        ]);
        $this->assertFalse($userWithOverride->fresh()->hasPermission('orders.dispatch'));
        $this->assertTrue($ordinaryMember->fresh()->hasPermission('orders.dispatch'));

        $this->actingAs($admin)
            ->from(route('admin.security.index', ['section' => 'roles']))
            ->patch(route('admin.security.roles.update', $role), [
                'name' => 'Custom dispatch and management',
                'description' => 'Role template updated independently',
                'scope' => 'global',
                'is_active' => true,
                'permission_ids' => [$dispatch->id, $manage->id],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue($role->fresh()->permissions()->where('code', 'orders.manage')->exists());
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $userWithOverride->id,
            'permission_id' => $dispatch->id,
            'scope_key' => 'global',
            'effect' => 'deny',
        ]);
        $this->assertDatabaseMissing('user_permission_overrides', [
            'user_id' => $ordinaryMember->id,
        ]);

        $this->assertFalse($userWithOverride->fresh()->hasPermission('orders.dispatch'));
        $this->assertTrue($userWithOverride->fresh()->hasPermission('orders.manage'));
        $this->assertTrue($ordinaryMember->fresh()->hasPermission('orders.dispatch'));
        $this->assertTrue($ordinaryMember->fresh()->hasPermission('orders.manage'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'role.updated',
            'user_id' => $admin->id,
            'auditable_type' => Role::class,
            'auditable_id' => $role->id,
        ]);
    }

    public function test_unauthorized_actor_cannot_modify_custom_role_or_individual_user_override(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN');
        $unauthorized = $this->userWithRole('B2B_ADMIN');
        $dispatch = Permission::query()->where('code', 'orders.dispatch')->firstOrFail();

        $role = Role::query()->create([
            'code' => 'V2_PRIVATE_ROLE',
            'name' => 'Limited template',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach($dispatch->id);

        $member = User::factory()->create(['is_active' => true]);
        $member->roles()->attach($role->id);

        $this->actingAs($admin)
            ->put(route('admin.security.users.permissions', $member), [
                'scope_type' => 'global',
                'permission_ids' => [],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($unauthorized)
            ->patch(route('admin.security.roles.update', $role), [
                'name' => 'Unauthorized replacement',
                'scope' => 'global',
                'is_active' => true,
                'permission_ids' => [],
            ])
            ->assertForbidden();

        $this->assertSame('Limited template', $role->fresh()->name);
        $this->assertTrue($role->fresh()->permissions()->where('code', 'orders.dispatch')->exists());
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $member->id,
            'permission_id' => $dispatch->id,
            'scope_key' => 'global',
            'effect' => 'deny',
        ]);
        $this->assertFalse($member->fresh()->hasPermission('orders.dispatch'));
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'role.updated',
            'user_id' => $unauthorized->id,
            'auditable_id' => $role->id,
        ]);
    }

    private function userWithRole(string $code): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }
}
