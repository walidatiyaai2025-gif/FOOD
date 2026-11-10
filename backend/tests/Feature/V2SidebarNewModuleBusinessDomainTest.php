<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SidebarNewModuleBusinessDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_newly_registered_module_uses_its_declared_business_group_without_new_navigation_access(): void
    {
        $user = $this->globalUser('B2B_ADMIN');
        $navigation = app(AdminNavigation::class);
        $originalGroups = $navigation->groupsFor($user);
        $this->assertSame('catalog', $this->groupOf($originalGroups, 'catalog_management'));

        // Simulate registering module metadata for an already-authorized route.
        // Navigation and permission declarations remain server-authoritative.
        config()->set('admin_sidebar.items.catalog_management.module', 'future_catalog_module');
        config()->set('admin_sidebar.items.catalog_management.group', 'analytics');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();
            $groups = $navigation->groupsFor($user->fresh());
            $this->assertSame('analytics', $this->groupOf($groups, 'catalog_management'));
            $this->assertCount(1, $this->itemGroups($groups, 'catalog_management'));
            $this->assertSame(
                $this->flattenItemKeys($originalGroups),
                $this->flattenItemKeys($groups),
            );

            $html = (string) $this->actingAs($user->fresh())
                ->get(route('admin.b2b.dashboard'))
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'">', false)
                ->getContent();

            $this->assertStringContainsString('data-nav-group="analytics"', $html);
            $this->assertStringContainsString(__('admin.nav_groups.analytics'), $html);
            $this->actingAs($user->fresh())->get(route('admin.catalog.index'))->assertOk();
        }
    }

    public function test_saved_administrator_placement_wins_and_disabled_target_group_hides_module(): void
    {
        $user = $this->globalUser('B2B_ADMIN');
        config()->set('admin_sidebar.items.catalog_management.module', 'future_catalog_module');
        config()->set('admin_sidebar.items.catalog_management.group', 'analytics');

        DB::table('admin_sidebar_items')->insert([
            'item_key' => 'catalog_management',
            'group_key' => 'marketing',
            'parent_key' => null,
            'sort_order' => 15,
            'is_enabled' => true,
            'label_ar' => null,
            'label_en' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $navigation = app(AdminNavigation::class);
        $this->assertSame('marketing', $this->groupOf($navigation->groupsFor($user), 'catalog_management'));

        DB::table('admin_sidebar_groups')->insert([
            'group_key' => 'marketing',
            'label_ar' => null,
            'label_en' => null,
            'icon' => '•',
            'sort_order' => 75,
            'is_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull($this->groupOf($navigation->groupsFor($user), 'catalog_management'));
        $this->assertTrue($user->fresh()->hasPermission('catalog.view'));
        $this->actingAs($user)->get(route('admin.catalog.index'))->assertOk();
    }

    public function test_invalid_module_group_cannot_create_unregistered_sidebar_sections(): void
    {
        $user = $this->globalUser('B2B_ADMIN');
        config()->set('admin_sidebar.items.catalog_management.module', 'future_catalog_module');
        config()->set('admin_sidebar.items.catalog_management.group', 'unknown_external_domain');

        $groups = app(AdminNavigation::class)->groupsFor($user);
        $this->assertSame('catalog', $this->groupOf($groups, 'catalog_management'));
        $this->assertNotContains('unknown_external_domain', array_column($groups, 'key'));

        // A normal page without a module tag is not silently reorganized.
        config()->set('admin_sidebar.items.catalog_management.module', null);
        config()->set('admin_sidebar.items.catalog_management.group', 'analytics');
        $this->assertSame('catalog', $this->groupOf(app(AdminNavigation::class)->groupsFor($user), 'catalog_management'));
    }

    public function test_module_grouping_does_not_grant_cross_channel_or_foreign_store_http_access(): void
    {
        config()->set('admin_sidebar.items.catalog_management.module', 'future_catalog_module');
        config()->set('admin_sidebar.items.catalog_management.group', 'analytics');

        $ownedStoreId = $this->retailStore('V2-P361-OWN');
        $foreignStoreId = $this->retailStore('V2-P361-FOREIGN');
        $retail = User::factory()->create(['is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->id,
            'store_id' => $ownedStoreId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $retail->forceFill(['locale' => $locale])->save();

            $this->actingAs($retail->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $ownedStoreId]))
                ->assertOk();
            $this->actingAs($retail->fresh())->get(route('admin.b2b.dashboard'))->assertForbidden();
            $this->actingAs($retail->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $foreignStoreId]))
                ->assertNotFound();
        }

        $roleless = User::factory()->create(['is_active' => true]);
        $this->assertSame([], app(AdminNavigation::class)->groupsFor($roleless));
        $this->actingAs($roleless)->get(route('admin.b2b.dashboard'))->assertForbidden();
    }

    /** @return list<string> */
    private function itemGroups(array $groups, string $itemKey): array
    {
        $found = [];
        foreach ($groups as $group) {
            foreach ((array) ($group['children'] ?? []) as $child) {
                if (($child['key'] ?? '') === $itemKey) {
                    $found[] = $group['key'];
                }
            }
        }

        return $found;
    }

    private function groupOf(array $groups, string $itemKey): ?string
    {
        return $this->itemGroups($groups, $itemKey)[0] ?? null;
    }

    /** @return list<string> */
    private function flattenItemKeys(array $groups): array
    {
        $keys = [];
        foreach ($groups as $group) {
            foreach ((array) ($group['children'] ?? []) as $child) {
                $keys[] = $child['key'];
                foreach ((array) ($child['children'] ?? []) as $nested) {
                    $keys[] = $nested['key'];
                }
            }
        }

        sort($keys);

        return $keys;
    }

    private function globalUser(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
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
