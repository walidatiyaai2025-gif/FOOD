<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\SmartOrderingEnterpriseEntitlementBridge;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 #2654 / P324: Restaurant Digital Ordering is a single guarded business
 * entry under existing Stores & Channels, with real Retail HTTP authority.
 */
final class V2RestaurantDigitalOrderingDomainAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_enabled_restaurant_store_has_one_discoverable_real_domain_destination_in_both_locales(): void
    {
        $restaurant = $this->store('V2-P324-OWN', StoreBusinessProfile::RESTAURANT);
        $this->enableRestaurantSaasForTestStore($restaurant);
        $actor = $this->retailAdmin($restaurant, 'v2-p324-owner@example.test');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $groups = $this->navigationGroups($actor);
            $storesGroup = $groups->firstWhere('key', 'stores');
            $this->assertIsArray($storesGroup);

            $items = $groups->flatMap(static fn (array $group): array => $group['children']);
            $restaurantItems = $items->where('key', 'smart_ordering');
            $this->assertCount(1, $restaurantItems, 'Restaurant domain must have one authoritative entry.');

            $item = $restaurantItems->first();
            $this->assertSame('admin.smart-ordering.index', $item['route']);
            $this->assertSame('smart_ordering.nav_title', $item['label']);
            $this->assertTrue(Route::has($item['route']));
            $this->assertContains('smart_ordering', collect($storesGroup['children'])->pluck('key')->all());
            $this->assertNotSame('smart_ordering.nav_title', __('smart_ordering.nav_title'));
            $this->assertFalse($items->contains('key', 'smart_ordering_qr'));
            $this->assertFalse($items->contains('key', 'smart_ordering_analytics'));

            $this->actingAs($actor->fresh())
                ->get(route($item['route'], ['store_id' => $restaurant], false))
                ->assertOk()
                ->assertSee('data-restaurant-saas-workspace-nav', false)
                ->assertSee('dir="'.$direction.'"', false);
        }
    }


    public function test_disabled_enterprise_module_hides_the_restaurant_entry_even_with_store_grant(): void
    {
        $restaurant = $this->store('V2-P324-MODULE-OFF', StoreBusinessProfile::RESTAURANT);
        $this->enableRestaurantSaasForTestStore($restaurant);
        $actor = $this->retailAdmin($restaurant, 'v2-p324-module-off@example.test');
        $this->assertTrue($this->hasRestaurantEntry($actor));

        app(ModuleRegistry::class)
            ->syncManifest(SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE)
            ->forceFill([
                'state' => ModuleState::DISABLED->value,
                'state_changed_at' => now(),
            ])->save();

        $this->assertFalse($this->hasRestaurantEntry($actor));
        $this->actingAs($actor)
            ->get(route('admin.smart-ordering.index', ['store_id' => $restaurant], false))
            ->assertForbidden();
    }

    public function test_mixed_wholesale_and_retail_roles_do_not_expand_restaurant_store_ownership(): void
    {
        $restaurant = $this->store('V2-P324-MIXED-OWN', StoreBusinessProfile::RESTAURANT);
        $foreignRestaurant = $this->store('V2-P324-MIXED-FOREIGN', StoreBusinessProfile::RESTAURANT);
        $wholesale = $this->store('V2-P324-MIXED-B2B', StoreBusinessProfile::RETAIL_STORE, 'B2B');
        $this->enableRestaurantSaasForTestStore($restaurant);
        $this->enableRestaurantSaasForTestStore($foreignRestaurant);
        $actor = $this->retailAdmin($restaurant, 'v2-p324-mixed@example.test');

        $wholesaleRole = Role::query()->where('code', 'B2B_ADMIN')->where('scope', 'global')->firstOrFail();
        $actor->roles()->syncWithoutDetaching([$wholesaleRole->id]);

        $this->assertTrue($this->hasRestaurantEntry($actor));
        foreach ([$foreignRestaurant, $wholesale] as $storeId) {
            $this->actingAs($actor)
                ->get(route('admin.smart-ordering.index', ['store_id' => $storeId], false))
                ->assertNotFound();
        }
    }

    public function test_missing_store_entitlement_does_not_advertise_or_open_restaurant_domain(): void
    {
        $restaurant = $this->store('V2-P324-NO-ENTITLEMENT', StoreBusinessProfile::RESTAURANT);
        $actor = $this->retailAdmin($restaurant, 'v2-p324-no-entitlement@example.test');

        $this->assertFalse($this->hasRestaurantEntry($actor));

        $this->actingAs($actor)
            ->get(route('admin.smart-ordering.index', ['store_id' => $restaurant], false))
            ->assertForbidden();
    }

    public function test_revoked_store_entitlement_immediately_removes_navigation_and_http_access(): void
    {
        $restaurant = $this->store('V2-P324-REVOKED', StoreBusinessProfile::RESTAURANT);
        $this->enableRestaurantSaasForTestStore($restaurant);
        $actor = $this->retailAdmin($restaurant, 'v2-p324-revoked@example.test');

        $this->assertTrue($this->hasRestaurantEntry($actor));
        DB::table('enterprise_module_entitlements')
            ->where('module_code', SmartOrderingEnterpriseEntitlementBridge::ENTERPRISE_MODULE)
            ->where('scope_key', 'store:'.$restaurant)
            ->update(['is_enabled' => false, 'updated_at' => now()]);

        $this->assertFalse($this->hasRestaurantEntry($actor));
        $this->actingAs($actor)
            ->get(route('admin.smart-ordering.index', ['store_id' => $restaurant], false))
            ->assertForbidden();
    }

    public function test_restaurant_navigation_cannot_be_inferred_from_a_normal_retail_store_or_foreign_channel(): void
    {
        $retail = $this->store('V2-P324-NORMAL-RETAIL', StoreBusinessProfile::RETAIL_STORE);
        $wholesale = $this->store('V2-P324-WHOLESALE', StoreBusinessProfile::RETAIL_STORE, 'B2B');
        $actor = $this->retailAdmin($retail, 'v2-p324-normal-retail@example.test');

        $this->assertFalse($this->hasRestaurantEntry($actor));
        $this->actingAs($actor)
            ->get(route('admin.smart-ordering.index', ['store_id' => $retail], false))
            ->assertForbidden();
        $this->actingAs($actor)
            ->get(route('admin.smart-ordering.index', ['store_id' => $wholesale], false))
            ->assertForbidden();
    }

    public function test_foreign_restaurant_store_id_is_rejected_even_when_both_stores_have_saas_enabled(): void
    {
        $own = $this->store('V2-P324-OWN-SCOPE', StoreBusinessProfile::RESTAURANT);
        $foreign = $this->store('V2-P324-FOREIGN-SCOPE', StoreBusinessProfile::RESTAURANT);
        $this->enableRestaurantSaasForTestStore($own);
        $this->enableRestaurantSaasForTestStore($foreign);
        $actor = $this->retailAdmin($own, 'v2-p324-scope@example.test');

        $this->assertTrue($this->hasRestaurantEntry($actor));
        $this->actingAs($actor)
            ->get(route('admin.smart-ordering.index', ['store_id' => $foreign], false))
            ->assertNotFound();
    }

    public function test_explicit_global_permission_deny_hides_the_domain_and_fails_closed_on_http(): void
    {
        $restaurant = $this->store('V2-P324-DENIED', StoreBusinessProfile::RESTAURANT);
        $this->enableRestaurantSaasForTestStore($restaurant);
        $actor = $this->retailAdmin($restaurant, 'v2-p324-denied@example.test');
        $this->assertTrue($this->hasRestaurantEntry($actor));

        $permission = Permission::query()->where('code', 'smart_ordering.view')->firstOrFail();
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

        $this->assertFalse($this->hasRestaurantEntry($actor));
        $this->actingAs($actor->fresh())
            ->get(route('admin.smart-ordering.index', ['store_id' => $restaurant], false))
            ->assertForbidden();
    }

    public function test_roleless_and_inactive_accounts_have_no_restaurant_domain_or_http_access(): void
    {
        $restaurant = $this->store('V2-P324-INACTIVE', StoreBusinessProfile::RESTAURANT);
        $this->enableRestaurantSaasForTestStore($restaurant);
        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->retailAdmin($restaurant, 'v2-p324-inactive@example.test');
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive->fresh()] as $actor) {
            $this->assertFalse($this->hasRestaurantEntry($actor));
            $this->actingAs($actor)
                ->get(route('admin.smart-ordering.index', ['store_id' => $restaurant], false))
                ->assertForbidden();
        }
    }

    /** @return Collection<int, array<string, mixed>> */
    private function navigationGroups(User $actor): Collection
    {
        app()->forgetInstance(AdminNavigation::class);

        return collect(app(AdminNavigation::class)->groupsFor($actor->fresh()));
    }

    private function hasRestaurantEntry(User $actor): bool
    {
        return $this->navigationGroups($actor)
            ->flatMap(static fn (array $group): array => $group['children'])
            ->contains('key', 'smart_ordering');
    }

    private function retailAdmin(int $storeId, string $email): User
    {
        $actor = User::factory()->create(['email' => $email, 'locale' => 'en', 'is_active' => true]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->where('scope', 'store')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function store(string $code, StoreBusinessProfile $profile, string $channel = 'B2C'): int
    {
        return (int) Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'business_profile' => $profile->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ])->getKey();
    }
}
