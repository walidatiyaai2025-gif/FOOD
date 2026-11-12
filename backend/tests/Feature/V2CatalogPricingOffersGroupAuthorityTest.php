<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P218 / #2548: Catalog, Pricing & Offers is a presentation group of real,
 * independently authorized routes, never an alternative permission grant.
 */
final class V2CatalogPricingOffersGroupAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_p218_reuses_single_authorized_catalog_group_with_exact_ar_en_title_and_registered_children(): void
    {
        $actor = $this->globalActor('B2B_ADMIN', 'v2-p218-wholesale@example.test');

        $this->assertSame('الكتالوج والتسعير والعروض', __('admin.nav_groups.catalog', [], 'ar'));
        $this->assertSame('Catalog, Pricing & Offers', __('admin.nav_groups.catalog', [], 'en'));

        $expectedKeys = null;
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $groups = app(AdminNavigation::class)->groupsFor($actor->fresh());
            $this->assertCount(1, collect($groups)->where('key', 'catalog'));
            $catalog = collect($groups)->firstWhere('key', 'catalog');
            $this->assertIsArray($catalog);
            $this->assertSame('admin.nav_groups.catalog', $catalog['label']);
            $keys = array_column($catalog['children'], 'key');
            $this->assertNotEmpty($keys);
            $this->assertCount(count(array_unique($keys)), $keys);
            $this->assertContains('catalog_management', $keys);
            $this->assertContains('commercial_sales_control', $keys);
            $this->assertContains('b2b_products', $keys);
            $this->assertNotContains('b2c_products', $keys);

            foreach ($catalog['children'] as $child) {
                $this->assertTrue(Route::has($child['route']), $child['key'].' has no registered route');
            }

            if ($expectedKeys !== null) {
                $this->assertSame($expectedKeys, $keys, 'Changing locale must not change authorization/order.');
            }
            $expectedKeys = $keys;

            $this->actingAs($actor->fresh())
                ->get(route('admin.catalog.index', [], false))
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'">', false)
                ->assertSee(__('admin.nav_groups.catalog', [], $locale));
        }
    }

    public function test_retail_catalog_navigation_is_store_scoped_and_cannot_open_wholesale_products(): void
    {
        $own = $this->retailStore('P218-OWN');
        $foreign = $this->retailStore('P218-FOREIGN');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $own,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $group = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))->firstWhere('key', 'catalog');
        $this->assertIsArray($group);
        $keys = collect($group['children'])->pluck('key')->all();
        $this->assertContains('b2c_products', $keys);
        $this->assertNotContains('b2b_products', $keys);
        $this->assertNotContains('commercial_sales_control', $keys);
        $product = collect($group['children'])->firstWhere('key', 'b2c_products');
        $this->assertSame((string) $own, $product['params']['store_id']);

        $this->actingAs($actor->fresh())
            ->get(route($product['route'], $product['params'], false))
            ->assertOk();
        $this->actingAs($actor->fresh())
            ->get(route('admin.b2c.module', ['module' => 'products', 'store_id' => $foreign], false))
            ->assertNotFound();
        $this->actingAs($actor->fresh())
            ->get(route('admin.b2b.module', ['module' => 'products'], false))
            ->assertForbidden();
    }

    public function test_catalog_global_deny_removes_restricted_links_and_direct_http_remains_forbidden(): void
    {
        $actor = $this->globalActor('B2B_ADMIN', 'v2-p218-deny@example.test');
        $this->assertTrue($actor->hasPermission('catalog.view'));
        $permission = Permission::query()->where('code', 'catalog.view')->firstOrFail();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $keys = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')
            ->all();

        $this->assertNotContains('catalog_management', $keys);
        $this->assertNotContains('commercial_sales_control', $keys);
        $this->assertNotContains('b2b_products', $keys);
        $this->actingAs($actor->fresh())->get(route('admin.catalog.index', [], false))->assertForbidden();
    }

    public function test_roleless_and_inactive_accounts_cannot_obtain_group_or_catalog_route(): void
    {
        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->globalActor('B2B_ADMIN', 'v2-p218-inactive@example.test');
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive] as $actor) {
            $this->assertNull(collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))->firstWhere('key', 'catalog'));
            $this->actingAs($actor->fresh())->get(route('admin.catalog.index', [], false))->assertForbidden();
        }
    }

    private function globalActor(string $roleCode, string $email): User
    {
        $actor = User::factory()->create([
            'email' => $email,
            'locale' => 'en',
            'is_active' => true,
        ]);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
