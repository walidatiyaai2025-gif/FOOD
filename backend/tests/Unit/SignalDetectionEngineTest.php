<?php

namespace Tests\Unit;

use App\Domain\Analytics\Signals\SignalDetectionEngine;
use PHPUnit\Framework\TestCase;

final class SignalDetectionEngineTest extends TestCase
{
    public function test_synthetic_anomalies_are_reproducible_and_scope_safe(): void
    {
        $engine = new SignalDetectionEngine;
        $diagnostic = $this->diagnostic(-30, -25, 12, 6, 9, 4);
        $diagnostic['inventory_coverage'] = [
            ['store_id' => 10, 'available_units' => 0, 'coverage_days' => 0, 'coverage_state' => 'out_of_stock'],
            ['store_id' => 11, 'available_units' => 900, 'coverage_days' => 45, 'coverage_state' => 'high_cover'],
            ['store_id' => 999, 'available_units' => 0, 'coverage_days' => 0, 'coverage_state' => 'out_of_stock'],
        ];
        $diagnostic['breakdowns'] = ['sku' => ['rows' => [
            ['key' => 'SKU-A', 'label' => 'SKU A', 'mix_shift_percentage_points' => 18],
            ['key' => 'SKU-B', 'label' => 'SKU B', 'mix_shift_percentage_points' => -12],
        ]]];

        $first = $engine->detect($diagnostic, evaluatedAt: '2026-10-18T20:00:00+00:00');
        $second = $engine->detect($diagnostic, evaluatedAt: '2026-10-18T20:00:00+00:00');

        $codes = array_column($first['signals'], 'code');
        foreach (['revenue_drop', 'orders_drop', 'refund_rate_increase', 'cancellation_rate_increase', 'stockout', 'overstock_risk', 'sku_mix_gain', 'sku_mix_loss'] as $code) {
            $this->assertContains($code, $codes);
        }
        $this->assertSame(array_column($first['signals'], 'fingerprint'), array_column($second['signals'], 'fingerprint'));
        $this->assertSame([10, 11], $first['scope']['store_ids']);

        foreach ($first['signals'] as $signal) {
            foreach ($signal['affected_entities'] as $entity) {
                if (($entity['type'] ?? null) === 'store') {
                    $this->assertNotSame(999, (int) $entity['id']);
                }
            }
        }
        $this->assertFalse($first['guardrails']['model_arithmetic_allowed']);
        $this->assertFalse($first['guardrails']['cross_tenant_baselines_allowed']);
    }

    public function test_false_positive_and_low_quality_controls_suppress_noise(): void
    {
        $engine = new SignalDetectionEngine;
        $this->assertSame([], $engine->detect(
            $this->diagnostic(-5, 4),
            evaluatedAt: '2026-10-18T20:00:00+00:00',
        )['signals']);

        $lowQuality = $this->diagnostic(-80, -70);
        $lowQuality['evidence']['state'] = 'insufficient';
        $lowQuality['evidence']['confidence'] = ['score' => 0.4, 'level' => 'low'];
        $lowQuality['evidence']['reasons'] = ['sparse_current_window'];

        $result = $engine->detect($lowQuality, evaluatedAt: '2026-10-18T20:00:00+00:00');
        $this->assertSame([], $result['signals']);
        $this->assertSame('insufficient_evidence', $result['suppressed'][0]['reason']);
    }

    public function test_cooldown_deduplicates_but_severity_escalation_emits(): void
    {
        $engine = new SignalDetectionEngine;
        $medium = $this->diagnostic(-18, 0);
        $first = $engine->detect($medium, evaluatedAt: '2026-10-18T20:00:00+00:00');
        $signal = $this->firstSignal($first['signals'], 'revenue_drop');
        $this->assertIsArray($signal);
        $this->assertSame('medium', $signal['severity']);

        $prior = [[
            'fingerprint' => $signal['fingerprint'],
            'state' => 'new',
            'severity' => 'medium',
            'last_emitted_at' => '2026-10-18T20:00:00+00:00',
        ]];

        $withinCooldown = $engine->detect($medium, priorSignals: $prior, evaluatedAt: '2026-10-18T21:00:00+00:00');
        $this->assertNull($this->firstSignal($withinCooldown['signals'], 'revenue_drop'));
        $this->assertContains('cooldown_active', array_column($withinCooldown['suppressed'], 'reason'));

        $escalated = $engine->detect($this->diagnostic(-45, 0), priorSignals: $prior, evaluatedAt: '2026-10-18T21:00:00+00:00');
        $revenue = $this->firstSignal($escalated['signals'], 'revenue_drop');
        $this->assertIsArray($revenue);
        $this->assertTrue($revenue['escalated']);
        $this->assertSame('critical', $revenue['severity']);
    }

