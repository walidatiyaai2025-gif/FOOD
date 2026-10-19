<?php

namespace App\Domain\Analytics\Diagnostics;

final class DeterministicAnalyticsEngine
{
    public const SCHEMA_VERSION = 'foodex.analytics.diagnostic.v1';

    public const MIN_TREND_POINTS = 3;

    /**
     * @param  array<string,mixed>  $current
     * @param  array<string,mixed>|null  $previous
     * @param  array<string,list<array<string,mixed>>>  $breakdowns
     * @param  array<string,mixed>|null  $currentMargin
     * @param  array<string,mixed>|null  $previousMargin
     * @return array<string,mixed>
     */
    public function analyze(
        array $current,
        ?array $previous = null,
        array $breakdowns = [],
        ?array $currentMargin = null,
        ?array $previousMargin = null,
        ?string $previousUnavailableReason = null,
    ): array {
        $currentFacts = $this->facts($current);
        $previousFacts = $previous === null ? [] : $this->facts($previous);
        $currentTotals = $this->totals($currentFacts);
        $previousTotals = $previous === null ? null : $this->totals($previousFacts);

        $quality = $this->quality(
            $current,
            $previous,
            $currentTotals,
            $previousTotals,
            $previousUnavailableReason,
        );

        $comparison = [];
        foreach ([
            'orders',
            'recognized_orders',
            'gross_revenue',
            'discounts',
            'net_revenue',
            'units',
            'refunded_value',
            'refunded_orders',
            'cancelled_value',
            'cancelled_orders',
            'payments_collected',
            'payments_refunded',
            'wholesale_purchases',
            'wholesale_purchase_units',
        ] as $metric) {
            $comparison[$metric] = $this->compareValue(
                (float) $currentTotals[$metric],
                $previousTotals === null ? null : (float) $previousTotals[$metric],
            );
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'metric_dictionary_version' => $current['metric_dictionary_version'] ?? null,
            'channel' => $current['channel'] ?? null,
            'flow' => $current['flow'] ?? null,
            'timezone' => $current['timezone'] ?? null,
            'scope' => [
                'store_ids' => array_values($current['store_ids'] ?? []),
                'current_window' => $current['window'] ?? null,
                'previous_window' => $previous['window'] ?? null,
            ],
            'evidence' => $quality,
            'totals' => [
                'current' => $currentTotals,
                'previous' => $previousTotals,
            ],
            'comparison' => $comparison,
            'revenue_decomposition' => $this->revenueDecomposition($comparison),
            'trend' => [
                'net_revenue' => $this->trend($currentFacts, 'net_revenue'),
                'units' => $this->trend($currentFacts, 'units'),
                'recognized_orders' => $this->trend($currentFacts, 'recognized_orders'),
            ],
            'exception_drivers' => $this->exceptionDrivers($currentFacts, $previousFacts),
            'inventory_coverage' => $this->inventoryCoverage(
                $currentFacts,
                $current['inventory_snapshots'] ?? [],
                (int) ($current['window']['days'] ?? 0),
            ),
            'breakdowns' => $this->normalizeBreakdowns($breakdowns),
            'margin' => $this->marginComparison($currentMargin, $previousMargin),
            'guardrails' => [
                'model_arithmetic_allowed' => false,
                'model_may_override_computed_values' => false,
                'causal_inference_from_correlation_allowed' => false,
                'insufficient_evidence_requires_qualification' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<array<string,mixed>>
     */
    private function facts(array $payload): array
    {
        $facts = $payload['facts'] ?? [];

        return is_array($facts) && array_is_list($facts) ? $facts : [];
    }

    /**
     * @param  list<array<string,mixed>>  $facts
     * @return array<string,mixed>
     */
    private function totals(array $facts): array
    {
        $sum = static function (array $rows, string $key): float {
            $value = 0.0;
            foreach ($rows as $row) {
                $value += is_numeric($row[$key] ?? null) ? (float) $row[$key] : 0.0;
            }

            return round($value, 3);
        };

        $currencies = [];
        foreach ($facts as $row) {
            $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
            if ($currency !== '') {
                $currencies[$currency] = true;
            }
        }
        $currencies = array_keys($currencies);
        sort($currencies);

        return [
            'orders' => (int) round($sum($facts, 'orders')),
            'recognized_orders' => (int) round($sum($facts, 'recognized_orders')),
            'gross_revenue' => $sum($facts, 'gross_revenue'),
            'discounts' => $sum($facts, 'discounts'),
            'net_revenue' => $sum($facts, 'net_revenue'),
            'units' => $sum($facts, 'units'),
            'refunded_value' => $sum($facts, 'refunded_value'),
            'refunded_orders' => (int) round($sum($facts, 'refunded_orders')),
            'cancelled_value' => $sum($facts, 'cancelled_value'),
            'cancelled_orders' => (int) round($sum($facts, 'cancelled_orders')),
            'payments_collected' => $sum($facts, 'payments_collected'),
            'payments_refunded' => $sum($facts, 'payments_refunded'),
            'wholesale_purchases' => $sum($facts, 'wholesale_purchases'),
            'wholesale_purchase_units' => $sum($facts, 'wholesale_purchase_units'),
            'currencies' => $currencies,
        ];
    }

    /**
     * @param  array<string,mixed>  $current
     * @param  array<string,mixed>|null  $previous
     * @param  array<string,mixed>  $currentTotals
     * @param  array<string,mixed>|null  $previousTotals
     * @return array<string,mixed>
     */
    private function quality(
        array $current,
        ?array $previous,
        array $currentTotals,
        ?array $previousTotals,
        ?string $previousUnavailableReason,
    ): array {
        $reasons = [];
        $score = 1.0;

        $currentRows = count($this->facts($current));
        $previousRows = $previous === null ? 0 : count($this->facts($previous));
        $currentDays = $this->distinctDays($this->facts($current));
        $previousDays = $previous === null ? 0 : $this->distinctDays($this->facts($previous));
        $currentState = (string) ($current['freshness']['state'] ?? 'incomplete');
        $previousState = $previous === null ? null : (string) ($previous['freshness']['state'] ?? 'incomplete');

        if ($currentRows === 0) {
            $reasons[] = 'missing_current_period';
            $score -= 0.5;
        }
        if ($currentState === 'incomplete') {
            $reasons[] = 'current_data_incomplete';
            $score -= 0.35;
        } elseif ($currentState === 'stale') {
            $reasons[] = 'current_data_stale';
            $score -= 0.15;
        }
        if ($currentDays < self::MIN_TREND_POINTS) {
            $reasons[] = 'sparse_current_window';
            $score -= 0.15;
        }

        if ($previous === null || $previousRows === 0) {
            $reasons[] = $previousUnavailableReason ?? 'missing_comparison_period';
            $score -= 0.25;
        } else {
            if ($previousState === 'incomplete') {
                $reasons[] = 'comparison_data_incomplete';
                $score -= 0.25;
            } elseif ($previousState === 'stale') {
                $reasons[] = 'comparison_data_stale';
                $score -= 0.1;
            }

            if ($previousDays < self::MIN_TREND_POINTS) {
                $reasons[] = 'sparse_comparison_window';
                $score -= 0.1;
            }
        }

        $currencyReason = $this->currencyIssue($currentTotals, $previousTotals);
        if ($currencyReason !== null) {
            $reasons[] = $currencyReason;
            $score -= 0.4;
        }

        $score = round(max(0.0, min(1.0, $score)), 2);
        $level = $score >= 0.8 ? 'high' : ($score >= 0.55 ? 'medium' : 'low');

        return [
            'state' => $reasons === [] ? 'sufficient' : 'insufficient',
            'confidence' => [
                'score' => $score,
                'level' => $level,
            ],
            'reasons' => array_values(array_unique($reasons)),
            'sample' => [
                'current_fact_rows' => $currentRows,
                'previous_fact_rows' => $previousRows,
                'current_distinct_days' => $currentDays,
                'previous_distinct_days' => $previousDays,
                'minimum_trend_days' => self::MIN_TREND_POINTS,
            ],
            'freshness' => [
                'current' => $current['freshness'] ?? null,
                'previous' => $previous['freshness'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $currentTotals
     * @param  array<string,mixed>|null  $previousTotals
     */
    private function currencyIssue(array $currentTotals, ?array $previousTotals): ?string
    {
        $current = $currentTotals['currencies'] ?? [];
        $previous = $previousTotals['currencies'] ?? [];

        if (count($current) > 1 || (is_array($previous) && count($previous) > 1)) {
            return 'mixed_currency';
        }

        if (
            count($current) === 1
            && is_array($previous)
            && count($previous) === 1
            && $current[0] !== $previous[0]
        ) {
            return 'currency_mismatch';
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function compareValue(float $current, ?float $previous): array
    {
        if ($previous === null) {
            return [
                'current' => round($current, 3),
                'previous' => null,
                'delta' => null,
                'percent_change' => null,
                'percent_change_reason' => 'missing_previous_period',
                'direction' => 'unknown',
            ];
        }

        $delta = round($current - $previous, 3);
        $percent = $previous == 0.0 ? null : round(($delta / abs($previous)) * 100, 2);

        return [
            'current' => round($current, 3),
            'previous' => round($previous, 3),
            'delta' => $delta,
            'percent_change' => $percent,
            'percent_change_reason' => $previous == 0.0 ? 'zero_previous_denominator' : null,
            'direction' => $this->direction($delta),
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $comparison
     * @return array<string,mixed>
     */
    private function revenueDecomposition(array $comparison): array
    {
        return [
            'net_revenue_change' => $comparison['net_revenue']['delta'],
            'gross_revenue_change' => $comparison['gross_revenue']['delta'],
            'discount_change' => $comparison['discounts']['delta'],
            'refund_value_change' => $comparison['refunded_value']['delta'],
            'cancellation_value_change' => $comparison['cancelled_value']['delta'],
            'note' => 'Refund and cancellation values are deterministic exception indicators, not causal or algebraic components of recognized net revenue.',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $facts
     * @return array<string,mixed>
     */
    private function trend(array $facts, string $metric): array
    {
        $daily = [];
        foreach ($facts as $row) {
            $date = (string) ($row['date'] ?? '');
            if ($date === '') {
                continue;
            }
            $daily[$date] = ($daily[$date] ?? 0.0) + (is_numeric($row[$metric] ?? null) ? (float) $row[$metric] : 0.0);
        }
        ksort($daily);

        if (count($daily) < self::MIN_TREND_POINTS) {
            return [
                'available' => false,
                'reason' => 'insufficient_points',
                'points' => count($daily),
                'minimum_points' => self::MIN_TREND_POINTS,
            ];
        }

        $values = array_values($daily);
        $count = count($values);
        $meanX = ($count - 1) / 2;
        $meanY = array_sum($values) / $count;
        $numerator = 0.0;
        $denominator = 0.0;

        foreach ($values as $index => $value) {
            $xDelta = $index - $meanX;
            $numerator += $xDelta * ($value - $meanY);
            $denominator += $xDelta * $xDelta;
        }

        $slope = $denominator == 0.0 ? 0.0 : $numerator / $denominator;
        $velocity = $meanY == 0.0 ? null : ($slope / abs($meanY)) * 100;

        return [
            'available' => true,
            'points' => $count,
            'slope_per_day' => round($slope, 3),
            'velocity_percent_per_day' => $velocity === null ? null : round($velocity, 2),
            'direction' => $this->direction($slope),
            'series' => array_map(
                static fn (string $date, float $value): array => ['date' => $date, 'value' => round($value, 3)],
                array_keys($daily),
                $values,
            ),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $currentFacts
     * @param  list<array<string,mixed>>  $previousFacts
     * @return array<string,mixed>
     */
    private function exceptionDrivers(array $currentFacts, array $previousFacts): array
    {
        $currentTotals = $this->totals($currentFacts);
        $previousTotals = $this->totals($previousFacts);

        return [
            'refund_rate_percent' => $this->rateComparison(
                (float) $currentTotals['refunded_orders'],
                (float) $currentTotals['orders'],
                (float) $previousTotals['refunded_orders'],
                (float) $previousTotals['orders'],
                $previousFacts !== [],
            ),
            'cancellation_rate_percent' => $this->rateComparison(
                (float) $currentTotals['cancelled_orders'],
                (float) $currentTotals['orders'],
                (float) $previousTotals['cancelled_orders'],
                (float) $previousTotals['orders'],
                $previousFacts !== [],
            ),
            'top_refund_contributors' => $this->topExceptionRows($currentFacts, 'refunded_value'),
            'top_cancellation_contributors' => $this->topExceptionRows($currentFacts, 'cancelled_value'),
            'interpretation' => 'contribution_only_not_causation',
        ];
    }

    /** @return array<string,mixed> */
    private function rateComparison(
        float $currentNumerator,
        float $currentDenominator,
        float $previousNumerator,
        float $previousDenominator,
        bool $hasPrevious,
    ): array {
        $current = $currentDenominator == 0.0 ? null : round(($currentNumerator / $currentDenominator) * 100, 2);
        $previous = ! $hasPrevious || $previousDenominator == 0.0
            ? null
            : round(($previousNumerator / $previousDenominator) * 100, 2);

        return [
            'current' => $current,
            'previous' => $previous,
            'delta_percentage_points' => $current === null || $previous === null
                ? null
                : round($current - $previous, 2),
            'current_reason' => $currentDenominator == 0.0 ? 'zero_orders_denominator' : null,
            'previous_reason' => ! $hasPrevious
                ? 'missing_previous_period'
                : ($previousDenominator == 0.0 ? 'zero_orders_denominator' : null),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $facts
     * @return list<array<string,mixed>>
     */
    private function topExceptionRows(array $facts, string $metric): array
    {
        $rows = array_values(array_filter(
            $facts,
            static fn (array $row): bool => is_numeric($row[$metric] ?? null) && (float) $row[$metric] > 0.0,
        ));

        usort(
            $rows,
            static fn (array $a, array $b): int => ((float) $b[$metric]) <=> ((float) $a[$metric]),
        );

        return array_map(
            static fn (array $row): array => [
                'store_id' => isset($row['store_id']) ? (int) $row['store_id'] : null,
                'date' => $row['date'] ?? null,
                'value' => round((float) $row[$metric], 3),
            ],
            array_slice($rows, 0, 5),
        );
    }

    /**
     * @param  list<array<string,mixed>>  $facts
     * @return list<array<string,mixed>>
     */
    private function inventoryCoverage(array $facts, mixed $snapshots, int $windowDays): array
    {
        if (! is_array($snapshots) || ! array_is_list($snapshots)) {
            return [];
        }

        $days = max(1, $windowDays);
        $unitsByStore = [];
        foreach ($facts as $row) {
            $storeId = (int) ($row['store_id'] ?? 0);
            if ($storeId <= 0) {
                continue;
            }
            $unitsByStore[$storeId] = ($unitsByStore[$storeId] ?? 0.0)
                + (is_numeric($row['units'] ?? null) ? (float) $row['units'] : 0.0);
        }

        $result = [];
        foreach ($snapshots as $snapshot) {
            if (! is_array($snapshot)) {
                continue;
            }

            $storeId = (int) ($snapshot['store_id'] ?? 0);
            $available = is_numeric($snapshot['available_units'] ?? null) ? (float) $snapshot['available_units'] : 0.0;
            $velocity = ($unitsByStore[$storeId] ?? 0.0) / $days;
            $cover = $velocity <= 0.0 ? null : $available / $velocity;

            $result[] = [
                'store_id' => $storeId,
                'available_units' => round($available, 3),
                'daily_unit_velocity' => round($velocity, 3),
                'coverage_days' => $cover === null ? null : round($cover, 2),
                'coverage_state' => $this->coverageState($available, $cover),
                'snapshot_date' => $snapshot['snapshot_date'] ?? null,
                'quality_state' => $snapshot['quality_state'] ?? null,
            ];
        }

        return $result;
    }

    private function coverageState(float $available, ?float $cover): string
    {
        if ($available <= 0.0) {
            return 'out_of_stock';
        }
        if ($cover === null) {
            return 'unknown_velocity';
        }
        if ($cover < 7.0) {
            return 'low_cover';
        }
        if ($cover > 30.0) {
            return 'high_cover';
        }

        return 'balanced';
    }

    /**
     * @param  array<string,list<array<string,mixed>>>  $breakdowns
     * @return array<string,array<string,mixed>>
     */
    private function normalizeBreakdowns(array $breakdowns): array
    {
        $result = [];

        foreach ($breakdowns as $dimension => $rows) {
            $currentTotal = 0.0;
            $previousTotal = 0.0;
            foreach ($rows as $row) {
                $currentTotal += is_numeric($row['current_revenue'] ?? null) ? (float) $row['current_revenue'] : 0.0;
                $previousTotal += is_numeric($row['previous_revenue'] ?? null) ? (float) $row['previous_revenue'] : 0.0;
            }
            $totalDelta = $currentTotal - $previousTotal;

            $normalized = [];
            foreach ($rows as $row) {
                $currentRevenue = is_numeric($row['current_revenue'] ?? null) ? (float) $row['current_revenue'] : 0.0;
                $previousRevenue = is_numeric($row['previous_revenue'] ?? null) ? (float) $row['previous_revenue'] : 0.0;
                $delta = $currentRevenue - $previousRevenue;
                $currentShare = $currentTotal == 0.0 ? null : ($currentRevenue / $currentTotal) * 100;
                $previousShare = $previousTotal == 0.0 ? null : ($previousRevenue / $previousTotal) * 100;

                $normalized[] = [
                    'key' => (string) ($row['key'] ?? ''),
                    'label' => (string) ($row['label'] ?? $row['key'] ?? ''),
                    'current_revenue' => round($currentRevenue, 3),
                    'previous_revenue' => round($previousRevenue, 3),
                    'revenue_delta' => round($delta, 3),
                    'percent_change' => $previousRevenue == 0.0 ? null : round(($delta / abs($previousRevenue)) * 100, 2),
                    'percent_change_reason' => $previousRevenue == 0.0 ? 'zero_previous_denominator' : null,
                    'current_mix_percent' => $currentShare === null ? null : round($currentShare, 2),
                    'previous_mix_percent' => $previousShare === null ? null : round($previousShare, 2),
                    'mix_shift_percentage_points' => $currentShare === null || $previousShare === null
                        ? null
                        : round($currentShare - $previousShare, 2),
                    'contribution_to_revenue_change_percent' => $totalDelta == 0.0
                        ? null
                        : round(($delta / $totalDelta) * 100, 2),
                    'current_units' => round((float) ($row['current_units'] ?? 0), 3),
                    'previous_units' => round((float) ($row['previous_units'] ?? 0), 3),
                    'current_orders' => (int) ($row['current_orders'] ?? 0),
                    'previous_orders' => (int) ($row['previous_orders'] ?? 0),
                ];
            }

            usort(
                $normalized,
                static fn (array $a, array $b): int => abs((float) $b['revenue_delta']) <=> abs((float) $a['revenue_delta']),
            );

            $result[$dimension] = [
                'metric' => 'recognized_line_revenue',
                'current_total' => round($currentTotal, 3),
                'previous_total' => round($previousTotal, 3),
                'rows' => array_slice($normalized, 0, 25),
            ];
        }

        return $result;
    }

    /**
     * @param  array<string,mixed>|null  $current
     * @param  array<string,mixed>|null  $previous
     * @return array<string,mixed>
     */
    private function marginComparison(?array $current, ?array $previous): array
    {
        if ($current === null) {
            return [
                'available' => false,
                'reason' => 'catalog_permission_or_cost_data_unavailable',
            ];
        }

        $currentMargin = is_numeric($current['gross_margin'] ?? null) ? (float) $current['gross_margin'] : 0.0;
        $previousMargin = $previous !== null && is_numeric($previous['gross_margin'] ?? null)
            ? (float) $previous['gross_margin']
            : null;

        $currentCoveredRevenue = is_numeric($current['cost_covered_revenue'] ?? null)
            ? (float) $current['cost_covered_revenue']
            : 0.0;
        $previousCoveredRevenue = $previous !== null && is_numeric($previous['cost_covered_revenue'] ?? null)
            ? (float) $previous['cost_covered_revenue']
            : null;
        $currentCogs = is_numeric($current['cogs'] ?? null) ? (float) $current['cogs'] : 0.0;
        $previousCogs = $previous !== null && is_numeric($previous['cogs'] ?? null)
            ? (float) $previous['cogs']
            : null;

        return [
            'available' => true,
            'current' => $current,
            'previous' => $previous,
            'cost_covered_revenue_change' => $this->compareValue($currentCoveredRevenue, $previousCoveredRevenue),
            'cogs_change' => $this->compareValue($currentCogs, $previousCogs),
            'gross_margin_change' => $this->compareValue($currentMargin, $previousMargin),
            'quality_state' => ($current['quality_state'] ?? 'incomplete') === 'complete'
                && ($previous === null || ($previous['quality_state'] ?? 'incomplete') === 'complete')
                    ? 'complete'
                    : 'incomplete',
        ];
    }

    /** @param list<array<string,mixed>> $facts */
    private function distinctDays(array $facts): int
    {
        $days = [];
        foreach ($facts as $row) {
            $date = trim((string) ($row['date'] ?? ''));
            if ($date !== '') {
                $days[$date] = true;
            }
        }

        return count($days);
    }

    private function direction(float $value): string
    {
        if (abs($value) < 0.0005) {
            return 'flat';
        }

        return $value > 0.0 ? 'up' : 'down';
    }
}
