<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminSidebarManager;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P375: Sidebar visibility/layout is not an RBAC, module or store grant.
 */
final class V2SidebarNoRbacEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_inactive_actor_has_no_navigation_even_when_layout_remains_enabled(): void
    {
        $user = $this->globalUser('B2B_ADMIN', 'v2-p375-inactive@example.test');
        $nav = app(AdminNavigation::class);

        $this->assertNotEmpty($nav->groupsFor($user));

        $user->forceFill(['is_active' => false])->save();

        $this->assertSame([], $nav->groupsFor($user->fresh()));
        $this->assertSame([], app(AdminSidebarManager::class)->apply($user->fresh(), [
            [
                'key' => 'catalog',
                'label' => 'admin.nav_groups.catalog',
                'icon' => 'catalog',
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
        ]));
    }

    public function test_forged_visible_role_override_cannot_grant_role_management_in_arabic_or_english(): void
    {
        $user = $this->globalUser('B2B_ADMIN', 'v2-p375-forged-role@example.test');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $this->assertFalse($user->hasPermission('roles.manage'));

        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->id,
            'item_key' => 'sidebar_role_access',
            'is_visible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // A forged saved layout also cannot move a forbidden page into an allowed group.
        DB::table('admin_sidebar_items')->insert([
            'item_key' => 'sidebar_role_access',
            'group_key' => 'catalog',
            'parent_key' => null,
            'label_ar' => null,
            'label_en' => null,
            'sort_order' => 1,
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $matrix = app(AdminSidebarManager::class)->roleMatrix($role);
        $allItems = collect($matrix['groups'])->flatMap(
            static fn (array $group): array => $group['items'],
        );
        $item = $allItems->firstWhere('key', 'sidebar_role_access');
        $this->assertNotNull($item);
        $this->assertFalse($item['base_allowed']);
        $this->assertFalse($item['visible_for_role']);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();

            $keys = $this->navigationKeys(app(AdminNavigation::class)->groupsFor($user));
            $this->assertNotContains('sidebar_role_access', $keys);
            $this->assertContains('catalog_management', $keys);

            $this->actingAs($user)
                ->get(route('admin.sidebar-access.roles', [], false))
                ->assertForbidden();
            $this->actingAs($user)
                ->get(route('admin.catalog.index', [], false))
                ->assertOk()->assertSee('dir="'.$direction.'"', false);
        }

        $this->assertFalse($user->fresh()->hasPermission('roles.manage'));
    }

    public function test_forged_wholesale_visibility_cannot_cross_retail_store_and_channel_boundaries(): void
    {
        $storeA = $this->retailStore('V2-P375-A');
        $storeB = $this->retailStore('V2-P375-B');
        $role = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeA,
            'role_id' => $role->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->getKey(),
            'item_key' => 'b2b_products',
            'is_visible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();

            $keys = $this->navigationKeys(app(AdminNavigation::class)->groupsFor($user));
            $this->assertNotContains('b2b_products', $keys);
            $this->assertContains('b2c_inventory', $keys);

            $this->actingAs($user)
                ->get(route('admin.b2b.module', ['module' => 'products']))
                ->assertForbidden();

            $this->actingAs($user)
                ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $storeA]))
                ->assertOk();

            $foreign = $this->actingAs($user)
                ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $storeB]));
            $this->assertContains($foreign->getStatusCode(), [403, 404]);
        }

        $this->assertFalse($user->fresh()->hasPermission('catalog.view'));
    }

    /** @return list<string> */
    private function navigationKeys(array $groups): array
    {
        $keys = [];
        foreach ($groups as $group) {
            foreach ($group['children'] as $item) {
                $keys[] = $item['key'];
                foreach ((array) ($item['children'] ?? []) as $child) {
                    $keys[] = $child['key'];
                }
            }
        }

        return $keys;
    }

    private function globalUser(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => 'V2 P375 '.$roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
