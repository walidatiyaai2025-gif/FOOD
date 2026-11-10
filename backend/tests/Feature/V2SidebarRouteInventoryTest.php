<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\V2SidebarMetadata;
use App\Support\V2SidebarRouteInventory;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 T037R01 — inventory reflects authorized route/action decisions rather
 * than treating a registered Sidebar item as an access grant.
 */
final class V2SidebarRouteInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_inventory_documents_existing_authorized_routes_actions_and_permissions_in_both_locales(): void
    {
        $actor = $this->globalActor('B2B_ADMIN');
        $roleGrantsBefore = DB::table('permission_role')->count();
        $overridesBefore = DB::table('user_permission_overrides')->count();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $rows = app(V2SidebarRouteInventory::class)->forUser($actor);
            $this->assertNotEmpty($rows);
            $this->assertCount(count(array_unique(array_column($rows, 'item_key'))), $rows);

            $catalog = collect($rows)->firstWhere('item_key', 'catalog_management');
            $this->assertNotNull($catalog);
            $this->assertSame('admin.catalog.index', $catalog['route']);
            $this->assertContains('GET', $catalog['http_methods']);
            $this->assertNotSame('', $catalog['controller_action']);
            $this->assertSame('catalog.view', $catalog['permission']);
            $this->assertSame(['catalog.view'], $catalog['permissions']);
            $this->assertSame('global', $catalog['scope']);

            foreach ($rows as $row) {
                $this->assertTrue(Route::has($row['route']), $row['item_key']);
                $this->assertNotSame('', $row['route_uri']);
                $this->assertNotEmpty($row['http_methods']);
                $this->assertNotSame('', $row['controller_action']);
                $this->assertContains($row['scope'], ['global', 'store', 'both']);
                $this->assertContains($row['permission_mode'], ['all', 'any']);
                $this->assertContains($row['readiness'], ['route_guarded', 'diagnostic_only']);
            }

            $this->assertEqualsCanonicalizing(
                $this->metadataKeys(app(V2SidebarMetadata::class)->forUser($actor)),
                array_column($rows, 'item_key'),
            );
            $this->actingAs($actor)->get(route('admin.catalog.index', [], false))->assertOk();
        }

        $this->assertSame($roleGrantsBefore, DB::table('permission_role')->count());
        $this->assertSame($overridesBefore, DB::table('user_permission_overrides')->count());
    }

    public function test_retail_inventory_only_lists_its_own_channel_and_foreign_store_direct_http_fails_closed(): void
    {
        $own = $this->retailStore('V2-P037-OWN');
        $foreign = $this->retailStore('V2-P037-FOREIGN');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->getKey(),
            'role_id' => $role->getKey(),
            'store_id' => $own,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $rows = app(V2SidebarRouteInventory::class)->forUser($actor);
            $retail = collect($rows)->firstWhere('item_key', 'b2c_inventory');
            $this->assertNotNull($retail);
            $this->assertSame('admin.b2c.module', $retail['route']);
            $this->assertSame('b2c', $retail['channel']);
            $this->assertSame('store', $retail['scope']);
            $this->assertSame('inventory.view', $retail['permission']);
            $this->assertNotContains('b2b_inventory', array_column($rows, 'item_key'));

            $this->actingAs($actor)->get(route('admin.b2c.module', [
                'module' => 'inventory',
                'store_id' => $own,
            ], false))->assertOk()->assertSee('dir="'.$direction.'"', false);

            $forbidden = $this->actingAs($actor)->get(route('admin.b2c.module', [
                'module' => 'inventory',
                'store_id' => $foreign,
            ], false));
            $this->assertContains($forbidden->getStatusCode(), [403, 404]);

            $this->actingAs($actor)->get(route('admin.b2b.module', [
                'module' => 'inventory',
            ], false))->assertForbidden();
        }
    }

    public function test_roleless_and_explicit_denied_accounts_cannot_invent_catalog_inventory_or_http_access(): void
    {
        $roleless = User::factory()->create(['is_active' => true]);
        $this->assertNotContains(
            'catalog_management',
            array_column(app(V2SidebarRouteInventory::class)->forUser($roleless), 'item_key'),
        );
        $this->actingAs($roleless)->get(route('admin.catalog.index', [], false))->assertForbidden();

        $actor = $this->globalActor('B2B_ADMIN');
        $this->assertContains(
            'catalog_management',
            array_column(app(V2SidebarRouteInventory::class)->forUser($actor), 'item_key'),
        );

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->getKey(),
            'permission_id' => Permission::query()->where('code', 'catalog.view')->firstOrFail()->getKey(),
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $actor = $actor->fresh();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->assertNotContains(
                'catalog_management',
                array_column(app(V2SidebarRouteInventory::class)->forUser($actor), 'item_key'),
            );
            $this->actingAs($actor)->get(route('admin.catalog.index', [], false))->assertForbidden();
        }

        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $actor->getKey(),
            'scope_key' => 'global',
            'effect' => 'deny',
        ]);
    }

    /** @param list<array<string, mixed>> $groups
     * @return list<string>
     */
    private function metadataKeys(array $groups): array
    {
        $keys = [];

        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $keys[] = $item['item_key'];
                foreach ((array) ($item['children'] ?? []) as $child) {
                    $keys[] = $child['item_key'];
                }
            }
        }

        return $keys;
    }

    private function globalActor(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user->fresh();
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
