<?php

namespace App\Domain\Analytics\Forecasting;

use InvalidArgumentException;

final class DemandForecastEngine
{
    public const SCHEMA_VERSION = 'foodex.forecast.replenishment.v1';

    public const DEFAULT_HORIZON_DAYS = 14;

    public const MAX_HORIZON_DAYS = 90;

    /**
     * @param list<array{
     *   retail_store_id:int,
     *   inventory:array<string,mixed>,
     *   reorder:array<string,mixed>
     * }> $snapshots
     * @return array<string,mixed>
     */
    public function evaluate(array $snapshots, int $horizonDays = self::DEFAULT_HORIZON_DAYS): array
    {
        if ($horizonDays < 1 || $horizonDays > self::MAX_HORIZON_DAYS) {
            throw new InvalidArgumentException('Forecast horizon must be between 1 and 90 days.');
        }

        $stores = [];
        $rollup = [];
        $seenRetailProducts = [];
        $errorValues = [];

        foreach ($snapshots as $snapshot) {
            $storeId = (int) $snapshot['retail_store_id'];
            if ($storeId <= 0) {
                throw new InvalidArgumentException('Retail store id must be a positive integer.');
            }

            $inventory = $snapshot['inventory'];
            $reorder = $snapshot['reorder'];

            $reorderByProduct = [];
            foreach ((array) ($reorder['recommendations'] ?? []) as $recommendation) {
                if (! is_array($recommendation)) {
                    continue;
                }

                $productId = (int) ($recommendation['retail_product_id'] ?? 0);
                if ($productId > 0) {
                    $reorderByProduct[$productId] = $recommendation;
                }
            }

            $products = [];
            foreach ((array) ($inventory['products'] ?? []) as $product) {
                if (! is_array($product)) {
                    continue;
                }

                $productId = (int) ($product['product_id'] ?? 0);
                if ($productId <= 0) {
                    continue;
                }

                $historyDays = (int) ($product['confidence']['history_days'] ?? 0);
                $confidence = (string) ($product['confidence']['state'] ?? 'insufficient');
                $velocity = round((float) ($product['sales']['velocity_units_per_day'] ?? 0.0), 3);
                $forecastAvailable = $confidence !== 'insufficient' && $historyDays >= 7;
                $projectedUnits = $forecastAvailable
                    ? round(max(0.0, $velocity) * $horizonDays, 3)
                    : null;

                $backtest = $this->backtest($product, $historyDays);
                if (is_float($backtest['absolute_percentage_error'] ?? null)) {
                    $errorValues[] = $backtest['absolute_percentage_error'];
                }

                $recommendation = $reorderByProduct[$productId] ?? [];
                $mapping = is_array($recommendation['mapping'] ?? null) ? $recommendation['mapping'] : null;
                $reorderModel = is_array($recommendation['reorder_model'] ?? null)
                    ? $recommendation['reorder_model']
                    : null;
                $decision = is_array($recommendation['recommendation'] ?? null)
                    ? $recommendation['recommendation']
                    : null;

                $products[] = [
                    'retail_product_id' => $productId,
                    'sku' => (string) ($product['sku'] ?? ''),
                    'name' => (string) ($product['name'] ?? ''),
                    'forecast' => [
                        'state' => $forecastAvailable ? 'available' : 'insufficient_history',
                        'horizon_days' => $horizonDays,
                        'baseline_daily_units' => $forecastAvailable ? $velocity : null,
                        'predicted_units' => $projectedUnits,
                        'confidence' => $confidence,
                        'history_days' => $historyDays,
                        'method' => 'weighted_recent_velocity',
                    ],
                    'backtest' => $backtest,
                    'inventory' => [
                        'available' => round((float) ($product['stock']['available'] ?? 0.0), 3),
                        'days_of_cover' => $product['cover']['days'] ?? null,
                        'stock_risk' => (string) ($product['stock_risk'] ?? 'unknown'),
                    ],
                    'replenishment' => [
                        'model' => $reorderModel,
                        'recommendation' => $decision,
                        'mapping' => $mapping,
                        'auto_order_created' => false,
                    ],
                ];

                if ($mapping === null || $decision === null) {
                    continue;
                }

                $sourceStoreId = (int) ($mapping['source_wholesale_store_id'] ?? 0);
                $sourceProductId = (int) ($mapping['source_wholesale_product_id'] ?? 0);
                if ($sourceStoreId <= 0 || $sourceProductId <= 0) {
                    continue;
                }

                $retailIdentity = $storeId.':'.$productId;
                if (isset($seenRetailProducts[$retailIdentity])) {
                    continue;
                }
                $seenRetailProducts[$retailIdentity] = true;

                $key = $sourceStoreId.':'.$sourceProductId;
                $rollup[$key] ??= [
                    'source_wholesale_store_id' => $sourceStoreId,
                    'source_wholesale_product_id' => $sourceProductId,
                    'source_sku' => (string) ($mapping['source_sku'] ?? ''),
                    'source_name' => (string) ($mapping['source_name'] ?? ''),
                    'retailer_store_ids' => [],
                    'recommended_wholesale_quantity' => 0.0,
                    'unconstrained_wholesale_quantity' => 0.0,
                    'expected_cost' => 0.0,
                ];

                $rollup[$key]['retailer_store_ids'][] = $storeId;
                $rollup[$key]['recommended_wholesale_quantity'] +=
                    (float) ($decision['recommended_wholesale_quantity'] ?? 0.0);
                $rollup[$key]['unconstrained_wholesale_quantity'] +=
                    (float) ($decision['unconstrained_wholesale_quantity'] ?? 0.0);
                $rollup[$key]['expected_cost'] += (float) ($decision['expected_cost'] ?? 0.0);
            }

            $stores[] = [
                'retail_store_id' => $storeId,
                'as_of' => $inventory['as_of'] ?? $reorder['as_of'] ?? null,
                'timezone' => $inventory['timezone'] ?? $reorder['timezone'] ?? 'Asia/Kuwait',
                'lead_time' => $reorder['lead_time'] ?? null,
                'products' => $products,
                'product_count' => count($products),
            ];
        }

        $wholesaleRollup = array_values(array_map(static function (array $row): array {
            $row['retailer_store_ids'] = array_values(array_unique(array_map('intval', $row['retailer_store_ids'])));
            sort($row['retailer_store_ids']);
            $row['retailer_count'] = count($row['retailer_store_ids']);
            $row['recommended_wholesale_quantity'] = round((float) $row['recommended_wholesale_quantity'], 3);
            $row['unconstrained_wholesale_quantity'] = round((float) $row['unconstrained_wholesale_quantity'], 3);
            $row['expected_cost'] = round((float) $row['expected_cost'], 3);

            return $row;
        }, $rollup));

        usort($wholesaleRollup, static function (array $left, array $right): int {
            return [
                $left['source_wholesale_store_id'],
                $left['source_wholesale_product_id'],
            ] <=> [
                $right['source_wholesale_store_id'],
                $right['source_wholesale_product_id'],
            ];
        });

        $meanApe = $errorValues === []
            ? null
            : round(array_sum($errorValues) / count($errorValues), 2);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'horizon_days' => $horizonDays,
            'stores' => $stores,
            'store_count' => count($stores),
            'wholesale_demand_rollup' => $wholesaleRollup,
            'backtest_summary' => [
                'samples' => count($errorValues),
                'mean_absolute_percentage_error' => $meanApe,
                'metric' => 'MAPE',
                'state' => $errorValues === [] ? 'insufficient_history' : 'available',
            ],
            'guardrails' => [
                'deterministic_math_only' => true,
                'model_arithmetic_allowed' => false,
                'auto_order_creation' => false,
                'sell_in_sell_out_must_remain_distinct' => true,
                'rollup_double_counting_protected' => true,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function backtest(array $product, int $historyDays): array
    {
        if ($historyDays < 14) {
            return [
                'state' => 'insufficient_history',
                'actual_recent_units' => null,
                'predicted_recent_units' => null,
                'error_units' => null,
                'absolute_percentage_error' => null,
                'method' => 'prior_windows_predict_recent_7_days',
            ];
        }

        $sales = is_array($product['sales']['units'] ?? null) ? $product['sales']['units'] : [];
        $actual = round((float) ($sales['days_1_7'] ?? 0.0), 3);
        $priorDays = min(7, max(0, $historyDays - 7))
            + min(16, max(0, $historyDays - 14));

        $priorUnits = (float) ($sales['days_8_14'] ?? 0.0)
            + (float) ($sales['days_15_30'] ?? 0.0);
        $predicted = round(($priorUnits / $priorDays) * 7, 3);
        $error = round($actual - $predicted, 3);
        $ape = $actual <= 0.0
            ? null
            : round((abs($error) / $actual) * 100, 2);

        return [
            'state' => 'available',
            'actual_recent_units' => $actual,
            'predicted_recent_units' => $predicted,
            'error_units' => $error,
            'absolute_percentage_error' => $ape,
            'method' => 'prior_windows_predict_recent_7_days',
        ];
    }
}
