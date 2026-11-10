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

class V2AuthorizedSidebarGroupProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_group_disappears_when_its_only_authorized_link_is_hidden_without_changing_rbac(): void
    {
        $user = $this->userWithRole('B2B_ADMIN', 'p010-group-visibility@example.test');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $manager = app(AdminSidebarManager::class);

        $this->assertTrue($user->hasPermission('catalog.view'));
        $this->assertSame(['catalog'], array_column($manager->apply($user, $this->catalogFixture()), 'key'));

        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->id,
            'item_key' => 'catalog_management',
            'is_visible' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([], $manager->apply($user, $this->catalogFixture()));
        $this->assertTrue($user->fresh()->hasPermission('catalog.view'));
    }

    public function test_enabled_sidebar_layout_does_not_grant_a_catalog_link_or_its_http_route(): void
    {
        $viewer = User::query()->create([
            'name' => 'P010 restricted viewer',
            'email' => 'p010-no-permission@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $groups = app(AdminNavigation::class)->groupsFor($viewer);
        $keys = array_column($this->flatten($groups), 'key');

        $this->assertNotContains('catalog_management', $keys);
        $this->actingAs($viewer)
            ->get(route('admin.catalog.index', [], false))
            ->assertForbidden();
    }

    public function test_inactive_accounts_and_missing_routes_cannot_generate_empty_sidebar_groups(): void
    {
        $user = $this->userWithRole('B2B_ADMIN', 'p010-invalid-route@example.test');
        $manager = app(AdminSidebarManager::class);
        $groups = $this->catalogFixture();

        $groups[0]['children'][0]['route'] = 'admin.p010.nonexistent';

        $this->assertFalse(Route::has('admin.p010.nonexistent'));
        $this->assertSame([], $manager->apply($user, $groups));

        $user->forceFill(['is_active' => false])->save();

        $this->assertSame([], $manager->apply($user, $this->catalogFixture()));
        $this->assertSame([], app(AdminNavigation::class)->groupsFor($user->fresh()));
    }

    public function test_arabic_and_english_group_labels_share_exact_authorized_navigation(): void
    {
        $user = $this->userWithRole('B2B_ADMIN', 'p010-bilingual@example.test');

        app()->setLocale('ar');
        $arabic = app(AdminNavigation::class)->groupsFor($user);

        app()->setLocale('en');
        $english = app(AdminNavigation::class)->groupsFor($user);

        $this->assertSame(array_column($arabic, 'key'), array_column($english, 'key'));
        $this->assertSame(
            array_column($this->flatten($arabic), 'key'),
            array_column($this->flatten($english), 'key'),
        );

        $catalog = collect($english)->firstWhere('key', 'catalog');
        $this->assertNotNull($catalog);
        $this->assertNotEmpty($catalog['children']);
        $this->assertSame('admin.nav_groups.catalog', $catalog['label']);
        $this->assertNotSame(
            __('admin.nav_groups.catalog', [], 'ar'),
            __('admin.nav_groups.catalog', [], 'en'),
        );

        foreach ($english as $group) {
            $this->assertNotEmpty($group['children'], 'An empty authorization group must never reach Blade.');
            foreach ($this->flatten($group['children']) as $item) {
                if (isset($item['route'])) {
                    $this->assertTrue(Route::has($item['route']), 'A visible link must have a real route.');
                }
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function catalogFixture(): array
    {
        return [
            [
                'key' => 'catalog',
                'label' => 'admin.nav_groups.catalog',
                'icon' => '▦',
                'children' => [
                    [
                        'key' => 'catalog_management',
                        'label' => 'admin.catalog_management',
                        'route' => 'admin.catalog.index',
                        'params' => [],
                        'permission' => 'catalog.view',
                    ],
                ],
            ],
        ];
    }

    /** @param array<int, array<string, mixed>> $nodes
     * @return array<int, array<string, mixed>>
     */
    private function flatten(array $nodes): array
    {
        $flat = [];
        foreach ($nodes as $node) {
            $flat[] = $node;
            if (($node['children'] ?? []) !== []) {
                array_push($flat, ...$this->flatten($node['children']));
            }
        }

        return $flat;
    }

    private function userWithRole(string $role, string $email): User
    {
        $user = User::query()->create([
            'name' => $role,
            'email' => $email,
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }
}
