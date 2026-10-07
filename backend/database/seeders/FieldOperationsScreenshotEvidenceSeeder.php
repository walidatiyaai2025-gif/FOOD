<?php

namespace Database\Seeders;

use App\Models\AddressQualityReview;
use App\Models\FleetCurrentLocation;
use App\Models\GeographyNode;
use App\Models\ServiceTerritory;
use App\Models\TerritoryGeometry;
use App\Models\User;
use App\Models\Van;
use App\Models\VanAssignment;
use App\Models\VanVisit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class FieldOperationsScreenshotEvidenceSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()
            ->where('email', 'screenshots@foodex.test')
            ->firstOrFail();

        $country = GeographyNode::query()->updateOrCreate(
            [
                'country_code' => 'KW',
                'type' => 'country',
                'code' => 'KW',
            ],
            [
                'name_ar' => 'الكويت',
                'name_en' => 'Kuwait',
                'is_active' => true,
            ],
        );

        $territory = ServiceTerritory::query()->updateOrCreate(
            ['code' => 'EVID-KW-1'],
            [
                'name_ar' => 'منطقة تشغيل تجريبية',
                'name_en' => 'Runtime Evidence Territory',
                'country_node_id' => $country->id,
                'status' => 'active',
                'priority' => 10,
            ],
        );

        TerritoryGeometry::query()->updateOrCreate(
            [
                'service_territory_id' => $territory->id,
                'version' => 1,
            ],
            [
                'geometry_type' => 'Polygon',
                'geojson' => [
                    'type' => 'Polygon',
                    'coordinates' => [[
                        [47.9700, 29.3600],
                        [47.9900, 29.3600],
                        [47.9900, 29.3800],
                        [47.9700, 29.3800],
                        [47.9700, 29.3600],
                    ]],
                ],
                'min_lat' => 29.3600,
                'max_lat' => 29.3800,
                'min_lng' => 47.9700,
                'max_lng' => 47.9900,
                'is_active' => true,
                'created_by' => $admin->id,
            ],
        );

        $van = Van::query()->updateOrCreate(
            ['code' => 'EVID-VAN-1'],
            [
                'public_id' => (string) Str::uuid(),
                'plate_number' => 'EVID-1037',
                'vehicle_type' => 'delivery',
                'status' => 'active',
                'capacity_units' => 120,
                'capacity_weight' => 750,
                'capabilities' => ['delivery', 'collection'],
            ],
        );

        VanAssignment::query()->updateOrCreate(
            [
                'van_id' => $van->id,
                'status' => 'active',
            ],
            [
                'public_id' => (string) Str::uuid(),
                'representative_user_id' => $admin->id,
                'territory_key' => $territory->code,
                'assignment_type' => 'primary',
                'effective_from' => now()->subHour(),
                'loaded_work_count' => 2,
                'created_by' => $admin->id,
            ],
        );

        FleetCurrentLocation::query()->updateOrCreate(
            [
                'actor_type' => 'van',
                'actor_id' => $van->id,
            ],
            [
                'vehicle_id' => $van->id,
                'latitude' => 29.3700,
                'longitude' => 47.9800,
                'accuracy' => 6,
                'speed' => 18,
                'heading' => 90,
                'captured_at' => now()->subMinute(),
                'received_at' => now(),
                'source_app' => 'van',
                'app_version' => '1.0.60',
                'is_mocked' => false,
            ],
        );

        VanVisit::query()->updateOrCreate(
            ['idempotency_key' => 'fieldops-screenshot-evidence-visit'],
            [
                'actor_user_id' => $admin->id,
                'customer_type' => 'b2c',
                'customer_id' => 1037,
                'status' => 'planned',
                'planned_at' => now()->addHour(),
                'metadata' => [
                    'route_key' => 'EVID-ROUTE-1',
                    'customer_label' => 'FOODEX Evidence Customer',
                ],
            ],
        );

        AddressQualityReview::query()->updateOrCreate(
            [
                'subject_type' => 'customer_address',
                'subject_id' => 1037,
            ],
            [
                'public_id' => (string) Str::uuid(),
                'status' => 'unmapped',
                'quality_class' => 'needs_review',
                'confidence' => 0.42,
                'territory_key' => $territory->code,
                'resolution_source' => 'evidence',
                'reason' => 'Deterministic runtime evidence fixture',
            ],
        );
    }
}
