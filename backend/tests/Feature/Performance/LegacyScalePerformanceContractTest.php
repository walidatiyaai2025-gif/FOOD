<?php

namespace Tests\Feature\Performance;

use Database\Seeders\EnterprisePerformanceSeeder;
use PHPUnit\Framework\TestCase;

final class LegacyScalePerformanceContractTest extends TestCase
{
    public function test_legacy_4x_profile_is_exactly_four_times_the_baseline_volume(): void
    {
        $profiles = EnterprisePerformanceSeeder::profiles();
        $baseline = $profiles['legacy1x'];
        $scaled = $profiles['legacy4x'];

        foreach ([
            'products',
            'customers',
            'orders',
            'order_items',
            'stock_movements',
            'finance_records',
            'audit_events_messages',
        ] as $metric) {
            $this->assertSame(
                $baseline[$metric] * 4,
                $scaled[$metric],
                $metric.' must preserve the reproducible 4x scale contract.',
            );
        }
    }

    public function test_legacy_4x_profile_meets_issue_2274_reference_volume(): void
    {
        $scaled = EnterprisePerformanceSeeder::profiles()['legacy4x'];

        $this->assertGreaterThanOrEqual(14304, $scaled['customers']);
        $this->assertGreaterThanOrEqual(749056, $scaled['order_items']);
        $this->assertGreaterThanOrEqual(99800, $scaled['finance_records']);
        $this->assertGreaterThanOrEqual(250000, $scaled['stock_movements']);
        $this->assertGreaterThanOrEqual(250000, $scaled['audit_events_messages']);
    }

    public function test_issue_2274_workload_keeps_headroom_above_expected_users(): void
    {
        $this->assertSame(50, EnterprisePerformanceSeeder::LOAD_USERS);
        $this->assertSame(25, EnterprisePerformanceSeeder::FIELD_USERS);
        $this->assertGreaterThanOrEqual(35, EnterprisePerformanceSeeder::LOAD_USERS);
    }

    public function test_driver_current_location_evidence_is_classified_as_bounded_current_state(): void
    {
        $backendRoot = dirname(__DIR__, 3);
        $repositoryRoot = dirname($backendRoot);

        $migration = file_get_contents(
            $backendRoot.'/database/migrations/2026_09_30_005120_create_driver_current_locations.php',
        );
        $evidenceRunner = file_get_contents(
            $repositoryRoot.'/scripts/foodex-legacy-scale-db-evidence.php',
        );

        $this->assertIsString($migration);
        $this->assertIsString($evidenceRunner);
        $this->assertStringContainsString("foreignId('driver_id')->unique()", $migration);
        $this->assertStringContainsString("'field_tracking_current'", $evidenceRunner);
        $this->assertStringContainsString("'growing_tables' => []", $evidenceRunner);
        $this->assertStringContainsString('Bounded current-state table', $evidenceRunner);
    }
}
