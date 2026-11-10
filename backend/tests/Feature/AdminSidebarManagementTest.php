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

class AdminSidebarManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_role_visibility_can_hide_authorized_sidebar_page_without_revoking_rbac_permission(): void
    {
        $user = $this->globalUser('B2B_ADMIN', 'sidebar-role@example.test');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();

        $before = collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(fn (array $group) => $group['children'])
            ->pluck('key');

        $this->assertTrue($before->contains('catalog_management'));
        $this->assertTrue($user->hasPermission('catalog.view'));

        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->id,
            'item_key' => 'catalog_management',
            'is_visible' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $after = collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(fn (array $group) => $this->flatten($group['children']))
            ->pluck('key');

        $this->assertFalse($after->contains('catalog_management'));
        $this->assertTrue($user->fresh()->hasPermission('catalog.view'));
    }

    public function test_layout_can_move_rename_and_nest_authorized_items(): void
    {
        $user = $this->globalUser('B2B_ADMIN', 'sidebar-layout@example.test');

        DB::table('admin_sidebar_items')->insert([
            [
                'item_key' => 'catalog_management',
                'group_key' => 'analytics',
                'parent_key' => null,
                'label_ar' => 'الكتالوج المخصص',
                'label_en' => 'Custom catalog',
                'sort_order' => 1,
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'item_key' => 'reports_center',
                'group_key' => 'analytics',
                'parent_key' => 'catalog_management',
                'label_ar' => null,
                'label_en' => null,
                'sort_order' => 2,
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        app()->setLocale('en');
        $analytics = collect(app(AdminNavigation::class)->groupsFor($user))->firstWhere('key', 'analytics');

        $this->assertNotNull($analytics);
        $catalog = collect($analytics['children'])->firstWhere('key', 'catalog_management');
        $this->assertNotNull($catalog);
        $this->assertSame('Custom catalog', $catalog['label_text']);
        $this->assertTrue(collect($catalog['children'])->contains('key', 'reports_center'));
    }

    public function test_legacy_deep_sidebar_layout_is_flattened_without_losing_or_duplicating_authorized_pages(): void
    {
        $user = $this->globalUser('B2B_ADMIN', 'sidebar-three-levels@example.test');
        $known = array_keys(app(AdminSidebarManager::class)->registry()['items']);
        $source = collect(app(AdminNavigation::class)->groupsFor($user))->first(
            fn (array $group): bool => count(array_intersect(
                array_column($group['children'], 'key'),
                $known,
            )) >= 4 && $group['key'] !== 'administration',
        );

        $this->assertNotNull($source, 'A group with four authorized sidebar pages is required.');
        $keys = array_slice(array_values(array_intersect(array_column($source['children'], 'key'), $known)), 0, 4);
        $rows = [];
        foreach ($keys as $index => $key) {
            $rows[] = [
                'item_key' => $key,
                'group_key' => $source['key'],
                'parent_key' => $index === 0 ? null : $keys[$index - 1],
                'label_ar' => null,
                'label_en' => null,
                'sort_order' => $index + 1,
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('admin_sidebar_items')->insert($rows);

        $rendered = collect(app(AdminNavigation::class)->groupsFor($user))->firstWhere('key', $source['key']);
        $this->assertNotNull($rendered);
        $root = collect($rendered['children'])->firstWhere('key', $keys[0]);
        $this->assertNotNull($root);
        $this->assertSame(array_slice($keys, 1), array_column($root['children'], 'key'));
        foreach ($root['children'] as $child) {
            $this->assertSame([], $child['children']);
        }

        $flatKeys = array_column($this->flatten($rendered['children']), 'key');
        foreach ($keys as $key) {
            $this->assertSame(1, count(array_filter($flatKeys, static fn (string $item): bool => $item === $key)));
        }

        // Malformed historic cycles cannot silently hide a previously authorized link.
        DB::table('admin_sidebar_items')->where('item_key', $keys[0])->update(['parent_key' => $keys[3]]);
        $cyclic = collect(app(AdminNavigation::class)->groupsFor($user))->firstWhere('key', $source['key']);
        $this->assertNotNull($cyclic);
        $cycleKeys = array_column($this->flatten($cyclic['children']), 'key');
        foreach ($keys as $key) {
            $this->assertSame(1, count(array_filter($cycleKeys, static fn (string $item): bool => $item === $key)));
        }

        // Hiding a parent by role never exposes it via its descendants and never
        // drops the independently authorized descendants.
        DB::table('admin_sidebar_items')->where('item_key', $keys[0])->update(['parent_key' => null]);
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->id,
            'item_key' => $keys[1],
            'is_visible' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $filtered = collect(app(AdminNavigation::class)->groupsFor($user))->firstWhere('key', $source['key']);
        $this->assertNotNull($filtered);
        $filteredKeys = array_column($this->flatten($filtered['children']), 'key');
        $this->assertNotContains($keys[1], $filteredKeys);
        foreach ([$keys[0], $keys[2], $keys[3]] as $key) {
            $this->assertSame(1, count(array_filter($filteredKeys, static fn (string $item): bool => $item === $key)));
        }
        foreach ($filtered['children'] as $rootItem) {
            foreach ($rootItem['children'] as $child) {
                $this->assertSame([], $child['children']);
            }
        }
    }

    public function test_sidebar_layout_rejects_fourth_level_and_accepts_third_level(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN', 'sidebar-depth-admin@example.test');
        $layout = app(AdminSidebarManager::class)->layoutData();

        $groups = [];
        foreach ($layout['groups'] as $key => $group) {
            $groups[$key] = [
                'label_ar' => $group['label_ar'],
                'label_en' => $group['label_en'],
                'icon' => $group['icon'],
                'sort_order' => $group['sort_order'],
                'is_enabled' => $group['is_enabled'] ? 1 : 0,
            ];
        }

        $items = [];
        foreach ($layout['items'] as $key => $item) {
            $items[$key] = [
                'group_key' => $item['group_key'],
                'parent_key' => $item['parent_key'],
                'label_ar' => $item['label_ar'],
                'label_en' => $item['label_en'],
                'sort_order' => $item['sort_order'],
                'is_enabled' => $item['is_enabled'] ? 1 : 0,
            ];
        }

        // Group -> catalog_management -> commercial_sales_control is valid;
        // adding lookup_management beneath it would create a forbidden fourth level.
        $items['commercial_sales_control']['parent_key'] = 'catalog_management';
        $items['lookup_management']['parent_key'] = 'commercial_sales_control';

        app()->setLocale('en');
        $before = DB::table('admin_sidebar_items')->count();
        $this->actingAs($admin)->put(route('admin.sidebar-layout.update', [], false), [
            'groups' => $groups,
            'items' => $items,
        ])->assertSessionHasErrors([
            'items.lookup_management.parent_key' => __('sidebar_admin.max_nesting'),
        ]);
        $this->assertSame($before, DB::table('admin_sidebar_items')->count());

        $items['lookup_management']['parent_key'] = null;
        $this->actingAs($admin)->put(route('admin.sidebar-layout.update', [], false), [
            'groups' => $groups,
            'items' => $items,
        ])->assertRedirect(route('admin.sidebar-layout.index', [], false))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('admin_sidebar_items', [
            'item_key' => 'commercial_sales_control',
            'parent_key' => 'catalog_management',
        ]);
        $this->assertNotSame('sidebar_admin.max_nesting', __('sidebar_admin.max_nesting', [], 'ar'));
        $this->assertNotSame('sidebar_admin.max_nesting', __('sidebar_admin.max_nesting', [], 'en'));
    }

    public function test_sidebar_layout_cannot_be_read_or_changed_without_roles_manage_permission(): void
    {
        $user = User::query()->create([
            'name' => 'Restricted Viewer',
            'email' => 'sidebar-no-rbac@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.sidebar-layout.index', [], false))
            ->assertForbidden();

        $this->actingAs($user)
            ->put(route('admin.sidebar-layout.update', [], false), [])
            ->assertForbidden();
    }

    public function test_only_active_sidebar_business_group_receives_direction_aware_highlight(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN', 'sidebar-active-group@example.test');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($admin)
                ->get(route('admin.sidebar-layout.index', [], false))
                ->assertOk();

            $html = $response->getContent();
            $this->assertIsString($html);
            $this->assertStringContainsString('<html lang="'.$locale.'" dir="'.$direction.'">', $html);

            // The current Administration group is visually distinct from any
            // group the user opened manually; no other group gains this marker.
            $this->assertMatchesRegularExpression(
                '/<details class="foodex-nav-group is-current-group" data-group="administration"[^>]*\\sopen\\s*>/',
                $html,
            );
            $this->assertMatchesRegularExpression(
                '/<details class="foodex-nav-group " data-group="overview"/',
                $html,
            );
            preg_match_all('/<details class="foodex-nav-group is-current-group"/', $html, $activeMatches);
            $this->assertCount(1, $activeMatches[0]);

            // Theme tokens and logical start border are shared by RTL/LTR and
            // remain visible in the compact sidebar without changing navigation.
            $this->assertStringContainsString(
                'border-inline-start:3px solid var(--foodex-green)',
                $html,
            );
            $this->assertStringContainsString('background:var(--foodex-green-soft)', $html);
            $this->assertStringContainsString('aria-current="page"', $html);
        }
    }

    public function test_super_admin_can_open_both_sidebar_management_pages(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN', 'sidebar-owner@example.test');

        $this->actingAs($admin)
            ->get(route('admin.sidebar-access.roles', [], false))
            ->assertOk()
            ->assertSee('شجرة الصفحات والصلاحيات');

        $this->actingAs($admin)
            ->get(route('admin.sidebar-layout.index', [], false))
            ->assertOk()
            ->assertSee('تنظيم السايدبار');
    }

    /** @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function flatten(array $items): array
    {
        $flat = [];
        foreach ($items as $item) {
            $flat[] = $item;
            if (($item['children'] ?? []) !== []) {
                array_push($flat, ...$this->flatten($item['children']));
            }
        }

        return $flat;
    }

    private function globalUser(string $roleCode, string $email): User
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
