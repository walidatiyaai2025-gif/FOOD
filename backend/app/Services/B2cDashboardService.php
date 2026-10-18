<?php

namespace App\Services;

use App\Domain\Analytics\Semantic\MetricDictionary;
use App\Domain\Analytics\Semantic\SemanticMetricService;
use App\Models\User;
use App\Support\DisplayNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class B2cDashboardService
{
    private const TIMEZONE = 'Asia/Kuwait';

    public function __construct(
        private readonly RetailMerchantDashboardService $merchantIntelligence,
        private readonly SemanticMetricService $semanticMetrics,
    ) {}

    /** @param list<int> $storeIds */
    public function build(
        User $user,
        array $storeIds,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?string $search = null,
    ): array {
        [$rangeFrom, $rangeTo] = $this->range($fromDate, $toDate);
        [$from] = $this->utcBounds($rangeFrom);
        [, $to] = $this->utcBounds($rangeTo);

        $days = (int) $rangeFrom->diffInDays($rangeTo) + 1;
        $previousRangeTo = $rangeFrom->subDay();
        $previousRangeFrom = $previousRangeTo->subDays($days - 1);
        [$previousFrom] = $this->utcBounds($previousRangeFrom);
        [, $previousTo] = $this->utcBounds($previousRangeTo);

        $orders = $this->orders($storeIds, $from, $to);
        $previousOrders = $this->orders($storeIds, $previousFrom, $previousTo);

        $metrics = $this->semanticMetrics->orderSnapshot(
            $orders,
            MetricDictionary::FLOW_SELL_OUT,
            $rangeFrom->toDateString(),
            $rangeTo->toDateString(),
            ['store_ids' => $storeIds],
        );
        $previousMetrics = $this->semanticMetrics->orderSnapshot(
            $previousOrders,
            MetricDictionary::FLOW_SELL_OUT,
            $previousRangeFrom->toDateString(),
            $previousRangeTo->toDateString(),
            ['store_ids' => $storeIds],
        );

        $orderCount = (int) $metrics['values']['orders'];
        $previousOrderCount = (int) $previousMetrics['values']['orders'];
        $revenue = (float) $metrics['values']['net_revenue'];
        $previousRevenue = (float) $previousMetrics['values']['net_revenue'];
        $averageOrderValue = (float) $metrics['values']['average_order_value'];
        $previousAverageOrderValue = (float) $previousMetrics['values']['average_order_value'];
        $sold = (float) $metrics['values']['units'];
        $previousSold = (float) $previousMetrics['values']['units'];

        $activeWindow = max(1, (int) config('admin.active_user_window_minutes', 15));
        $activeSince = now()->subMinutes($activeWindow);
        $activeUsers = DB::table('users')
            ->join('user_store_roles', 'user_store_roles.user_id', '=', 'users.id')
            ->whereIn('user_store_roles.store_id', $storeIds)
            ->where('users.is_active', true)
            ->whereNotNull('users.last_seen_at')
            ->where('users.last_seen_at', '>=', $activeSince)
            ->distinct()
            ->count('users.id');

        return [
            'selected_date' => $rangeTo->toDateString(),
            'selected_from' => $rangeFrom->toDateString(),
            'selected_to' => $rangeTo->toDateString(),
            'range_days' => $days,
            'timezone' => self::TIMEZONE,
            'currency' => 'EGP',
            'scope_store_ids' => $storeIds,
            'metric_provenance' => $metrics['provenance'],
            'metric_definitions' => $metrics['definitions'],
            'kpis' => [
                'active_users' => [
                    'value' => $activeUsers,
                    'delta' => null,
                    'window_minutes' => $activeWindow,
                ],
                'orders' => [
                    'value' => $orderCount,
                    'previous_value' => $previousOrderCount,
                    'delta' => $this->delta($orderCount, $previousOrderCount),
                ],
                'revenue' => [
                    'value' => round($revenue, 3),
                    'previous_value' => round($previousRevenue, 3),
                    'delta' => $this->delta($revenue, $previousRevenue),
                ],
                'average_order_value' => [
                    'value' => round($averageOrderValue, 3),
                    'previous_value' => round($previousAverageOrderValue, 3),
                    'delta' => $this->delta($averageOrderValue, $previousAverageOrderValue),
                ],
                'products_sold' => [
                    'value' => round($sold, 3),
                    'previous_value' => round($previousSold, 3),
                    'delta' => $this->delta($sold, $previousSold),
                ],
            ],
            'series' => $this->series($storeIds, $rangeFrom, $rangeTo),
            'distribution' => $this->distribution($orders),
            'previous_distribution' => $this->distribution($previousOrders),
            'low_stock' => $this->lowStock($storeIds),
            'recent_orders' => $this->recentOrders($storeIds, $from, $to),
            'mobile_apps' => $this->mobileApps(),
            'notifications_unread' => $this->unreadNotifications($user, $storeIds),
            'search' => $this->search($storeIds, trim((string) $search)),
            'merchant_intelligence' => count($storeIds) === 1
                ? $this->merchantIntelligence->forUserStore(
                    $user,
                    (int) $storeIds[0],
                    $rangeFrom,
                    $rangeTo,
                )
                : null,
        ];
    }

    /** @param list<int> $storeIds */
    private function orders(array $storeIds, string $from, string $to): Builder
    {
        return DB::table('orders')
            ->whereIn('orders.store_id', $storeIds)
            ->where('orders.channel', 'b2c')
            ->whereBetween('orders.created_at', [$from, $to]);
    }

    /** @param list<int> $storeIds */
    private function series(array $storeIds, CarbonImmutable $rangeFrom, CarbonImmutable $rangeTo): array
    {
        $rows = [];

        for ($day = $rangeFrom; $day->lte($rangeTo); $day = $day->addDay()) {
            [$from, $to] = $this->utcBounds($day);
            $query = $this->orders($storeIds, $from, $to);
            $metrics = $this->semanticMetrics->orderSnapshot(
                $query,
                MetricDictionary::FLOW_SELL_OUT,
                $day->toDateString(),
                $day->toDateString(),
                ['store_ids' => $storeIds],
            );

            $rows[] = [
                'date' => $day->toDateString(),
                'label' => $day->format('d/m'),
                'orders' => (int) $metrics['values']['orders'],
                'revenue' => (float) $metrics['values']['net_revenue'],
            ];
        }

        return $rows;
    }

    private function distribution(Builder $orders): array
    {
        $raw = (clone $orders)
            ->selectRaw('orders.status, COUNT(*) as total')
            ->groupBy('orders.status')
            ->pluck('total', 'orders.status');

        $groups = [
            'processing' => ['pending', 'confirmed', 'processing', 'paid', 'accepted'],
            'out_for_delivery' => ['assigned', 'picked_up', 'out_for_delivery', 'in_transit'],
            'delivered' => ['delivered', 'completed'],
            'cancelled' => ['cancelled', 'refunded'],
        ];

        $result = [];
        foreach ($groups as $key => $statuses) {
            $result[$key] = collect($statuses)->sum(fn (string $status): int => (int) ($raw[$status] ?? 0));
        }

        return $result;
    }

    /** @param list<int> $storeIds */
    private function lowStock(array $storeIds): array
    {
        $threshold = max(0, (float) config('admin.low_stock_threshold', 12));

        return DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->join('products', 'products.id', '=', 'inventories.product_id')
            ->leftJoin('product_images', function ($join): void {
                $join->on('product_images.product_id', '=', 'products.id')
                    ->where('product_images.is_primary', true);
            })
            ->whereIn('warehouses.store_id', $storeIds)
            ->where('products.is_active', true)
            ->select([
                'products.id',
                'products.name',
                'products.sku',
                'product_images.path as image',
            ])
            ->selectRaw('SUM(inventories.quantity - inventories.reserved_quantity) as available')
            ->groupBy('products.id', 'products.name', 'products.sku', 'product_images.path')
            ->havingRaw('SUM(inventories.quantity - inventories.reserved_quantity) <= ?', [$threshold])
            ->orderBy('available')
            ->orderBy('products.id')
            ->limit(5)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'sku' => (string) $row->sku,
                'image' => $row->image === null ? null : (string) $row->image,
                'available' => round((float) $row->available, 3),
            ])
            ->all();
    }

    /** @param list<int> $storeIds */
    private function recentOrders(array $storeIds, string $from, string $to): array
    {
        return DB::table('orders')
            ->leftJoin('b2c_customers', function ($join): void {
                $join->on('b2c_customers.id', '=', 'orders.b2c_customer_id')
                    ->on('b2c_customers.store_id', '=', 'orders.store_id');
            })
            ->leftJoin('customers as legacy_customers', 'legacy_customers.id', '=', 'orders.customer_id')
            ->leftJoin('order_items', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.store_id', $storeIds)
            ->where('orders.channel', 'b2c')
            ->whereBetween('orders.created_at', [$from, $to])
            ->select([
                'orders.id',
                'orders.order_number',
                'orders.status',
                'orders.grand_total',
                'orders.currency',
                'orders.created_at',
            ])
            ->selectRaw('COALESCE(b2c_customers.name, legacy_customers.name, ?) as customer', ['—'])
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0) as items_count')
            ->groupBy(
                'orders.id',
                'orders.order_number',
                'orders.status',
                'orders.grand_total',
                'orders.currency',
                'orders.created_at',
                'b2c_customers.name',
                'legacy_customers.name',
            )
            ->orderByDesc('orders.created_at')
            ->limit(5)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'number' => DisplayNumber::reference($row->id),
                'internal_number' => (string) $row->order_number,
                'status' => (string) $row->status,
                'amount' => round((float) $row->grand_total, 3),
                'currency' => (string) $row->currency,
                'created_at' => (string) $row->created_at,
                'customer' => (string) $row->customer,
                'items' => (int) $row->items_count,
            ])
            ->all();
    }

    private function mobileApps(): array
    {
        return DB::table('mobile_app_settings')
            ->where('environment', 'production')
            ->whereIn('app', ['customer', 'driver'])
            ->orderBy('app')
            ->get([
                'app',
                'display_name',
                'google_play_url',
                'app_store_url',
                'published_version',
            ])
            ->map(fn (object $row): array => [
                'app' => (string) $row->app,
                'name' => (string) $row->display_name,
                'google_play_url' => $row->google_play_url === null ? null : (string) $row->google_play_url,
                'app_store_url' => $row->app_store_url === null ? null : (string) $row->app_store_url,
                'version' => $row->published_version === null ? null : (string) $row->published_version,
            ])
            ->all();
    }

    /** @param list<int> $storeIds */
    private function search(array $storeIds, string $term): array
    {
        if ($term === '') {
            return [];
        }

        $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        $orders = DB::table('orders')
            ->leftJoin('b2c_customers', function ($join): void {
                $join->on('b2c_customers.id', '=', 'orders.b2c_customer_id')
                    ->on('b2c_customers.store_id', '=', 'orders.store_id');
            })
            ->leftJoin('customers as legacy_customers', 'legacy_customers.id', '=', 'orders.customer_id')
            ->whereIn('orders.store_id', $storeIds)
            ->where('orders.channel', 'b2c')
            ->where(function (Builder $query) use ($needle, $term): void {
                $query->where('orders.order_number', 'like', $needle)
                    ->orWhere('b2c_customers.name', 'like', $needle)
                    ->orWhere('legacy_customers.name', 'like', $needle);

                $shortId = DisplayNumber::referenceId($term);
                if ($shortId !== null) {
                    $query->orWhere('orders.id', $shortId);
                }
            })
            ->latest('orders.created_at')
            ->limit(4)
            ->get([
                'orders.id',
                'orders.order_number',
                DB::raw("COALESCE(b2c_customers.name, legacy_customers.name, '-') as name"),
            ])
            ->map(fn (object $row): array => [
                'type' => 'order',
                'title' => DisplayNumber::reference($row->id),
                'subtitle' => (string) $row->name,
                'route' => route('admin.b2c.module', ['module' => 'orders']),
            ]);

        $products = DB::table('products')
            ->join('store_products', 'store_products.product_id', '=', 'products.id')
            ->whereIn('store_products.store_id', $storeIds)
            ->where(function (Builder $query) use ($needle): void {
                $query->where('products.name', 'like', $needle)
                    ->orWhere('products.sku', 'like', $needle);
            })
            ->distinct()
            ->limit(4)
            ->get(['products.name', 'products.sku'])
            ->map(fn (object $row): array => [
                'type' => 'product',
                'title' => (string) $row->name,
                'subtitle' => (string) $row->sku,
                'route' => route('admin.b2c.module', ['module' => 'products']),
            ]);

        $customers = DB::table('b2c_customers')
            ->whereIn('b2c_customers.store_id', $storeIds)
            ->where(function (Builder $query) use ($needle): void {
                $query->where('b2c_customers.name', 'like', $needle)
                    ->orWhere('b2c_customers.email', 'like', $needle)
                    ->orWhere('b2c_customers.phone', 'like', $needle);
            })
            ->limit(4)
            ->get(['b2c_customers.name', 'b2c_customers.email'])
            ->map(fn (object $row): array => [
                'type' => 'customer',
                'title' => (string) $row->name,
                'subtitle' => $row->email === null ? '' : (string) $row->email,
                'route' => route('admin.b2c.module', ['module' => 'customers']),
            ]);

        return $orders->concat($products)->concat($customers)->take(8)->values()->all();
    }

    /** @param list<int> $storeIds */
    private function unreadNotifications(User $user, array $storeIds): int
    {
        return DB::table('notifications')
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->whereIn('app', ['all', 'dashboard'])
            ->where(function (Builder $target) use ($user): void {
                $target->where('target_channel', 'all')
                    ->orWhere('target_channel', 'b2c')
                    ->orWhere('user_id', $user->id);
            })
            ->where(function (Builder $scope) use ($user, $storeIds): void {
                $scope->whereNull('store_id')
                    ->orWhere('user_id', $user->id)
                    ->orWhereIn('store_id', $storeIds);
            })
            ->where(function (Builder $audience) use ($user): void {
                $audience->where('audience', 'all')
                    ->orWhere('user_id', $user->id);
            })
            ->whereNotExists(function (Builder $read) use ($user): void {
                $read->selectRaw('1')
                    ->from('notification_reads')
                    ->whereColumn('notification_reads.notification_id', 'notifications.id')
                    ->where('notification_reads.user_id', $user->id);
            })
            ->count();
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function range(?string $fromDate, ?string $toDate): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();

        if ($fromDate !== null && $toDate === null) {
            $from = CarbonImmutable::parse($fromDate, self::TIMEZONE)->startOfDay();

            return [$from, $from];
        }

        $to = CarbonImmutable::parse($toDate ?: $today->toDateString(), self::TIMEZONE)->startOfDay();
        $from = CarbonImmutable::parse($fromDate ?: $to->subDays(6)->toDateString(), self::TIMEZONE)->startOfDay();

        return [$from, $to];
    }

    private function delta(float|int $current, float|int $previous): ?float
    {
        if ((float) $previous === 0.0) {
            return (float) $current === 0.0 ? 0.0 : null;
        }

        return round((((float) $current - (float) $previous) / abs((float) $previous)) * 100, 1);
    }

    /** @return array{0:string,1:string} */
    private function utcBounds(CarbonImmutable $localDay): array
    {
        return [
            $localDay->startOfDay()->utc()->toDateTimeString(),
            $localDay->endOfDay()->utc()->toDateTimeString(),
        ];
    }
}
