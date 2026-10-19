<?php

namespace Tests\Unit;

use App\Domain\Analytics\Diagnostics\DeterministicAnalyticsEngine;
use PHPUnit\Framework\TestCase;

final class DeterministicAnalyticsEngineTest extends TestCase
{
    public function test_golden_comparison_trend_mix_inventory_and_exception_math_is_reproducible(): void
    {
        $engine = new DeterministicAnalyticsEngine;

        $result = $engine->analyze(
            $this->payload([
                $this->fact('2026-10-13', 10, 9, 110, 10, 100, 20, 10, 1, 5, 1),
                $this->fact('2026-10-14', 12, 11, 130, 10, 120, 24),
                $this->fact('2026-10-15', 14, 13, 150, 10, 140, 28),
            ], 3, [[
                'store_id' => 1,
                'snapshot_date' => '2026-10-15',
                'available_units' => 72,
                'quality_state' => 'complete',
            ]]),
            $this->payload([
                $this->fact('2026-10-10', 8, 8, 90, 10, 80, 16),
                $this->fact('2026-10-11', 8, 8, 90, 10, 80, 16),
                $this->fact('2026-10-12', 8, 8, 90, 10, 80, 16),
            ], 3),
            [
                'sku' => [
                    [
                        'key' => 'SKU-A',
                        'label' => 'SKU A',
                        'current_revenue' => 240,
                        'previous_revenue' => 120,
                        'current_units' => 30,
                        'previous_units' => 15,
                        'current_orders' => 12,
                        'previous_orders' => 6,
                    ],
                    [
                        'key' => 'SKU-B',
                        'label' => 'SKU B',
                        'current_revenue' => 120,
                        'previous_revenue' => 120,
                        'current_units' => 18,
                        'previous_units' => 18,
                        'current_orders' => 8,
                        'previous_orders' => 8,
                    ],
                ],
            ],
            [
                'cost_covered_revenue' => 200.0,
                'cogs' => 110.0,
                'gross_margin' => 90.0,
                'quality_state' => 'complete',
                'cost_coverage_percent' => 100.0,
            ],
            [
                'cost_covered_revenue' => 200.0,
                'cogs' => 140.0,
                'gross_margin' => 60.0,
                'quality_state' => 'complete',
                'cost_coverage_percent' => 100.0,
            ],
        );

        $this->assertSame(DeterministicAnalyticsEngine::SCHEMA_VERSION, $result['schema_version']);
        $this->assertSame('sufficient', $result['evidence']['state']);
        $this->assertSame(360.0, $result['totals']['current']['net_revenue']);
        $this->assertSame(240.0, $result['totals']['previous']['net_revenue']);
        $this->assertSame(120.0, $result['comparison']['net_revenue']['delta']);
        $this->assertSame(50.0, $result['comparison']['net_revenue']['percent_change']);
        $this->assertSame('up', $result['comparison']['net_revenue']['direction']);

        $this->assertTrue($result['trend']['net_revenue']['available']);
        $this->assertSame(20.0, $result['trend']['net_revenue']['slope_per_day']);
        $this->assertSame(16.67, $result['trend']['net_revenue']['velocity_percent_per_day']);

        $this->assertSame(2.78, $result['exception_drivers']['refund_rate_percent']['current']);
        $this->assertSame(10.0, $result['exception_drivers']['top_refund_contributors'][0]['value']);
        $this->assertSame(5.0, $result['exception_drivers']['top_cancellation_contributors'][0]['value']);

        $this->assertSame(24.0, $result['inventory_coverage'][0]['daily_unit_velocity']);
        $this->assertSame(3.0, $result['inventory_coverage'][0]['coverage_days']);
        $this->assertSame('low_cover', $result['inventory_coverage'][0]['coverage_state']);

        $this->assertSame(16.67, $result['breakdowns']['sku']['rows'][0]['mix_shift_percentage_points']);
        $this->assertSame(100.0, $result['breakdowns']['sku']['rows'][0]['contribution_to_revenue_change_percent']);
        $this->assertSame(30.0, $result['margin']['gross_margin_change']['delta']);
        $this->assertSame(0.0, $result['margin']['cost_covered_revenue_change']['delta']);
        $this->assertSame(-30.0, $result['margin']['cogs_change']['delta']);
        $this->assertFalse($result['guardrails']['model_arithmetic_allowed']);
        $this->assertFalse($result['guardrails']['model_may_override_computed_values']);
    }

