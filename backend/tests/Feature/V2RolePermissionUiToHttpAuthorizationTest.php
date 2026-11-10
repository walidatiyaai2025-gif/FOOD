<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P099: a saved role permission must change the real protected HTTP route.
 * Presentation-only Sidebar preferences never replace the server Gate.
 */
final class V2RolePermissionUiToHttpAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_role_editor_grant_and_revoke_change_the_real_route_and_navigation_in_both_locales(): void
    {
        $permission = Permission::query()->where('code', 'roles.manage')->firstOrFail();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $admin = $this->userWithRole('SUPER_ADMIN', 'admin-'.$locale, $locale);
            $target = $this->userWithRole('B2B_ADMIN', 'target-'.$locale, $locale);
            $customRole = Role::query()->create([
                'code' => 'V2P099_'.strtoupper($locale),
                'name' => 'P099 temporary security editor '.$locale,
                'description' => 'P099 route authorization regression fixture',
                'scope' => 'global',
                'is_system' => false,
                'is_active' => true,
            ]);
            $target->roles()->attach($customRole);

            $this->assertFalse($target->fresh()->hasPermission('roles.manage'));
            $this->assertNotContains('sidebar_role_access', $this->visibleKeys($target->fresh()));
            $this->actingAs($target)
                ->get(route('admin.sidebar-access.roles', [], false))
                ->assertForbidden();

            // A genuine Security Center role edit persists RBAC, not a visual
            // checkbox or a forged Sidebar visibility grant.
            $this->actingAs($admin)
                ->patch(route('admin.security.roles.update', $customRole), $this->roleValues($customRole, [$permission->id]))
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $this->assertDatabaseHas('permission_role', [
                'role_id' => $customRole->id,
                'permission_id' => $permission->id,
            ]);
            $this->assertTrue($target->fresh()->hasPermission('roles.manage'));
            $this->assertContains('sidebar_role_access', $this->visibleKeys($target->fresh()));
            $this->actingAs($target)
                ->get(route('admin.sidebar-access.roles', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            // Revocation has to take effect on the same real HTTP endpoint;
            // no stale Sidebar/role cache may keep privileged access alive.
            $this->actingAs($admin)
                ->patch(route('admin.security.roles.update', $customRole), $this->roleValues($customRole, []))
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $this->assertDatabaseMissing('permission_role', [
                'role_id' => $customRole->id,
                'permission_id' => $permission->id,
            ]);
            $this->assertFalse($target->fresh()->hasPermission('roles.manage'));
            $this->assertNotContains('sidebar_role_access', $this->visibleKeys($target->fresh()));
            $this->actingAs($target)
                ->get(route('admin.sidebar-access.roles', [], false))
                ->assertForbidden();

            $this->assertSame(2, DB::table('audit_logs')
                ->where('event', 'role.updated')
                ->where('auditable_type', Role::class)
                ->where('auditable_id', $customRole->id)
                ->where('user_id', $admin->id)
                ->count());
            $this->assertDatabaseMissing('user_permission_overrides', [
                'user_id' => $target->id,
                'permission_id' => $permission->id,
            ]);
        }
    }

    public function test_forged_role_editor_request_and_builtin_role_mutation_do_not_bypass_authorization(): void
    {
        $permission = Permission::query()->where('code', 'roles.manage')->firstOrFail();
        $builtIn = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $baseline = $builtIn->permissions()->pluck('permissions.id')->all();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $unprivileged = $this->userWithRole('B2B_ADMIN', 'blocked-'.$locale, $locale);
            $superAdmin = $this->userWithRole('SUPER_ADMIN', 'super-'.$locale, $locale);
            $customRole = Role::query()->create([
                'code' => 'V2P099_FORGE_'.strtoupper($locale),
                'name' => 'P099 cannot self grant '.$locale,
                'description' => 'P099 forged UI permission edit regression',
                'scope' => 'global',
                'is_system' => false,
                'is_active' => true,
            ]);

            $auditBefore = DB::table('audit_logs')->where('event', 'role.updated')->count();
            $this->actingAs($unprivileged)
                ->patch(route('admin.security.roles.update', $customRole), $this->roleValues($customRole, [$permission->id]))
                ->assertForbidden();

            $this->assertDatabaseMissing('permission_role', [
                'role_id' => $customRole->id,
                'permission_id' => $permission->id,
            ]);
            $this->assertSame($auditBefore, DB::table('audit_logs')->where('event', 'role.updated')->count());

            // Built-in job role templates are policy-owned: even a valid UI
            // form from Super Admin cannot silently rewrite the canonical set.
            $this->actingAs($superAdmin)
                ->patch(route('admin.security.roles.update', $builtIn), $this->roleValues($builtIn, [$permission->id]))
                ->assertRedirect()
                ->assertSessionHasErrors(['role']);

            $this->assertEqualsCanonicalizing(
                $baseline,
                $builtIn->fresh()->permissions()->pluck('permissions.id')->all(),
            );
            $this->assertSame($auditBefore, DB::table('audit_logs')->where('event', 'role.updated')->count());
        }
    }

    /** @param list<int> $ids
     * @return array{name:string,description:?string,scope:string,is_active:int,permission_ids:list<int>}
     */
    private function roleValues(Role $role, array $ids): array
    {
        return [
            'name' => (string) $role->name,
            'description' => $role->description,
            'scope' => (string) $role->scope,
            'is_active' => 1,
            'permission_ids' => $ids,
        ];
    }

    private function userWithRole(string $code, string $suffix, string $locale): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'locale' => $locale,
            'email' => 'p099-'.$suffix.'@example.test',
        ]);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }

    /** @return list<string> */
    private function visibleKeys(User $user): array
    {
        $keys = [];
        foreach (app(AdminNavigation::class)->groupsFor($user) as $group) {
            foreach ($group['children'] as $item) {
                $keys[] = $item['key'];
                foreach ((array) ($item['children'] ?? []) as $child) {
                    $keys[] = $child['key'];
                }
            }
        }

        return $keys;
    }
}
