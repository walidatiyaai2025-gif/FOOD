<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2RetailStoreAdminPersonaAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_t353r7_retail_admin_discovers_canonical_business_modules_without_wholesale_menu(): void
    {
        $owned = $this->retailStore('V2-PERSONA-OWNED');
        $actor = $this->retailManager([$owned]);

        $navigation = app(AdminNavigation::class);
        $this->assertSame(['b2c'], array_keys($navigation->for($actor)));

        // Saved Sidebar layout can nest a page under its authorized domain root.
        // Inspect the actual rendered tree, not just top-level group children.
        $groups = $navigation->groupsFor($actor);
        $items = collect($this->allVisibleItems($groups));
        $keys = $items->pluck('key')->all();

        foreach ([
            'b2c_dashboard',
            'b2c_storefront',
            'b2c_products',
            'b2c_inventory',
            'b2c_orders',
            'b2c_customers',
            'b2c_promotions',
            'b2c_reports',
        ] as $key) {
            $this->assertContains($key, $keys, 'Required Retail persona destination is not discoverable: '.$key);
        }

        $this->assertSame([], array_values(array_filter(
            $keys,
            fn (string $key): bool => str_starts_with($key, 'b2b_')
                || str_starts_with($key, 'field_ops_')
                || str_starts_with($key, 'erp_')
                || $key === 'retail_store_provisioning',
        )));

        foreach ($items as $item) {
            if (str_starts_with($item['key'], 'b2c_')) {
                $this->assertSame((string) $owned, (string) ($item['params']['store_id'] ?? ''),
                    $item['key'].' must use the assigned Retail store instead of a global context.');
            }
        }
    }

    public function test_t353r7_real_retail_workspace_routes_are_scoped_in_english_and_arabic(): void
    {
        $owned = $this->retailStore('V2-PERSONA-LOCALIZED');
        $actor = $this->retailManager([$owned]);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            foreach (['dashboard', 'products', 'inventory', 'orders', 'customers', 'promotions', 'reports'] as $module) {
                $this->actingAs($actor)
                    ->get(route('admin.b2c.module', ['module' => $module, 'store_id' => $owned]))
                    ->assertOk()
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertViewHas('storeId', $owned)
                    ->assertViewHas('storeIds', [$owned]);
            }
        }
    }

    public function test_t353r7_multi_store_actor_cannot_select_foreign_retail_or_wholesale(): void
    {
        $first = $this->retailStore('V2-PERSONA-FIRST');
        $second = $this->retailStore('V2-PERSONA-SECOND');
        $foreign = $this->retailStore('V2-PERSONA-FOREIGN');
        $actor = $this->retailManager([$first, $second]);

        $this->actingAs($actor)
            ->get(route('admin.b2c.module', ['module' => 'orders', 'store_id' => $second]))
            ->assertOk()
            ->assertViewHas('storeId', $second)
            ->assertViewHas('storeIds', [$second]);

        $this->actingAs($actor)
            ->get(route('admin.b2c.module', ['module' => 'orders', 'store_id' => $foreign]))
            ->assertNotFound();

        $this->actingAs($actor)
            ->get(route('admin.b2b.module', ['module' => 'orders']))
            ->assertForbidden();
    }

    public function test_t353r7_explicit_store_permission_deny_is_respected_by_navigation_and_http(): void
    {
        $denied = $this->retailStore('V2-PERSONA-DENIED');
        $allowed = $this->retailStore('V2-PERSONA-ALLOWED');
        $actor = $this->retailManager([$denied, $allowed]);

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => (int) DB::table('permissions')
                ->where('code', 'inventory.view')->value('id'),
            'scope_key' => 'store:'.$denied,
            'store_id' => $denied,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $navigation = app(AdminNavigation::class);
        $item = collect($this->allVisibleItems($navigation->groupsFor($actor)))
            ->firstWhere('key', 'b2c_inventory');

        $this->assertIsArray($item);
        $this->assertSame((string) $allowed, (string) ($item['params']['store_id'] ?? ''));

        $this->actingAs($actor)
            ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $denied]))
            ->assertForbidden();
        $this->actingAs($actor)
            ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $allowed]))
            ->assertOk()
            ->assertViewHas('storeIds', [$allowed]);
    }

    /**
     * Resolve actual Sidebar placement including saved parent-child layout,
     * without mistaking a nested link for an absent/unauthorized destination.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function allVisibleItems(array $groups): array
    {
        $items = [];
        $visit = function (array $nodes) use (&$visit, &$items): void {
            foreach ($nodes as $node) {
                $items[] = $node;
                $visit((array) ($node['children'] ?? []));
            }
        };

        foreach ($groups as $group) {
            $visit((array) ($group['children'] ?? []));
        }

        return $items;
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param list<int> $storeIds */
    private function retailManager(array $storeIds): User
    {
        $user = User::query()->create([
            'name' => 'V2 Retail Store Admin',
            'email' => 'v2-retail-persona@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        foreach ($storeIds as $storeId) {
            DB::table('user_store_roles')->insert([
                'user_id' => $user->id,
                'store_id' => $storeId,
                'role_id' => $role->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }
}
