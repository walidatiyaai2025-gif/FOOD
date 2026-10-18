<?php

namespace App\Domain\Analytics\Signals;

use InvalidArgumentException;

final class SignalDetectionEngine
{
    public const SCHEMA_VERSION = 'foodex.ai.signals.v1';

    public const DIAGNOSTIC_SCHEMA_VERSION = 'foodex.analytics.diagnostic.v1';

    private const MIN_CONFIDENCE = 0.55;

    private const SEVERITY_RANK = ['info' => 1, 'low' => 2, 'medium' => 3, 'high' => 4, 'critical' => 5];

    private const COOLDOWN_HOURS = ['critical' => 4, 'high' => 6, 'medium' => 12, 'low' => 24, 'info' => 24];

    /**
     * @param  array<string,mixed>  $diagnostic
     * @param  list<array<string,mixed>>  $retailerInsights  Authorized server-produced rows only.
     * @param  list<array<string,mixed>>  $forecastRows  Authorized server-produced rows only.
     * @param  list<array<string,mixed>>  $priorSignals
     * @return array<string,mixed>
     */
    public function detect(
        array $diagnostic,
        array $retailerInsights = [],
        array $forecastRows = [],
        array $priorSignals = [],
        string $evaluatedAt = '1970-01-01T00:00:00+00:00',
    ): array {
        if (($diagnostic['schema_version'] ?? null) !== self::DIAGNOSTIC_SCHEMA_VERSION) {
            throw new InvalidArgumentException('Signal detection requires foodex.analytics.diagnostic.v1 input.');
        }

        $scope = $this->scope($diagnostic);
        $confidence = $this->confidence($diagnostic);
        $digest = hash('sha256', $this->stableJson([
            'diagnostic' => $diagnostic,
            'retailer_insights' => $retailerInsights,
            'forecast_rows' => $forecastRows,
        ]));

        if (($diagnostic['evidence']['state'] ?? 'insufficient') !== 'sufficient' || $confidence < self::MIN_CONFIDENCE) {
            return $this->result($scope, $evaluatedAt, $digest, [], [[
                'reason' => 'insufficient_evidence',
                'confidence' => $confidence,
                'evidence_reasons' => array_values((array) ($diagnostic['evidence']['reasons'] ?? [])),
            ]]);
        }

        $candidates = [];
        $this->comparisonSignals($candidates, $diagnostic, $scope, $confidence);
        $this->exceptionSignals($candidates, $diagnostic, $scope, $confidence);
        $this->inventorySignals($candidates, $diagnostic, $scope, $confidence);
        $this->mixSignals($candidates, $diagnostic, $confidence);
        $this->retailerSignals($candidates, $retailerInsights, $confidence);
        $this->forecastSignals($candidates, $forecastRows, $scope, $confidence);

        $prior = [];
        foreach ($priorSignals as $signal) {
            $fingerprint = trim((string) ($signal['fingerprint'] ?? ''));
            if ($fingerprint !== '') {
                $prior[$fingerprint] = $signal;
            }
        }

        $signals = [];
        $suppressed = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $fingerprint = $this->fingerprint($candidate, $scope);
            if (isset($seen[$fingerprint])) {
                $suppressed[] = ['fingerprint' => $fingerprint, 'code' => $candidate['code'], 'reason' => 'duplicate_in_evaluation'];

                continue;
            }
            $seen[$fingerprint] = true;

            $candidate['fingerprint'] = $fingerprint;
            $candidate['scope'] = $scope;
            $candidate['confidence'] = round(min($confidence, (float) $candidate['confidence']), 2);
            $candidate['cooldown_hours'] = $this->cooldown((string) $candidate['severity']);
            $candidate['lifecycle'] = ['state' => 'new', 'allowed_next' => ['acknowledged', 'resolved', 'suppressed']];
            $candidate['reproducibility'] = [
                'engine_schema' => self::SCHEMA_VERSION,
                'diagnostic_schema' => self::DIAGNOSTIC_SCHEMA_VERSION,
                'input_digest' => $digest,
                'evaluated_at' => $evaluatedAt,
            ];

            $decision = $this->noiseDecision($candidate, $prior[$fingerprint] ?? null, $evaluatedAt);
            if (! $decision['emit']) {
                $suppressed[] = [
                    'fingerprint' => $fingerprint,
                    'code' => $candidate['code'],
                    'reason' => $decision['reason'],
                    'cooldown_hours' => $candidate['cooldown_hours'],
                ];

                continue;
            }
            if ($decision['escalated']) {
                $candidate['escalated'] = true;
            }
            $signals[] = $candidate;
        }

