<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('/vendor/leaflet/1.9.4/leaflet.css', false)
            ->assertSee('/vendor/leaflet/1.9.4/leaflet.js', false)
            ->assertDontSee('unpkg.com', false)
            ->assertSee('/admin/driver-live-tracking/feed', false);
    }

    public function test_dashboard_web_session_can_read_live_tracking_feed(): void
    {
        $user = $this->globalUser('SUPER_ADMIN', 'tracking-feed@example.test');
        app(\App\Services\WholesalePrincipal::class)->storeId();

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
