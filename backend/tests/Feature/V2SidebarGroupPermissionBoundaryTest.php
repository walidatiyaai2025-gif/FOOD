<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AdminSidebarManager;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SidebarGroupPermissionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_business_group_key_is_rejected_before_role_visibility_or_audit_changes_in_both_locales(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN', 'p093-group-admin@example.test');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $registry = app(AdminSidebarManager::class)->registry();

        $this->assertArrayHasKey('catalog', $registry['groups']);
        $this->assertArrayNotHasKey('catalog', $registry['items']);
        $this->assertArrayHasKey('catalog_management', $registry['items']);

        $beforeRolePermissionIds = $role->permissions()->pluck('permissions.id')->all();

        foreach (['ar', 'en'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin)->put(route('admin.sidebar-access.roles.update', ['role' => $role->id], false), [
                'item_keys' => ['catalog', 'catalog_management'],
            ])->assertSessionHasErrors([
                'item_keys.0' => __('sidebar_admin.invalid_item', [], $locale),
            ]);

            $this->assertSame(0, DB::table('admin_sidebar_role_visibility')->where('role_id', $role->id)->count());
            $this->assertSame(0, DB::table('audit_logs')->where('event', 'admin.sidebar.role_visibility.updated')->count());
        }

        $this->assertEqualsCanonicalizing(
            $beforeRolePermissionIds,
            $role->fresh()->permissions()->pluck('permissions.id')->all(),
        );
    }

    public function test_unknown_item_key_is_not_silently_accepted_or_audited(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN', 'p093-unknown-admin@example.test');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();

        $this->actingAs($admin)->put(route('admin.sidebar-access.roles.update', ['role' => $role->id], false), [
            'item_keys' => ['v2_not_a_registered_page'],
        ])->assertSessionHasErrors(['item_keys.0']);

        $this->assertDatabaseMissing('admin_sidebar_role_visibility', [
            'role_id' => $role->id,
            'item_key' => 'v2_not_a_registered_page',
        ]);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'admin.sidebar.role_visibility.updated')->count());
    }

    public function test_valid_registered_page_is_saved_without_creating_a_group_permission(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN', 'p093-valid-admin@example.test');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $beforePermissions = $role->permissions()->pluck('permissions.id')->all();

        $this->actingAs($admin)->put(route('admin.sidebar-access.roles.update', ['role' => $role->id], false), [
            'item_keys' => ['catalog_management'],
        ])->assertRedirect(route('admin.sidebar-access.roles', ['role_id' => $role->id], false))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('admin_sidebar_role_visibility', [
            'role_id' => $role->id,
            'item_key' => 'catalog_management',
            'is_visible' => true,
        ]);

        $this->assertDatabaseMissing('admin_sidebar_role_visibility', [
            'role_id' => $role->id,
            'item_key' => 'catalog',
        ]);

        $this->assertEqualsCanonicalizing(
            $beforePermissions,
            $role->fresh()->permissions()->pluck('permissions.id')->all(),
        );

        $this->assertSame(1, DB::table('audit_logs')->where('event', 'admin.sidebar.role_visibility.updated')->count());
    }

    public function test_group_header_is_not_an_authorized_route_or_an_effective_permission(): void
    {
        $user = $this->globalUser('B2B_ADMIN', 'p093-b2b@example.test');
        $groups = collect(app(AdminNavigation::class)->groupsFor($user));
        $catalog = $groups->firstWhere('key', 'catalog');

        $this->assertNotNull($catalog);
        $this->assertNotEmpty($catalog['children']);
        $this->assertNotContains('catalog', $user->effectivePermissionCodes());

        $keys = collect($catalog['children'])->pluck('key')->all();
        $this->assertNotContains('catalog', $keys);
        $this->assertContains('catalog_management', $keys);
    }

    public function test_unprivileged_actor_cannot_write_role_visibility_even_with_group_key(): void
    {
        $actor = User::query()->create([
            'name' => 'P093 Restricted',
            'email' => 'p093-no-role@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();

        $this->actingAs($actor)->put(route('admin.sidebar-access.roles.update', ['role' => $role->id], false), [
            'item_keys' => ['catalog'],
        ])->assertForbidden();

        $this->assertSame(0, DB::table('admin_sidebar_role_visibility')->where('role_id', $role->id)->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'admin.sidebar.role_visibility.updated')->count());
    }

    private function globalUser(string $roleCode, string $email): User
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