        usort($signals, fn (array $a, array $b): int => $this->rank((string) $b['severity']) <=> $this->rank((string) $a['severity']));

        return $this->result($scope, $evaluatedAt, $digest, $signals, $suppressed);
    }

    /** @param list<array<string,mixed>> $out */
    private function comparisonSignals(array &$out, array $diagnostic, array $scope, float $confidence): void
    {
        foreach ([['net_revenue', 'revenue'], ['orders', 'orders']] as [$metric, $label]) {
            $row = $diagnostic['comparison'][$metric] ?? null;
            if (! is_array($row) || ! is_numeric($row['percent_change'] ?? null)) {
                continue;
            }
            $percent = (float) $row['percent_change'];
            $current = $this->number($row['current'] ?? null);
            $baseline = $this->number($row['previous'] ?? null);
            $delta = $this->number($row['delta'] ?? null);
            if ($percent <= -15) {
                $severity = $percent <= -40 ? 'critical' : ($percent <= -25 ? 'high' : 'medium');
                $out[] = $this->candidate(
                    $label.'_drop', 'risk', $severity, $confidence,
                    ucfirst($label).' dropped materially', $label === 'revenue' ? 'انخفاض جوهري في الإيرادات' : 'انخفاض جوهري في الطلبات',
                    $metric, $current, $baseline, $delta, $percent, $this->scopeEntities($scope),
                    ['direction' => 'negative', 'value' => abs((float) $delta), 'unit' => $metric === 'orders' ? 'orders' : 'currency'],
                );
            } elseif ($percent >= 25) {
                $candidate = $this->candidate(
                    $label.'_spike', 'opportunity', $percent >= 60 ? 'high' : 'medium', $confidence,
                    ucfirst($label).' increased materially', $label === 'revenue' ? 'ارتفاع جوهري في الإيرادات' : 'ارتفاع جوهري في الطلبات',
                    $metric, $current, $baseline, $delta, $percent, $this->scopeEntities($scope),
                    ['direction' => 'positive', 'value' => abs((float) $delta), 'unit' => $metric === 'orders' ? 'orders' : 'currency'],
                );
                $candidate['recommendation'] = $this->recommendation('validate_capacity_and_inventory');
                $out[] = $candidate;
            }
        }
    }

    /** @param list<array<string,mixed>> $out */
    private function exceptionSignals(array &$out, array $diagnostic, array $scope, float $confidence): void
    {
        foreach ([
            'refund_rate_percent' => ['refund_rate_increase', 'Refund rate increased', 'ارتفاع معدل الاسترجاع'],
            'cancellation_rate_percent' => ['cancellation_rate_increase', 'Cancellation rate increased', 'ارتفاع معدل الإلغاء'],
        ] as $key => [$code, $en, $ar]) {
            $row = $diagnostic['exception_drivers'][$key] ?? null;
            if (! is_array($row) || ! is_numeric($row['current'] ?? null) || ! is_numeric($row['delta_percentage_points'] ?? null)) {
                continue;
            }
            $current = (float) $row['current'];
            $delta = (float) $row['delta_percentage_points'];
            if ($current < 5 || $delta < 3) {
                continue;
            }
            $out[] = $this->candidate(
                $code, 'risk', ($current >= 15 || $delta >= 10) ? 'high' : 'medium', $confidence,
                $en, $ar, $key, $current, $this->number($row['previous'] ?? null), $delta, null,
                $this->scopeEntities($scope), ['direction' => 'negative', 'value' => $delta, 'unit' => 'percentage_points'],
            );
        }
    }

    /** @param list<array<string,mixed>> $out */
    private function inventorySignals(array &$out, array $diagnostic, array $scope, float $confidence): void
    {
        foreach ((array) ($diagnostic['inventory_coverage'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $storeId = $this->positiveInt($row['store_id'] ?? null);
            if ($storeId === null || ! in_array($storeId, $scope['store_ids'], true)) {
                continue;
            }
            $state = (string) ($row['coverage_state'] ?? '');
            $coverage = $this->number($row['coverage_days'] ?? null);
            $available = $this->number($row['available_units'] ?? null);
            $affected = [['type' => 'store', 'id' => $storeId]];
            if ($state === 'out_of_stock') {
                $out[] = $this->candidate('stockout', 'risk', 'critical', $confidence, 'Stockout detected', 'تم اكتشاف نفاد مخزون', 'available_units', $available, 1, -1, null, $affected, ['direction' => 'negative', 'value' => 1, 'unit' => 'stockout']);
            } elseif ($state === 'low_cover' && $coverage !== null) {
                $out[] = $this->candidate('stockout_risk', 'risk', $coverage <= 3 ? 'high' : 'medium', $confidence, 'Low inventory cover', 'تغطية المخزون منخفضة', 'coverage_days', $coverage, 7, round($coverage - 7, 2), null, $affected, ['direction' => 'negative', 'value' => max(0, 7 - $coverage), 'unit' => 'coverage_days_gap']);
            } elseif ($state === 'high_cover' && $coverage !== null) {
                $out[] = $this->candidate('overstock_risk', 'risk', $coverage >= 60 ? 'high' : 'medium', $confidence, 'High inventory cover', 'تغطية المخزون مرتفعة', 'coverage_days', $coverage, 30, round($coverage - 30, 2), null, $affected, ['direction' => 'negative', 'value' => max(0, $coverage - 30), 'unit' => 'excess_cover_days']);
            }
        }
    }

    /** @param list<array<string,mixed>> $out */
    private function mixSignals(array &$out, array $diagnostic, float $confidence): void
    {
        foreach ((array) ($diagnostic['breakdowns']['sku']['rows'] ?? []) as $row) {
            if (! is_array($row) || ! is_numeric($row['mix_shift_percentage_points'] ?? null)) {
                continue;
            }
            $shift = (float) $row['mix_shift_percentage_points'];
            $sku = trim((string) ($row['key'] ?? ''));
            if (abs($shift) < 10 || $sku === '') {
                continue;
            }
            $gain = $shift > 0;
            $candidate = $this->candidate(
                $gain ? 'sku_mix_gain' : 'sku_mix_loss', $gain ? 'opportunity' : 'risk', abs($shift) >= 20 ? 'high' : 'medium', $confidence,
                $gain ? 'SKU mix is gaining share' : 'SKU mix is losing share', $gain ? 'ارتفاع حصة الصنف في المزيج' : 'انخفاض حصة الصنف في المزيج',
                'mix_shift_percentage_points', $shift, 0, $shift, null,
                [['type' => 'sku', 'id' => $sku, 'label' => $row['label'] ?? $sku]],
                ['direction' => $gain ? 'positive' : 'negative', 'value' => abs($shift), 'unit' => 'percentage_points'],
            );
            if ($gain) {
                $candidate['recommendation'] = $this->recommendation('protect_winning_sku_availability');
            }
            $out[] = $candidate;
        }
    }

    /** @param list<array<string,mixed>> $out @param list<array<string,mixed>> $rows */
    private function retailerSignals(array &$out, array $rows, float $confidence): void
    {
        foreach ($rows as $row) {
            if (($row['authorized_scope'] ?? false) !== true) {
                continue;
            }
            $storeId = $this->positiveInt($row['retailer_store_id'] ?? null);
            $rowConfidence = is_numeric($row['confidence'] ?? null) ? min(1, max(0, (float) $row['confidence'])) : $confidence;
            if ($storeId === null || $rowConfidence < self::MIN_CONFIDENCE) {
                continue;
            }
            $affected = [['type' => 'retailer_store', 'id' => $storeId]];
            $days = $this->number($row['days_since_last_purchase'] ?? null);
            $baselineDays = $this->number($row['baseline_reorder_days'] ?? null);
            if ($days !== null && $baselineDays !== null && $baselineDays > 0) {
                $threshold = max(14, $baselineDays * 1.5);
                if ($days >= $threshold) {
                    $out[] = $this->candidate('retailer_inactivity', 'risk', $days >= $threshold * 2 ? 'high' : 'medium', $rowConfidence, 'Retailer activity is overdue', 'تأخر نشاط تاجر التجزئة', 'days_since_last_purchase', $days, $baselineDays, round($days - $baselineDays, 2), null, $affected, ['direction' => 'negative', 'value' => round($days - $baselineDays, 2), 'unit' => 'days_over_baseline']);
                }
            }
            $trend = $this->number($row['sell_through_trend_percent'] ?? null);
            if ($trend !== null && $trend >= 15) {
                $candidate = $this->candidate('rising_sell_through', 'opportunity', $trend >= 30 ? 'high' : 'medium', $rowConfidence, 'Retailer sell-through is rising', 'ارتفاع معدل البيع للمستهلك لدى تاجر التجزئة', 'sell_through_trend_percent', $trend, 0, $trend, $trend, $affected, ['direction' => 'positive', 'value' => $trend, 'unit' => 'percent']);
                $candidate['recommendation'] = $this->recommendation('review_replenishment_and_growth');
                $out[] = $candidate;
            }
            $cover = $this->number($row['stock_cover_days'] ?? null);
            $qty = $this->number($row['recommended_reorder_quantity'] ?? null);
            if ($cover !== null && $cover <= 7 && $qty !== null && $qty > 0) {
                $candidate = $this->candidate('likely_reorder', 'opportunity', $cover <= 3 ? 'high' : 'medium', $rowConfidence, 'Likely reorder window', 'نافذة إعادة طلب محتملة', 'stock_cover_days', $cover, 7, round($cover - 7, 2), null, $affected, ['direction' => 'positive', 'value' => $qty, 'unit' => 'recommended_units']);
                $candidate['recommendation'] = $this->recommendation('prepare_reorder_conversation');
                $out[] = $candidate;
            }
            foreach ((array) ($row['cross_sell_candidates'] ?? []) as $crossSell) {
                if (! is_array($crossSell) || ! is_numeric($crossSell['score'] ?? null) || (float) $crossSell['score'] < 0.7) {
                    continue;
                }
                $sku = trim((string) ($crossSell['sku'] ?? ''));
                if ($sku === '') {
                    continue;
                }
                $score = (float) $crossSell['score'];
                $candidate = $this->candidate('cross_sell_candidate', 'opportunity', 'medium', min($rowConfidence, $score), 'Cross-sell candidate', 'فرصة بيع تكميلي', 'cross_sell_score', $score, 0.7, round($score - 0.7, 3), null, [...$affected, ['type' => 'sku', 'id' => $sku]], ['direction' => 'positive', 'value' => $score, 'unit' => 'score']);
                $candidate['recommendation'] = $this->recommendation('suggest_cross_sell');
                $out[] = $candidate;
            }
        }
    }

    /** @param list<array<string,mixed>> $out @param list<array<string,mixed>> $rows */
    private function forecastSignals(array &$out, array $rows, array $scope, float $confidence): void
    {
        foreach ($rows as $row) {
            if (($row['authorized_scope'] ?? false) !== true) {
                continue;
            }
            $rowConfidence = is_numeric($row['confidence'] ?? null) ? min(1, max(0, (float) $row['confidence'])) : $confidence;
            $forecast = $this->number($row['forecast_quantity'] ?? null);
            $actual = $this->number($row['actual_quantity'] ?? null);
            if ($rowConfidence < self::MIN_CONFIDENCE || $forecast === null || $actual === null) {
                continue;
            }
            $error = is_numeric($row['error_percent'] ?? null) ? (float) $row['error_percent'] : ($forecast == 0.0 ? null : (($actual - $forecast) / abs($forecast)) * 100);
            if ($error === null || abs($error) < 20) {
                continue;
            }
            $entityId = trim((string) ($row['entity_id'] ?? ''));
            $entities = $entityId === '' ? $this->scopeEntities($scope) : [[
                'type' => trim((string) ($row['entity_type'] ?? 'sku')) ?: 'sku',
                'id' => $entityId,
            ]];
            $out[] = $this->candidate('forecast_deviation', 'risk', abs($error) >= 40 ? 'high' : 'medium', $rowConfidence, 'Demand deviated from forecast', 'انحراف الطلب عن التوقع', 'forecast_error_percent', round($error, 2), 0, round($actual - $forecast, 3), round($error, 2), $entities, ['direction' => $error < 0 ? 'negative' : 'positive', 'value' => abs(round($actual - $forecast, 3)), 'unit' => 'units']);
            $stock = $this->number($row['stock_on_hand'] ?? null);
            $gap = max(0, $actual - $forecast);
            if ($error >= 20 && $stock !== null && $stock < $gap) {
                $candidate = $this->candidate('underserved_demand', 'opportunity', $error >= 40 ? 'high' : 'medium', $rowConfidence, 'Demand may be underserved', 'قد يكون الطلب غير ملبى بالكامل', 'demand_gap_units', round($gap, 3), $stock, round($gap - $stock, 3), null, $entities, ['direction' => 'positive', 'value' => round(max(0, $gap - $stock), 3), 'unit' => 'potential_unserved_units']);
                $candidate['recommendation'] = $this->recommendation('review_supply_and_replenishment');
                $out[] = $candidate;
            }
        }
    }

    /** @return array<string,mixed> */
    private function candidate(string $code, string $kind, string $severity, float $confidence, string $en, string $ar, string $metric, ?float $current, ?float $baseline, ?float $delta, ?float $percent, array $affected, array $impact): array
    {
        return [
            'code' => $code, 'kind' => $kind, 'severity' => $severity, 'confidence' => round($confidence, 2),
            'title' => ['en' => $en, 'ar' => $ar],
            'metric' => ['name' => $metric, 'current' => $current, 'baseline' => $baseline, 'delta' => $delta, 'percent_change' => $percent, 'baseline_source' => 'authorized_previous_period_or_authorized_domain_input'],
            'affected_entities' => $affected,
            'estimated_impact' => $impact,
            'recommendation' => ['is_recommendation' => false, 'action_key' => null],
        ];
    }

    /** @return array{is_recommendation:true,action_key:string} */
    private function recommendation(string $action): array
    {
        return ['is_recommendation' => true, 'action_key' => $action];
    }

    /** @return array<string,mixed> */
    private function result(array $scope, string $evaluatedAt, string $digest, array $signals, array $suppressed): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'evaluated_at' => $evaluatedAt,
            'scope' => $scope,
            'input_digest' => $digest,
            'signals' => $signals,
            'suppressed' => $suppressed,
            'counts' => [
                'signals' => count($signals),
                'suppressed' => count($suppressed),
                'risks' => count(array_filter($signals, static fn (array $signal): bool => $signal['kind'] === 'risk')),
                'opportunities' => count(array_filter($signals, static fn (array $signal): bool => $signal['kind'] === 'opportunity')),
            ],
            'guardrails' => [
                'deterministic_business_math' => true,
                'model_arithmetic_allowed' => false,
                'model_may_override_signal_values' => false,
                'cross_tenant_baselines_allowed' => false,
                'opportunities_are_recommendations_not_orders' => true,
            ],
        ];
    }

    /** @return array{channel:?string,flow:?string,store_ids:list<int>,current_window:mixed,previous_window:mixed} */
    private function scope(array $diagnostic): array
    {
        $ids = [];
        foreach ((array) ($diagnostic['scope']['store_ids'] ?? []) as $id) {
            $value = $this->positiveInt($id);
            if ($value !== null) {
                $ids[$value] = true;
            }
        }
        $storeIds = array_keys($ids);
        sort($storeIds);

        return [
            'channel' => is_string($diagnostic['channel'] ?? null) ? strtolower($diagnostic['channel']) : null,
            'flow' => is_string($diagnostic['flow'] ?? null) ? strtolower($diagnostic['flow']) : null,
            'store_ids' => $storeIds,
            'current_window' => $diagnostic['scope']['current_window'] ?? null,
            'previous_window' => $diagnostic['scope']['previous_window'] ?? null,
        ];
    }

    /** @return list<array{type:string,id:int}> */
    private function scopeEntities(array $scope): array
    {
        return array_map(static fn (int $id): array => ['type' => 'store', 'id' => $id], $scope['store_ids']);
    }

    private function fingerprint(array $candidate, array $scope): string
    {
        $entities = $candidate['affected_entities'];
        usort($entities, static fn (array $a, array $b): int => strcmp((string) $a['type'].':'.(string) $a['id'], (string) $b['type'].':'.(string) $b['id']));

        return hash('sha256', $this->stableJson([
            'schema' => self::SCHEMA_VERSION,
            'code' => $candidate['code'],
            'kind' => $candidate['kind'],
            'channel' => $scope['channel'],
            'flow' => $scope['flow'],
            'store_ids' => $scope['store_ids'],
            'affected_entities' => $entities,
        ]));
    }

    /** @return array{emit:bool,reason:?string,escalated:bool} */
    private function noiseDecision(array $candidate, ?array $previous, string $evaluatedAt): array
    {
        if ($previous === null || ($previous['state'] ?? null) === 'resolved') {
            return ['emit' => true, 'reason' => null, 'escalated' => false];
        }
        if (($previous['state'] ?? null) === 'suppressed') {
            $until = $previous['suppressed_until'] ?? null;
            if (! is_string($until) || $this->timestamp($evaluatedAt) < $this->timestamp($until)) {
                return ['emit' => false, 'reason' => 'manually_suppressed', 'escalated' => false];
            }
        }
        if ($this->rank((string) $candidate['severity']) > $this->rank((string) ($previous['severity'] ?? 'info'))) {
            return ['emit' => true, 'reason' => null, 'escalated' => true];
        }
        $last = $previous['last_emitted_at'] ?? null;
        if (is_string($last) && trim($last) !== '' && $this->timestamp($evaluatedAt) - $this->timestamp($last) < $this->cooldown((string) $candidate['severity']) * 3600) {
            return ['emit' => false, 'reason' => 'cooldown_active', 'escalated' => false];
        }

        return ['emit' => true, 'reason' => null, 'escalated' => false];
    }

    private function confidence(array $diagnostic): float
    {
        $value = $diagnostic['evidence']['confidence']['score'] ?? 0;

        return is_numeric($value) ? round(min(1, max(0, (float) $value)), 2) : 0;
    }

    private function rank(string $severity): int
    {
        return self::SEVERITY_RANK[$severity] ?? 1;
    }

    private function cooldown(string $severity): int
    {
        return self::COOLDOWN_HOURS[$severity] ?? 24;
    }

    private function timestamp(string $value): int
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new InvalidArgumentException('Signal timestamps must be valid ISO-compatible date/time values.');
        }

        return $timestamp;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 3) : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }

    private function stableJson(mixed $value): string
    {
        return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
