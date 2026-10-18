<?php

namespace App\Domain\Analytics\Semantic;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class SemanticMetricService
{
    private const NON_REVENUE_STATUSES = ['cancelled', 'refunded'];

    public function __construct(private MetricDictionary $dictionary) {}

    /**
     * Calculate canonical order metrics from an already-authorized/scoped order query.
     * The service additionally enforces the commercial flow channel so a sell-in
     * observation can never silently include sell-out rows, or vice versa.
     *
     * @param  array<string,mixed>  $scope
     * @return array{values:array<string,int|float>,provenance:array<string,mixed>,definitions:array<string,array<string,mixed>>}
     */
    public function orderSnapshot(
        Builder $scopedOrders,
        string $flow,
        string $from,
        string $to,
        array $scope,
        string $currency = 'EGP',
        string $locale = 'en',
    ): array {
        $channel = match ($flow) {
            MetricDictionary::FLOW_SELL_IN => 'b2b',
            MetricDictionary::FLOW_SELL_OUT => 'b2c',
            default => throw new \InvalidArgumentException("Unsupported metric flow [{$flow}]."),
        };

        $orders = (clone $scopedOrders)->where('orders.channel', $channel);
        $recognized = (clone $orders)->whereNotIn('orders.status', self::NON_REVENUE_STATUSES);
        $cancelled = (clone $orders)->where('orders.status', 'cancelled');
        $refunded = (clone $orders)->where('orders.status', 'refunded');

        $orderCount = (int) (clone $orders)->count('orders.id');
        $recognizedOrderCount = (int) (clone $recognized)->count('orders.id');
        $netRevenue = round((float) (clone $recognized)->sum('orders.grand_total'), 3);
        $grossRevenue = round((float) (clone $recognized)
            ->selectRaw('COALESCE(SUM(orders.subtotal + orders.delivery_total), 0) as aggregate')
            ->value('aggregate'), 3);
        $discounts = round((float) (clone $recognized)->sum('orders.discount_total'), 3);
        $cancelledValue = round((float) (clone $cancelled)->sum('orders.grand_total'), 3);
        $refundedValue = round((float) (clone $refunded)->sum('orders.grand_total'), 3);

        $recognizedOrderIds = (clone $recognized)->select('orders.id');
        $units = round((float) DB::table('order_items')
            ->whereIn('order_items.order_id', $recognizedOrderIds)
            ->sum('order_items.quantity'), 3);

        $averageOrderValue = $recognizedOrderCount === 0
            ? 0.0
            : round($netRevenue / $recognizedOrderCount, 3);

        $metricIds = [
            'orders.total',
            'orders.recognized',
            'revenue.gross',
            'discounts.total',
            'revenue.net',
            'refunds.total',
            'refunds.orders',
            'cancellations.total',
            'cancellations.orders',
            'units.sold',
            'orders.aov',
        ];

        $definitions = [];
        foreach ($metricIds as $metricId) {
            $definitions[$metricId] = $this->dictionary->get($metricId)->toArray($locale);
        }

        return [
            'values' => [
                'orders' => $orderCount,
                'recognized_orders' => $recognizedOrderCount,
                'gross_revenue' => $grossRevenue,
                'discounts' => $discounts,
                'net_revenue' => $netRevenue,
                'refunded_value' => $refundedValue,
                'refunded_orders' => (int) (clone $refunded)->count('orders.id'),
                'cancelled_value' => $cancelledValue,
                'cancelled_orders' => (int) (clone $cancelled)->count('orders.id'),
                'units' => $units,
                'average_order_value' => $averageOrderValue,
            ],
            'provenance' => [
                'dictionary_version' => MetricDictionary::VERSION,
                'flow' => $flow,
                'channel' => $channel,
                'window' => ['from' => $from, 'to' => $to],
                'timezone' => MetricDictionary::TIMEZONE,
                'currency' => $currency,
                'scope' => $scope,
                'source' => 'server_computed_canonical_semantic_layer',
                'model_arithmetic_allowed' => false,
            ],
            'definitions' => $definitions,
        ];
    }
}
