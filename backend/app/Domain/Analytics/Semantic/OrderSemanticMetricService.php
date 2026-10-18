<?php

namespace App\Domain\Analytics\Semantic;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class OrderSemanticMetricService
{
    public const TIMEZONE = 'Asia/Kuwait';

    private const NON_REVENUE_STATUSES = ['cancelled', 'refunded'];

    public function __construct(private readonly SemanticMetricDictionary $dictionary) {}

    /**
     * Compatibility totals are deliberately channel-agnostic. They preserve the
     * existing FOODEX scalar contract while canonical semantic observations are
     * always emitted per dataset by summariesByDataset().
     *
     * @return array{
     *   orders:int,recognized_orders:int,gross_value:float,recognized_revenue:float,
     *   average_order_value:float,discounts:float,refunds:float,cancellations:int,units:float
     * }
     */
    public function compatibilityTotals(Builder $orders): array
    {
        $recognized = (clone $orders)->whereNotIn('orders.status', self::NON_REVENUE_STATUSES);
        $recognizedOrders = (int) (clone $recognized)->count('orders.id');
        $recognizedRevenue = round((float) (clone $recognized)->sum('orders.grand_total'), 3);

        $recognizedOrderIds = (clone $recognized)
            ->select('orders.id')
            ->distinct();

        $units = (float) DB::table('order_items')
            ->whereIn('order_id', $recognizedOrderIds)
            ->sum('quantity');

        return [
            'orders' => (int) (clone $orders)->count('orders.id'),
            'recognized_orders' => $recognizedOrders,
            'gross_value' => round((float) (clone $orders)->sum('orders.grand_total'), 3),
            'recognized_revenue' => $recognizedRevenue,
            'average_order_value' => $recognizedOrders === 0
                ? 0.0
                : round($recognizedRevenue / $recognizedOrders, 3),
            'discounts' => round((float) (clone $recognized)->sum('orders.discount_total'), 3),
            'refunds' => round((float) (clone $orders)
                ->where('orders.status', 'refunded')
                ->sum('orders.grand_total'), 3),
            'cancellations' => (int) (clone $orders)
                ->where('orders.status', 'cancelled')
                ->count('orders.id'),
            'units' => round($units, 3),
        ];
    }

    /**
     * Canonical semantic observations never combine SELL_IN and SELL_OUT.
     *
     * @param array<string, mixed> $scope
     * @return array<string, array<string, mixed>>
     */
    public function summariesByDataset(
        Builder $orders,
        string $from,
        string $to,
        array $scope = [],
        ?string $fallbackCurrency = null,
        string $locale = 'en',
    ): array {
        $channels = (clone $orders)
            ->select('orders.channel')
            ->distinct()
            ->pluck('orders.channel')
            ->filter(static fn ($channel): bool => is_string($channel) && in_array(strtolower($channel), ['b2b', 'b2c'], true))
            ->map(static fn (string $channel): string => strtolower($channel))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $declaredChannel = $scope['channel'] ?? null;
        if ($channels === [] && is_string($declaredChannel) && in_array(strtolower($declaredChannel), ['b2b', 'b2c'], true)) {
            $channels = [strtolower($declaredChannel)];
        }

        $summaries = [];
        foreach ($channels as $channel) {
            $datasetClass = MetricDatasetClass::fromChannel($channel);
            $summaries[$datasetClass] = $this->summaryForDataset(
                $orders,
                $datasetClass,
                $from,
                $to,
                $scope,
                $fallbackCurrency,
                $locale,
            );
        }

        return $summaries;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function summaryForDataset(
        Builder $orders,
        string $datasetClass,
        string $from,
        string $to,
        array $scope = [],
        ?string $fallbackCurrency = null,
        string $locale = 'en',
    ): array {
        $channel = MetricDatasetClass::channel($datasetClass);
        $datasetOrders = (clone $orders)->where('orders.channel', $channel);
        $totals = $this->compatibilityTotals($datasetOrders);
        $currency = $this->currency($datasetOrders, $fallbackCurrency);
        $computedAt = CarbonImmutable::now(self::TIMEZONE)->toIso8601String();
        $semanticScope = [
            ...$scope,
            'channel' => $channel,
            'dataset_class' => $datasetClass,
        ];

        $values = [
            'gross_revenue' => $totals['gross_value'],
            'net_revenue' => $totals['recognized_revenue'],
            'discounts' => $totals['discounts'],
            'refunds' => $totals['refunds'],
            'units' => $totals['units'],
            'average_order_value' => $totals['average_order_value'],
            'cancellations' => $totals['cancellations'],
        ];

        $metrics = [];
        foreach ($values as $metric => $value) {
            $definition = $this->dictionary->definition($metric, $datasetClass);
            $observation = new SemanticMetricObservation(
                definition: $definition,
                value: $value,
                currency: $definition->unit === 'currency' ? $currency : null,
                from: $from,
                to: $to,
                timezone: self::TIMEZONE,
                computedAt: $computedAt,
                scope: $semanticScope,
            );
            $metrics[$definition->key] = $observation->toArray($locale);
        }

        $marginDefinition = $this->dictionary->definition('margin', $datasetClass);
        $metrics[$marginDefinition->key] = (new SemanticMetricObservation(
            definition: $marginDefinition,
            value: null,
            currency: $currency,
            from: $from,
            to: $to,
            timezone: self::TIMEZONE,
            computedAt: $computedAt,
            scope: $semanticScope,
            coverage: 'unavailable',
            freshness: 'current',
        ))->toArray($locale);

        return [
            'dataset_class' => $datasetClass,
            'channel' => $channel,
            'metric_version' => SemanticMetricDictionary::VERSION,
            'from' => $from,
            'to' => $to,
            'timezone' => self::TIMEZONE,
            'currency' => $currency,
            'scope' => $semanticScope,
            'totals' => $totals,
            'metrics' => $metrics,
        ];
    }

    private function currency(Builder $orders, ?string $fallbackCurrency): ?string
    {
        $currencies = (clone $orders)
            ->select('orders.currency')
            ->distinct()
            ->pluck('orders.currency')
            ->filter(static fn ($currency): bool => is_string($currency) && trim($currency) !== '')
            ->map(static fn (string $currency): string => strtoupper(trim($currency)))
            ->unique()
            ->values()
            ->all();

        if (count($currencies) === 1) {
            return $currencies[0];
        }

        if ($currencies === [] && $fallbackCurrency !== null && trim($fallbackCurrency) !== '') {
            return strtoupper(trim($fallbackCurrency));
        }

        return null;
    }
}
