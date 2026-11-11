<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2UserRoleScopePageTreePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_can_preview_an_active_global_role_without_mutating_any_grant_or_audit_record(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $role = $this->customRole('V2_P077_GLOBAL', 'global');
        $role->permissions()->attach(Permission::query()->where('code', 'customers.view')->firstOrFail()->id);
        $relationsBefore = DB::table('permission_role')->count();
        $auditBefore = DB::table('audit_logs')->count();

        $this->actingAs($admin)
            ->getJson(route('admin.security.role-tree-preview', [
                'scope' => 'global',
                'global_role_ids' => [$role->id],
            ]))
            ->assertOk()
            ->assertJsonPath('projection_only', true)
            ->assertJsonStructure(['projection_only', 'count', 'groups', 'disclaimer'])
            ->assertHeader('Cache-Control', 'private, no-store');

        $this->assertSame($relationsBefore, DB::table('permission_role')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_existing_security_users_role_picker_exposes_the_read_only_preview_in_both_locales(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $admin = $this->actor('SUPER_ADMIN', $locale);
            $response = $this->actingAs($admin)
                ->get(route('admin.security.index', ['section' => 'users']));

            $response->assertOk()
                ->assertSee('data-v2-user-page-tree', false)
                ->assertSee('data-v2-user-tree-scope', false)
                ->assertSee('data-v2-user-tree-refresh', false);

            $response->assertSee($locale === 'ar'
                ? 'معاينة شجرة الصفحات للدور والنطاق'
                : 'Role and scope page tree preview');
        }
    }

    public function test_roleless_actor_is_denied_and_store_scope_requires_an_existing_active_retail_store(): void
    {
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->actingAs($roleless)
            ->getJson(route('admin.security.role-tree-preview', ['scope' => 'global']))
            ->assertForbidden();

        $admin = $this->actor('SUPER_ADMIN', 'en');
        $this->actingAs($admin)
            ->getJson(route('admin.security.role-tree-preview', [
                'scope' => 'store',
                'store_id' => 999999999,
            ]))->assertUnprocessable();

        $this->actingAs($admin)
            ->getJson(route('admin.security.role-tree-preview', [
                'scope' => 'global',
                'store_id' => 1,
            ]))->assertUnprocessable();
    }

    public function test_scope_mismatch_and_inactive_roles_cannot_be_previewed(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'en');
        $storeRole = $this->customRole('V2_P077_STORE', 'store');
        $inactive = $this->customRole('V2_P077_INACTIVE', 'global', false);

        $this->actingAs($admin)
            ->getJson(route('admin.security.role-tree-preview', [
                'scope' => 'global',
                'global_role_ids' => [$storeRole->id],
            ]))->assertUnprocessable();

        $this->actingAs($admin)
            ->getJson(route('admin.security.role-tree-preview', [
                'scope' => 'global',
                'global_role_ids' => [$inactive->id],
            ]))->assertUnprocessable();

        $this->actingAs($admin)
            ->getJson(route('admin.security.role-tree-preview', [
                'scope' => 'global',
                'global_role_ids' => [99999999],
            ]))->assertUnprocessable();
    }

    public function test_read_only_projection_disclaimer_is_localized_in_arabic(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'ar');
        $response = $this->actingAs($admin)
            ->getJson(route('admin.security.role-tree-preview', ['scope' => 'global']));

        $response->assertOk()->assertJsonPath('projection_only', true);
        $this->assertStringContainsString('هذه معاينة تقديرية', $response->json('disclaimer'));
    }

    private function actor(string $roleCode, string $locale): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => $locale]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function customRole(string $code, string $scope, bool $active = true): Role
    {
        return Role::query()->create([
            'code' => $code,
            'name' => $code,
            'scope' => $scope,
            'is_system' => false,
            'is_active' => $active,
        ]);
    }
}
