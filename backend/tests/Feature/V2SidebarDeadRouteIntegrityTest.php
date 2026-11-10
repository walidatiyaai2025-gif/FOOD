<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AdminSidebarManager;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class V2SidebarDeadRouteIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_a_group_with_no_visible_authorized_children_disappears_in_both_locales(): void
    {
        $user = $this->userWithRole('B2B_ADMIN', 'v2-sidebar-empty-group@example.test');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();

        $catalog = collect(app(AdminNavigation::class)->groupsFor($user))->firstWhere('key', 'catalog');
        $this->assertNotNull($catalog);
        $this->assertNotEmpty($catalog['children']);

        foreach ($catalog['children'] as $item) {
            DB::table('admin_sidebar_role_visibility')->insert([
                'role_id' => $role->getKey(),
                'item_key' => $item['key'],
                'is_visible' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (['ar', 'en'] as $locale) {
            $user->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $groups = collect(app(AdminNavigation::class)->groupsFor($user));
            $this->assertNull($groups->firstWhere('key', 'catalog'));
            $this->assertNotEmpty($groups);
            foreach ($groups as $group) {
                $this->assertNotEmpty($group['children'], 'No empty Sidebar group may be shown.');
            }
        }

        // Layout visibility is not a permission revocation: the HTTP handler
        // still makes its own authorization decision.
        $this->assertTrue($user->fresh()->hasPermission('catalog.view'));
        $this->actingAs($user)->get(route('admin.catalog.index', [], false))->assertOk();
    }

    public function test_stale_route_is_removed_before_sidebar_layout_and_empty_group_is_suppressed(): void
    {
        $user = $this->userWithRole('B2B_ADMIN', 'v2-sidebar-dead-route@example.test');
        $catalog = collect(app(AdminNavigation::class)->groupsFor($user))->firstWhere('key', 'catalog');
        $this->assertNotNull($catalog);
        $this->assertGreaterThanOrEqual(2, count($catalog['children']));

        $valid = $catalog['children'][0];
        $stale = $catalog['children'][1];
        $stale['route'] = 'admin.v2.removed.route.p045';

        $this->assertTrue(Route::has($valid['route']));
        $this->assertFalse(Route::has($stale['route']));

        $manager = app(AdminSidebarManager::class);
        $input = [
            'key' => 'catalog',
            'label' => $catalog['label'],
            'icon' => $catalog['icon'],
            'children' => [$stale, $valid],
        ];

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $result = $manager->apply($user, [$input]);
            $this->assertCount(1, $result);
            $keys = array_column($result[0]['children'], 'key');
            $this->assertContains($valid['key'], $keys);
            $this->assertNotContains($stale['key'], $keys);

            $input['children'] = [$stale];
            $this->assertSame([], $manager->apply($user, [$input]));
            $input['children'] = [$stale, $valid];
        }
    }

    public function test_a_hidden_sidebar_destination_does_not_bypass_server_authorization(): void
    {
        $noRole = User::query()->create([
            'name' => 'No Sidebar RBAC',
            'email' => 'v2-sidebar-no-access@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->actingAs($noRole)
            ->get(route('admin.sidebar-layout.index', [], false))
            ->assertForbidden();
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
