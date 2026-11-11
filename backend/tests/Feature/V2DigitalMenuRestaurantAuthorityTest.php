<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 P325: Digital Menu reuses the authorized Restaurant SaaS workspace
 * menu tab, not a second Sidebar item or a visual authorization grant.
 */
final class V2DigitalMenuRestaurantAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_enabled_restaurant_manager_discovers_one_menu_tab_and_reaches_real_editor_in_ar_and_en(): void
    {
        $restaurant = $this->store('V2-P325-MENU', StoreBusinessProfile::RESTAURANT);
        $this->enableRestaurantSaasForTestStore($restaurant->id);
        $manager = $this->manager($restaurant);

        $this->assertTrue(Route::has('admin.smart-ordering.menu.index'));
        $this->assertTrue($manager->hasPermission('smart_ordering.manage', $restaurant->id));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $manager->forceFill(['locale' => $locale])->save();
            $actor = $manager->fresh();

            $navigation = collect(app(AdminNavigation::class)->groupsFor($actor));
            $links = $navigation->flatMap(static fn (array $group): array => $group['children']);
            $smartOrdering = $links->where('key', 'smart_ordering')->values();
            $this->assertCount(1, $smartOrdering, 'Reuse the existing single Restaurant SaaS Sidebar entry');
            $this->assertSame('stores', $navigation->first(fn (array $group): bool => collect($group['children'])->contains('key', 'smart_ordering'))['key']);
            $this->assertSame('admin.smart-ordering.index', $smartOrdering->first()['route']);
            $this->assertFalse($links->contains('key', 'smart_ordering_menu'));
            $this->assertFalse($links->contains('key', 'digital_menu'));

            $menuLink = route('admin.smart-ordering.menu.index', [
                'store_id' => $restaurant->id,
                'setup' => 'menu',
            ], false);

            $this->actingAs($actor)
                ->get(route('admin.smart-ordering.index', ['store_id' => $restaurant->id], false))
                ->assertOk()
                ->assertSee('data-restaurant-saas-workspace-nav', false)
                ->assertSee(e($menuLink), false)
                ->assertSee(__('smart_ordering_menu.nav_title'));

            $this->actingAs($actor)
                ->get(route('admin.smart-ordering.menu.index', ['store_id' => $restaurant->id], false))
                ->assertOk()
                ->assertViewIs('admin.smart-ordering-menu')
                ->assertViewHas('site', static fn ($site): bool => (int) $site->store_id === (int) $restaurant->id)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-foodex-mobile-web-page="smart-ordering-menu"', false)
                ->assertSee('data-restaurant-saas-workspace-nav', false)
                ->assertSee(__('smart_ordering_menu.title'));
        }
    }

    public function test_menu_editor_enforces_requested_restaurant_store_and_rejects_foreign_store(): void
    {
        $own = $this->store('V2-P325-OWN', StoreBusinessProfile::RESTAURANT);
        $foreign = $this->store('V2-P325-FOREIGN', StoreBusinessProfile::RESTAURANT);
        $this->enableRestaurantSaasForTestStore($own->id);
        $this->enableRestaurantSaasForTestStore($foreign->id);
        $manager = $this->manager($own);

        $this->actingAs($manager)
            ->get(route('admin.smart-ordering.menu.index', ['store_id' => $own->id], false))
            ->assertOk();

        $this->actingAs($manager)
            ->get(route('admin.smart-ordering.menu.index', ['store_id' => $foreign->id], false))
            ->assertNotFound();
    }

    public function test_store_view_permission_does_not_grant_menu_management_after_explicit_store_deny(): void
    {
        $restaurant = $this->store('V2-P325-VIEW-ONLY', StoreBusinessProfile::RESTAURANT);
        $this->enableRestaurantSaasForTestStore($restaurant->id);
        $manager = $this->manager($restaurant);
        $permission = Permission::query()->where('code', 'smart_ordering.manage')->firstOrFail();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $manager->id,
            'permission_id' => $permission->id,
            'scope_key' => 'store:'.$restaurant->id,
            'store_id' => $restaurant->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $manager->forceFill(['has_permission_overrides' => true])->save();
        $actor = $manager->fresh();

        $this->assertTrue($actor->hasPermission('smart_ordering.view', $restaurant->id));
        $this->assertFalse($actor->hasPermission('smart_ordering.manage', $restaurant->id));

        $links = collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group): array => $group['children']);
        $this->assertCount(1, $links->where('key', 'smart_ordering'));

        $this->actingAs($actor)
            ->get(route('admin.smart-ordering.index', ['store_id' => $restaurant->id], false))
            ->assertOk();

        $this->actingAs($actor)
            ->get(route('admin.smart-ordering.menu.index', ['store_id' => $restaurant->id], false))
            ->assertForbidden();
    }

    public function test_non_restaurant_disabled_entitlement_roleless_and_wholesale_cannot_open_digital_menu(): void
    {
        $retail = $this->store('V2-P325-GENERAL-RETAIL', StoreBusinessProfile::RETAIL_STORE);
        $disabled = $this->store('V2-P325-DISABLED', StoreBusinessProfile::RESTAURANT);

        foreach ([$retail, $disabled] as $store) {
            $manager = $this->manager($store);

            $this->actingAs($manager)
                ->get(route('admin.smart-ordering.menu.index', ['store_id' => $store->id], false))
                ->assertForbidden();

            $this->assertFalse(collect(app(AdminNavigation::class)->groupsFor($manager->fresh()))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->contains('key', 'smart_ordering'));
        }

        $enabled = $this->store('V2-P325-ENABLED', StoreBusinessProfile::RESTAURANT);
        $this->enableRestaurantSaasForTestStore($enabled->id);

        foreach (['B2B_ADMIN', null] as $roleCode) {
            $actor = User::factory()->create(['is_active' => true]);
            if ($roleCode !== null) {
                $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());
            }

            $this->actingAs($actor)
                ->get(route('admin.smart-ordering.menu.index', ['store_id' => $enabled->id], false))
                ->assertForbidden();
        }
    }

    private function manager(Store $restaurant): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $restaurant->id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function store(string $code, StoreBusinessProfile $profile): Store
    {
        return Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => $profile->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }
}
