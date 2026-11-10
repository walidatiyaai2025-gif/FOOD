<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AdminSidebarManager;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P011: Sidebar Layout edits are presentation preferences, never RBAC grants.
 */
final class V2SidebarLayoutPresentationWhitelistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_layout_http_update_changes_only_registered_presentation_fields_and_audits_the_edit(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'v2-p011-admin@example.test');
        $viewer = $this->actor('B2B_ADMIN', 'v2-p011-viewer@example.test');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $permissionIds = $role->permissions()->pluck('permissions.id')->all();
        $memberRoleIds = $viewer->roles()->pluck('roles.id')->all();
        $registryBefore = app(AdminSidebarManager::class)->registry()['items']['catalog_management'];
        $auditBefore = DB::table('audit_logs')
            ->where('event', 'admin.sidebar.layout.updated')->count();

        $this->actingAs($admin)->put(route('admin.sidebar-layout.update', [], false), [
            'groups' => [
                'catalog' => [
                    'label_ar' => 'الكتالوج',
                    'label_en' => 'Catalog',
                    'icon' => '▦',
                    'sort_order' => 41,
                    'is_enabled' => 1,
                    'permissions' => ['platform.manage'],
                ],
            ],
            'items' => [
                'catalog_management' => [
                    'group_key' => 'catalog',
                    'parent_key' => null,
                    'label_ar' => 'إدارة الكتالوج',
                    'label_en' => 'Catalog management',
                    'sort_order' => 9,
                    'is_enabled' => 1,
                    'roles' => ['PLATFORM_OWNER'],
                    'permissions' => ['platform.manage'],
                    'scope' => 'global',
                    'route' => 'admin.sidebar-layout.index',
                ],
            ],
        ])->assertRedirect(route('admin.sidebar-layout.index', [], false))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('admin_sidebar_groups', [
            'group_key' => 'catalog',
            'label_ar' => 'الكتالوج',
            'label_en' => 'Catalog',
            'sort_order' => 41,
        ]);
        $this->assertDatabaseHas('admin_sidebar_items', [
            'item_key' => 'catalog_management',
            'group_key' => 'catalog',
            'label_ar' => 'إدارة الكتالوج',
            'label_en' => 'Catalog management',
            'sort_order' => 9,
        ]);
        $this->assertSame(
            $registryBefore,
            app(AdminSidebarManager::class)->registry()['items']['catalog_management'],
            'Presentation editing must not overwrite canonical permission, scope or route metadata.',
        );
        $this->assertEqualsCanonicalizing(
            $permissionIds,
            $role->fresh()->permissions()->pluck('permissions.id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            $memberRoleIds,
            $viewer->fresh()->roles()->pluck('roles.id')->all(),
        );
        $this->assertSame($auditBefore + 1, DB::table('audit_logs')
            ->where('event', 'admin.sidebar.layout.updated')->count());

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();
            $this->actingAs($admin)
                ->get(route('admin.sidebar-layout.index', [], false))
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'">', false);
        }

        $this->actingAs($viewer)
            ->get(route('admin.sidebar-layout.index', [], false))
            ->assertForbidden();
    }

    public function test_unknown_layout_keys_are_rejected_atomically_and_unprivileged_users_cannot_write(): void
    {
        $admin = $this->actor('SUPER_ADMIN', 'v2-p011-invalid-admin@example.test');
        $noRole = User::query()->create([
            'name' => 'Unprivileged',
            'email' => 'v2-p011-unprivileged@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $groupsBefore = DB::table('admin_sidebar_groups')->count();
        $itemsBefore = DB::table('admin_sidebar_items')->count();
        $auditBefore = DB::table('audit_logs')
            ->where('event', 'admin.sidebar.layout.updated')->count();

        $invalid = [
            'groups' => [],
            'items' => [
                'unregistered_super_admin_link' => [
                    'group_key' => 'administration',
                    'parent_key' => null,
                    'label_ar' => 'غير مصرح',
                    'label_en' => 'Unauthorized',
                    'sort_order' => 1,
                    'is_enabled' => 1,
                ],
            ],
        ];

        $this->actingAs($admin)
            ->put(route('admin.sidebar-layout.update', [], false), $invalid)
            ->assertSessionHasErrors('items');

        $this->actingAs($noRole)
            ->put(route('admin.sidebar-layout.update', [], false), $invalid)
            ->assertForbidden();

        $this->assertSame($groupsBefore, DB::table('admin_sidebar_groups')->count());
        $this->assertSame($itemsBefore, DB::table('admin_sidebar_items')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')
            ->where('event', 'admin.sidebar.layout.updated')->count());
        $this->assertDatabaseMissing('admin_sidebar_items', ['item_key' => 'unregistered_super_admin_link']);
    }

    private function actor(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
