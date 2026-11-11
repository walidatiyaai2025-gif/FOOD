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

final class V2SidebarPresentationCannotGrantAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);

        // Real B2B customer-group HTTP reads resolve an authorized wholesale store.
        // The core reference seed does not promise an operational store fixture.
        DB::table('stores')->insert([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'V2-P022-WHOLESALE',
            'name' => 'V2 Sidebar Boundary Wholesale',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_hiding_an_authorized_sidebar_item_does_not_revoke_its_http_permission(): void
    {
        $role = $this->b2bAdminRole();
        $user = $this->b2bAdmin($role);

        $this->assertContains('customer_groups', $this->visibleKeys($user));
        $this->actingAs($user)
            ->get(route('admin.b2b.customer-groups.index', [], false))
            ->assertOk();

        $permissionCount = DB::table('permission_role')->where('role_id', $role->id)->count();
        $this->saveRoleDisplayPreference($role, 'customer_groups', false);

        $this->assertNotContains('customer_groups', $this->visibleKeys($user));
        $this->actingAs($user)
            ->get(route('admin.b2b.customer-groups.index', [], false))
            ->assertOk();
        $this->assertSame($permissionCount, DB::table('permission_role')->where('role_id', $role->id)->count());
    }

    public function test_saved_visible_preference_cannot_grant_an_unassigned_permission(): void
    {
        $role = $this->b2bAdminRole();
        $user = $this->b2bAdmin($role);

        $this->assertFalse($user->hasPermission('notifications.manage'));
        $this->saveRoleDisplayPreference($role, 'notifications_center', true);

        $this->assertNotContains('notifications_center', $this->visibleKeys($user));
        $this->actingAs($user)
            ->get(route('admin.notifications.index', [], false))
            ->assertForbidden();

        $matrix = app(AdminSidebarManager::class)->roleMatrix($role);
        $item = collect($matrix['groups'])
            ->flatMap(static fn (array $group): array => $group['items'])
            ->firstWhere('key', 'notifications_center');

        $this->assertIsArray($item);
        $this->assertFalse($item['base_allowed']);
        $this->assertFalse($item['visible_for_role']);
        $this->assertTrue($item['override']);
    }

    public function test_cosmetic_visibility_cannot_cross_wholesale_and_retail_channels(): void
    {
        $role = $this->b2bAdminRole();
        $user = $this->b2bAdmin($role);

        $this->saveRoleDisplayPreference($role, 'b2c_customer_groups', true);

        $this->assertContains('customer_groups', $this->visibleKeys($user));
        $this->assertNotContains('b2c_customer_groups', $this->visibleKeys($user));
        $this->actingAs($user)
            ->get(route('admin.b2c.customer-groups.index', [], false))
            ->assertNotFound();
    }

    public function test_global_cosmetic_disable_hides_navigation_without_changing_authorization(): void
    {
        $role = $this->b2bAdminRole();
        $user = $this->b2bAdmin($role);

        $this->assertContains('customer_groups', $this->visibleKeys($user));

        DB::table('admin_sidebar_items')->insert([
            'item_key' => 'customer_groups',
            'group_key' => 'accounts',
            'sort_order' => 30,
            'is_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNotContains('customer_groups', $this->visibleKeys($user));
        $this->actingAs($user)
            ->get(route('admin.b2b.customer-groups.index', [], false))
            ->assertOk();
        $this->assertTrue($user->fresh()->hasPermission('customers.view'));
    }

    public function test_roleless_actor_cannot_use_cosmetic_editor_to_gain_roles_manage(): void
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = $this->b2bAdminRole();

        $this->assertNotContains('sidebar_layout', $this->visibleKeys($user));
        $this->actingAs($user)
            ->get(route('admin.sidebar-layout.index', [], false))
            ->assertForbidden();
        $this->actingAs($user)
            ->put(route('admin.sidebar-access.roles.update', ['role' => $role->id], false), [
                'item_keys' => ['sidebar_layout', 'notifications_center'],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('admin_sidebar_role_visibility', [
            'role_id' => $role->id,
            'item_key' => 'notifications_center',
        ]);
    }

    private function b2bAdminRole(): Role
    {
        return Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
    }

    private function b2bAdmin(Role $role): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach($role);

        return $user;
    }

    /** @return list<string> */
    private function visibleKeys(User $user): array
    {
        return collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')
            ->values()
            ->all();
    }

    private function saveRoleDisplayPreference(Role $role, string $key, bool $visible): void
    {
        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->id,
            'item_key' => $key,
            'is_visible' => $visible,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
