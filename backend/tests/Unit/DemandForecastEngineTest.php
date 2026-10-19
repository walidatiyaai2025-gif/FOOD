<?php

namespace Tests\Unit;

use App\Domain\Analytics\Forecasting\DemandForecastEngine;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DemandForecastEngineTest extends TestCase
{
    public function test_forecast_backtest_and_wholesale_rollup_are_deterministic_without_double_counting(): void
    {
        $engine = new DemandForecastEngine;

        $snapshots = [
            $this->snapshot(
                10,
                100,
                2.0,
                30,
                'high',
                ['days_1_7' => 14.0, 'days_8_14' => 7.0, 'days_15_30' => 16.0],
                500,
                3.0,
                4.0,
                12.0,
            ),
            $this->snapshot(
                11,
                101,
                1.0,
                30,
                'high',
                ['days_1_7' => 7.0, 'days_8_14' => 7.0, 'days_15_30' => 16.0],
                500,
                2.0,
                2.0,
                8.0,
            ),
        ];

        $first = $engine->evaluate($snapshots, 14);
        $second = $engine->evaluate($snapshots, 14);

        $this->assertSame($first, $second);
        $this->assertSame(DemandForecastEngine::SCHEMA_VERSION, $first['schema_version']);
        $this->assertSame(28.0, $first['stores'][0]['products'][0]['forecast']['predicted_units']);
        $this->assertSame(50.0, $first['stores'][0]['products'][0]['backtest']['absolute_percentage_error']);
        $this->assertSame(0.0, $first['stores'][1]['products'][0]['backtest']['absolute_percentage_error']);
        $this->assertSame(25.0, $first['backtest_summary']['mean_absolute_percentage_error']);

        $this->assertCount(1, $first['wholesale_demand_rollup']);
        $rollup = $first['wholesale_demand_rollup'][0];
        $this->assertSame([10, 11], $rollup['retailer_store_ids']);
        $this->assertSame(2, $rollup['retailer_count']);
        $this->assertSame(5.0, $rollup['recommended_wholesale_quantity']);
        $this->assertSame(6.0, $rollup['unconstrained_wholesale_quantity']);
        $this->assertSame(20.0, $rollup['expected_cost']);
        $this->assertTrue($first['guardrails']['rollup_double_counting_protected']);
        $this->assertFalse($first['guardrails']['auto_order_creation']);
    }

    public function test_new_sku_and_sparse_history_suppress_forecast_instead_of_fabricating_demand(): void
    {
        $engine = new DemandForecastEngine;

        $snapshot = $this->snapshot(
            20,
            200,
            3.5,
            4,
            'insufficient',
            ['days_1_7' => 8.0, 'days_8_14' => 0.0, 'days_15_30' => 0.0],
            600,
            0.0,
            0.0,
            0.0,
            'insufficient_data',
            'blocked_insufficient_history',
        );

        $result = $engine->evaluate([$snapshot], 30);
        $product = $result['stores'][0]['products'][0];

        $this->assertSame('insufficient_history', $product['forecast']['state']);
        $this->assertNull($product['forecast']['predicted_units']);
        $this->assertSame('insufficient_history', $product['backtest']['state']);
        $this->assertNull($product['backtest']['absolute_percentage_error']);
        $this->assertFalse($product['replenishment']['auto_order_created']);
        $this->assertSame('insufficient_data', $product['replenishment']['recommendation']['action']);
        $this->assertSame('blocked_insufficient_history', $product['replenishment']['recommendation']['state']);
        $this->assertSame('insufficient_history', $result['backtest_summary']['state']);
    }

    public function test_backtest_records_seasonal_deviation_without_dividing_by_zero(): void
    {
        $engine = new DemandForecastEngine;

        $snapshot = $this->snapshot(
            30,
            300,
            1.2,
            30,
            'high',
            ['days_1_7' => 0.0, 'days_8_14' => 14.0, 'days_15_30' => 32.0],
            700,
            1.0,
            1.0,
            5.0,
        );

        $result = $engine->evaluate([$snapshot], 7);
        $backtest = $result['stores'][0]['products'][0]['backtest'];

        $this->assertSame('available', $backtest['state']);
        $this->assertSame(0.0, $backtest['actual_recent_units']);
        $this->assertGreaterThan(0.0, $backtest['predicted_recent_units']);
        $this->assertNull($backtest['absolute_percentage_error']);
        $this->assertSame(0, $result['backtest_summary']['samples']);
    }

    public function test_forecast_horizon_is_bounded(): void
    {
        $engine = new DemandForecastEngine;

        $this->expectException(InvalidArgumentException::class);
        $engine->evaluate([], DemandForecastEngine::MAX_HORIZON_DAYS + 1);
    }

    /** @return array<string,mixed> */
    private function snapshot(
        int $storeId,
        int $retailProductId,
        float $velocity,
        int $historyDays,
        string $confidence,
        array $units,
        int $wholesaleProductId,
        float $recommended,
        float $unconstrained,
        float $expectedCost,
        string $action = 'reorder_now',
        string $state = 'executable',
    ): array {
        return [
            'retail_store_id' => $storeId,
            'inventory' => [
                'as_of' => '2026-10-19T05:00:00+03:00',
                'timezone' => 'Asia/Kuwait',
                'products' => [[
                    'product_id' => $retailProductId,
                    'sku' => 'R-'.$retailProductId,
                    'name' => 'Retail product '.$retailProductId,
                    'stock' => ['available' => 2.0],
                    'sales' => [
                        'velocity_units_per_day' => $velocity,
                        'units' => $units,
                    ],
                    'cover' => ['days' => 1.0],
                    'stock_risk' => 'critical_understock',
                    'confidence' => [
                        'state' => $confidence,
                        'history_days' => $historyDays,
                    ],
                ]],
            ],
            'reorder' => [
                'as_of' => '2026-10-19T05:00:00+03:00',
                'timezone' => 'Asia/Kuwait',
                'lead_time' => [
                    'expected_days' => 7.0,
                    'confidence' => 'observed',
                ],
                'recommendations' => [[
                    'retail_product_id' => $retailProductId,
                    'mapping' => [
                        'source_wholesale_store_id' => 1,
                        'source_wholesale_product_id' => $wholesaleProductId,
                        'source_sku' => 'W-'.$wholesaleProductId,
                        'source_name' => 'Wholesale product '.$wholesaleProductId,
                    ],
                    'reorder_model' => [
                        'expected_lead_time_days' => 7.0,
                        'safety_stock' => 2.0,
                        'reorder_point' => 10.0,
                        'target_stock' => 20.0,
                    ],
                    'recommendation' => [
                        'action' => $action,
                        'state' => $state,
                        'recommended_wholesale_quantity' => $recommended,
                        'unconstrained_wholesale_quantity' => $unconstrained,
                        'expected_cost' => $expectedCost,
                        'is_executable' => $state === 'executable',
                    ],
                ]],
            ],
        ];
    }
}
