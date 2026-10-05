<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Services\ServiceTerritoryResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServiceTerritoryResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_geometry_overlap_is_resolved_deterministically_by_priority(): void
    {
        $address = $this->address(30.05, 31.25);

        $low = $this->territory('EG-LOW', 10, $this->square(31.0, 30.0, 31.5, 30.5));
        $high = $this->territory('EG-HIGH', 20, $this->square(31.0, 30.0, 31.5, 30.5));

        $result = app(ServiceTerritoryResolutionService::class)->resolve($address);

        $this->assertSame($high, $result['territory']['id']);
        $this->assertSame([$high, $low], $result['candidate_territory_ids']);
        $this->assertSame('highest_priority_overlap_candidate', $result['chosen_reason']);
        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'resolved_service_territory_id' => $high,
            'territory_resolution_source' => 'geometry',
            'serviceability_status' => 'serviceable',
        ]);
    }

    public function test_explicit_active_override_wins_over_geometry(): void
    {
        $address = $this->address(30.05, 31.25);
        $geometryWinner = $this->territory('EG-GEO', 100, $this->square(31.0, 30.0, 31.5, 30.5));
        $override = $this->territory('EG-OVERRIDE', 1, $this->square(32.0, 31.0, 32.5, 31.5));

        DB::table('address_territory_overrides')->insert([
            'address_id' => $address->id,
            'service_territory_id' => $override,
            'status' => 'active',
            'reason' => 'Admin-approved exception',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(ServiceTerritoryResolutionService::class)->resolve($address);

        $this->assertSame($override, $result['territory']['id']);
        $this->assertSame('override', $result['source']);
        $this->assertNotSame($geometryWinner, $result['territory']['id']);
    }

    public function test_admin_mapping_is_used_when_coordinates_are_unavailable(): void
    {
        $address = $this->address(null, null, 'Cairo', 'Nasr City');
        $territory = $this->territory('EG-ADMIN', 5, $this->square(31.0, 30.0, 31.5, 30.5));

        DB::table('territory_admin_mappings')->insert([
            'country_code' => 'EG',
            'city' => 'Cairo',
            'area' => 'Nasr City',
            'service_territory_id' => $territory,
            'status' => 'active',
            'priority' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(ServiceTerritoryResolutionService::class)->resolve($address);

        $this->assertSame($territory, $result['territory']['id']);
        $this->assertSame('admin_mapping', $result['source']);
    }

    public function test_unmapped_address_becomes_auditable_serviceability_exception(): void
    {
        $address = $this->address(null, null, 'Unknown', null);

        $result = app(ServiceTerritoryResolutionService::class)->resolve($address);

        $this->assertNull($result['territory']);
        $this->assertSame('unserviceable_exception', $result['serviceability_status']);
        $this->assertDatabaseHas('territory_resolution_traces', [
            'address_id' => $address->id,
            'source' => 'exception',
            'chosen_service_territory_id' => null,
        ]);
    }

    private function address(?float $latitude, ?float $longitude, string $city = 'Cairo', ?string $area = 'Nasr City'): Address
    {
        $customerId = DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => 'Territory Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Address::query()->create([
            'customer_id' => $customerId,
            'line1' => 'Test address',
            'city' => $city,
            'area' => $area,
            'country_code' => 'EG',
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    private function territory(string $code, int $priority, array $geometry): int
    {
        return (int) DB::table('service_territories')->insertGetId([
            'country_code' => 'EG',
            'code' => $code,
            'name_en' => $code,
            'name_ar' => $code,
            'geometry' => json_encode($geometry, JSON_THROW_ON_ERROR),
            'status' => 'active',
            'priority' => $priority,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function square(float $minLon, float $minLat, float $maxLon, float $maxLat): array
    {
        return [
            'type' => 'Polygon',
            'coordinates' => [[
                [$minLon, $minLat],
                [$maxLon, $minLat],
                [$maxLon, $maxLat],
                [$minLon, $maxLat],
                [$minLon, $minLat],
            ]],
        ];
    }
}
