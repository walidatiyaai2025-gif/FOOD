<?php

namespace Tests\Feature;

use Tests\TestCase;

class B2BVanFinalClosureGateTest extends TestCase
{
    public function test_all_critical_and_high_coverage_rows_are_evidenced(): void
    {
        $matrix = file_get_contents(base_path('../docs/execution/FOODEX_B2B_VAN_FULFILLMENT_COVERAGE_MATRIX.md'));
        $this->assertIsString($matrix);

        preg_match_all('/^\| (BF-\d+) \| (Critical|High) \|.*?\| ([A-Z_]+) \|$/m', $matrix, $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches, 'Expected Critical/High coverage rows.');

        $unresolved = [];
        foreach ($matches as $row) {
            if ($row[3] !== 'EVIDENCED') {
                $unresolved[] = $row[1].'='.$row[3];
            }
        }

        $this->assertSame([], $unresolved, 'Unresolved Critical/High coverage rows: '.implode(', ', $unresolved));
    }

    public function test_w16_integrated_scenario_contracts_remain_in_required_backend_suite(): void
    {
        $required = [
            'OrderTerritoryRoutingServiceTest.php' => [
                'test_post_create_coordinator_routes_supported_b2b_sources_and_never_falls_back_to_driver',
                'test_picked_up_execution_blocks_silent_reroute_to_another_van',
            ],
            'OrderManualDispatchServiceTest.php' => [
                'test_customer_service_can_assign_b2b_van_idempotently_initialize_execution_state_and_clear',
                'test_manual_dispatch_rejects_cross_channel_actor_assignment',
            ],
            'VanDeliveryExecutionTest.php' => [
                'test_van_executes_full_b2b_lifecycle_with_staged_delivery_proof',
                'test_failed_delivery_is_idempotent_and_retry_returns_order_to_out_for_delivery',
                'test_account_credit_outstanding_does_not_require_van_cash_collection_before_delivered',
            ],
            'VanCollectionControllerTest.php' => [
                'test_van_collection_and_remittance_are_authoritative_scoped_and_retry_safe',
            ],
            'B2bFinanceFilterExportTest.php' => [
                'test_van_collection_allocations_drive_invoice_paid_status_and_balance',
            ],
            'VanRegistryServiceTest.php' => [
                'test_loaded_work_requires_explicit_transfer_before_suspension',
            ],
            'DriverJourneyE2EAcceptanceTest.php' => [
                'test_dashboard_assignment_to_driver_proof_is_authoritative_notified_and_idempotent',
            ],
            'B2BVanFulfillmentCutoverTest.php' => [
                'test_apply_ends_legacy_b2b_driver_preserves_history_and_routes_to_van_idempotently',
                'test_b2b_without_eligible_van_becomes_explicit_awaiting_dispatch_and_retry_is_safe',
            ],
        ];

        foreach ($required as $file => $methods) {
            $source = file_get_contents(base_path('tests/Feature/'.$file));
            $this->assertIsString($source, 'Missing W16 scenario suite '.$file);

            foreach ($methods as $method) {
                $this->assertStringContainsString(
                    'function '.$method.'(',
                    $source,
                    $file.' no longer contains '.$method,
                );
            }
        }
    }

    public function test_w16_rows_are_evidenced_before_umbrella_closure(): void
    {
        $matrix = file_get_contents(base_path('../docs/execution/FOODEX_B2B_VAN_FULFILLMENT_COVERAGE_MATRIX.md'));
        $this->assertIsString($matrix);

        foreach (range(81, 87) as $number) {
            $id = sprintf('BF-%03d', $number);
            $this->assertMatchesRegularExpression(
                '/^\| '.preg_quote($id, '/').' \|.*\| EVIDENCED \|$/m',
                $matrix,
                $id.' must be EVIDENCED before #1190 may close.',
            );
        }
    }
}
