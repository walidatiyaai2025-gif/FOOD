<?php

namespace App\Domain\Assistant\Analytics;

use App\Domain\Analytics\ReadModel\AnalyticsFactMaterializer;
use App\Domain\Analytics\ReadModel\AnalyticsReadModelService;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use App\Models\User;
use App\Services\CustomerServiceCaseManager;
use App\Services\OperationalTenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AssistantAnalyticsReadService
{
    public function __construct(
        private AnalyticsReadModelService $readModel,
        private OperationalTenantScope $scope,
        private CustomerServiceCaseManager $customerService,
    ) {}

    public function execute(string $tool, AssistantToolRequest $request): AssistantToolResult
    {
        return match ($tool) {
            'analytics.summary' => $this->analyticsSummary($request),
            'inventory.snapshot' => $this->inventorySnapshot($request),
            'refunds.summary' => $this->refundsSummary($request),
            'retailers.performance' => $this->retailersPerformance($request),
            'wholesale.purchases' => $this->wholesalePurchases($request),
            'margin.summary' => $this->marginSummary($request),
            'support.summary' => $this->supportSummary($request),
            default => throw new \InvalidArgumentException("Unsupported Assistant analytics tool [{$tool}]."),
        };
    }

    private function analyticsSummary(AssistantToolRequest $request): AssistantToolResult
    {
        $read = $this->materializedRead($request, $this->requiredChannel($request), $this->requestedStoreIds($request));

        return new AssistantToolResult(data: [
            'channel' => $read['channel'],
            'flow' => $read['flow'],
            'window' => $read['window'],
            'totals' => $this->factTotals($read['facts']),
            'freshness' => $read['freshness'],
            'metric_dictionary_version' => $read['metric_dictionary_version'],
            'timezone' => $read['timezone'],
            'store_ids' => $read['store_ids'],
        ]);
    }

    private function inventorySnapshot(AssistantToolRequest $request): AssistantToolResult
    {
        $read = $this->materializedRead($request, $this->requiredChannel($request), $this->requestedStoreIds($request));

        return new AssistantToolResult(data: [
            'window' => $read['window'],
            'snapshots' => $read['inventory_snapshots'],
            'snapshot_count' => count($read['inventory_snapshots']),
            'freshness' => $read['freshness'],
            'timezone' => $read['timezone'],
            'store_ids' => $read['store_ids'],
        ]);
    }

    private function refundsSummary(AssistantToolRequest $request): AssistantToolResult
    {
        $read = $this->materializedRead($request, $this->requiredChannel($request), $this->requestedStoreIds($request));
        $totals = $this->factTotals($read['facts']);

        return new AssistantToolResult(data: [
            'channel' => $read['channel'],
            'flow' => $read['flow'],
            'window' => $read['window'],
            'refunded_orders' => $totals['refunded_orders'],
            'refunded_value' => $totals['refunded_value'],
            'payments_refunded' => $totals['payments_refunded'],
            'cancelled_orders' => $totals['cancelled_orders'],
            'cancelled_value' => $totals['cancelled_value'],
            'currencies' => $totals['currencies'],
            'freshness' => $read['freshness'],
            'store_ids' => $read['store_ids'],
        ]);
    }

    private function retailersPerformance(AssistantToolRequest $request): AssistantToolResult
    {
        $storeIds = $this->retailerStoreIds($request);
        if ($storeIds === []) {
            throw ValidationException::withMessages([
                'retailer_store_ids' => ['Retailer performance requires one or more retailer store ids.'],
            ]);
        }

        $read = $this->materializedRead($request, 'b2c', $storeIds);
        $rows = collect($read['facts'])
            ->groupBy('store_id')
            ->map(function ($facts, $storeId): array {
                $facts = $facts->values()->all();
                $totals = $this->factTotals($facts);

                return [
                    'retailer_store_id' => (int) $storeId,
                    'orders' => $totals['orders'],
                    'recognized_orders' => $totals['recognized_orders'],
                    'net_revenue' => $totals['net_revenue'],
                    'units' => $totals['units'],
                    'refunded_value' => $totals['refunded_value'],
                    'cancelled_value' => $totals['cancelled_value'],
                    'wholesale_purchases' => $totals['wholesale_purchases'],
                    'wholesale_purchase_units' => $totals['wholesale_purchase_units'],
                    'currencies' => $totals['currencies'],
                ];
            })
            ->sortByDesc('net_revenue')
            ->values()
            ->all();

        return new AssistantToolResult(data: [
            'flow' => 'sell_out',
            'window' => $read['window'],
            'retailers' => $rows,
            'retailer_count' => count($rows),
            'freshness' => $read['freshness'],
            'timezone' => $read['timezone'],
        ]);
    }

    private function wholesalePurchases(AssistantToolRequest $request): AssistantToolResult
    {
        $storeIds = $this->retailerStoreIds($request);
        if ($storeIds === []) {
            throw ValidationException::withMessages([
                'retailer_store_ids' => ['Wholesale purchase analytics requires one or more retailer store ids.'],
            ]);
        }

        $read = $this->materializedRead($request, 'b2c', $storeIds);
        $totals = $this->factTotals($read['facts']);

        return new AssistantToolResult(data: [
            'flow' => 'retailer_procurement',
            'window' => $read['window'],
            'wholesale_purchases' => $totals['wholesale_purchases'],
            'wholesale_purchase_units' => $totals['wholesale_purchase_units'],
            'retailer_store_ids' => $read['store_ids'],
            'currencies' => $totals['currencies'],
            'freshness' => $read['freshness'],
            'timezone' => $read['timezone'],
        ]);
    }

    private function marginSummary(AssistantToolRequest $request): AssistantToolResult
    {
        $actor = $this->actor($request);
        $channel = $this->requiredChannel($request);
        $storeIds = $this->intersectStores($actor, ['reports.view', 'catalog.view'], $channel);

        if ($request->storeId !== null) {
            abort_unless(in_array($request->storeId, $storeIds, true), 404);
            $storeIds = [$request->storeId];
        }

        abort_if($storeIds === [], 403);
        [$fromUtc, $toUtc, $from, $to] = $this->window($request);

        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('store_products', function ($join): void {
                $join->on('store_products.store_id', '=', 'orders.store_id')
                    ->on('store_products.product_id', '=', 'order_items.product_id');
            })
            ->whereIn('orders.store_id', $storeIds)
            ->where('orders.channel', $channel)
            ->whereBetween('orders.created_at', [$fromUtc, $toUtc])
            ->whereNotIn('orders.status', ['cancelled', 'refunded']);

        $recognizedRevenue = round((float) (clone $query)->sum('order_items.line_total'), 3);
        $coveredRevenue = round((float) (clone $query)->whereNotNull('store_products.cost_price')->sum('order_items.line_total'), 3);
        $cogs = round((float) (clone $query)
            ->whereNotNull('store_products.cost_price')
            ->selectRaw('COALESCE(SUM(order_items.quantity * store_products.cost_price), 0) as cogs')
            ->value('cogs'), 3);
        $grossMargin = round($coveredRevenue - $cogs, 3);
        $coverage = $recognizedRevenue <= 0.0 ? 100.0 : round(($coveredRevenue / $recognizedRevenue) * 100, 1);

        $currencies = (clone $query)
            ->whereNotNull('orders.currency')
            ->distinct()
            ->pluck('orders.currency')
            ->map(static fn ($currency): string => strtoupper((string) $currency))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return new AssistantToolResult(data: [
            'channel' => $channel,
            'flow' => $channel === 'b2b' ? 'sell_in' : 'sell_out',
            'window' => ['from' => $from, 'to' => $to],
            'recognized_revenue' => $recognizedRevenue,
            'cost_covered_revenue' => $coveredRevenue,
            'cogs' => $cogs,
            'gross_margin' => $grossMargin,
            'gross_margin_percent_on_costed_revenue' => $coveredRevenue <= 0.0 ? null : round(($grossMargin / $coveredRevenue) * 100, 1),
            'cost_coverage_percent' => $coverage,
            'quality_state' => $coverage >= 99.999 ? 'complete' : 'incomplete',
            'incomplete_reason' => $coverage >= 99.999 ? null : 'missing_product_cost',
            'currencies' => $currencies,
            'store_ids' => $storeIds,
            'model_arithmetic_allowed' => false,
        ]);
    }

    private function supportSummary(AssistantToolRequest $request): AssistantToolResult
    {
        $actor = $this->actor($request);
        $allowed = array_values(array_intersect(
            $this->customerService->viewStoreIds($actor),
            $this->scope->allowedStoreIds($actor, 'assistant.use', $request->channel),
        ));
        sort($allowed);
        abort_if($allowed === [], 403);

        if ($request->storeId !== null) {
            abort_unless(in_array($request->storeId, $allowed, true), 404);
            $allowed = [$request->storeId];
        }

        [$fromUtc, $toUtc, $from, $to] = $this->window($request);
        $base = DB::table('customer_service_cases')
            ->whereIn('store_id', $allowed)
            ->whereBetween('created_at', [$fromUtc, $toUtc]);

        $status = (clone $base)
            ->selectRaw('status, COUNT(*) as cases_count')
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->map(static fn (object $row): array => [
                'status' => (string) $row->status,
                'cases' => (int) $row->cases_count,
            ])
            ->all();

        $priority = (clone $base)
            ->selectRaw('priority, COUNT(*) as cases_count')
            ->groupBy('priority')
            ->orderBy('priority')
            ->get()
            ->map(static fn (object $row): array => [
                'priority' => (string) $row->priority,
                'cases' => (int) $row->cases_count,
            ])
            ->all();

        $overdue = (clone $base)
            ->whereIn('status', ['open', 'pending'])
            ->whereNotNull('sla_target_at')
            ->where('sla_target_at', '<', now())
            ->count();

        return new AssistantToolResult(data: [
            'window' => ['from' => $from, 'to' => $to],
            'cases' => (clone $base)->count(),
            'open_or_pending' => (clone $base)->whereIn('status', ['open', 'pending'])->count(),
            'overdue_sla' => $overdue,
            'recent_complaints' => (clone $base)->where('type', 'complaint')->count(),
            'status_breakdown' => $status,
            'priority_breakdown' => $priority,
            'store_ids' => $allowed,
            'generated_at' => now(AnalyticsFactMaterializer::TIMEZONE)->toIso8601String(),
        ]);
    }

    /** @param list<int> $storeIds
     * @return array<string,mixed>
     */
    private function materializedRead(AssistantToolRequest $request, string $channel, array $storeIds): array
    {
        $actor = $this->actor($request);
        [, , $from, $to] = $this->window($request);
        $wholesaleStoreId = $this->positiveInt($request->entities['wholesale_store_id'] ?? null);

        if ($wholesaleStoreId === null && strtolower((string) $request->channel) === 'b2b') {
            $wholesaleStoreId = $request->storeId;
        }

        return $this->readModel->read(
            $actor,
            $channel,
            $storeIds,
            $from,
            $to,
            $wholesaleStoreId,
            $request->locale,
        );
    }

    /** @param list<array<string,mixed>> $facts
     * @return array<string,mixed>
     */
    private function factTotals(array $facts): array
    {
        $sum = static fn (string $key): float => round((float) collect($facts)->sum(
            static fn (array $row): float => (float) ($row[$key] ?? 0),
        ), 3);

        return [
            'orders' => (int) collect($facts)->sum('orders'),
            'recognized_orders' => (int) collect($facts)->sum('recognized_orders'),
            'gross_value' => $sum('gross_value'),
            'gross_revenue' => $sum('gross_revenue'),
            'discounts' => $sum('discounts'),
            'net_revenue' => $sum('net_revenue'),
            'units' => $sum('units'),
            'refunded_value' => $sum('refunded_value'),
            'refunded_orders' => (int) collect($facts)->sum('refunded_orders'),
            'cancelled_value' => $sum('cancelled_value'),
            'cancelled_orders' => (int) collect($facts)->sum('cancelled_orders'),
            'payments_collected' => $sum('payments_collected'),
            'payments_refunded' => $sum('payments_refunded'),
            'wholesale_purchases' => $sum('wholesale_purchases'),
            'wholesale_purchase_units' => $sum('wholesale_purchase_units'),
            'currencies' => collect($facts)->pluck('currency')->filter()->unique()->sort()->values()->all(),
        ];
    }

    private function actor(AssistantToolRequest $request): User
    {
        $actor = User::query()->whereKey($request->actorUserId)->where('is_active', true)->first();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function requiredChannel(AssistantToolRequest $request): string
    {
        $channel = $request->channel ?? ($request->entities['channel'] ?? null);
        $channel = is_string($channel) ? strtolower(trim($channel)) : null;

        if (! in_array($channel, ['b2b', 'b2c'], true)) {
            throw ValidationException::withMessages([
                'channel' => ['This analytics tool requires an explicit b2b or b2c channel.'],
            ]);
        }

        return $channel;
    }

    /** @return list<int> */
    private function requestedStoreIds(AssistantToolRequest $request): array
    {
        return $request->storeId === null ? [] : [$request->storeId];
    }

    /** @return list<int> */
    private function retailerStoreIds(AssistantToolRequest $request): array
    {
        $values = $request->entities['retailer_store_ids'] ?? [];
        if (is_array($values) && $values !== []) {
            return collect($values)
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
        }

        return strtolower((string) $request->channel) === 'b2c' && $request->storeId !== null
            ? [$request->storeId]
            : [];
    }

    /** @param list<string> $permissions
     * @return list<int>
     */
    private function intersectStores(User $actor, array $permissions, string $channel): array
    {
        $sets = array_map(
            fn (string $permission): array => $this->scope->allowedStoreIds($actor, $permission, $channel),
            ['assistant.use', ...$permissions],
        );

        $ids = array_shift($sets);
        foreach ($sets as $set) {
            $ids = array_values(array_intersect($ids, $set));
        }

        sort($ids);

        return $ids;
    }

    /** @return array{0:string,1:string,2:string,3:string} */
    private function window(AssistantToolRequest $request): array
    {
        $today = CarbonImmutable::now(AnalyticsFactMaterializer::TIMEZONE)->toDateString();
        $from = $this->stringValue($request->entities['from'] ?? null) ?? $today;
        $to = $this->stringValue($request->entities['to'] ?? null) ?? $from;

        $fromDate = CarbonImmutable::parse($from, AnalyticsFactMaterializer::TIMEZONE)->startOfDay();
        $toDate = CarbonImmutable::parse($to, AnalyticsFactMaterializer::TIMEZONE)->endOfDay();

        $days = (int) $fromDate->startOfDay()->diffInDays($toDate->startOfDay()) + 1;
        if ($toDate->lt($fromDate) || $days > AnalyticsReadModelService::MAX_DAYS) {
            throw ValidationException::withMessages([
                'to' => ['Assistant analytics windows must be between 1 and 90 days.'],
            ]);
        }

        return [
            $fromDate->utc()->toDateTimeString(),
            $toDate->utc()->toDateTimeString(),
            $fromDate->toDateString(),
            $toDate->toDateString(),
        ];
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }
}
