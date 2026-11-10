<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P261: existing Live Tracking Sidebar destination must reflect actual
 * store/channel tracking rights, not expose a cross-tenant lookup surface.
 */
final class V2ScopedLiveTrackingSidebarHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_retail_tracking_link_and_lookup_are_store_scoped_in_both_locales(): void
    {
        $ownStore = $this->retailStore('V2-P261-OWN');
        $foreignStore = $this->retailStore('V2-P261-FOREIGN');
        $actor = $this->retailTracker($ownStore);
        $this->driverLocation($ownStore, 'V2 P261 Own Driver');
        $this->driverLocation($foreignStore, 'V2 P261 Foreign Driver');

        $this->assertTrue($actor->hasPermission('drivers.tracking.view', $ownStore));
        $this->assertFalse($actor->hasPermission('drivers.tracking.view', $foreignStore));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $items = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->where('key', 'driver_live_tracking')
                ->values();

            $this->assertCount(1, $items);
            $this->assertSame('admin.driver-live-tracking.index', $items->first()['route']);

            $this->actingAs($actor->fresh())
                ->get(route('admin.driver-live-tracking.index', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-driver-live-map', false)
                ->assertViewHas('trackingStores', static function (array $stores) use ($ownStore, $foreignStore): bool {
                    $ids = array_column($stores, 'id');

                    return in_array($ownStore, $ids, true) && ! in_array($foreignStore, $ids, true);
                });

            $this->actingAs($actor->fresh())
                ->getJson(route('admin.driver-live-tracking.lookups', [
                    'kind' => 'driver',
                    'channel' => 'b2c',
                    'q' => 'V2 P261',
                ], false))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.label', 'V2 P261 Own Driver')
                ->assertDontSee('V2 P261 Foreign Driver');

            $foreignResponse = $this->actingAs($actor->fresh())
                ->getJson(route('admin.driver-live-tracking.lookups', [
                    'kind' => 'driver',
                    'channel' => 'b2c',
                    'store_id' => $foreignStore,
                ], false));
            $this->assertContains($foreignResponse->getStatusCode(), [403, 404]);

            $this->actingAs($actor->fresh())
                ->getJson(route('admin.driver-live-tracking.lookups', [
                    'kind' => 'driver',
                    'channel' => 'b2b',
                ], false))
                ->assertForbidden();
        }
    }

    public function test_roleless_actor_cannot_use_sidebar_or_direct_tracking_page_and_lookups(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor = User::factory()->create(['locale' => $locale, 'is_active' => true]);

            $keys = collect(app(AdminNavigation::class)->groupsFor($actor))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')->all();

            $this->assertNotContains('driver_live_tracking', $keys);

            $this->actingAs($actor)
                ->get(route('admin.driver-live-tracking.index', [], false))
                ->assertForbidden();

            $this->actingAs($actor)
                ->getJson(route('admin.driver-live-tracking.lookups', [
                    'kind' => 'driver',
                ], false))
                ->assertForbidden();
        }
    }

    private function retailTracker(int $storeId): User
    {
        $role = Role::query()->create([
            'code' => 'V2_P261_STORE_TRACKER',
            'name' => 'V2 P261 Retail Tracking Reader',
            'scope' => 'store',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach(
            Permission::query()->where('code', 'drivers.tracking.view')->firstOrFail()->getKey(),
        );

        $user = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => $role->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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

    private function driverLocation(int $storeId, string $name): void
    {
        $driver = User::factory()->create(['name' => $name, 'is_active' => true]);
        $driverId = (int) DB::table('drivers')->insertGetId([
            'user_id' => $driver->getKey(),
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('driver_current_locations')->insert([
            'driver_id' => $driverId,
            'store_id' => $storeId,
            'channel' => 'b2c',
            'latitude' => 29.37,
            'longitude' => 47.98,
            'captured_at' => now(),
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
