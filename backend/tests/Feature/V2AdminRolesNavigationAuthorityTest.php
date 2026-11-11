<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2AdminRolesNavigationAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_discovers_real_roles_tab_from_administration_hub_in_both_locales(): void
    {
        $admin = $this->superAdmin('en');
        $roleCount = Role::query()->count();
        $grantCount = DB::table('permission_role')->count();
        $auditCount = DB::table('audit_logs')->count();

        $this->actingAs($admin)
            ->get(route('admin.administration.index', [], false))
            ->assertOk()
            ->assertSee('data-admin-card="users-permissions"', false)
            ->assertSee(route('admin.security.index', [], false), false);

        $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'roles'], false))
            ->assertOk()
            ->assertViewHas('securitySection', 'roles')
            ->assertSee('dir="ltr"', false)
            ->assertSee('data-role-search-panel', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee(__('admin.security.roles_title'))
            ->assertSee('data-role-search-card', false);

        $admin->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($admin->fresh())
            ->get(route('admin.security.index', ['section' => 'roles'], false))
            ->assertOk()
            ->assertViewHas('securitySection', 'roles')
            ->assertSee('dir="rtl"', false)
            ->assertSee('data-role-search-panel', false)
            ->assertSee(__('admin.security.roles_title'));

        $this->assertSame($roleCount, Role::query()->count());
        $this->assertSame($grantCount, DB::table('permission_role')->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_view_only_security_role_can_inspect_roles_but_cannot_create_or_change_them(): void
    {
        $role = Role::query()->create([
            'code' => 'V2_P335_VIEW_ONLY',
            'name' => 'P335 read only',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach(Permission::query()->where('code', 'security.view')->firstOrFail()->id);

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach($role->id);
        $before = Role::query()->count();

        $this->actingAs($actor)
            ->get(route('admin.security.index', ['section' => 'roles'], false))
            ->assertOk()
            ->assertViewHas('securitySection', 'roles')
            ->assertSee('data-role-search-panel', false)
            ->assertDontSee('action="'.route('admin.security.roles.store', [], false).'"', false);

        $this->actingAs($actor)
            ->post(route('admin.security.roles.store', [], false), [
                'code' => 'V2_P335_UNAUTHORIZED',
                'name' => 'Must not exist',
                'scope' => 'global',
            ])
            ->assertForbidden();

        $this->actingAs($actor)
            ->patch(route('admin.security.roles.update', ['role' => $role->id], false), [
                'name' => 'Unauthorized modification',
            ])
            ->assertForbidden();

        $this->assertSame($before, Role::query()->count());
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'P335 read only']);
        $this->assertDatabaseMissing('roles', ['code' => 'V2_P335_UNAUTHORIZED']);
    }

    public function test_roleless_user_cannot_read_roles_tab_or_mutate_role_catalog(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $target = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $before = DB::table('permission_role')->count();

        $this->actingAs($actor)
            ->get(route('admin.security.index', ['section' => 'roles'], false))
            ->assertForbidden();

        $this->actingAs($actor)
            ->patch(route('admin.security.roles.update', ['role' => $target->id], false), [
                'name' => 'Roleless mutation',
            ])
            ->assertForbidden();

        $this->assertSame($before, DB::table('permission_role')->count());
        $this->assertSame('B2B_ADMIN', $target->fresh()->code);
    }

    private function superAdmin(string $locale): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => $locale]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor;
    }
}
