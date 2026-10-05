<?php

namespace Tests\Feature;

use App\Models\FleetCurrentLocation;
use App\Services\FleetLocationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetLocationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_and_van_share_one_authoritative_model_without_losing_identity(): void
    {
        $service = app(FleetLocationService::class);

        $driver = $service->heartbeat('driver', 10, [
            'latitude' => 29.3,
            'longitude' => 47.9,
            'captured_at' => '2026-10-05T19:00:00Z',
            'source_app' => 'driver',
        ]);
        $van = $service->heartbeat('van', 20, [
            'vehicle_id' => 20,
            'latitude' => 29.4,
            'longitude' => 48.0,
            'captured_at' => '2026-10-05T19:00:01Z',
            'source_app' => 'van',
        ]);

        $this->assertSame('driver', $driver->actor_type);
        $this->assertSame(10, $driver->actor_id);
        $this->assertSame('van', $van->actor_type);
        $this->assertSame(20, $van->actor_id);
        $this->assertDatabaseCount('fleet_current_locations', 2);
    }

    public function test_older_heartbeat_cannot_replace_newer_location(): void
    {
        $service = app(FleetLocationService::class);

        $service->heartbeat('driver', 10, [
            'latitude' => 29.3,
            'longitude' => 47.9,
            'captured_at' => '2026-10-05T19:05:00Z',
            'source_app' => 'driver',
        ]);
        $location = $service->heartbeat('driver', 10, [
            'latitude' => 0.0,
            'longitude' => 0.0,
            'captured_at' => '2026-10-05T19:04:00Z',
            'source_app' => 'driver',
        ]);

        $this->assertSame(29.3, (float) $location->latitude);
        $this->assertSame(47.9, (float) $location->longitude);
        $this->assertDatabaseCount('fleet_current_locations', 1);
    }

    public function test_freshness_never_reports_stale_or_offline_locations_as_online(): void
    {
        $service = app(FleetLocationService::class);
        $location = FleetCurrentLocation::query()->create([
            'actor_type' => 'driver',
            'actor_id' => 10,
            'latitude' => 29.3,
            'longitude' => 47.9,
            'captured_at' => '2026-10-05T18:55:00Z',
            'received_at' => '2026-10-05T18:55:00Z',
            'source_app' => 'driver',
        ]);

        $at = CarbonImmutable::parse('2026-10-05T19:00:00Z');

        $this->assertSame('offline', $service->status($location, $at));
    }

    public function test_retention_prunes_only_rows_before_cutoff(): void
    {
        $service = app(FleetLocationService::class);

        FleetCurrentLocation::query()->create([
            'actor_type' => 'driver',
            'actor_id' => 1,
            'latitude' => 29.3,
            'longitude' => 47.9,
            'captured_at' => '2026-10-01T00:00:00Z',
            'received_at' => '2026-10-01T00:00:00Z',
            'source_app' => 'driver',
        ]);
        FleetCurrentLocation::query()->create([
            'actor_type' => 'van',
            'actor_id' => 2,
            'latitude' => 29.4,
            'longitude' => 48.0,
            'captured_at' => '2026-10-05T00:00:00Z',
            'received_at' => '2026-10-05T00:00:00Z',
            'source_app' => 'van',
        ]);

        $this->assertSame(1, $service->pruneBefore('2026-10-03T00:00:00Z'));
        $this->assertDatabaseCount('fleet_current_locations', 1);
        $this->assertDatabaseHas('fleet_current_locations', ['actor_type' => 'van', 'actor_id' => 2]);
    }
}
