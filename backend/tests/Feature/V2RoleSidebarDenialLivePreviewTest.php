<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2RoleSidebarDenialLivePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_existing_authorized_role_editor_exposes_safe_unsaved_preview_in_both_locales(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'p367-admin@example.test');
        $role = $this->customRole('P367_WHOLESALE', 'global');
        $catalog = Permission::query()->where('code', 'catalog.view')->firstOrFail();
        $role->permissions()->sync([$catalog->id]);
        $before = $role->permissions()->pluck('permissions.id')->all();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($admin)->get(route('admin.sidebar-access.roles', [
                'role_id' => $role->id,
            ], false));

            $response->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('v2_role_permissions.preview_title', [], $locale))
                ->assertSee(__('v2_role_permissions.preview_module', [], $locale))
                ->assertSee(__('v2_role_permissions.preview_server', [], $locale))
                ->assertSee('data-v2-role-preview-form', false)
                ->assertSee('data-v2-preview-summary', false)
                ->assertSee('data-v2-role-preview-node', false)
                ->assertSee('data-v2-role-permission-code="catalog.view"', false)
                ->assertSee('data-preview-permissions="catalog.view"', false)
                ->assertSee('aria-live="polite"', false)
                ->assertDontSee('name="item_keys[]"', false);
        }

        $this->assertEqualsCanonicalizing($before, $role->fresh()->permissions()->pluck('permissions.id')->all());
        $this->assertSame(0, DB::table('admin_sidebar_role_visibility')->where('role_id', $role->id)->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'role.updated')->count());
    }

    public function test_store_role_preview_exposes_scope_boundary_without_creating_a_grant(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'p367-store-admin@example.test');
        $role = $this->customRole('P367_RETAIL', 'store');
        $html = (string) $this->actingAs($admin)->get(route('admin.sidebar-access.roles', [
            'role_id' => $role->id,
        ], false))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/data-v2-role-node="catalog_management"[^>]*data-preview-scope-ok="0"/',
            $html,
        );
        $this->assertStringContainsString('data-preview-module="1"', $html);
        $this->assertSame(0, $role->fresh()->permissions()->count());
    }

    public function test_non_privileged_actor_cannot_read_the_preview_or_mutate_role_permissions(): void
    {
        $role = $this->customRole('P367_RESTRICTED', 'global');
        $actor = User::query()->create([
            'name' => 'P367 Restricted',
            'email' => 'p367-restricted@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $permission = Permission::query()->where('code', 'catalog.view')->firstOrFail();

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
    }

    private function customRole(string $code, string $scope): Role
    {
        return Role::query()->create([
            'code' => $code,
            'name' => 'V2 P367 Preview Role',
            'description' => 'P367',
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
