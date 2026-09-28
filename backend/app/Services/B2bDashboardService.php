<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class B2bDashboardService
{
    private const TIMEZONE = 'Asia/Kuwait';

    /** @param list<int> $storeIds */
    public function build(User $user, array $storeIds, ?string $date = null): array
    {
        $selected = CarbonImmutable::parse(
            $date ?: CarbonImmutable::now(self::TIMEZONE)->toDateString(),
            self::TIMEZONE,
        )->startOfDay();

        [$from, $to] = $this->utcBounds($selected);
        [$previousFrom, $previousTo] = $this->utcBounds($selected->subDay());

        $orders = $this->orders($storeIds, $from, $to);
        $previousOrders = $this->orders($storeIds, $previousFrom, $previousTo);

        $orderCount = (clone $orders)->count('orders.id');
        $previousOrderCount = (clone $previousOrders)->count('orders.id');

        $revenue = (float) (clone $orders)
            ->whereNotIn('orders.status', ['cancelled', 'refunded'])
            ->sum('orders.grand_total');
        $previousRevenue = (float) (clone $previousOrders)
            ->whereNotIn('orders.status', ['cancelled', 'refunded'])
            ->sum('orders.grand_total');

        $validOrderCount = (int) (clone $orders)
            ->whereNotIn('orders.status', ['cancelled', 'refunded'])
            ->count('orders.id');
        $previousValidOrderCount = (int) (clone $previousOrders)
            ->whereNotIn('orders.status', ['cancelled', 'refunded'])
            ->count('orders.id');

        $average = $validOrderCount > 0 ? $revenue / $validOrderCount : 0.0;
        $previousAverage = $previousValidOrderCount > 0 ? $previousRevenue / $previousValidOrderCount : 0.0;

        $customerCount = DB::table('b2b_customers')
            ->where('created_at', '<=', $to)
            ->count();
        $previousCustomerCount = DB::table('b2b_customers')
            ->where('created_at', '<', $from)
            ->count();

        $series = $this->series($storeIds, $selected);
        $recentOrders = $this->recentOrders($storeIds);
        $lowStock = $this->lowStock($storeIds);

        return [
            'selected_date' => $selected->toDateString(),
            'timezone' => self::TIMEZONE,
            'currency' => 'EGP',
            'scope_store_ids' => $storeIds,
            'kpis' => [
                'sales' => [
                    'value' => round($revenue, 3),
                    'delta' => $this->delta($revenue, $previousRevenue),
                ],
                'orders' => [
                    'value' => $orderCount,
                    'delta' => $this->delta($orderCount, $previousOrderCount),
                ],
                'customers' => [
                    'value' => $customerCount,
                    'delta' => $this->delta($customerCount, $previousCustomerCount),
                ],
                'average' => [
                    'value' => round($average, 3),
                    'delta' => $this->delta($average, $previousAverage),
                ],
            ],
            'series' => $series,
            'distribution' => $this->distribution($orders),
            'recent_orders' => $recentOrders,
            'top_products' => $this->topProducts($storeIds, $selected),
            'alerts' => $this->alerts($storeIds, $recentOrders, $lowStock),
        ];
    }

    /** @param list<int> $storeIds */
    private function orders(array $storeIds, string $from, string $to): Builder
    {
        return DB::table('orders')
            ->whereIn('orders.store_id', $storeIds)
            ->where('orders.channel', 'b2b')
            ->whereNotNull('orders.b2b_customer_id')
            ->whereBetween('orders.created_at', [$from, $to]);
    }

    /** @param list<int> $storeIds */
    private function series(array $storeIds, CarbonImmutable $selected): array
    {
        $rows = [];

        for ($offset = 6; $offset >= 0; $offset--) {
            $day = $selected->subDays($offset);
            [$from, $to] = $this->utcBounds($day);
            $query = $this->orders($storeIds, $from, $to);

            $rows[] = [
                'date' => $day->toDateString(),
                'label' => $day->format('d/m'),
                'orders' => (int) (clone $query)->count('orders.id'),
                'revenue' => round((float) (clone $query)
                    ->whereNotIn('orders.status', ['cancelled', 'refunded'])
                    ->sum('orders.grand_total'), 3),
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
            'delivery' => ['assigned', 'picked_up', 'out_for_delivery', 'in_transit'],
            'completed' => ['delivered', 'completed'],
        ];

        $result = [];
        foreach ($groups as $key => $statuses) {
            $result[$key] = collect($statuses)
                ->sum(fn (string $status): int => (int) ($raw[$status] ?? 0));
        }

        return $result;
    }

    /** @param list<int> $storeIds */
    private function recentOrders(array $storeIds): array
    {
        return DB::table('orders')
            ->join('b2b_customers', 'b2b_customers.id', '=', 'orders.b2b_customer_id')
            ->join('stores', 'stores.id', '=', 'orders.store_id')
            ->whereIn('orders.store_id', $storeIds)
            ->where('orders.channel', 'b2b')
            ->whereNotNull('orders.b2b_customer_id')
            ->latest('orders.created_at')
            ->limit(4)
            ->get([
                'orders.id',
                'orders.order_number',
                'orders.status',
                'orders.grand_total',
                'orders.currency',
                'orders.created_at',
                'b2b_customers.name as customer',
                'stores.name as store',
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'number' => (string) $row->order_number,
                'status' => (string) $row->status,
                'amount' => round((float) $row->grand_total, 3),
                'currency' => (string) $row->currency,
                'created_at' => (string) $row->created_at,
                'customer' => (string) $row->customer,
                'store' => (string) $row->store,
            ])
            ->all();
    }

    /** @param list<int> $storeIds */
    private function topProducts(array $storeIds, CarbonImmutable $selected): array
    {
        [$from] = $this->utcBounds($selected->subDays(6));
        [, $to] = $this->utcBounds($selected);

        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('product_images', function ($join): void {
                $join->on('product_images.product_id', '=', 'products.id')
                    ->where('product_images.is_primary', true);
            })
            ->whereIn('orders.store_id', $storeIds)
            ->where('orders.channel', 'b2b')
            ->whereNotNull('orders.b2b_customer_id')
            ->whereBetween('orders.created_at', [$from, $to])
            ->whereNotIn('orders.status', ['cancelled', 'refunded'])
            ->select([
                'products.id',
                'products.name',
                'products.sku',
                'product_images.path as image',
            ])
            ->selectRaw('SUM(order_items.quantity) as sold_quantity')
            ->groupBy('products.id', 'products.name', 'products.sku', 'product_images.path')
            ->orderByDesc('sold_quantity')
            ->limit(4)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'sku' => (string) $row->sku,
                'image' => $row->image === null ? null : (string) $row->image,
                'quantity' => round((float) $row->sold_quantity, 3),
            ])
            ->all();
    }

    /** @param list<int> $storeIds */
    private function lowStock(array $storeIds): array
    {
        $threshold = max(0, (float) config('admin.low_stock_threshold', 12));

        return DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->join('products', 'products.id', '=', 'inventories.product_id')
            ->whereIn('warehouses.store_id', $storeIds)
            ->where('products.is_active', true)
            ->select(['products.id', 'products.name', 'products.sku'])
            ->selectRaw('SUM(inventories.quantity - inventories.reserved_quantity) as available')
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->havingRaw('SUM(inventories.quantity - inventories.reserved_quantity) <= ?', [$threshold])
            ->orderBy('available')
            ->limit(2)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'sku' => (string) $row->sku,
                'available' => round((float) $row->available, 3),
            ])
            ->all();
    }

    /**
     * @param  list<int>  $storeIds
     * @param  list<array<string,mixed>>  $recentOrders
     * @param  list<array<string,mixed>>  $lowStock
     */
    private function alerts(array $storeIds, array $recentOrders, array $lowStock): array
    {
        $alerts = [];

        if ($lowStock !== []) {
            $row = $lowStock[0];
            $alerts[] = [
                'key' => 'low_stock',
                'icon' => 'inventory',
                'title' => 'low_stock',
                'body' => $row['name'].' · '.$row['available'],
                'tone' => 'orange',
                'url' => route('admin.b2b.module', ['module' => 'inventory']),
            ];
        }

        if ($recentOrders !== []) {
            $row = $recentOrders[0];
            $alerts[] = [
                'key' => 'new_order',
                'icon' => 'orders',
                'title' => 'new_order',
                'body' => $row['currency'].' '.number_format((float) $row['amount'], 3).' · '.$row['number'],
                'tone' => 'green',
                'url' => route('admin.b2b.module', ['module' => 'orders']),
            ];
        }

        $delivered = DB::table('orders')
            ->whereIn('store_id', $storeIds)
            ->where('channel', 'b2b')
            ->whereNotNull('b2b_customer_id')
            ->whereIn('status', ['delivered', 'completed'])
            ->latest('updated_at')
            ->first(['order_number']);

        if ($delivered !== null) {
            $alerts[] = [
                'key' => 'delivered',
                'icon' => 'delivery',
                'title' => 'delivered',
                'body' => (string) $delivered->order_number,
                'tone' => 'green',
                'url' => route('admin.b2b.module', ['module' => 'orders']),
            ];
        }

        $overdue = DB::table('invoices')
            ->join('orders', 'orders.id', '=', 'invoices.order_id')
            ->whereIn('orders.store_id', $storeIds)
            ->where('orders.channel', 'b2b')
            ->whereNotNull('invoices.due_at')
            ->where('invoices.due_at', '<', now())
            ->whereNotIn('invoices.status', ['paid', 'settled', 'cancelled', 'void'])
            ->orderBy('invoices.due_at')
            ->first(['invoices.invoice_number', 'invoices.total', 'invoices.currency']);

        if ($overdue !== null) {
            $alerts[] = [
                'key' => 'overdue_invoice',
                'icon' => 'revenue',
                'title' => 'overdue_invoice',
                'body' => $overdue->currency.' '.number_format((float) $overdue->total, 3).' · '.$overdue->invoice_number,
                'tone' => 'orange',
                'url' => route('admin.b2b.module', ['module' => 'finance']),
            ];
        }

        return array_slice($alerts, 0, 4);
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
