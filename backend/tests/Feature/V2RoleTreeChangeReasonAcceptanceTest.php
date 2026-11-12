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
 * V2 #2800 / P049: the Page Tree is a real audited RBAC editor, not a
 * visibility-only form or a permanently invalid save button.
 */
final class V2RoleTreeChangeReasonAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
    }

    public function test_role_tree_displays_required_audit_reason_in_arabic_and_english(): void
    {
        [$admin, $role] = $this->operator();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($admin->fresh())
                ->get(route('admin.sidebar-access.roles', ['role_id' => $role->id]))
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'">', false)
                ->assertSee('data-v2-role-preview-form', false)
                ->assertSee('name="change_reason"', false)
                ->assertSee('minlength="8"', false)
                ->assertSee(__('security_permissions.change_reason'))
                ->assertSee(__('security_permissions.change_reason_hint'));
        }
    }

    public function test_role_tree_cannot_save_without_reason_and_real_save_updates_permissions_with_audit(): void
    {
        [$admin, $role] = $this->operator();
        $permission = Permission::query()->where('code', 'catalog.view')->firstOrFail();

        $request = [
            'name' => $role->name,
            'description' => $role->description,
            'scope' => $role->scope,
            'is_active' => 1,
            'permission_ids' => [$permission->id],
        ];

        $this->actingAs($admin)
            ->patch(route('admin.security.roles.update', $role), $request)
            ->assertSessionHasErrors(['change_reason']);

        $this->assertDatabaseMissing('permission_role', [
            'role_id' => $role->id,
            'permission_id' => $permission->id,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.security.roles.update', $role), [
                ...$request,
                'change_reason' => 'Approved P049 role page tree update',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('permission_role', [
            'role_id' => $role->id,
            'permission_id' => $permission->id,
        ]);
        $this->assertTrue(
            DB::table('audit_logs')->where('event', 'role.updated')->exists(),
            'A successful role tree permission edit must be audited.',
        );
    }

    public function test_read_only_control_plane_role_and_unauthorized_operator_cannot_change_permissions(): void
    {
        [$admin] = $this->operator();
        $system = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $permissionIds = $system->permissions()->pluck('permissions.id')->all();

        $this->actingAs($admin)
            ->get(route('admin.sidebar-access.roles', ['role_id' => $system->id]))
            ->assertOk()
            ->assertDontSee('name="change_reason"', false);

        $this->actingAs($admin)
            ->patch(route('admin.security.roles.update', $system), [
                'name' => $system->name,
                'scope' => $system->scope,
                'is_active' => 1,
                'permission_ids' => [],
                'change_reason' => 'Remove every system permission',
            ])
            ->assertSessionHasErrors(['role']);

        $this->assertEqualsCanonicalizing(
            $permissionIds,
            $system->fresh()->permissions()->pluck('permissions.id')->all(),
        );

        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->actingAs($roleless)
            ->get(route('admin.sidebar-access.roles'))
            ->assertForbidden();
        $this->actingAs($roleless)
            ->patch(route('admin.security.roles.update', $system), [
                'name' => $system->name,
                'scope' => $system->scope,
                'is_active' => 1,
                'change_reason' => 'Unauthorized role permission edit',
            ])
            ->assertForbidden();
    }

    /** @return array{User, Role} */
    private function operator(): array
    {
        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $role = Role::query()->create([
            'code' => 'P049_EDITOR',
            'name' => 'P049 Role Tree Test',
            'description' => 'Bounded role tree regression',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);

        return [$admin, $role];
    }
}
