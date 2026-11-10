<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2RestaurantOverviewEntryAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_restaurant_entry_is_a_single_localized_live_destination(): void
    {
        $store = $this->store('V2-P183-LIVE', StoreBusinessProfile::RESTAURANT);
        $this->enableRestaurantSaasForTestStore($store->id);
        $manager = $this->manager($store);

        foreach (['ar' => ['rtl', 'الطلب الذكي'], 'en' => ['ltr', 'Smart Ordering']] as $locale => [$direction, $label]) {
            app()->setLocale($locale);
            $manager->forceFill(['locale' => $locale])->save();

            $links = collect(app(AdminNavigation::class)->groupsFor($manager->fresh()))
                ->flatMap(static fn (array $group): array => $group['children']);
            $matches = $links->where('key', 'smart_ordering');
            $this->assertCount(1, $matches);
            $link = $matches->first();
            $this->assertSame('admin.smart-ordering.index', $link['route']);
            $this->assertSame('smart_ordering.nav_title', $link['label']);
            $this->assertSame($label, __($link['label']));
            $this->assertFalse($links->contains('key', 'smart_ordering_qr'));
            $this->assertFalse($links->contains('key', 'smart_ordering_analytics'));

            $this->actingAs($manager->fresh())
                ->get(route($link['route'], ['store_id' => $store->id]))
                ->assertOk()
                ->assertViewIs('admin.smart-ordering')
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee($label);
        }
    }

    public function test_non_restaurant_or_disabled_restaurant_is_not_discoverable_or_directly_openable(): void
    {
        foreach ([
            $this->store('V2-P183-RETAIL', StoreBusinessProfile::RETAIL_STORE),
            $this->store('V2-P183-DISABLED', StoreBusinessProfile::RESTAURANT),
        ] as $store) {
            $manager = $this->manager($store);
            $this->assertFalse($this->hasEntry($manager));
            $this->actingAs($manager)
                ->get(route('admin.smart-ordering.index', ['store_id' => $store->id]))
                ->assertForbidden();
        }
    }

    public function test_enabled_restaurant_manager_is_denied_foreign_tenant_url(): void
    {
        $owned = $this->store('V2-P183-OWN', StoreBusinessProfile::RESTAURANT);
        $foreign = $this->store('V2-P183-FOREIGN', StoreBusinessProfile::RESTAURANT);
        $this->enableRestaurantSaasForTestStore($owned->id);
        $this->enableRestaurantSaasForTestStore($foreign->id);
        $manager = $this->manager($owned);

        $this->assertTrue($this->hasEntry($manager));
        $this->actingAs($manager)->get(route('admin.smart-ordering.index', ['store_id' => $owned->id]))->assertOk();
        $this->actingAs($manager)->get(route('admin.smart-ordering.index', ['store_id' => $foreign->id]))->assertNotFound();
    }

    public function test_wholesale_and_super_admin_have_no_implicit_restaurant_operations_entry(): void
    {
        $store = $this->store('V2-P183-ROLE', StoreBusinessProfile::RESTAURANT);
        $this->enableRestaurantSaasForTestStore($store->id);
        foreach (['B2B_ADMIN', 'SUPER_ADMIN'] as $roleCode) {
            $actor = User::factory()->create(['is_active' => true]);
            $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());
            $this->assertFalse($this->hasEntry($actor));
            if ($roleCode === 'B2B_ADMIN') {
                $this->actingAs($actor)
                    ->get(route('admin.smart-ordering.index', ['store_id' => $store->id]))
                    ->assertForbidden();
            }
        }
    }

    private function hasEntry(User $actor): bool
    {
        return collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->contains('key', 'smart_ordering');
    }

    private function manager(Store $store): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $store->getKey(),
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
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
