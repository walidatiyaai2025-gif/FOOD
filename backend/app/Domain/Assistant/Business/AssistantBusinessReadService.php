<?php

namespace App\Domain\Assistant\Business;

use App\Domain\Assistant\Data\AssistantAction;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use App\Models\User;
use App\Services\OperationalTenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class AssistantBusinessReadService
{
    private const TIMEZONE = 'Asia/Kuwait';

    private const NON_REVENUE_STATUSES = ['cancelled', 'refunded'];

    public function __construct(private readonly OperationalTenantScope $scope) {}

    public function execute(string $tool, AssistantToolRequest $request): AssistantToolResult
    {
        return match ($tool) {
            'sales.summary' => $this->salesSummary($request),
            'sales.compare' => $this->salesCompare($request),
            'orders.summary' => $this->ordersSummary($request),
            'orders.lookup' => $this->ordersLookup($request),
            'stores.summary' => $this->storesSummary($request),
            'stores.compare' => $this->storesCompare($request),
            'customers.summary' => $this->customersSummary($request),
            'customers.activity' => $this->customersActivity($request),
            'products.performance' => $this->productsPerformance($request),
            default => throw new \InvalidArgumentException("Unsupported Assistant business tool [{$tool}]."),
        };
    }

    private function salesSummary(AssistantToolRequest $request): AssistantToolResult
    {
        $user = $this->actor($request);
        $storeIds = $this->storeIds($user, $request, 'reports.view');
        [$fromUtc, $toUtc, $from, $to] = $this->dateWindow($request);
        $query = $this->ordersQuery($storeIds, $request, $fromUtc, $toUtc);
        $recognized = (clone $query)->whereNotIn('orders.status', self::NON_REVENUE_STATUSES);

        return new AssistantToolResult(
            data: [
                'from' => $from,
                'to' => $to,
                'orders' => (clone $query)->count('orders.id'),
                'recognized_orders' => (clone $recognized)->count('orders.id'),
                'gross_value' => round((float) (clone $query)->sum('orders.grand_total'), 3),
                'recognized_revenue' => round((float) (clone $recognized)->sum('orders.grand_total'), 3),
                'average_order_value' => round((float) ((clone $recognized)->avg('orders.grand_total') ?? 0), 3),
                'currency' => 'EGP',
                'store_ids' => $storeIds,
            ],
            actions: [$this->reportsAction($request)],
        );
    }

    private function salesCompare(AssistantToolRequest $request): AssistantToolResult
    {
        $user = $this->actor($request);
        $storeIds = $this->storeIds($user, $request, 'reports.view');
        [$fromUtc, $toUtc, $from, $to] = $this->dateWindow($request);
        $fromDate = CarbonImmutable::parse($from, self::TIMEZONE);
        $toDate = CarbonImmutable::parse($to, self::TIMEZONE);
        $days = $fromDate->diffInDays($toDate) + 1;
        $previousTo = $fromDate->subDay();
        $previousFrom = $previousTo->subDays($days - 1);

        $current = $this->salesTotals($storeIds, $request, $fromUtc, $toUtc);
        [$previousFromUtc, $previousToUtc] = $this->utcBounds(
            $previousFrom->toDateString(),
            $previousTo->toDateString(),
        );
        $previous = $this->salesTotals($storeIds, $request, $previousFromUtc, $previousToUtc);
        $delta = round($current['recognized_revenue'] - $previous['recognized_revenue'], 3);

        return new AssistantToolResult(
            data: [
                'current' => ['from' => $from, 'to' => $to, ...$current],
                'previous' => [
                    'from' => $previousFrom->toDateString(),
                    'to' => $previousTo->toDateString(),
                    ...$previous,
                ],
                'recognized_revenue_delta' => $delta,
                'recognized_revenue_change_percent' => $previous['recognized_revenue'] === 0.0
                    ? null
                    : round(($delta / $previous['recognized_revenue']) * 100, 1),
                'currency' => 'EGP',
                'store_ids' => $storeIds,
            ],
            actions: [$this->reportsAction($request)],
        );
    }

    private function ordersSummary(AssistantToolRequest $request): AssistantToolResult
    {
        $user = $this->actor($request);
        $storeIds = $this->storeIds($user, $request, 'orders.view');
        [$fromUtc, $toUtc, $from, $to] = $this->dateWindow($request);
        $query = $this->ordersQuery($storeIds, $request, $fromUtc, $toUtc);

        $statusBreakdown = (clone $query)
            ->selectRaw('orders.status, COUNT(*) as orders_count, COALESCE(SUM(orders.grand_total), 0) as order_value')
            ->groupBy('orders.status')
            ->orderBy('orders.status')
            ->get()
            ->map(static fn (object $row): array => [
                'status' => (string) $row->status,
                'orders' => (int) $row->orders_count,
                'value' => round((float) $row->order_value, 3),
            ])
            ->all();

        $rows = (clone $query)
            ->join('stores', 'stores.id', '=', 'orders.store_id')
            ->latest('orders.created_at')
            ->limit(25)
            ->get([
                'orders.id',
                'orders.order_number',
                'orders.store_id',
                'stores.name as store',
                'orders.channel',
                'orders.status',
                'orders.grand_total',
                'orders.created_at',
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'order_number' => (string) $row->order_number,
                'store_id' => (int) $row->store_id,
                'store' => (string) $row->store,
                'channel' => strtolower((string) $row->channel),
                'status' => (string) $row->status,
                'grand_total' => round((float) $row->grand_total, 3),
                'created_at' => $this->localDateTime($row->created_at),
            ])
            ->all();

        return new AssistantToolResult(
            data: [
                'from' => $from,
                'to' => $to,
                'orders' => (clone $query)->count('orders.id'),
                'gross_value' => round((float) (clone $query)->sum('orders.grand_total'), 3),
                'status_breakdown' => $statusBreakdown,
                'recent_orders' => $rows,
                'currency' => 'EGP',
                'store_ids' => $storeIds,
            ],
            actions: [$this->ordersAction($request)],
        );
    }

    private function ordersLookup(AssistantToolRequest $request): AssistantToolResult
    {
        $user = $this->actor($request);
        $storeIds = $this->storeIds($user, $request, 'orders.view');
        $orderId = $this->positiveInt($request->entities['order_id'] ?? null);
        $orderNumber = $this->stringValue($request->entities['order_number'] ?? null);

        if ($orderId === null && $orderNumber === null) {
            throw new \InvalidArgumentException('orders.lookup requires order_id or order_number.');
        }

        $row = DB::table('orders')
            ->join('stores', 'stores.id', '=', 'orders.store_id')
            ->leftJoin('b2c_customers', 'b2c_customers.id', '=', 'orders.b2c_customer_id')
            ->leftJoin('b2b_customers', 'b2b_customers.id', '=', 'orders.b2b_customer_id')
            ->leftJoin('customers as legacy_customers', 'legacy_customers.id', '=', 'orders.customer_id')
            ->whereIn('orders.store_id', $storeIds)
            ->when($request->channel !== null, fn (Builder $query) => $query->where('orders.channel', strtolower($request->channel)))
            ->when($orderId !== null, fn (Builder $query) => $query->where('orders.id', $orderId))
            ->when($orderNumber !== null, fn (Builder $query) => $query->where('orders.order_number', $orderNumber))
            ->first([
                'orders.id',
                'orders.order_number',
                'orders.store_id',
                'stores.name as store',
                'orders.channel',
                'orders.status',
                'orders.grand_total',
                'orders.currency',
                'orders.created_at',
                DB::raw('COALESCE(b2c_customers.name, b2b_customers.name, legacy_customers.name) as customer'),
            ]);

        if ($row === null) {
            return new AssistantToolResult(data: ['found' => false]);
        }

        $channel = strtolower((string) $row->channel);
        $storeId = (int) $row->store_id;

        return new AssistantToolResult(
            data: [
                'found' => true,
                'order' => [
                    'id' => (int) $row->id,
                    'order_number' => (string) $row->order_number,
                    'store_id' => $storeId,
                    'store' => (string) $row->store,
                    'channel' => $channel,
                    'status' => (string) $row->status,
                    'customer' => (string) $row->customer,
                    'grand_total' => round((float) $row->grand_total, 3),
                    'currency' => (string) $row->currency,
                    'created_at' => $this->localDateTime($row->created_at),
                ],
            ],
            actions: [
                new AssistantAction(
                    label: $this->localized($request, 'Open order', 'فتح الطلب'),
                    routeName: $channel === 'b2c' ? 'admin.b2c.module' : 'admin.b2b.module',
                    routeParameters: $channel === 'b2c'
                        ? ['module' => 'orders', 'store_id' => $storeId]
                        : ['module' => 'orders'],
                ),
            ],
            references: [[
                'type' => 'order',
                'id' => (int) $row->id,
                'order_number' => (string) $row->order_number,
                'store_id' => $storeId,
                'channel' => $channel,
            ]],
        );
    }

    private function storesSummary(AssistantToolRequest $request): AssistantToolResult
    {
        $user = $this->actor($request);
        $storeIds = $this->storeIds($user, $request, 'stores.view');
        [$fromUtc, $toUtc, $from, $to] = $this->dateWindow($request);
        $stores = $this->storeMetrics($storeIds, $request, $fromUtc, $toUtc);

        return new AssistantToolResult(
            data: [
                'from' => $from,
                'to' => $to,
                'stores' => $stores,
                'store_count' => count($stores),
                'orders' => array_sum(array_column($stores, 'orders')),
                'recognized_revenue' => round(array_sum(array_column($stores, 'recognized_revenue')), 3),
                'currency' => 'EGP',
            ],
            actions: [$this->storesAction($request)],
        );
    }

    private function storesCompare(AssistantToolRequest $request): AssistantToolResult
    {
        $user = $this->actor($request);
        $allowed = $this->storeIds($user, $request, 'stores.view');
        $requested = $this->positiveIntList($request->entities['store_ids'] ?? []);

        if ($requested !== []) {
            foreach ($requested as $storeId) {
                abort_unless(in_array($storeId, $allowed, true), 404);
            }
            $storeIds = $requested;
        } else {
            $storeIds = array_slice($allowed, 0, 10);
        }

        if (count($storeIds) < 2) {
            throw new \InvalidArgumentException('stores.compare requires at least two authorized stores.');
        }

        [$fromUtc, $toUtc, $from, $to] = $this->dateWindow($request);

        return new AssistantToolResult(
            data: [
                'from' => $from,
                'to' => $to,
                'stores' => $this->storeMetrics($storeIds, $request, $fromUtc, $toUtc),
                'currency' => 'EGP',
            ],
            actions: [$this->storesAction($request)],
        );
    }

    private function customersSummary(AssistantToolRequest $request): AssistantToolResult
    {
        $user = $this->actor($request);
        $storeIds = $this->customerStoreIds($user, $request);
        [$fromUtc, $toUtc, $from, $to] = $this->dateWindow($request);
        $query = $this->ordersQuery($storeIds, $request, $fromUtc, $toUtc);

        $rows = (clone $query)
            ->leftJoin('b2c_customers', 'b2c_customers.id', '=', 'orders.b2c_customer_id')
            ->leftJoin('b2b_customers', 'b2b_customers.id', '=', 'orders.b2b_customer_id')
            ->leftJoin('customers as legacy_customers', 'legacy_customers.id', '=', 'orders.customer_id')
            ->select([
                'orders.channel',
                'orders.b2b_customer_id',
                'orders.b2c_customer_id',
                'orders.customer_id as legacy_customer_id',
            ])
            ->selectRaw('COALESCE(b2c_customers.name, b2b_customers.name, legacy_customers.name) as customer')
            ->selectRaw('COUNT(orders.id) as orders_count')
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN orders.status NOT IN ('cancelled','refunded') THEN orders.grand_total ELSE 0 END), 0) as recognized_revenue",
            )
            ->groupBy(
                'orders.channel',
                'orders.b2b_customer_id',
                'orders.b2c_customer_id',
                'orders.customer_id',
                'b2c_customers.name',
                'b2b_customers.name',
                'legacy_customers.name',
            )
            ->orderByDesc('recognized_revenue')
            ->get();

        return new AssistantToolResult(
            data: [
                'from' => $from,
                'to' => $to,
                'active_customers' => $rows->count(),
                'recognized_revenue' => round((float) $rows->sum('recognized_revenue'), 3),
                'top_customers' => $rows->take(25)->map(static fn (object $row): array => [
                    'channel' => strtolower((string) $row->channel),
                    'customer_id' => (int) ($row->b2c_customer_id ?? $row->b2b_customer_id ?? $row->legacy_customer_id),
                    'customer' => (string) $row->customer,
                    'orders' => (int) $row->orders_count,
                    'recognized_revenue' => round((float) $row->recognized_revenue, 3),
                ])->all(),
                'currency' => 'EGP',
                'store_ids' => $storeIds,
            ],
        );
    }

    private function customersActivity(AssistantToolRequest $request): AssistantToolResult
    {
        $user = $this->actor($request);
        $channel = $this->channel($request);
        if ($channel === null) {
            throw new \InvalidArgumentException('customers.activity requires a channel.');
        }

        $storeIds = $this->customerStoreIds($user, $request, $channel);
        $customerId = $this->positiveInt($request->entities['customer_id'] ?? null);
        if ($customerId === null) {
            throw new \InvalidArgumentException('customers.activity requires customer_id.');
        }

        [$fromUtc, $toUtc, $from, $to] = $this->dateWindow($request);
        $query = $this->ordersQuery($storeIds, $request, $fromUtc, $toUtc)
            ->when(
                $channel === 'b2b',
                fn (Builder $builder) => $builder->where('orders.b2b_customer_id', $customerId),
                fn (Builder $builder) => $builder->where('orders.b2c_customer_id', $customerId),
            );

        $name = $channel === 'b2b'
            ? DB::table('b2b_customers')->where('id', $customerId)
                ->whereExists(function (Builder $sub) use ($storeIds, $customerId): void {
                    $sub->selectRaw('1')
                        ->from('orders')
                        ->where('orders.channel', 'b2b')
                        ->where('orders.b2b_customer_id', $customerId)
                        ->whereIn('orders.store_id', $storeIds);
                })
                ->value('name')
            : DB::table('b2c_customers')->where('id', $customerId)->whereIn('store_id', $storeIds)->value('name');

        if (! is_string($name)) {
            return new AssistantToolResult(data: ['found' => false]);
        }

        $rows = (clone $query)
            ->latest('orders.created_at')
            ->limit(25)
            ->get(['orders.id', 'orders.order_number', 'orders.store_id', 'orders.status', 'orders.grand_total', 'orders.created_at'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'order_number' => (string) $row->order_number,
                'store_id' => (int) $row->store_id,
                'status' => (string) $row->status,
                'grand_total' => round((float) $row->grand_total, 3),
                'created_at' => $this->localDateTime($row->created_at),
            ])
            ->all();

        $recognized = (clone $query)->whereNotIn('orders.status', self::NON_REVENUE_STATUSES);

        return new AssistantToolResult(
            data: [
                'found' => true,
                'channel' => $channel,
                'customer_id' => $customerId,
                'customer' => $name,
                'from' => $from,
                'to' => $to,
                'orders' => (clone $query)->count('orders.id'),
                'recognized_revenue' => round((float) (clone $recognized)->sum('orders.grand_total'), 3),
                'recent_orders' => $rows,
                'currency' => 'EGP',
            ],
            references: [[
                'type' => 'customer',
                'id' => $customerId,
                'channel' => $channel,
            ]],
        );
    }

    private function productsPerformance(AssistantToolRequest $request): AssistantToolResult
    {
        $user = $this->actor($request);
        $storeIds = $this->storeIds($user, $request, 'catalog.view');
        [$fromUtc, $toUtc, $from, $to] = $this->dateWindow($request);
        $productId = $this->positiveInt($request->entities['product_id'] ?? null);

        $sales = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->whereIn('orders.store_id', $storeIds)
            ->whereIn('catalogs.store_id', $storeIds)
            ->whereBetween('orders.created_at', [$fromUtc, $toUtc])
            ->whereNotIn('orders.status', self::NON_REVENUE_STATUSES)
            ->when($request->channel !== null, fn (Builder $query) => $query->where('orders.channel', strtolower($request->channel)))
            ->when($productId !== null, fn (Builder $query) => $query->where('products.id', $productId));

        $rows = (clone $sales)
            ->selectRaw(
                'products.id, products.sku, products.name, COALESCE(SUM(order_items.quantity), 0) as quantity, '.
                'COALESCE(SUM(order_items.line_total), 0) as revenue',
            )
            ->groupBy('products.id', 'products.sku', 'products.name')
            ->orderByDesc('revenue')
            ->limit(25)
            ->get()
            ->map(static fn (object $row): array => [
                'product_id' => (int) $row->id,
                'sku' => (string) $row->sku,
                'name' => (string) $row->name,
                'quantity' => round((float) $row->quantity, 3),
                'revenue' => round((float) $row->revenue, 3),
            ])
            ->all();

        return new AssistantToolResult(
            data: [
                'from' => $from,
                'to' => $to,
                'products' => $rows,
                'selling_products' => count($rows),
                'quantity_sold' => round((float) (clone $sales)->sum('order_items.quantity'), 3),
                'product_revenue' => round((float) (clone $sales)->sum('order_items.line_total'), 3),
                'currency' => 'EGP',
                'store_ids' => $storeIds,
            ],
            actions: [
                new AssistantAction(
                    label: $this->localized($request, 'Open catalog', 'فتح الكتالوج'),
                    routeName: 'admin.catalog.index',
                ),
            ],
            references: array_map(
                static fn (array $row): array => [
                    'type' => 'product',
                    'id' => $row['product_id'],
                    'sku' => $row['sku'],
                ],
                $rows,
            ),
        );
    }

    private function actor(AssistantToolRequest $request): User
    {
        $user = User::query()->whereKey($request->actorUserId)->where('is_active', true)->first();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @return list<int>
     */
    private function storeIds(
        User $user,
        AssistantToolRequest $request,
        string $permission,
        ?string $channelOverride = null,
    ): array {
        $channel = $channelOverride ?? $this->channel($request);
        $assistant = $this->scope->allowedStoreIds($user, 'assistant.use', $channel);
        $domain = $this->scope->allowedStoreIds($user, $permission, $channel);
        $allowed = array_values(array_intersect($assistant, $domain));
        sort($allowed);

        if ($request->storeId !== null) {
            abort_unless(in_array($request->storeId, $allowed, true), 404);

            return [$request->storeId];
        }

        abort_if($allowed === [], 403);

        return $allowed;
    }

    /**
     * @return list<int>
     */
    private function customerStoreIds(
        User $user,
        AssistantToolRequest $request,
        ?string $channelOverride = null,
    ): array {
        $channel = $channelOverride ?? $this->channel($request);

        if ($channel === 'b2b') {
            return $this->storeIds($user, $request, 'b2b.accounts.view', 'b2b');
        }

        if ($channel === 'b2c') {
            return $this->storeIds($user, $request, 'customers.view', 'b2c');
        }

        $ids = array_merge(
            $this->intersectStoreIds($user, 'b2b.accounts.view', 'b2b'),
            $this->intersectStoreIds($user, 'customers.view', 'b2c'),
        );
        $ids = array_values(array_unique($ids));
        sort($ids);

        if ($request->storeId !== null) {
            abort_unless(in_array($request->storeId, $ids, true), 404);

            return [$request->storeId];
        }

        abort_if($ids === [], 403);

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function intersectStoreIds(User $user, string $permission, string $channel): array
    {
        return array_values(array_intersect(
            $this->scope->allowedStoreIds($user, 'assistant.use', $channel),
            $this->scope->allowedStoreIds($user, $permission, $channel),
        ));
    }

    private function ordersQuery(
        array $storeIds,
        AssistantToolRequest $request,
        string $fromUtc,
        string $toUtc,
    ): Builder {
        return DB::table('orders')
            ->whereIn('orders.store_id', $storeIds)
            ->whereBetween('orders.created_at', [$fromUtc, $toUtc])
            ->when($request->channel !== null, fn (Builder $query) => $query->where('orders.channel', strtolower($request->channel)))
            ->when(
                $this->stringValue($request->entities['status'] ?? null) !== null,
                fn (Builder $query) => $query->where('orders.status', $this->stringValue($request->entities['status'])),
            );
    }

    /**
     * @return array{orders:int, recognized_orders:int, gross_value:float, recognized_revenue:float}
     */
    private function salesTotals(
        array $storeIds,
        AssistantToolRequest $request,
        string $fromUtc,
        string $toUtc,
    ): array {
        $query = $this->ordersQuery($storeIds, $request, $fromUtc, $toUtc);
        $recognized = (clone $query)->whereNotIn('orders.status', self::NON_REVENUE_STATUSES);

        return [
            'orders' => (clone $query)->count('orders.id'),
            'recognized_orders' => (clone $recognized)->count('orders.id'),
            'gross_value' => round((float) (clone $query)->sum('orders.grand_total'), 3),
            'recognized_revenue' => round((float) (clone $recognized)->sum('orders.grand_total'), 3),
        ];
    }

    /**
     * @return list<array<string, int|float|string>>
     */
    private function storeMetrics(
        array $storeIds,
        AssistantToolRequest $request,
        string $fromUtc,
        string $toUtc,
    ): array {
        $stores = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->whereIn('stores.id', $storeIds)
            ->where('stores.is_active', true)
            ->orderBy('stores.id')
            ->get(['stores.id', 'stores.name', 'store_types.code as channel']);

        return $stores->map(function (object $store) use ($request, $fromUtc, $toUtc): array {
            $query = DB::table('orders')
                ->where('orders.store_id', (int) $store->id)
                ->whereBetween('orders.created_at', [$fromUtc, $toUtc])
                ->when($request->channel !== null, fn (Builder $builder) => $builder->where('orders.channel', strtolower($request->channel)));
            $recognized = (clone $query)->whereNotIn('orders.status', self::NON_REVENUE_STATUSES);

            return [
                'store_id' => (int) $store->id,
                'store' => (string) $store->name,
                'channel' => strtolower((string) $store->channel),
                'orders' => (clone $query)->count('orders.id'),
                'recognized_orders' => (clone $recognized)->count('orders.id'),
                'recognized_revenue' => round((float) (clone $recognized)->sum('orders.grand_total'), 3),
            ];
        })->all();
    }

    /**
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function dateWindow(AssistantToolRequest $request): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->toDateString();
        $from = $this->stringValue($request->entities['from'] ?? null) ?? $today;
        $to = $this->stringValue($request->entities['to'] ?? null) ?? $from;

        $fromDate = CarbonImmutable::parse($from, self::TIMEZONE)->startOfDay();
        $toDate = CarbonImmutable::parse($to, self::TIMEZONE)->endOfDay();

        if ($toDate->lessThan($fromDate)) {
            throw new \InvalidArgumentException('Assistant business tool date range is invalid.');
        }

        if ($fromDate->diffInDays($toDate) > 366) {
            throw new \InvalidArgumentException('Assistant business tool date range exceeds 366 days.');
        }

        return [
            $fromDate->utc()->toDateTimeString(),
            $toDate->utc()->toDateTimeString(),
            $fromDate->toDateString(),
            $toDate->toDateString(),
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function utcBounds(string $from, string $to): array
    {
        return [
            CarbonImmutable::parse($from.' 00:00:00', self::TIMEZONE)->utc()->toDateTimeString(),
            CarbonImmutable::parse($to.' 23:59:59', self::TIMEZONE)->utc()->toDateTimeString(),
        ];
    }

    private function channel(AssistantToolRequest $request): ?string
    {
        $channel = $request->channel ?? $this->stringValue($request->entities['channel'] ?? null);
        if ($channel === null) {
            return null;
        }

        $channel = strtolower($channel);
        if (! in_array($channel, ['b2b', 'b2c'], true)) {
            throw new \InvalidArgumentException('Assistant business tool channel must be b2b or b2c.');
        }

        return $channel;
    }

    private function reportsAction(AssistantToolRequest $request): AssistantAction
    {
        return new AssistantAction(
            label: $this->localized($request, 'Open reports', 'فتح التقارير'),
            routeName: 'admin.reports.index',
        );
    }

    private function ordersAction(AssistantToolRequest $request): AssistantAction
    {
        return new AssistantAction(
            label: $this->localized($request, 'Open orders', 'فتح الطلبات'),
            routeName: 'admin.operations.orders.index',
        );
    }

    private function storesAction(AssistantToolRequest $request): AssistantAction
    {
        return new AssistantAction(
            label: $this->localized($request, 'Open stores', 'فتح المتاجر'),
            routeName: 'admin.retail-stores.index',
        );
    }

    private function localized(AssistantToolRequest $request, string $english, string $arabic): string
    {
        return strtolower($request->locale) === 'ar' ? $arabic : $english;
    }

    private function localDateTime(mixed $value): string
    {
        return CarbonImmutable::parse((string) $value, 'UTC')
            ->setTimezone(self::TIMEZONE)
            ->format('Y-m-d H:i');
    }

    private function positiveInt(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }

    /**
     * @return list<int>
     */
    private function positiveIntList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $values = [];
        foreach ($value as $item) {
            $integer = $this->positiveInt($item);
            if ($integer !== null) {
                $values[] = $integer;
            }
        }

        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
