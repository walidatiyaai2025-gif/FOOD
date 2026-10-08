<?php

namespace Tests\Feature;

use App\Models\AppVersion;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\DriverCurrentLocation;
use App\Models\Role;
use App\Models\User;
use App\Services\DriverLocationEnforcementPolicy;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverFreshLocationEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-30 08:30:00'));
        $this->seed(CoreReferenceSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_truthy_runtime_config_cannot_enable_enforcement_without_persisted_policy(): void
    {
        config()->set('driver_location.enforcement_default', true);

        $this->assertFalse(app(DriverLocationEnforcementPolicy::class)->enabled());

        [$user] = $this->b2bDriver('location-env-bypass@example.test');
        Sanctum::actingAs($user, ['app:driver']);

        $this->getJson('/api/v1/driver/assignments')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_enforcement_off_preserves_existing_driver_operational_access(): void
    {
        [$user] = $this->b2bDriver('location-off@example.test');
        Sanctum::actingAs($user, ['app:driver']);

        $this->getJson('/api/v1/driver/assignments')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_missing_and_stale_locations_are_blocked_with_stable_recovery_contract(): void
    {
        [$user, $driver, $storeId] = $this->b2bDriver('location-required@example.test');
        $this->enablePolicy();
        Sanctum::actingAs($user, ['app:driver']);

        $missing = $this->getJson('/api/v1/driver/assignments')
            ->assertStatus(428)
            ->assertJsonPath('code', 'DRIVER_LOCATION_HEARTBEAT_REQUIRED')
            ->assertJsonPath('reason', 'missing')
            ->assertJsonPath('freshness_seconds', 90);

        $this->assertStringNotContainsString('latitude', $missing->getContent());
        $this->assertStringNotContainsString('longitude', $missing->getContent());

        $this->location(
            $driver,
            $storeId,
            'b2b',
            receivedAt: now()->subSeconds(91),
        );

        $stale = $this->getJson('/api/v1/driver/assignments')
            ->assertStatus(428)
            ->assertJsonPath('code', 'DRIVER_LOCATION_HEARTBEAT_REQUIRED')
            ->assertJsonPath('reason', 'stale');

        $this->assertStringNotContainsString('latitude', $stale->getContent());
        $this->assertStringNotContainsString('longitude', $stale->getContent());
    }

    public function test_server_received_at_controls_freshness_boundary_not_device_capture_time(): void
    {
        [$user, $driver, $storeId] = $this->b2bDriver('location-boundary@example.test');
        $this->enablePolicy();
        Sanctum::actingAs($user, ['app:driver']);

        $location = $this->location(
            $driver,
            $storeId,
            'b2b',
            capturedAt: now()->subHours(4),
            receivedAt: now()->subSeconds(90),
        );

        $this->getJson('/api/v1/driver/assignments')->assertOk();

        $location->forceFill([
            'captured_at' => now(),
            'received_at' => now()->subSeconds(91),
        ])->save();

        $this->getJson('/api/v1/driver/assignments')
            ->assertStatus(428)
            ->assertJsonPath('reason', 'stale');
    }

    public function test_scope_mismatch_cannot_satisfy_enforcement(): void
    {
        [$user, $driver] = $this->b2bDriver('location-scope@example.test');
        $this->enablePolicy();
        Sanctum::actingAs($user, ['app:driver']);

        $b2bTypeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $otherStore = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2bTypeId,
            'code' => 'LOCATION-OTHER',
            'name' => 'Location Other',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->location($driver, $otherStore, 'b2b');

        $this->getJson('/api/v1/driver/assignments')
            ->assertStatus(428)
            ->assertJsonPath('code', 'DRIVER_LOCATION_HEARTBEAT_REQUIRED')
            ->assertJsonPath('reason', 'scope_mismatch');
    }

    public function test_another_drivers_fresh_location_cannot_satisfy_enforcement(): void
    {
        [$targetUser] = $this->b2bDriver('location-target@example.test');
        [, $otherDriver, $storeId] = $this->b2bDriver('location-other@example.test');
        $this->enablePolicy();

        $this->location($otherDriver, $storeId, 'b2b');

        Sanctum::actingAs($targetUser, ['app:driver']);
        $this->getJson('/api/v1/driver/assignments')
            ->assertStatus(428)
            ->assertJsonPath('code', 'DRIVER_LOCATION_HEARTBEAT_REQUIRED')
            ->assertJsonPath('reason', 'missing');
    }

    public function test_b2c_driver_with_fresh_same_store_location_keeps_operational_access(): void
    {
        [$user, $driver, $storeId] = $this->b2cDriver('location-b2c@example.test');
        $this->enablePolicy();
        $this->location($driver, $storeId, 'b2c');

        Sanctum::actingAs($user, ['app:driver']);
        $this->getJson('/api/v1/driver/assignments')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_heartbeat_remains_reachable_and_recovers_blocked_driver(): void
    {
        [$user, $driver, $storeId] = $this->b2bDriver('location-recovery@example.test');
        $this->enablePolicy();
        Sanctum::actingAs($user, ['app:driver']);

        $this->getJson('/api/v1/driver/assignments')
            ->assertStatus(428)
            ->assertJsonPath('reason', 'missing');

        $this->postJson('/api/v1/driver/location/heartbeat', [
            'latitude' => 29.3759,
            'longitude' => 47.9774,
            'accuracy' => 5,
            'captured_at' => now()->toISOString(),
            'app_version' => '1.0.38+38',
        ])
            ->assertOk()
            ->assertJsonPath('data.driver_id', $driver->id)
            ->assertJsonPath('data.store_id', $storeId);

        $this->getJson('/api/v1/driver/assignments')->assertOk();
    }

    public function test_admin_cannot_enable_enforcement_before_driver_rollout_policy_is_ready(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN', 'location-policy-blocked@example.test');

        $this->actingAs($admin)
            ->put('/admin/settings/mobile/driver-location-policy', [
                'enabled' => '1',
                'freshness_seconds' => 90,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['enabled']);

        $this->assertFalse(app(DriverLocationEnforcementPolicy::class)->enabled());
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'driver.location_enforcement.policy_updated',
        ]);
    }

    public function test_mobile_settings_toggle_is_audited_and_rollback_is_immediate(): void
    {
        [$driverUser] = $this->b2bDriver('location-rollback-driver@example.test');
        $admin = $this->globalUser('SUPER_ADMIN', 'location-policy-admin@example.test');
        $this->makeDriverRolloutReady();

        $this->actingAs($admin)
            ->put('/admin/settings/mobile/driver-location-policy', [
                'enabled' => '1',
                'freshness_seconds' => 120,
            ])
            ->assertRedirect();

        $policy = app(DriverLocationEnforcementPolicy::class);
        $this->assertTrue($policy->enabled());
        $this->assertSame(120, $policy->freshnessSeconds());

        $audit = AuditLog::query()
            ->where('event', 'driver.location_enforcement.policy_updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertFalse((bool) data_get($audit->before, 'enabled'));
        $this->assertTrue((bool) data_get($audit->after, 'enabled'));
        $this->assertSame(120, (int) data_get($audit->after, 'freshness_seconds'));

        Sanctum::actingAs($driverUser, ['app:driver']);
        $this->getJson('/api/v1/driver/assignments')->assertStatus(428);

        $this->actingAs($admin)
            ->put('/admin/settings/mobile/driver-location-policy', [
                'freshness_seconds' => 120,
            ])
            ->assertRedirect();

        $this->assertFalse(app(DriverLocationEnforcementPolicy::class)->enabled());

        Sanctum::actingAs($driverUser, ['app:driver']);
        $this->getJson('/api/v1/driver/assignments')->assertOk();

        $this->assertSame(
            2,
            AuditLog::query()
                ->where('event', 'driver.location_enforcement.policy_updated')
                ->count(),
        );
    }

    /** @return array{0: User, 1: Driver, 2: int} */
    private function b2bDriver(string $email): array
    {
        $user = $this->globalUser('B2B_DRIVER', $email);
        $storeId = app(WholesalePrincipal::class)->storeId();

        $driver = Driver::query()->create([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);

        return [$user, $driver, $storeId];
    }

    /** @return array{0: User, 1: Driver, 2: int} */
    private function b2cDriver(string $email): array
    {
        $user = $this->globalUser('B2C_DRIVER', $email);
        $storeTypeId = (int) DB::table('store_types')
            ->where('code', 'B2C')
            ->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'LOC-B2C-'.strtoupper(substr(md5($email), 0, 8)),
            'name' => 'Location B2C Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $driver = Driver::query()->create([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        return [$user, $driver, $storeId];
    }

    private function globalUser(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function enablePolicy(int $freshnessSeconds = 90): void
    {
        app(DriverLocationEnforcementPolicy::class)->persist(true, $freshnessSeconds);
    }

    private function makeDriverRolloutReady(): void
    {
        foreach (['android', 'ios'] as $platform) {
            AppVersion::query()->updateOrCreate(
                ['app' => 'driver', 'platform' => $platform],
                [
                    'latest_version' => '1.0.38',
                    'minimum_supported_version' => '1.0.38',
                    'force_update' => true,
                    'store_url' => $platform === 'android'
                        ? 'https://example.test/driver/android'
                        : 'https://example.test/driver/ios',
                    'release_notes' => 'Heartbeat-capable Driver release',
                ],
            );
        }
    }

    private function location(
        Driver $driver,
        int $storeId,
        string $channel,
        ?Carbon $capturedAt = null,
        ?Carbon $receivedAt = null,
    ): DriverCurrentLocation {
        return DriverCurrentLocation::query()->create([
            'driver_id' => $driver->id,
            'store_id' => $storeId,
            'channel' => $channel,
            'latitude' => 29.3759,
            'longitude' => 47.9774,
            'accuracy' => 5,
            'captured_at' => $capturedAt ?? now(),
            'received_at' => $receivedAt ?? now(),
            'app_version' => '1.0.38+38',
            'is_mocked' => false,
        ]);
    }
}
