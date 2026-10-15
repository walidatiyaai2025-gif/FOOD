<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DriverLiveTrackingDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_can_open_live_tracking_dashboard(): void
    {
        $user = $this->globalUser('SUPER_ADMIN', 'tracking-owner@example.test');

        $this->actingAs($user)
            ->get('/admin/driver-live-tracking')
            ->assertOk()
            ->assertSee('data-foodex-utility="driver-live-tracking"', false)
            ->assertSee('data-map-provider="openstreetmap"', false)
            ->assertSee('/assets/leaflet/1.9.4/leaflet.css', false)
            ->assertSee('/assets/leaflet/1.9.4/leaflet.js', false)
            ->assertSee('/assets/admin/driver-live-map.css', false)
            ->assertSee('/assets/admin/driver-live-map.js', false)
            ->assertSee('data-driver-live-map', false)
            ->assertSee('data-driver-live-map-i18n', false)
            ->assertSee('data-actor-kind="mixed"', false)
            ->assertSee('data-secondary-feed-url="', false)
            ->assertSee('/admin/field-operations/fleet-map/feed', false)
            ->assertSee('Live Tracking', false)
            ->assertSee('data-live-map="error-message"', false)
            ->assertSee('data-live-map="retry"', false)
            ->assertSee('data-assets-failed=', false)
            ->assertSee('Map files could not load.', false)
            ->assertSee('reportMissingRuntime', false)
            ->assertSee('"status":"Status"', false)
            ->assertSee('"online":"Online"', false)
            ->assertDontSee('const popupFor', false)
            ->assertDontSee('unpkg.com', false)
            ->assertSee('/admin/driver-live-tracking/feed', false);
    }

    public function test_full_dashboard_uses_scoped_lookup_controls_instead_of_raw_identifiers(): void
    {
        $user = $this->globalUser('SUPER_ADMIN', 'tracking-lookups-ui@example.test');
        app(WholesalePrincipal::class)->storeId();

        $this->actingAs($user)
            ->get('/admin/driver-live-tracking')
            ->assertOk()
            ->assertSee('data-lookup-url="', false)
            ->assertSee('/admin/driver-live-tracking/lookups', false)
            ->assertSee('data-live-lookup="entity"', false)
            ->assertSee('data-live-lookup="order"', false)
            ->assertSee('data-live-lookup="route"', false)
            ->assertSee('role="combobox"', false)
            ->assertSee('role="listbox"', false)
            ->assertSee('data-live-map="entity-id" type="hidden"', false)
            ->assertSee('data-live-map="order-id" type="hidden"', false)
            ->assertSee('data-live-map="route-key" type="hidden"', false)
            ->assertDontSee('data-live-map="driver-id" type="number"', false)
            ->assertDontSee('data-live-map="actor-id" type="number"', false)
            ->assertDontSee('data-live-map="order-id" type="number"', false);
    }

    public function test_lookup_endpoint_returns_only_human_readable_tracking_options_with_business_context(): void
    {
        $user = $this->globalUser('SUPER_ADMIN', 'tracking-lookups-api@example.test');
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'LOOKUP-B2C',
            'name' => 'Lookup Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $driverUser = User::query()->create([
            'name' => 'Lookup Driver',
            'email' => 'lookup-driver@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $driverId = (int) DB::table('drivers')->insertGetId([
            'user_id' => $driverUser->id,
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

        $customerId = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => 'Lookup Customer',
            'phone' => '+96550000000',
            'email' => 'lookup-customer@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'order_number' => 'LOOKUP-ORDER-900',
            'channel' => 'b2c',
            'status' => 'out_for_delivery',
            'currency' => 'KWD',
            'subtotal' => 12,
            'discount_total' => 0,
            'delivery_total' => 0,
            'tax_total' => 0,
            'grand_total' => 12,
            'payment_method' => 'cash_on_delivery',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $vanId = (int) DB::table('vans')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'code' => 'LOOKUP-VAN',
            'plate_number' => 'KUWAIT-LKP',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('fleet_current_locations')->insert([
            'actor_type' => 'van',
            'actor_id' => $vanId,
            'vehicle_id' => $vanId,
            'route_key' => 'ROUTE-LOOKUP-1',
            'store_id' => $storeId,
            'channel' => 'b2c',
            'latitude' => 29.38,
            'longitude' => 47.99,
            'captured_at' => now(),
            'received_at' => now(),
            'source_app' => 'van',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $driver = $this->actingAs($user)->getJson('/admin/driver-live-tracking/lookups?kind=driver&q=Lookup&channel=b2c&store_id='.$storeId);
        $driver->assertOk()->assertJsonFragment([
            'kind' => 'driver',
            'value' => $driverId,
            'label' => 'Lookup Driver',
        ]);
        $this->assertStringContainsString('lookup-driver@example.test', (string) $driver->json('data.0.context'));
        $this->assertStringContainsString('Lookup Retail', (string) $driver->json('data.0.context'));

        $order = $this->actingAs($user)->getJson('/admin/driver-live-tracking/lookups?kind=order&q='.urlencode('#'.$orderId).'&channel=b2c&store_id='.$storeId);
        $order->assertOk()->assertJsonFragment([
            'kind' => 'order',
            'value' => $orderId,
            'label' => '#'.$orderId.' · LOOKUP-ORDER-900',
        ]);
        $this->assertStringContainsString('Lookup Customer', (string) $order->json('data.0.context'));
        $this->assertStringContainsString('Out for delivery', (string) $order->json('data.0.context'));

        $van = $this->actingAs($user)->getJson('/admin/driver-live-tracking/lookups?kind=van&q=LOOKUP&channel=b2c&store_id='.$storeId);
        $van->assertOk()->assertJsonFragment([
            'kind' => 'van',
            'value' => $vanId,
            'label' => 'LOOKUP-VAN · KUWAIT-LKP',
        ]);

        $route = $this->actingAs($user)->getJson('/admin/driver-live-tracking/lookups?kind=route&q=ROUTE&channel=b2c&store_id='.$storeId);
        $route->assertOk()->assertJsonFragment([
            'kind' => 'route',
            'value' => 'ROUTE-LOOKUP-1',
            'label' => 'ROUTE-LOOKUP-1',
        ]);
        $this->assertStringContainsString('LOOKUP-VAN', (string) $route->json('data.0.context'));
        $this->assertStringContainsString('Lookup Retail', (string) $route->json('data.0.context'));
    }

    public function test_arabic_live_tracking_contract_exposes_localized_status_labels(): void
    {
        $user = $this->globalUser('SUPER_ADMIN', 'tracking-ar@example.test');
        $user->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($user)
            ->get('/admin/driver-live-tracking')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('data-mode="full"', false)
            ->assertSee('data-actor-kind="mixed"', false)
            ->assertSee('التتبع الحي', false)
            ->assertSee('تعذر تحميل ملفات الخريطة.', false)
            ->assertSee('data-live-map="retry"', false)
            ->assertViewHas('trackingI18n', static function (array $i18n): bool {
                return $i18n['status'] === 'الحالة'
                    && $i18n['statuses']['online'] === 'متصل'
                    && $i18n['statuses']['stale'] === 'متأخر'
                    && $i18n['statuses']['offline'] === 'غير متصل'
                    && $i18n['driver'] === 'سائق'
                    && $i18n['van'] === 'فان';
            });
    }

    public function test_dashboard_web_session_can_read_live_tracking_feed(): void
    {
        $user = $this->globalUser('SUPER_ADMIN', 'tracking-feed@example.test');
        app(WholesalePrincipal::class)->storeId();

        $this->actingAs($user)
            ->getJson('/admin/driver-live-tracking/feed')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_user_without_tracking_permission_is_denied_and_navigation_hides_entry(): void
    {
        $user = User::query()->create([
            'name' => 'No Tracking',
            'email' => 'no-tracking@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get('/admin/driver-live-tracking')
            ->assertForbidden();

        $this->actingAs($user)
            ->getJson('/admin/driver-live-tracking/lookups?kind=driver&q=No')
            ->assertForbidden();

        $items = collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(fn (array $group) => $group['children'])
            ->pluck('key');

        $this->assertFalse($items->contains('driver_live_tracking'));
    }

    public function test_authorized_navigation_contains_single_live_tracking_entry(): void
    {
        $user = $this->globalUser('SUPER_ADMIN', 'tracking-nav@example.test');

        $items = collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(fn (array $group) => $group['children'])
            ->where('key', 'driver_live_tracking')
            ->values();

        $this->assertCount(1, $items);
        $this->assertSame('admin.driver-live-tracking.index', $items->first()['route']);
    }

    private function globalUser(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
