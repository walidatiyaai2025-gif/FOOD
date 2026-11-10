<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SidebarLayoutVsRouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_disabling_one_sidebar_item_hides_only_navigation_and_keeps_authorized_http_access(): void
    {
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $user = $this->b2bAdmin($locale);

            $this->assertTrue($user->hasPermission('catalog.view'));
            $this->assertContains('catalog_management', $this->visibleKeys($user));

            $this->actingAs($user)
                ->get(route('admin.catalog.index'))
                ->assertOk();

            DB::table('admin_sidebar_items')->updateOrInsert(
                ['item_key' => 'catalog_management'],
                [
                    'group_key' => 'catalog',
                    'parent_key' => null,
                    'label_ar' => null,
                    'label_en' => null,
                    'sort_order' => 10,
                    'is_enabled' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $this->assertNotContains('catalog_management', $this->visibleKeys($user));
            $this->assertTrue($user->fresh()->hasPermission('catalog.view'));

            $this->actingAs($user)
                ->get(route('admin.catalog.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($user)
                ->get(route('admin.b2c.dashboard'))
                ->assertForbidden();

            // Restore layout between independent locale assertions.
            DB::table('admin_sidebar_items')
                ->where('item_key', 'catalog_management')
                ->update(['is_enabled' => true, 'updated_at' => now()]);
            $this->assertContains('catalog_management', $this->visibleKeys($user));
        }
    }

    public function test_disabling_an_entire_layout_group_does_not_revoke_canonical_route_permissions(): void
    {
        $user = $this->b2bAdmin('en');
        $this->assertContains('catalog_management', $this->visibleKeys($user));

        DB::table('admin_sidebar_groups')->updateOrInsert(
            ['group_key' => 'catalog'],
            [
                'label_ar' => null,
                'label_en' => null,
                'icon' => '▦',
                'sort_order' => 40,
                'is_enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $groups = app(AdminNavigation::class)->groupsFor($user);
        $this->assertFalse(collect($groups)->contains('key', 'catalog'));
        $this->assertNotContains('catalog_management', $this->visibleKeys($user));

        $this->actingAs($user)
            ->get(route('admin.catalog.index'))
            ->assertOk();

        DB::table('admin_sidebar_groups')
            ->where('group_key', 'catalog')
            ->update(['is_enabled' => true, 'updated_at' => now()]);

        $this->assertContains('catalog_management', $this->visibleKeys($user));
        $this->assertTrue($user->fresh()->hasPermission('catalog.view'));
    }

    public function test_enabling_layout_cannot_make_an_unauthorized_catalog_route_visible_or_accessible(): void
    {
        $role = Role::query()->create([
            'code' => 'V2_LAYOUT_SETTINGS_ONLY',
            'name' => 'V2 Layout Settings Viewer',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach(Permission::query()->where('code', 'settings.view')->firstOrFail());
        $user = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $user->roles()->attach($role->id);

        DB::table('admin_sidebar_items')->updateOrInsert(
            ['item_key' => 'catalog_management'],
            [
                'group_key' => 'catalog',
                'parent_key' => null,
                'label_ar' => null,
                'label_en' => null,
                'sort_order' => 1,
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $this->assertFalse($user->hasPermission('catalog.view'));
        $this->assertNotContains('catalog_management', $this->visibleKeys($user));

        $this->actingAs($user)
            ->get(route('admin.catalog.index'))
            ->assertForbidden();
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

    private function b2bAdmin(string $locale): User
    {
        $user = User::factory()->create(['locale' => $locale, 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        return $user;
    }
}
