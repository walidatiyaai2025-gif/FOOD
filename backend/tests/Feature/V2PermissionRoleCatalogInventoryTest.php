<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2PermissionRoleCatalogInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_sees_real_role_permission_inventory_with_historical_delta_without_mutation(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'en');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $permission = Permission::query()->create(['code' => 'v2_audit_fixture.observe', 'name' => 'Audit fixture']);
        $role->permissions()->attach($permission->id);

        $beforeCount = DB::table('permission_role')->count();
        $beforeAudit = DB::table('audit_logs')->count();

        $response = $this->actingAs($admin)->get(route('admin.security.index', ['section' => 'roles']));
        $response->assertOk()
            ->assertSee('data-v2-permission-role-inventory', false)
            ->assertSee('Permission catalog and role-link audit')
            ->assertSee('146 permissions and 773 role-to-permission rows')
            ->assertSee('dir="ltr"', false);

        $audit = $response->viewData('permissionRoleAudit');
        $this->assertIsArray($audit);
        $this->assertSame(Permission::query()->count(), $audit['permission_count']);
        $this->assertSame($beforeCount, $audit['relation_count']);
        $this->assertSame(1, collect($audit['namespaces'])->firstWhere('code', 'v2_audit_fixture')['permissions']);
        $this->assertSame(1, collect($audit['namespaces'])->firstWhere('code', 'v2_audit_fixture')['relations']);
        $this->assertSame($beforeCount, DB::table('permission_role')->count());
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('permission_role', ['role_id' => $role->id, 'permission_id' => $permission->id]);
    }

    public function test_audit_is_localized_in_arabic_and_not_visible_outside_roles_tab(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'ar');
        $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'roles']))
            ->assertOk()
            ->assertSee('جرد سجل الصلاحيات وعلاقات الأدوار')
            ->assertSee('dir="rtl"', false);

        $this->actingAs($admin)
            ->get(route('admin.security.index', ['section' => 'users']))
            ->assertOk()
            ->assertDontSee('data-v2-permission-role-inventory', false)
            ->assertViewHas('permissionRoleAudit', null);
    }

    public function test_delegated_security_viewer_cannot_see_full_catalog_or_role_links(): void
    {
        $viewer = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->create([
            'code' => 'AUDIT_VIEW_ONLY',
            'name' => 'Read only security',
            'scope' => 'global',
            'is_active' => true,
            'is_system' => false,
        ]);
        $role->permissions()->attach(Permission::query()->where('code', 'security.view')->firstOrFail()->id);
        $viewer->roles()->attach($role);

        $this->actingAs($viewer)
            ->get(route('admin.security.index', ['section' => 'roles']))
            ->assertOk()
            ->assertDontSee('data-v2-permission-role-inventory', false)
            ->assertViewHas('permissionRoleAudit', null);
    }

    public function test_actor_without_security_view_cannot_read_inventory(): void
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->actingAs($user)
            ->get(route('admin.security.index', ['section' => 'roles']))
            ->assertForbidden();
    }

    private function userWithRole(string $code, string $locale): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => $locale]);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }
}
