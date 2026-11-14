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
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * T356R5 / #2722: migrate existing B2B Sales Control into Catalog & Pricing
 * without duplicate destinations or widening real HTTP authorization.
 */
final class V2CommercialSalesControlSidebarMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_sales_control_has_one_canonical_catalog_owner_and_existing_route(): void
    {
        $sidebar = app(AdminSidebarManager::class);
        $registry = $sidebar->registry();
        $layout = $sidebar->layoutData();

        $this->assertSame('catalog', $registry['items']['commercial_sales_control']['group']);
        $this->assertSame('catalog', $layout['items']['commercial_sales_control']['group_key']);
        $this->assertSame('admin.commercial_sales_control', $registry['items']['commercial_sales_control']['label']);
        $this->assertSame(['catalog.view'], $registry['items']['commercial_sales_control']['permissions']);
        $this->assertSame('b2b', $registry['items']['commercial_sales_control']['channel']);
        $this->assertTrue(Route::has('admin.commercial.sales-control'));
        $this->assertSame('admin.commercial.sales-control', Route::getRoutes()->getByName('admin.commercial.sales-control')?->getName());

        $this->assertSame('الكتالوج والتسعير والعروض', __('admin.nav_groups.catalog', [], 'ar'));
        $this->assertSame('Catalog, Pricing & Offers', __('admin.nav_groups.catalog', [], 'en'));
        $this->assertSame('التحكم التجاري والتسعير', __('admin.commercial_sales_control', [], 'ar'));
        $this->assertSame('Commercial & Pricing Control', __('admin.commercial_sales_control', [], 'en'));
    }

    public function test_wholesale_catalog_link_is_unique_bilingual_and_opens_real_http_workspace(): void
    {
        $storeId = $this->store('B2B', 'V2-2722-WHOLESALE');
        $actor = $this->globalUser('B2B_ADMIN');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $matches = [];
            foreach (app(AdminNavigation::class)->groupsFor($actor->fresh()) as $group) {
                foreach ($group['children'] as $item) {
                    if ($item['key'] === 'commercial_sales_control') {
                        $matches[] = ['group' => $group['key'], 'item' => $item];
                    }
                }
            }

            $this->assertCount(1, $matches, $locale.' single authorized destination');
            $this->assertSame('catalog', $matches[0]['group']);
            $this->assertSame('admin.commercial.sales-control', $matches[0]['item']['route']);
            $this->assertSame('catalog.view', $matches[0]['item']['permission']);
            $this->assertSame([], $matches[0]['item']['params']);

            $this->actingAs($actor->fresh())
                ->get(route('admin.commercial.sales-control', ['store_id' => $storeId]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-nav-group="catalog"', false)
                ->assertSee('data-nav-item="commercial_sales_control"', false)
                ->assertSee(__('admin.commercial_sales_control', [], $locale));
        }
    }

    public function test_retail_roleless_and_inactive_users_get_no_wholesale_sales_control_grant(): void
    {
        $retailStoreId = $this->store('B2C', 'V2-2722-RETAIL');
        $retail = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->id,
            'store_id' => $retailStoreId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $retail->forceFill(['locale' => $locale])->save();
            $keys = collect(app(AdminNavigation::class)->groupsFor($retail->fresh()))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')
                ->all();

            $this->assertNotContains('commercial_sales_control', $keys);
            $this->actingAs($retail->fresh())
                ->get(route('admin.commercial.sales-control', ['store_id' => $retailStoreId]))
                ->assertNotFound();
        }

        $roleless = User::factory()->create(['is_active' => true]);
        $this->assertSame([], app(AdminNavigation::class)->groupsFor($roleless));
        $this->actingAs($roleless)
            ->get(route('admin.commercial.sales-control'))
            ->assertForbidden();

        $inactive = $this->globalUser('B2B_ADMIN');
        $inactive->forceFill(['is_active' => false])->save();
        $this->assertSame([], app(AdminNavigation::class)->groupsFor($inactive->fresh()));
        $this->actingAs($inactive->fresh())
            ->get(route('admin.commercial.sales-control'))
            ->assertForbidden();
    }

    private function globalUser(string $code): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $actor;
    }

    private function store(string $channel, string $code): int
    {
        $attributes = [
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($channel === 'B2C') {
            $attributes['business_profile'] = StoreBusinessProfile::RETAIL_STORE->value;
        }

        return (int) DB::table('stores')->insertGetId($attributes);
    }
}
