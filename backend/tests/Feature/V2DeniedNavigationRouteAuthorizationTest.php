<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2DeniedNavigationRouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_user_deny_removes_sidebar_links_and_blocks_real_read_write_routes_without_changing_shared_role(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'en');
        $permission = Permission::query()->where('code', 'roles.manage')->firstOrFail();
        $role = Role::query()->create([
            'code' => 'V2_SIDEBAR_ROLE_DELEGATE',
            'name' => 'V2 Sidebar Governance Delegate',
            'scope' => 'global',
            'is_active' => true,
            'is_system' => false,
        ]);
        $role->permissions()->attach($permission->id);

        $restricted = $this->userWithRole('B2B_ADMIN', 'en');
        $unrestricted = $this->userWithRole('B2B_ADMIN', 'ar');
        $restricted->roles()->attach($role->id);
        $unrestricted->roles()->attach($role->id);

        $this->assertTrue($restricted->hasPermission('roles.manage'));
        $this->assertContains('sidebar_role_access', $this->visibleKeys($restricted));
        $this->assertContains('sidebar_layout', $this->visibleKeys($restricted));

        $this->actingAs($restricted)
            ->get(route('admin.sidebar-access.roles'))
            ->assertOk();

        // Use the real authorized RBAC endpoint: a user-level DENY never edits
        // the shared role or grants a second user different permissions.
        $selected = Permission::query()
            ->whereIn('code', $restricted->inheritedPermissionCodes())
            ->where('code', '!=', 'roles.manage')
            ->pluck('id')
            ->all();

        $this->actingAs($admin)
            ->put(route('admin.security.users.permissions', $restricted), [
                'scope_type' => 'global',
                'permission_ids' => $selected,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $restricted = $restricted->fresh();
        $this->assertFalse($restricted->hasPermission('roles.manage'));
        $this->assertNotContains('sidebar_role_access', $this->visibleKeys($restricted));
        $this->assertNotContains('sidebar_layout', $this->visibleKeys($restricted));
        $this->assertTrue($role->fresh()->permissions()->where('code', 'roles.manage')->exists());
        $this->assertTrue($unrestricted->fresh()->hasPermission('roles.manage'));
        $this->assertContains('sidebar_role_access', $this->visibleKeys($unrestricted));

        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $restricted->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'effect' => 'deny',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.permissions_changed',
            'user_id' => $admin->id,
            'auditable_id' => $restricted->id,
        ]);

        $this->actingAs($restricted)
            ->get(route('admin.sidebar-access.roles'))
            ->assertForbidden();

        $this->actingAs($restricted)
            ->get(route('admin.sidebar-layout.index'))
            ->assertForbidden();

        $this->actingAs($restricted)
            ->put(route('admin.sidebar-access.roles.update', $role), [
                'item_keys' => [],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'admin.sidebar.role_visibility.updated',
            'user_id' => $restricted->id,
        ]);

        // Restoring the inherited permission removes only the personal DENY.
        $restore = Permission::query()
            ->whereIn('code', $restricted->inheritedPermissionCodes())
            ->pluck('id')
            ->all();

        $this->actingAs($admin)
            ->put(route('admin.security.users.permissions', $restricted), [
                'scope_type' => 'global',
                'permission_ids' => $restore,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $restored = $restricted->fresh();
        $this->assertTrue($restored->hasPermission('roles.manage'));
        $this->assertContains('sidebar_role_access', $this->visibleKeys($restored));
        $this->assertDatabaseMissing('user_permission_overrides', [
            'user_id' => $restricted->id,
            'permission_id' => $permission->id,
            'effect' => 'deny',
        ]);
    }

    public function test_missing_role_permission_is_hidden_and_denied_in_both_locales_without_cross_channel_access(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $user = $this->userWithRole('B2B_ADMIN', $locale);
            $this->assertFalse($user->hasPermission('roles.manage'));
            $this->assertNotContains('sidebar_role_access', $this->visibleKeys($user));
            $this->assertNotContains('sidebar_layout', $this->visibleKeys($user));

            $this->actingAs($user)
                ->get(route('admin.b2b.dashboard'))
                ->assertOk()
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                ->assertDontSee('href="'.route('admin.sidebar-access.roles').'"', false);

            $this->actingAs($user)
                ->get(route('admin.sidebar-access.roles'))
                ->assertForbidden();

            $this->actingAs($user)
                ->get(route('admin.sidebar-layout.index'))
                ->assertForbidden();

            $this->actingAs($user)
                ->get(route('admin.b2c.dashboard'))
                ->assertForbidden();
        }
    }

    /** @return list<string> */
    private function visibleKeys(User $user): array
    {
        return collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')
            ->values()
            ->all();
    }

    private function userWithRole(string $code, string $locale): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => $locale]);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }
}
