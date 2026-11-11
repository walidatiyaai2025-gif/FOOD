<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\B2bPricingMode;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 #2556 / P226: existing B2B price-list workspace is a genuine,
 * independently-authorized and readiness-scoped Catalog journey.
 */
final class V2WholesalePriceListsSidebarHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_price_lists_are_discoverable_from_catalog_and_wholesale_hub_when_enabled_in_both_locales(): void
    {
        $store = app(WholesalePrincipal::class)->storeId();
        [$product, $tier] = $this->productAndTier($store);
        $admin = $this->wholesaleAdmin();
        $this->setTiersEnabled($store, true);
        $this->priceRule($store, $product, $tier, 7.5);

        $this->assertTrue(Route::has('admin.b2b.pricing-approvals'));
        $this->assertTrue(Route::has('admin.commercial.sales-control'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $admin->forceFill(['locale' => $locale])->save();
            $actor = $admin->fresh();
            $catalog = collect(app(AdminNavigation::class)->groupsFor($actor))
                ->firstWhere('key', 'catalog');
            $this->assertIsArray($catalog);
            $sales = collect($catalog['children'])->firstWhere('key', 'commercial_sales_control');
            $this->assertIsArray($sales);
            $this->assertSame('admin.commercial.sales-control', $sales['route']);

            $this->actingAs($actor)
                ->get(route($sales['route'], ['store_id' => $store]))
                ->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('commercial.tabs.price_lists'))
                ->assertSee('data-v2-pricing-lists-link', false)
                ->assertSee('href="'.route('admin.b2b.pricing-approvals').'"', false)
                ->assertViewHas('pricingListsUrl', route('admin.b2b.pricing-approvals'));

            $this->actingAs($actor)
                ->get(route('admin.b2b.module', ['module' => 'dashboard']))
                ->assertOk()
                ->assertSee('data-v2-pricing-lists-hub', false)
                ->assertSee(__('commercial.tabs.price_lists'));

            $this->actingAs($actor)
                ->get(route('admin.b2b.pricing-approvals'))
                ->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('P226 Price List Product')
                ->assertSee('P226 Gold Tier')
                ->assertViewHas('module', 'pricing')
                ->assertViewHas('moduleData', static fn (array $data): bool => collect($data['rows'] ?? [])->contains(static fn (array $row): bool => $row['product'] === 'P226 Price List Product'
                        && $row['tier'] === 'P226 Gold Tier'));
        }
    }

    public function test_real_pricing_form_persists_a_wholesale_price_rule_and_readback(): void
    {
        $store = app(WholesalePrincipal::class)->storeId();
        [$product, $tier] = $this->productAndTier($store);
        $admin = $this->wholesaleAdmin();
        $this->setTiersEnabled($store, true);

        $this->actingAs($admin)
            ->post(route('admin.b2b.pricing.save'), [
                'price_tier_id' => $tier,
                'product_id' => $product,
                'unit_price' => 7.25,
                'minimum_quantity' => 5,
                'is_active' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('b2b_price_rules', [
            'store_id' => $store,
            'product_id' => $product,
            'price_tier_id' => $tier,
            'unit_price' => 7.25,
            'minimum_quantity' => 5,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.b2b.pricing-approvals'))
            ->assertOk()
            ->assertViewHas('moduleData', static fn (array $data): bool => collect($data['rows'] ?? [])->contains(static fn (array $row): bool => $row['product'] === 'P226 Price List Product'
                    && $row['unit_price'] === '7.250 EGP'
                    && $row['minimum_quantity'] === '5.000'));
    }

    public function test_disabled_pricing_tiers_hide_every_discoverable_link_and_block_direct_write(): void
    {
        $store = app(WholesalePrincipal::class)->storeId();
        [$product, $tier] = $this->productAndTier($store);
        $admin = $this->wholesaleAdmin();
        $this->setTiersEnabled($store, false);

        $this->actingAs($admin)
            ->get(route('admin.commercial.sales-control', ['store_id' => $store]))
            ->assertOk()
            ->assertViewHas('pricingListsUrl', null)
            ->assertDontSee('data-v2-pricing-lists-link', false);

        $this->actingAs($admin)
            ->get(route('admin.b2b.module', ['module' => 'dashboard']))
            ->assertOk()
            ->assertDontSee('data-v2-pricing-lists-hub', false);

        $this->actingAs($admin)
            ->get(route('admin.b2b.pricing-approvals'))
            ->assertNotFound();

        $this->actingAs($admin)
            ->post(route('admin.b2b.pricing.save'), [
                'price_tier_id' => $tier,
                'product_id' => $product,
                'unit_price' => 4,
                'minimum_quantity' => 1,
            ])
            ->assertStatus(409);

        $this->assertDatabaseMissing('b2b_price_rules', [
            'store_id' => $store,
            'product_id' => $product,
        ]);
    }

    public function test_independent_pricing_view_and_manage_denies_do_not_leak_or_escalate_access(): void
    {
        $store = app(WholesalePrincipal::class)->storeId();
        [$product, $tier] = $this->productAndTier($store);
        $this->setTiersEnabled($store, true);
        $readerDenied = $this->wholesaleAdmin();
        $this->deny($readerDenied, 'b2b.pricing.view');

        $this->actingAs($readerDenied->fresh())
            ->get(route('admin.commercial.sales-control', ['store_id' => $store]))
            ->assertOk()
            ->assertViewHas('pricingListsUrl', null)
            ->assertDontSee('data-v2-pricing-lists-link', false);

        $this->actingAs($readerDenied->fresh())
            ->get(route('admin.b2b.module', ['module' => 'dashboard']))
            ->assertOk()
            ->assertDontSee('data-v2-pricing-lists-hub', false);

        $this->actingAs($readerDenied->fresh())
            ->get(route('admin.b2b.pricing-approvals'))
            ->assertForbidden();

        $writerDenied = $this->wholesaleAdmin();
        $this->deny($writerDenied, 'b2b.pricing.manage');

        $this->actingAs($writerDenied->fresh())
            ->get(route('admin.b2b.pricing-approvals'))
            ->assertOk()
            ->assertDontSee('action="'.route('admin.b2b.pricing.save').'"', false);

        $this->actingAs($writerDenied->fresh())
            ->post(route('admin.b2b.pricing.save'), [
                'price_tier_id' => $tier,
                'product_id' => $product,
                'unit_price' => 1,
                'minimum_quantity' => 1,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('b2b_price_rules', [
            'store_id' => $store,
            'product_id' => $product,
        ]);
    }

    public function test_wholesale_list_never_exposes_retail_products_or_accepts_forged_foreign_product(): void
    {
        $store = app(WholesalePrincipal::class)->storeId();
        [$wholesaleProduct, $tier] = $this->productAndTier($store);
        $retail = $this->retailStore();
        $retailProduct = $this->insertProduct($retail, 'b2c', 'P226 Retail Only Product', 'P226-RETAIL');
        $admin = $this->wholesaleAdmin();
        $this->setTiersEnabled($store, true);
        $this->priceRule($store, $wholesaleProduct, $tier, 7.5);
        $this->priceRule($retail, $retailProduct, $tier, 1.25);

        $this->actingAs($admin)
            ->get(route('admin.b2b.pricing-approvals', ['store_id' => $retail]))
            ->assertOk()
            ->assertViewHas('moduleData', static fn (array $data): bool => collect($data['rows'] ?? [])->contains(static fn (array $row): bool => $row['product'] === 'P226 Price List Product')
                && ! collect($data['rows'] ?? [])->contains(static fn (array $row): bool => $row['product'] === 'P226 Retail Only Product'));

        $this->actingAs($admin)
            ->post(route('admin.b2b.pricing.save'), [
                'price_tier_id' => $tier,
                'product_id' => $retailProduct,
                'unit_price' => 1,
                'minimum_quantity' => 1,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('b2b_price_rules', [
            'store_id' => $store,
            'product_id' => $retailProduct,
        ]);
    }

    public function test_roleless_inactive_and_retail_users_never_acquire_wholesale_price_lists(): void
    {
        $store = app(WholesalePrincipal::class)->storeId();
        $this->setTiersEnabled($store, true);
        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->wholesaleAdmin();
        $inactive->forceFill(['is_active' => false])->save();
        $retailStore = $this->retailStore();
        $retailActor = User::factory()->create(['is_active' => true]);
        $retailActor->storeRoleAssignments()->create([
            'store_id' => $retailStore,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
        ]);

        foreach ([$roleless, $inactive, $retailActor] as $actor) {
            $keys = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')
                ->all();
            $this->assertNotContains('commercial_sales_control', $keys);

            $commercial = $this->actingAs($actor->fresh())
                ->get(route('admin.commercial.sales-control', ['store_id' => $store]));
            $this->assertContains($commercial->getStatusCode(), [403, 404]);

            $pricing = $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.pricing-approvals'));
            $this->assertContains($pricing->getStatusCode(), [403, 404]);
        }
    }

    /** @return array{int,int} */
    private function productAndTier(int $store): array
    {
        $product = $this->insertProduct($store, 'b2b', 'P226 Price List Product', 'P226-WHOLESALE');
        $tier = (int) DB::table('b2b_price_tiers')->insertGetId([
            'code' => 'P226-TIER',
            'name' => 'P226 Gold Tier',
            'priority' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$product, $tier];
    }

    private function insertProduct(int $store, string $channel, string $name, string $code): int
    {
        $catalog = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $store,
            'channel' => $channel,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unit = (int) DB::table('units')->insertGetId([
            'code' => $code.'-EA',
            'name' => $name.' Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog,
            'unit_id' => $unit,
            'sku' => $code.'-SKU',
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $store,
            'product_id' => $product,
            'price' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $product;
    }

    private function priceRule(int $store, int $product, int $tier, float $price): void
    {
        DB::table('b2b_price_rules')->insert([
            'price_tier_id' => $tier,
            'store_id' => $store,
            'product_id' => $product,
            'unit_price' => $price,
            'minimum_quantity' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function setTiersEnabled(int $store, bool $enabled): void
    {
        DB::table('settings')->updateOrInsert([
            'store_id' => $store,
            'key' => B2bPricingMode::SETTING_KEY,
        ], [
            'value' => json_encode($enabled, JSON_THROW_ON_ERROR),
            'is_secret' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function wholesaleAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        return $user;
    }

    private function retailStore(): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => 'P226-RETAIL-STORE',
            'name' => 'P226 Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function deny(User $actor, string $ability): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->getKey(),
            'permission_id' => DB::table('permissions')->where('code', $ability)->value('id'),
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
    }
}
