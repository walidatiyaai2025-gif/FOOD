<?php

namespace Tests\Feature;

use App\Models\GeographyNode;
use App\Models\ServiceTerritory;
use App\Services\TerritoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TerritoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_more_specific_overlap_is_resolved_deterministically_by_priority(): void
    {
        $service = app(TerritoryService::class);
        $country = GeographyNode::query()->create([
            'type' => 'country', 'code' => 'market-1', 'name_ar' => 'سوق', 'name_en' => 'Market',
            'country_code' => 'M1', 'is_active' => true,
        ]);

        $low = ServiceTerritory::query()->create([
            'code' => 'zone-low', 'name_ar' => 'أ', 'name_en' => 'Low', 'country_node_id' => $country->id,
            'status' => 'active', 'priority' => 10,
        ]);
        $high = ServiceTerritory::query()->create([
            'code' => 'zone-high', 'name_ar' => 'ب', 'name_en' => 'High', 'country_node_id' => $country->id,
            'status' => 'active', 'priority' => 20,
        ]);

        $square = ['type' => 'Polygon', 'coordinates' => [[
            [30.0, 30.0], [31.0, 30.0], [31.0, 31.0], [30.0, 31.0], [30.0, 30.0],
        ]]];
        $service->addGeometry($low, $square);
        $service->addGeometry($high, $square);

        $result = $service->resolveAddress('test', 'address-1', 30.5, 30.5);

        $this->assertSame($high->id, $result['territory_id']);
        $this->assertSame([$low->id, $high->id], collect($result['candidate_territory_ids'])->sort()->values()->all());
        $this->assertSame('point_in_polygon', $result['source']);
        $this->assertNotEmpty($result['chosen_reason']);
    }

    public function test_explicit_override_wins_before_geometry(): void
    {
        $service = app(TerritoryService::class);
        $country = GeographyNode::query()->create([
            'type' => 'country', 'code' => 'market-2', 'name_ar' => 'سوق', 'name_en' => 'Market',
            'country_code' => 'M2', 'is_active' => true,
        ]);
        $override = ServiceTerritory::query()->create([
            'code' => 'override-zone', 'name_ar' => 'ج', 'name_en' => 'Override', 'country_node_id' => $country->id,
            'status' => 'active', 'priority' => 1,
        ]);

        $result = $service->resolveAddress('test', 'address-2', null, null, $override->id);

        $this->assertSame($override->id, $result['territory_id']);
        $this->assertSame('explicit_override', $result['source']);
    }

    public function test_unmapped_address_is_explicit_exception_not_free_text_guess(): void
    {
        $result = app(TerritoryService::class)->resolveAddress('test', 'address-3', null, null);

        $this->assertNull($result['territory_id']);
        $this->assertSame('unmapped', $result['serviceability_status']);
        $this->assertSame('unmapped', $result['source']);
    }

    public function test_future_territory_is_not_used_early(): void
    {
        $service = app(TerritoryService::class);
        $country = GeographyNode::query()->create([
            'type' => 'country', 'code' => 'market-3', 'name_ar' => 'سوق', 'name_en' => 'Market',
            'country_code' => 'M3', 'is_active' => true,
        ]);
        $future = ServiceTerritory::query()->create([
            'code' => 'future-zone', 'name_ar' => 'د', 'name_en' => 'Future', 'country_node_id' => $country->id,
            'status' => 'active', 'priority' => 100, 'effective_from' => '2026-11-01 00:00:00',
        ]);
        $service->addGeometry($future, ['type' => 'Polygon', 'coordinates' => [[
            [30.0, 30.0], [31.0, 30.0], [31.0, 31.0], [30.0, 31.0], [30.0, 30.0],
        ]]]);

        $result = $service->resolveAddress('test', 'address-4', 30.5, 30.5, at: '2026-10-05T00:00:00Z');

        $this->assertNull($result['territory_id']);
        $this->assertSame('unmapped', $result['source']);
    }
}
