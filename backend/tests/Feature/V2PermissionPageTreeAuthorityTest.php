<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AdminSidebarManager;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P337: the existing page-permission tree explains canonical authorization
 * metadata but never turns a preview, group or checkbox into an HTTP grant.
 */
final class V2PermissionPageTreeAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_role_page_tree_projects_real_item_permission_scope_channel_and_module_metadata_in_both_locales(): void
    {
        $editor = $this->actor('SUPER_ADMIN', 'p337-owner@example.test');
        $retailRole = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        $matrix = app(AdminSidebarManager::class)->roleMatrix($retailRole);
        $nodes = collect($matrix['groups'])
            ->flatMap(static fn (array $group): array => $group['items'])
            ->keyBy('key');

        $this->assertTrue($nodes->has('b2c_orders'));
        $this->assertSame('store', $nodes['b2c_orders']['meta']['scope']);
        $this->assertSame('b2c', $nodes['b2c_orders']['meta']['channel']);
        $this->assertContains('orders.view', $nodes['b2c_orders']['permissions']);
        $this->assertTrue($nodes->has('erp_finance'));
        $this->assertSame('finance', $nodes['erp_finance']['meta']['module']);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $editor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($editor->fresh())
                ->get(route('admin.sidebar-access.roles', ['role_id' => $retailRole->id]))
                ->assertOk()
                ->assertViewHas('selectedRole', static fn (Role $role): bool => $role->id === $retailRole->id)
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'">', false)
                ->assertSee('data-v2-role-node="b2c_orders"', false)
                ->assertSee('data-v2-role-node="erp_finance"', false)
                ->assertSee('data-preview-module="1"', false)
                ->assertSee('data-v2-preview-no-action-grant', false)
                ->assertSee(__('v2_role_tree.name'))
                ->assertSee(__('v2_role_tree.code'))
                ->assertSee(__('v2_role_tree.permissions'))
                ->assertSee(__('v2_role_tree.scope'))
                ->assertSee(__('v2_role_tree.channel'))
                ->assertSee(__('v2_role_tree.module'));
        }
    }

    public function test_control_plane_role_page_tree_is_read_only_and_module_preview_never_issues_a_grant(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'p337-readonly@example.test');
        $protectedRole = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.sidebar-access.roles', ['role_id' => $protectedRole->id]))
            ->assertOk()
            ->assertViewHas('canEditRolePermissions', false)
            ->assertSee('data-v2-role-preview-node', false)
            ->assertSee('data-preview-module-ready=', false)
            ->assertSee('data-v2-preview-no-action-grant', false)
            ->assertDontSee('name="change_reason"', false);
    }

    public function test_driver_and_roleless_identities_cannot_open_role_page_tree_by_direct_url(): void
    {
        $protectedRole = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $driver = $this->actor('B2C_DRIVER', 'p337-driver@example.test');
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'ar']);

        foreach ([$driver, $roleless] as $actor) {
            $this->actingAs($actor)
                ->get(route('admin.sidebar-access.roles', ['role_id' => $protectedRole->id]))
                ->assertForbidden();
        }
    }

    private function actor(string $roleCode, string $email): User
    {
        $actor = User::factory()->create(['email' => $email, 'locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }
}