    public function test_zero_previous_denominator_never_fabricates_a_percentage(): void
    {
        $engine = new DeterministicAnalyticsEngine;

        $current = $this->payload([
            $this->fact('2026-10-13', 1, 1, 10, 0, 10, 1),
            $this->fact('2026-10-14', 1, 1, 20, 0, 20, 1),
            $this->fact('2026-10-15', 1, 1, 30, 0, 30, 1),
        ], 3);
        $previous = $this->payload([
            $this->fact('2026-10-10', 0, 0, 0, 0, 0, 0),
            $this->fact('2026-10-11', 0, 0, 0, 0, 0, 0),
            $this->fact('2026-10-12', 0, 0, 0, 0, 0, 0),
        ], 3);

        $result = $engine->analyze($current, $previous);

        $this->assertNull($result['comparison']['net_revenue']['percent_change']);
        $this->assertSame(
            'zero_previous_denominator',
            $result['comparison']['net_revenue']['percent_change_reason'],
        );
        $this->assertSame(60.0, $result['comparison']['net_revenue']['delta']);
    }

    public function test_missing_previous_period_returns_insufficient_evidence_instead_of_a_cause(): void
    {
        $engine = new DeterministicAnalyticsEngine;

        $result = $engine->analyze(
            $this->payload([
                $this->fact('2026-10-13', 1, 1, 10, 0, 10, 1),
                $this->fact('2026-10-14', 1, 1, 10, 0, 10, 1),
                $this->fact('2026-10-15', 1, 1, 10, 0, 10, 1),
            ], 3),
            null,
            previousUnavailableReason: 'comparison_window_not_authorized_or_available',
        );

        $this->assertSame('insufficient', $result['evidence']['state']);
        $this->assertContains(
            'comparison_window_not_authorized_or_available',
            $result['evidence']['reasons'],
        );
        $this->assertNull($result['comparison']['net_revenue']['previous']);
        $this->assertNull($result['comparison']['net_revenue']['delta']);
        $this->assertSame('unknown', $result['comparison']['net_revenue']['direction']);
        $this->assertSame('contribution_only_not_causation', $result['exception_drivers']['interpretation']);
    }

    public function test_sparse_or_incomplete_data_is_explicitly_low_confidence(): void
    {
        $engine = new DeterministicAnalyticsEngine;

        $current = $this->payload([
            $this->fact('2026-10-15', 2, 1, 10, 0, 10, 1),
        ], 1);
        $current['freshness']['state'] = 'incomplete';

        $result = $engine->analyze($current, null);

        $this->assertSame('insufficient', $result['evidence']['state']);
        $this->assertSame('low', $result['evidence']['confidence']['level']);
        $this->assertContains('current_data_incomplete', $result['evidence']['reasons']);
        $this->assertContains('sparse_current_window', $result['evidence']['reasons']);
        $this->assertFalse($result['trend']['net_revenue']['available']);
        $this->assertSame('insufficient_points', $result['trend']['net_revenue']['reason']);
    }

    /** @param list<array<string,mixed>> $facts
     * @param  list<array<string,mixed>>  $inventory
     * @return array<string,mixed>
     */
    private function payload(array $facts, int $days, array $inventory = []): array
    {
        return [
            'metric_dictionary_version' => '1.0',
            'channel' => 'b2c',
            'flow' => 'sell_out',
            'timezone' => 'Asia/Kuwait',
            'window' => [
                'from' => $facts[0]['date'],
                'to' => $facts[count($facts) - 1]['date'],
                'days' => $days,
            ],
            'store_ids' => [1],
            'facts' => $facts,
            'inventory_snapshots' => $inventory,
            'freshness' => [
                'state' => 'fresh',
                'expected_rows' => count($facts),
                'actual_rows' => count($facts),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function fact(
        string $date,
        int $orders,
        int $recognizedOrders,
        float $grossRevenue,
        float $discounts,
        float $netRevenue,
        float $units,
        float $refundedValue = 0,
        int $refundedOrders = 0,
        float $cancelledValue = 0,
        int $cancelledOrders = 0,
    ): array {
        return [
            'store_id' => 1,
            'channel' => 'b2c',
            'flow' => 'sell_out',
            'date' => $date,
            'orders' => $orders,
            'recognized_orders' => $recognizedOrders,
            'gross_value' => $netRevenue + $refundedValue + $cancelledValue,
            'gross_revenue' => $grossRevenue,
            'discounts' => $discounts,
            'net_revenue' => $netRevenue,
            'refunded_value' => $refundedValue,
            'refunded_orders' => $refundedOrders,
            'cancelled_value' => $cancelledValue,
            'cancelled_orders' => $cancelledOrders,
            'units' => $units,
            'average_order_value' => $recognizedOrders === 0 ? 0 : $netRevenue / $recognizedOrders,
            'payments_collected' => $netRevenue,
            'payments_refunded' => $refundedValue,
            'wholesale_purchases' => 0,
            'wholesale_purchase_units' => 0,
            'currency' => 'KWD',
            'quality_state' => 'complete',
            'incomplete_reason' => null,
            'materialized_at' => '2026-10-15 12:00:00',
        ];
    }
}
