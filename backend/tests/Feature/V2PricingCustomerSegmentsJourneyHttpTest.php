<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 #2557 / P227: the authorized customer-segment journey starts in
 * existing Customer Groups and ends in store-scoped B2B pricing targeting.
 */
final class V2PricingCustomerSegmentsJourneyHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_live_wholesale_segment_is_discoverable_in_both_locales_and_in_commercial_targeting(): void
    {
        $store = app(WholesalePrincipal::class)->storeId();
        $product = $this->wholesaleProduct($store);
        $admin = $this->wholesaleAdmin();
        $group = $this->createSegment($admin, $store, 'P227 Preferred Wholesale');

        $this->assertTrue(Route::has('admin.b2b.customer-groups.index'));
        $this->assertTrue(Route::has('admin.commercial.sales-control'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $admin->forceFill(['locale' => $locale])->save();
            $actor = $admin->fresh();

            $groups = collect(app(AdminNavigation::class)->groupsFor($actor));
            $accounts = $groups->firstWhere('key', 'accounts');
            $catalog = $groups->firstWhere('key', 'catalog');
            $this->assertIsArray($accounts);
            $this->assertIsArray($catalog);
            $groupLink = collect($accounts['children'])->firstWhere('key', 'customer_groups');
            $salesLink = collect($catalog['children'])->firstWhere('key', 'commercial_sales_control');
            $this->assertSame('admin.b2b.customer-groups.index', $groupLink['route']);
            $this->assertSame('admin.commercial.sales-control', $salesLink['route']);

            $this->actingAs($actor)
                ->get(route($groupLink['route'], ['store_id' => $store]))
                ->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('P227 Preferred Wholesale');

            $this->actingAs($actor)
                ->get(route($salesLink['route'], ['store_id' => $store, 'product' => $product]))
                ->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('P227 Preferred Wholesale')
                ->assertViewHas('ruleGroups', static fn ($values): bool => collect($values)
                    ->contains(static fn ($value): bool => (int) $value->id === $group));
        }
    }

    public function test_real_customer_membership_is_visible_to_segment_editor_and_valid_pricing_rule_persists(): void
    {
        $store = app(WholesalePrincipal::class)->storeId();
        $product = $this->wholesaleProduct($store);
        $admin = $this->wholesaleAdmin();
        $group = $this->createSegment($admin, $store, 'P227 Volume Segment');
        $customer = app(B2bCustomerService::class)->create(['name' => 'P227 Account']);

        $this->actingAs($admin)->post(route('admin.b2b.customer-groups.members', ['group' => $group]), [
            'store_id' => $store,
            'operation' => 'add',
            'customer_ids' => [(int) $customer->legacy_customer_id],
        ])->assertRedirect();
        $this->assertDatabaseHas('commercial_customer_group_members', [
            'customer_group_id' => $group,
            'customer_id' => (int) $customer->legacy_customer_id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.b2b.customer-groups.index', ['store_id' => $store]))
            ->assertOk()
            ->assertViewHas('counts', static fn ($counts): bool => (int) ($counts[$group] ?? -1) === 1);

        $this->actingAs($admin)
            ->from(route('admin.commercial.sales-control', ['store_id' => $store, 'product' => $product]))
            ->put(route('admin.commercial.sales-control.save', ['product' => $product, 'store_id' => $store]),
                $this->pricingPayload($group))
            ->assertRedirect(route('admin.commercial.sales-control', ['store_id' => $store, 'product' => $product]))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('product_commercial_rules', [
            'product_id' => $product,
            'customer_group_id' => $group,
            'channel' => 'customer',
            'is_allowed' => true,
        ]);
        $this->actingAs($admin)
            ->get(route('admin.commercial.sales-control', ['store_id' => $store, 'product' => $product]))
            ->assertOk()
            ->assertViewHas('commercialRules', static fn ($rules): bool => collect($rules->get($product))
                ->contains(static fn ($rule): bool => (int) $rule->customer_group_id === $group));
    }

    public function test_inactive_and_foreign_store_segments_are_not_eligible_for_pricing_rules(): void
    {
        $store = app(WholesalePrincipal::class)->storeId();
        $product = $this->wholesaleProduct($store);
        $admin = $this->wholesaleAdmin();
        $group = $this->createSegment($admin, $store, 'P227 Disabled');
        $retail = $this->retailStore();
        $foreign = (int) DB::table('commercial_customer_groups')->insertGetId([
            'store_id' => $retail,
            'name' => 'P227 Foreign Retail Segment',
            'priority' => 2,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->patch(route('admin.b2b.customer-groups.update', ['group' => $group]), [
            'store_id' => $store,
            'name' => 'P227 Disabled',
            'priority' => 0,
            'is_active' => false,
        ])->assertRedirect();

        $response = $this->actingAs($admin)
            ->get(route('admin.commercial.sales-control', ['store_id' => $store, 'product' => $product]))
            ->assertOk()
            ->assertViewHas('ruleGroups', static fn ($values): bool => ! collect($values)
                ->contains(static fn ($row): bool => in_array((int) $row->id, [$group, $foreign], true)));

        foreach ([$group, $foreign] as $invalidGroup) {
            $this->actingAs($admin)
                ->put(route('admin.commercial.sales-control.save', ['product' => $product, 'store_id' => $store]),
                    $this->pricingPayload($invalidGroup))
                ->assertSessionHasErrors('rules_json.0.customer_group_id');
        }
        $this->assertDatabaseMissing('product_commercial_rules', [
            'product_id' => $product,
            'customer_group_id' => $group,
        ]);
        $this->assertDatabaseMissing('product_commercial_rules', [
            'product_id' => $product,
            'customer_group_id' => $foreign,
        ]);
    }

    public function test_read_only_customer_group_role_cannot_write_and_sidebar_does_not_grant_pricing_access(): void
    {
        $store = app(WholesalePrincipal::class)->storeId();
        $admin = $this->wholesaleAdmin();
        $this->deny($admin, 'customers.manage');

        $this->actingAs($admin->fresh())
            ->get(route('admin.b2b.customer-groups.index', ['store_id' => $store]))
            ->assertOk()
            ->assertViewHas('canManage', false);

        $result = $this->actingAs($admin->fresh())->post(route('admin.b2b.customer-groups.store'), [
            'store_id' => $store, 'name' => 'Forged Group',
        ]);
        $this->assertContains($result->getStatusCode(), [403, 404]);
        $this->assertDatabaseMissing('commercial_customer_groups', ['name' => 'Forged Group']);

        $this->deny($admin, 'catalog.view');
        $keys = collect(app(AdminNavigation::class)->groupsFor($admin->fresh()))
            ->flatMap(static fn (array $section): array => $section['children'])
            ->pluck('key')->all();
        $this->assertNotContains('commercial_sales_control', $keys);
        $this->assertContains('customer_groups', $keys);

        $response = $this->actingAs($admin->fresh())
            ->get(route('admin.commercial.sales-control', ['store_id' => $store]));
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_roleless_inactive_and_retail_users_cannot_discover_wholesale_pricing_segments(): void
    {
        $store = app(WholesalePrincipal::class)->storeId();
        $retailStore = $this->retailStore();
        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->wholesaleAdmin();
        $inactive->forceFill(['is_active' => false])->save();
        $retail = User::factory()->create(['is_active' => true]);
        $retail->storeRoleAssignments()->create([
            'store_id' => $retailStore,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
        ]);

        foreach ([$roleless, $inactive, $retail] as $actor) {
            $keys = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')->all();
            $this->assertNotContains('customer_groups', $keys);
            $this->assertNotContains('commercial_sales_control', $keys);

            $groupResponse = $this->actingAs($actor)
                ->get(route('admin.b2b.customer-groups.index', ['store_id' => $store]));
            $this->assertContains($groupResponse->getStatusCode(), [403, 404]);
            $salesResponse = $this->actingAs($actor)
                ->get(route('admin.commercial.sales-control', ['store_id' => $store]));
            $this->assertContains($salesResponse->getStatusCode(), [403, 404]);
        }
    }

    /** @return array<string,mixed> */
    private function pricingPayload(int $group): array
    {
        return [
            'status' => 'OPEN',
            'channels_json' => json_encode(['customer', 'van'], JSON_THROW_ON_ERROR),
            'break_pack_policy' => 'mixed',
            'business_timezone' => 'Asia/Kuwait',
            'week_starts_on' => 1,
            'selling_units_json' => json_encode([
                ['code' => 'PIECE', 'name' => 'Piece', 'conversion_factor' => 1, 'is_base' => true, 'is_active' => true],
            ], JSON_THROW_ON_ERROR),
            'availability_windows_json' => '[]',
            'rules_json' => json_encode([
                ['customer_group_id' => $group, 'channel' => 'customer', 'is_allowed' => true, 'max_per_order' => 10],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    private function wholesaleAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        return $user;
    }

    private function createSegment(User $admin, int $store, string $name): int
    {
        $this->actingAs($admin)
            ->post(route('admin.b2b.customer-groups.store'), [
                'store_id' => $store,
                'name' => $name,
                'priority' => 5,
            ])->assertRedirect();
        $id = (int) DB::table('commercial_customer_groups')
            ->where('store_id', $store)->where('name', $name)->value('id');
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    private function wholesaleProduct(int $store): int
    {
        $unit = (int) DB::table('units')->insertGetId([
            'code' => 'P227-EA',
            'name' => 'P227 Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalog = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $store,
            'channel' => 'b2b',
            'code' => 'p227-wholesale',
            'name' => 'P227 Wholesale',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $category = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalog,
            'name' => 'P227 Segments',
            'slug' => 'p227-segments',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog,
            'category_id' => $category,
            'unit_id' => $unit,
            'sku' => 'P227-SEGMENT-PRODUCT',
            'name' => 'P227 Product',
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

    private function retailStore(): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => 'P227-FOREIGN-RETAIL',
            'name' => 'P227 Foreign Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function deny(User $actor, string $permission): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => DB::table('permissions')->where('code', $permission)->value('id'),
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
    }
}
