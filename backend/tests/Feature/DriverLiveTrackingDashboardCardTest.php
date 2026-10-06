<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DriverLiveTrackingDashboardCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_dashboard_has_exact_three_primary_cards_with_shared_live_map(): void
    {
        $user = $this->globalUser('B2B_ADMIN', 'tracking-card-b2b@example.test');
        $storeId = app(WholesalePrincipal::class)->storeId();

        $response = $this->actingAs($user)
            ->get('/admin/b2b/dashboard')
            ->assertOk()
            ->assertSee('data-dashboard-primary-row', false)
            ->assertSee('data-dashboard-primary-card="sales"', false)
            ->assertSee('data-dashboard-primary-card="driver-map"', false)
            ->assertSee('data-dashboard-primary-card="order-distribution"', false)
            ->assertSee('data-dashboard-live-driver-map', false)
            ->assertSee('data-mode="compact"', false)
            ->assertSee('data-actor-kind="mixed"', false)
            ->assertSee('data-secondary-feed-url="', false)
            ->assertSee('/admin/field-operations/fleet-map/feed', false)
            ->assertSee('data-show-list="0"', false)
            ->assertSee('data-live-map="retry"', false)
            ->assertSee('data-live-map="count-online">—', false)
            ->assertSee('reportMissingRuntime', false)
            ->assertSee('/assets/leaflet/1.9.4/leaflet.css', false)
            ->assertSee('/assets/leaflet/1.9.4/leaflet.js', false)
            ->assertSee('/assets/admin/driver-live-map.css', false)
            ->assertSee('/assets/admin/driver-live-map.js', false)
            ->assertSee('channel=b2b', false)
            ->assertSee('store_id='.$storeId, false)
            ->assertSee('data-live-map-cta', false)
            ->assertSee('/admin/driver-live-tracking', false);

        $this->assertSame(
            3,
            substr_count($response->getContent(), 'data-dashboard-primary-card='),
        );
    }

    public function test_retail_dashboard_scopes_compact_live_map_to_selected_authorized_store(): void
    {
        $storeId = $this->retailStore('TRACK-CARD-B2C');
        $user = $this->storeUser(
            'B2C_STORE_ADMIN',
            $storeId,
            'tracking-card-b2c@example.test',
        );

        $response = $this->actingAs($user)
            ->get('/admin/b2c/dashboard?store_id='.$storeId)
            ->assertOk()
            ->assertSee('data-dashboard-primary-card="sales"', false)
            ->assertSee('data-dashboard-primary-card="driver-map"', false)
            ->assertSee('data-dashboard-primary-card="order-distribution"', false)
            ->assertSee('data-mode="compact"', false)
            ->assertSee('data-actor-kind="mixed"', false)
            ->assertSee('/admin/field-operations/fleet-map/feed', false)
            ->assertSee('data-live-map="retry"', false)
            ->assertSee('reportMissingRuntime', false)
            ->assertSee('channel=b2c', false)
            ->assertSee('store_id='.$storeId, false)
            ->assertSee('data-live-map="count-online"', false)
            ->assertSee('data-live-map="count-stale"', false)
            ->assertSee('data-live-map="count-offline"', false);

        $this->assertSame(
            3,
            substr_count($response->getContent(), 'data-dashboard-primary-card='),
        );
    }

    public function test_retail_dashboard_without_tracking_permission_hides_map_and_map_assets(): void
    {
        $storeId = $this->retailStore('TRACK-CARD-HIDDEN');
        $user = $this->storeUser(
            'RETAIL_INVENTORY',
            $storeId,
            'tracking-card-hidden@example.test',
        );

        $response = $this->actingAs($user)
            ->get('/admin/b2c/dashboard?store_id='.$storeId)
            ->assertOk()
            ->assertDontSee('data-dashboard-primary-card="driver-map"', false)
            ->assertDontSee('data-dashboard-live-driver-map', false)
            ->assertDontSee('/assets/leaflet/1.9.4/leaflet.css', false)
            ->assertDontSee('/assets/leaflet/1.9.4/leaflet.js', false)
            ->assertDontSee('/assets/admin/driver-live-map.js', false);

        $this->assertSame(
            2,
            substr_count($response->getContent(), 'data-dashboard-primary-card='),
        );
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

    private function storeUser(string $roleCode, int $storeId, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $roleId = (int) Role::query()->where('code', $roleCode)->value('id');

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function retailStore(string $code): int
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
