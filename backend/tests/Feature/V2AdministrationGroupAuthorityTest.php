<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P330: the Administration group only projects independently authorized pages.
 */
final class V2AdministrationGroupAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_sees_existing_administration_group_and_real_child_routes(): void
    {
        $admin = $this->superAdmin();
        $group = collect(app(AdminNavigation::class)->groupsFor($admin))
            ->firstWhere('key', 'administration');

        $this->assertIsArray($group);
        $this->assertSame('admin.nav_groups.administration', $group['label']);
        $keys = collect($group['children'])->pluck('key')->all();
        $this->assertContains('administration_hub', $keys);
        $this->assertCount(count(array_unique($keys)), $keys);

        foreach ($group['children'] as $child) {
            $this->assertTrue(Route::has($child['route']), $child['key']);
        }

        $this->assertArrayNotHasKey('route', $group);
    }

    public function test_roles_manage_cannot_invent_platform_owner_destinations(): void
    {
        $operator = $this->operatorWithRolesManage();
        $groups = app(AdminNavigation::class)->groupsFor($operator);
        $group = collect($groups)->firstWhere('key', 'administration');

        $this->assertIsArray($group);
        $keys = collect($group['children'])->pluck('key')->all();
        $this->assertContains('sidebar_role_access', $keys);
        $this->assertContains('sidebar_layout', $keys);
        $this->assertNotContains('platform_owner_modules', $keys);
        $this->assertNotContains('administration_hub', $keys);
    }

    public function test_roleless_and_inactive_users_cannot_open_administration(): void
    {
        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->superAdmin();
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive] as $actor) {
            $groups = app(AdminNavigation::class)->groupsFor($actor->fresh());
            $this->assertNotContains('administration', collect($groups)->pluck('key')->all());
        }

        $this->actingAs($roleless)
            ->get(route('admin.sidebar-access.roles'))
            ->assertForbidden();
    }

    public function test_administration_hub_uses_arabic_and_english_with_correct_direction(): void
    {
        $admin = $this->superAdmin();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin->fresh())
                ->get(route('admin.administration.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('admin.nav_groups.administration', [], $locale));
        }
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function operatorWithRolesManage(): User
    {
        $operator = User::factory()->create(['is_active' => true]);
        $role = Role::query()->create([
            'code' => 'P2660_ROLE_MANAGER',
            'name' => 'P2660 Role Manager',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach(
            Permission::query()->where('code', 'roles.manage')->firstOrFail(),
        );
        $operator->roles()->attach($role);

        return $operator;
    }
}