    public function test_authorized_retailer_and_forecast_inputs_emit_recommendations_only(): void
    {
        $engine = new SignalDetectionEngine;
        $result = $engine->detect(
            $this->diagnostic(0, 0),
            retailerInsights: [[
                'authorized_scope' => true,
                'retailer_store_id' => 701,
                'confidence' => 0.86,
                'days_since_last_purchase' => 30,
                'baseline_reorder_days' => 10,
                'sell_through_trend_percent' => 28,
                'stock_cover_days' => 3,
                'recommended_reorder_quantity' => 48,
                'cross_sell_candidates' => [['sku' => 'SKU-CROSS', 'score' => 0.81]],
            ]],
            forecastRows: [[
                'authorized_scope' => true,
                'entity_type' => 'sku',
                'entity_id' => 'SKU-DEMAND',
                'forecast_quantity' => 100,
                'actual_quantity' => 150,
                'stock_on_hand' => 10,
                'confidence' => 0.9,
            ], [
                'authorized_scope' => false,
                'entity_type' => 'sku',
                'entity_id' => 'SKU-NOT-AUTHORIZED',
                'forecast_quantity' => 100,
                'actual_quantity' => 200,
                'stock_on_hand' => 0,
                'confidence' => 1,
            ]],
            evaluatedAt: '2026-10-18T20:00:00+00:00',
        );

        $codes = array_column($result['signals'], 'code');
        foreach (['retailer_inactivity', 'rising_sell_through', 'likely_reorder', 'cross_sell_candidate', 'forecast_deviation', 'underserved_demand'] as $code) {
            $this->assertContains($code, $codes);
        }
        foreach ($result['signals'] as $signal) {
            if ($signal['kind'] === 'opportunity') {
                $this->assertTrue($signal['recommendation']['is_recommendation']);
            }
            foreach ($signal['affected_entities'] as $entity) {
                $this->assertNotSame('SKU-NOT-AUTHORIZED', (string) $entity['id']);
            }
        }
        $this->assertTrue($result['guardrails']['opportunities_are_recommendations_not_orders']);
    }

    /** @param list<array<string,mixed>> $signals */
    private function firstSignal(array $signals, string $code): ?array
    {
        foreach ($signals as $signal) {
            if (($signal['code'] ?? null) === $code) {
                return $signal;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function diagnostic(
        float $revenuePercent,
        float $ordersPercent,
        float $refundCurrent = 2,
        float $refundDelta = 0,
        float $cancellationCurrent = 2,
        float $cancellationDelta = 0,
    ): array {
        $revenuePrevious = 1000.0;
        $revenueCurrent = $revenuePrevious * (1 + ($revenuePercent / 100));
        $orderPrevious = 100.0;
        $orderCurrent = $orderPrevious * (1 + ($ordersPercent / 100));

        return [
            'schema_version' => SignalDetectionEngine::DIAGNOSTIC_SCHEMA_VERSION,
            'channel' => 'b2b',
            'flow' => 'sell_in',
            'scope' => [
                'store_ids' => [11, 10],
                'current_window' => ['from' => '2026-10-12', 'to' => '2026-10-18'],
                'previous_window' => ['from' => '2026-10-05', 'to' => '2026-10-11'],
            ],
            'evidence' => [
                'state' => 'sufficient',
                'confidence' => ['score' => 0.92, 'level' => 'high'],
                'reasons' => [],
            ],
            'comparison' => [
                'net_revenue' => [
                    'current' => round($revenueCurrent, 3),
                    'previous' => $revenuePrevious,
                    'delta' => round($revenueCurrent - $revenuePrevious, 3),
                    'percent_change' => $revenuePercent,
                ],
                'orders' => [
                    'current' => round($orderCurrent, 3),
                    'previous' => $orderPrevious,
                    'delta' => round($orderCurrent - $orderPrevious, 3),
                    'percent_change' => $ordersPercent,
                ],
            ],
            'exception_drivers' => [
                'refund_rate_percent' => [
                    'current' => $refundCurrent,
                    'previous' => $refundCurrent - $refundDelta,
                    'delta_percentage_points' => $refundDelta,
                ],
                'cancellation_rate_percent' => [
                    'current' => $cancellationCurrent,
                    'previous' => $cancellationCurrent - $cancellationDelta,
                    'delta_percentage_points' => $cancellationDelta,
                ],
            ],
            'inventory_coverage' => [],
            'breakdowns' => [],
        ];
    }
}
