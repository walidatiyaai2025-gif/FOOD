<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P096: a shared custom-role template is not a personal user grant.
 * A user-scoped DENY must never rewrite the shared template or a peer user.
 */
final class V2RoleTemplateVsUserOverrideIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_role_template_changes_all_assignees_but_personal_deny_changes_only_its_owner_in_ar_and_en(): void
    {
        $view = Permission::query()->where('code', 'orders.view')->firstOrFail();
        $manage = Permission::query()->where('code', 'orders.manage')->firstOrFail();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor = $this->userWithRole('SUPER_ADMIN', 'admin-'.$locale, $locale);
            $role = Role::query()->create([
                'code' => 'V2P096_'.strtoupper($locale),
                'name' => 'Scoped custom operations template '.$locale,
                'description' => 'P096 role-template versus individual override',
                'scope' => 'global',
                'is_system' => false,
                'is_active' => true,
            ]);
            $role->permissions()->attach([$view->id]);
            $target = User::factory()->create([
                'is_active' => true,
                'locale' => $locale,
                'email' => 'p096-target-'.$locale.'@example.test',
            ]);
            $peer = User::factory()->create([
                'is_active' => true,
                'locale' => $locale,
                'email' => 'p096-peer-'.$locale.'@example.test',
            ]);
            $target->roles()->attach($role);
            $peer->roles()->attach($role);

            $this->assertTrue($target->fresh()->hasPermission('orders.view'));
            $this->assertFalse($target->fresh()->hasPermission('orders.manage'));
            $this->assertFalse($peer->fresh()->hasPermission('orders.manage'));

            // Changing the ROLE TEMPLATE is the same shared inheritance for
            // both assigned users; it does not create individual overrides.
            $this->actingAs($actor)
                ->patch(route('admin.security.roles.update', $role), $this->roleValues($role, [$view->id, $manage->id]))
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $this->assertTrue($target->fresh()->hasPermission('orders.manage'));
            $this->assertTrue($peer->fresh()->hasPermission('orders.manage'));
            $this->assertSame(0, DB::table('user_permission_overrides')
                ->whereIn('user_id', [$target->id, $peer->id])->count());

            // A personal selection excluding manage writes a DENY for only
            // this target. The peer continues to inherit from the template.
            $this->actingAs($actor)
                ->put(route('admin.security.users.permissions', $target), [
                    'change_reason' => 'Regression fixture approved RBAC access change',
                    'scope_type' => 'global',
                    'permission_ids' => [$view->id],
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $this->assertDatabaseHas('user_permission_overrides', [
                'user_id' => $target->id,
                'permission_id' => $manage->id,
                'scope_key' => 'global',
                'effect' => 'deny',
            ]);
            $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $peer->id]);
            $this->assertFalse($target->fresh()->hasPermission('orders.manage'));
            $this->assertTrue($peer->fresh()->hasPermission('orders.manage'));
            $this->assertEqualsCanonicalizing(
                [$view->id, $manage->id],
                $role->fresh()->permissions()->pluck('permissions.id')->all(),
            );

            // A later TEMPLATE edit changes inherited access for both.
            // Crucially, it cannot silently erase the target's explicit DENY.
            $this->actingAs($actor)
                ->patch(route('admin.security.roles.update', $role), $this->roleValues($role, [$manage->id]))
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $this->assertFalse($target->fresh()->hasPermission('orders.view'));
            $this->assertFalse($peer->fresh()->hasPermission('orders.view'));
            $this->assertFalse($target->fresh()->hasPermission('orders.manage'));
            $this->assertTrue($peer->fresh()->hasPermission('orders.manage'));
            $this->assertDatabaseHas('user_permission_overrides', [
                'user_id' => $target->id,
                'permission_id' => $manage->id,
                'scope_key' => 'global',
                'effect' => 'deny',
            ]);

            $this->assertDatabaseHas('audit_logs', [
                'event' => 'role.updated',
                'user_id' => $actor->id,
                'auditable_type' => Role::class,
                'auditable_id' => $role->id,
            ]);
            $this->assertDatabaseHas('audit_logs', [
                'event' => 'user.permissions_changed',
                'user_id' => $actor->id,
                'auditable_type' => User::class,
                'auditable_id' => $target->id,
            ]);

            // Directly forged HTTP mutations are denied before any write,
            // in both locales, preserving DENY precedence and auditability.
            $blocked = $this->userWithRole('B2B_ADMIN', 'blocked-'.$locale, $locale);
            $auditCount = DB::table('audit_logs')->count();
            $this->actingAs($blocked)
                ->patch(route('admin.security.roles.update', $role), $this->roleValues($role, [$view->id, $manage->id]))
                ->assertForbidden();
            $this->actingAs($blocked)
                ->put(route('admin.security.users.permissions', $target), [
                    'change_reason' => 'Regression fixture approved RBAC access change',
                    'scope_type' => 'global',
                    'permission_ids' => [$view->id, $manage->id],
                ])
                ->assertForbidden();

            $this->assertSame($auditCount, DB::table('audit_logs')->count());
            $this->assertFalse($target->fresh()->hasPermission('orders.manage'));
            $this->assertTrue($peer->fresh()->hasPermission('orders.manage'));
            $this->assertEqualsCanonicalizing(
                [$manage->id],
                $role->fresh()->permissions()->pluck('permissions.id')->all(),
            );
        }
    }

    /** @param list<int> $permissions
     * @return array{name:string,description:string,scope:string,is_active:int,permission_ids:list<int>,change_reason:string}
     */
    private function roleValues(Role $role, array $permissions): array
    {
        return [
            'name' => (string) $role->name,
            'description' => (string) $role->description,
            'scope' => 'global',
            'is_active' => 1,
            'change_reason' => 'Regression fixture approved RBAC role edit',
            'permission_ids' => $permissions,
        ];
    }

    private function userWithRole(string $roleCode, string $suffix, string $locale): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'locale' => $locale,
            'email' => 'p096-'.$suffix.'@example.test',
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
