<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SidebarRolePermissionCodesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_page_tree_edits_permission_codes_not_sidebar_visibility_in_arabic_and_english(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'p094-editor@example.test');
        $role = $this->customRole('V2_PAGE_TREE_CODES');
        $catalog = Permission::query()->where('code', 'catalog.view')->firstOrFail();
        $orders = Permission::query()->where('code', 'orders.view')->firstOrFail();
        $unrelated = Permission::query()->where('code', 'users.view')->firstOrFail();
        $role->permissions()->sync([$catalog->id, $unrelated->id]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($admin)->get(route('admin.sidebar-access.roles', [
                'role_id' => $role->id,
            ], false));

            $response->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('v2_role_permissions.description', [], $locale))
                ->assertSee('name="permission_ids[]"', false)
                ->assertDontSee('name="item_keys[]"', false)
                ->assertSee('action="'.route('admin.security.roles.update', $role, false).'"', false);

            // Each shared permission code is an input only once, even when
            // several sidebar items reuse it.
            $html = (string) $response->getContent();
            $this->assertSame(1, substr_count($html, 'name="permission_ids[]" value="'.$catalog->id.'"'));
        }

        $admin->forceFill(['locale' => 'en'])->save();
        $this->actingAs($admin)->patch(route('admin.security.roles.update', $role, false), [
            'name' => $role->name,
            'description' => $role->description,
            'scope' => 'global',
            'is_active' => '1',
            // Remove catalog, grant orders and preserve users.view even
            // though the latter is not controlled by any page checkbox.
            'permission_ids' => [$orders->id, $unrelated->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $codes = $role->fresh()->permissions()->pluck('code')->all();
        $this->assertEqualsCanonicalizing(['orders.view', 'users.view'], $codes);
        $this->assertSame(0, DB::table('admin_sidebar_role_visibility')->where('role_id', $role->id)->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'admin.sidebar.role_visibility.updated')->count());
        $this->assertGreaterThanOrEqual(1, DB::table('audit_logs')->where('event', 'role.updated')->count());
    }

    public function test_super_admin_is_displayed_read_only_and_its_canonical_permissions_cannot_be_removed(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'p094-locked@example.test');
        $role = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $before = $role->permissions()->pluck('permissions.id')->all();

        $this->actingAs($admin)->get(route('admin.sidebar-access.roles', [
            'role_id' => $role->id,
        ], false))->assertOk()
            ->assertSee(__('v2_role_permissions.read_only', [], 'en'))
            ->assertDontSee('type="submit">'.__('v2_role_permissions.save', [], 'en'), false);

        $this->assertEqualsCanonicalizing($before, $role->fresh()->permissions()->pluck('permissions.id')->all());
    }

    public function test_role_permission_editor_does_not_allow_unprivileged_users_to_read_or_mutate(): void
    {
        $actor = User::query()->create([
            'name' => 'Restricted',
            'email' => 'p094-no-access@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = $this->customRole('V2_FORBIDDEN_EDITOR');
        $permission = Permission::query()->where('code', 'orders.view')->firstOrFail();

        $this->actingAs($actor)->get(route('admin.sidebar-access.roles', [
            'role_id' => $role->id,
        ], false))->assertForbidden();

        $this->actingAs($actor)->patch(route('admin.security.roles.update', $role, false), [
            'name' => $role->name,
            'scope' => 'global',
            'is_active' => '1',
            'permission_ids' => [$permission->id],
        ])->assertForbidden();

        $this->assertSame(0, $role->fresh()->permissions()->count());
        $this->assertSame(0, DB::table('admin_sidebar_role_visibility')->where('role_id', $role->id)->count());
    }

    public function test_retail_role_cannot_gain_wholesale_permissions_through_canonical_role_update(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'p094-retail-boundary@example.test');
        $role = $this->customRole('V2_RETAIL_PAGE_TREE', 'store');
        $wholesale = Permission::query()->where('code', 'b2b.accounts.view')->firstOrFail();

        $this->actingAs($admin)->patch(route('admin.security.roles.update', $role, false), [
            'name' => $role->name,
            'description' => null,
            'scope' => 'store',
            'is_active' => '1',
            'permission_ids' => [$wholesale->id],
        ])->assertSessionHasErrors('permission_ids');

        $this->assertSame(0, $role->fresh()->permissions()->count());
        $this->assertSame(0, DB::table('admin_sidebar_role_visibility')->where('role_id', $role->id)->count());
    }

    private function customRole(string $code, string $scope = 'global'): Role
    {
        return Role::query()->create([
            'code' => $code,
            'name' => 'V2 Page Tree Role',
            'description' => 'P094',
            'scope' => $scope,
            'is_system' => false,
            'is_active' => true,
        ]);
    }

    private function userWithRole(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
