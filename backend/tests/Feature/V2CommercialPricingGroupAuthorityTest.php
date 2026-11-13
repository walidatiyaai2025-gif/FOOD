<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AdminSidebarManager;
use App\Services\B2bPricingMode;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 #2555 / P225 (native P214): Commercial Pricing is the authorized
 * Catalog journey, not a second sidebar group or independent RBAC gate.
 */
final class V2CommercialPricingGroupAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_commercial_pricing_reuses_one_catalog_group_and_opens_real_wholesale_pages_in_both_locales(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $this->setPricingReady($storeId, true);
        $user = $this->wholesaleActor();

        $registry = app(AdminSidebarManager::class)->registry();
        $this->assertSame('catalog', $registry['items']['commercial_sales_control']['group']);
        $this->assertSame('b2b', $registry['items']['commercial_sales_control']['channel']);
        $this->assertSame(['catalog.view'], $registry['items']['commercial_sales_control']['permissions']);
        $this->assertArrayNotHasKey('commercial_pricing', $registry['groups']);
        $this->assertTrue(Route::has('admin.commercial.sales-control'));
        $this->assertTrue(Route::has('admin.b2b.pricing-approvals'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();
            $actor = $user->fresh();
            $groups = app(AdminNavigation::class)->groupsFor($actor);
            $this->assertCount(1, collect($groups)->where('key', 'catalog'));
            $this->assertNull(collect($groups)->firstWhere('key', 'commercial_pricing'));

            $catalog = collect($groups)->firstWhere('key', 'catalog');
            $this->assertSame('admin.nav_groups.catalog', $catalog['label']);
            $commercial = collect($catalog['children'])->where('key', 'commercial_sales_control');
            $this->assertCount(1, $commercial);
            $entry = $commercial->first();
            $this->assertSame('admin.commercial.sales-control', $entry['route']);
            $this->assertSame('catalog.view', $entry['permission']);
            $this->assertSame([], $entry['params']);

            $this->actingAs($actor)
                ->get(route($entry['route'], ['store_id' => $storeId], false))
                ->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-nav-group="catalog"', false)
                ->assertSee('data-nav-item="commercial_sales_control"', false)
                ->assertSee(__('admin.commercial_sales_control', [], $locale))
                ->assertSee('data-v2-pricing-lists-link', false)
                ->assertViewHas('pricingListsUrl', route('admin.b2b.pricing-approvals'));

            $this->actingAs($actor)
                ->get(route('admin.b2b.pricing-approvals', [], false))
                ->assertOk();
        }
    }

    public function test_catalog_visibility_never_bypasses_independent_price_list_grant_or_readiness(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $this->setPricingReady($storeId, true);
        $user = $this->wholesaleActor();

        $this->deny($user, 'b2b.pricing.view');
        $actor = $user->fresh();
        $catalog = collect(app(AdminNavigation::class)->groupsFor($actor))
            ->firstWhere('key', 'catalog');
        $this->assertIsArray($catalog);
        $this->assertNotNull(collect($catalog['children'])->firstWhere('key', 'commercial_sales_control'));

        $this->actingAs($actor)
            ->get(route('admin.commercial.sales-control', ['store_id' => $storeId], false))
            ->assertOk()
            ->assertViewHas('pricingListsUrl', null)
            ->assertDontSee('data-v2-pricing-lists-link', false);

        $this->actingAs($actor)
            ->get(route('admin.b2b.pricing-approvals', [], false))
            ->assertForbidden();

        $this->deny($user, 'catalog.view');
        $denied = $user->fresh();
        $keys = collect(app(AdminNavigation::class)->groupsFor($denied))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')->all();
        $this->assertNotContains('commercial_sales_control', $keys);
        $this->actingAs($denied)
            ->get(route('admin.commercial.sales-control', ['store_id' => $storeId], false))
            ->assertForbidden();
    }

    public function test_disabled_price_list_mode_keeps_safe_commercial_entry_but_not_unready_child(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $user = $this->wholesaleActor();
        $this->setPricingReady($storeId, false);

        $catalog = collect(app(AdminNavigation::class)->groupsFor($user))
            ->firstWhere('key', 'catalog');
        $this->assertIsArray($catalog);
        $this->assertNotNull(collect($catalog['children'])->firstWhere('key', 'commercial_sales_control'));

        $this->actingAs($user)
            ->get(route('admin.commercial.sales-control', ['store_id' => $storeId], false))
            ->assertOk()
            ->assertViewHas('pricingListsUrl', null)
            ->assertDontSee('data-v2-pricing-lists-link', false);

        $this->actingAs($user)
            ->get(route('admin.b2b.pricing-approvals', [], false))
            ->assertNotFound();
    }

    public function test_retail_roleless_and_inactive_accounts_cannot_inherit_wholesale_commercial_group(): void
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => 'V2-P225-RETAIL',
            'name' => 'V2 P225 Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $retail = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $keys = collect(app(AdminNavigation::class)->groupsFor($retail->fresh()))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')->all();
        $this->assertNotContains('commercial_sales_control', $keys);
        $this->actingAs($retail)
            ->get(route('admin.commercial.sales-control', ['store_id' => $storeId], false))
            ->assertNotFound();

        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->assertSame([], app(AdminNavigation::class)->groupsFor($roleless));
        $this->actingAs($roleless)
            ->get(route('admin.commercial.sales-control', [], false))
            ->assertForbidden();

        $inactive = $this->wholesaleActor();
        $inactive->forceFill(['is_active' => false])->save();
        $this->assertSame([], app(AdminNavigation::class)->groupsFor($inactive->fresh()));
        $this->actingAs($inactive->fresh())
            ->get(route('admin.commercial.sales-control', [], false))
            ->assertForbidden();
    }

    private function wholesaleActor(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        return $actor;
    }

    private function setPricingReady(int $storeId, bool $enabled): void
    {
        DB::table('settings')->updateOrInsert([
            'store_id' => $storeId,
            'key' => B2bPricingMode::SETTING_KEY,
        ], [
            'value' => json_encode($enabled, JSON_THROW_ON_ERROR),
            'is_secret' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function deny(User $user, string $permission): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->id,
            'permission_id' => DB::table('permissions')->where('code', $permission)->value('id'),
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->forceFill(['has_permission_overrides' => true])->save();
    }
}
