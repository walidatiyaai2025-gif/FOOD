<?php

namespace App\Services;

use App\Models\User;
use App\Support\DisplayNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ManagementReportService
{
    public const UI_LIMIT = 100;

    public const EXPORT_LIMIT = 5000;

    private const TIMEZONE = 'Asia/Kuwait';

    private const NON_REVENUE_STATUSES = ['cancelled', 'refunded'];

    /** @return array<string, string> */
    public function catalog(): array
    {
        return [
            'orders' => 'reports.families.orders',
            'products' => 'reports.families.products',
            'customers' => 'reports.families.customers',
            'operations' => 'reports.families.operations',
            'sales_exceptions' => 'reports.families.sales_exceptions',
            'field_operations' => 'reports.families.field_operations',
        ];
    }

    /** @param array<string, mixed> $filters */
    public function run(User $user, string $report, array $filters, int $limit = self::UI_LIMIT, int $offset = 0): array
    {
        if (! array_key_exists($report, $this->catalog())) {
            throw ValidationException::withMessages([
                'report' => __('reports.invalid_report'),
            ]);
        }

        $filters = $this->normalizeFilters($filters);
        $filters['store_id'] = $this->resolveStoreScope($user, $filters, 'reports.view');
        $filters = $this->enforceChannelScope($user, $filters);

        return match ($report) {
            'orders' => $this->ordersReport($filters, $limit, $offset),
            'products' => $this->productsReport($filters, $limit, $offset),
            'customers' => $this->customersReport($filters, $limit, $offset),
            'operations' => $this->operationsReport($filters, $limit, $offset),
            'sales_exceptions' => $this->salesExceptionsReport($filters, $limit, $offset),
            'field_operations' => $this->fieldOperationsReport($user, $filters, $limit, $offset),
            default => throw ValidationException::withMessages([
                'report' => __('reports.invalid_report'),
            ]),
        };
    }

    /** @param array<string, mixed> $filters */
    public function authorizeExport(User $user, array $filters): void
    {
        $filters = $this->normalizeFilters($filters);
        $this->resolveStoreScope($user, $filters, 'reports.export');
        $this->enforceChannelScope($user, $filters);
    }

    /** @param array<string, mixed> $filters */
    public function normalizeFilters(array $filters): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->toDateString();

        $channel = $this->nullableString($filters['channel'] ?? null);
        if ($channel !== null) {
            $channel = strtolower($channel);
            if (! in_array($channel, ['b2b', 'b2c'], true)) {
                throw ValidationException::withMessages([
                    'channel' => ['Channel must be b2b or b2c.'],
                ]);
            }
        }

        return [
            'from' => (string) ($filters['from'] ?? CarbonImmutable::now(self::TIMEZONE)->subDays(29)->toDateString()),
            'to' => (string) ($filters['to'] ?? $today),
            'store_id' => $this->positiveInt($filters['store_id'] ?? null),
            'channel' => $channel,
            'status' => $this->nullableString($filters['status'] ?? null),
            'product_id' => $this->positiveInt($filters['product_id'] ?? null),
            'category_id' => $this->positiveInt($filters['category_id'] ?? null),
            'customer_id' => $this->positiveInt($filters['customer_id'] ?? null),
            'payment_provider' => $this->nullableString($filters['payment_provider'] ?? null),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function ordersReport(array $filters, int $limit, int $offset): array
    {
        $query = DB::table('orders')
            ->join('stores', 'stores.id', '=', 'orders.store_id')
            ->leftJoin('b2c_customers', 'b2c_customers.id', '=', 'orders.b2c_customer_id')
            ->leftJoin('b2b_customers', 'b2b_customers.id', '=', 'orders.b2b_customer_id')
            ->leftJoin('customers as legacy_customers', 'legacy_customers.id', '=', 'orders.customer_id');

        $this->applyOrderFilters($query, $filters);

        $totalRows = (clone $query)->count('orders.id');
        $grossValue = (float) (clone $query)->sum('orders.grand_total');
        $recognizedRevenue = (float) (clone $query)
            ->whereNotIn('orders.status', self::NON_REVENUE_STATUSES)
            ->sum('orders.grand_total');
        $averageOrder = (float) ((clone $query)
            ->whereNotIn('orders.status', self::NON_REVENUE_STATUSES)
            ->avg('orders.grand_total') ?? 0);

        $statusBreakdown = (clone $query)
            ->selectRaw('orders.status, COUNT(*) as orders_count, COALESCE(SUM(orders.grand_total), 0) as order_value')
            ->groupBy('orders.status')
            ->orderBy('orders.status')
            ->get()
            ->map(fn (object $row): array => [
                'status' => (string) $row->status,
                'orders' => (int) $row->orders_count,
                'value' => round((float) $row->order_value, 3),
            ])
            ->all();

        $payments = DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->whereIn('payments.status', ['paid', 'captured', 'refunded']);

        $this->applyOrderFilters($payments, $filters);

        $paymentBreakdown = $payments
            ->selectRaw('payments.provider, payments.status, COUNT(*) as payments_count, COALESCE(SUM(payments.amount), 0) as amount')
            ->groupBy('payments.provider', 'payments.status')
            ->orderBy('payments.provider')
            ->get()
            ->map(fn (object $row): array => [
                'provider' => (string) $row->provider,
                'status' => (string) $row->status,
                'payments' => (int) $row->payments_count,
                'amount' => round((float) $row->amount, 3),
            ])
            ->all();

        $rows = (clone $query)
            ->select([
                'orders.id',
                'orders.order_number',
                'orders.created_at',
                'stores.name as store',
                'orders.channel',
                'orders.status',
                DB::raw('COALESCE(b2c_customers.name, b2b_customers.name, legacy_customers.name) as customer'),
                'orders.grand_total',
            ])
            ->selectSub(
                DB::table('payments as latest_payment')
                    ->select('latest_payment.provider')
                    ->whereColumn('latest_payment.order_id', 'orders.id')
                    ->latest('latest_payment.id')
                    ->limit(1),
                'payment_provider',
            )
            ->latest('orders.created_at')
            ->offset(max(0, $offset))
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'order_number' => DisplayNumber::reference($row->id),
                'internal_order_number' => (string) $row->order_number,
                'date' => $this->localDateTime($row->created_at),
                'store' => (string) $row->store,
                'channel' => $this->channelLabel((string) $row->channel),
                'status' => (string) $row->status,
                'customer' => (string) $row->customer,
                'total' => round((float) $row->grand_total, 3),
                'payment_method' => $row->payment_provider === null ? '—' : (string) $row->payment_provider,
            ])
            ->all();

        return $this->reportPayload(
            'orders',
            $filters,
            [
                'orders' => $totalRows,
                'gross_value' => round($grossValue, 3),
                'recognized_revenue' => round($recognizedRevenue, 3),
                'average_order_value' => round($averageOrder, 3),
            ],
            ['order_number', 'date', 'store', 'channel', 'status', 'customer', 'total', 'payment_method'],
            $rows,
            $totalRows,
            $limit,
            [
                'status_breakdown' => $statusBreakdown,
                'payment_breakdown' => $paymentBreakdown,
            ],
        );
    }

    /** @param array<string, mixed> $filters */
    private function productsReport(array $filters, int $limit, int $offset): array
    {
        $sales = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->whereNotIn('orders.status', self::NON_REVENUE_STATUSES)
            ->when(
                $filters['product_id'] !== null,
                fn (Builder $query) => $query->where('products.id', $filters['product_id']),
            )
            ->when(
                $filters['category_id'] !== null,
                fn (Builder $query) => $query->where('products.category_id', $filters['category_id']),
            );

        $this->applyOrderFilters($sales, $filters, false);

        $ranked = (clone $sales)
            ->selectRaw(
                'products.id, products.sku, products.name, categories.name as category, '.
                'COALESCE(SUM(order_items.quantity), 0) as quantity, '.
                'COALESCE(SUM(order_items.line_total), 0) as revenue',
            )
            ->groupBy('products.id', 'products.sku', 'products.name', 'categories.name')
            ->get();

        $byQuantity = $ranked->sortByDesc('quantity')->values();
        $byRevenue = $ranked->sortByDesc('revenue')->values();
        $leastByQuantity = $ranked->sortBy('quantity')->values();
        $leastByRevenue = $ranked->sortBy('revenue')->values();

        $soldProductIds = DB::table('order_items as sold_items')
            ->join('orders', 'orders.id', '=', 'sold_items.order_id')
            ->whereNotIn('orders.status', self::NON_REVENUE_STATUSES)
            ->select('sold_items.product_id')
            ->distinct();

        $this->applyOrderFilters($soldProductIds, $filters, false);

        $zeroSalesQuery = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoinSub($soldProductIds, 'sold_products', function ($join): void {
                $join->on('sold_products.product_id', '=', 'products.id');
            })
            ->whereNull('sold_products.product_id')
            ->where('products.is_active', true)
            ->when(
                $filters['product_id'] !== null,
                fn (Builder $query) => $query->where('products.id', $filters['product_id']),
            )
            ->when(
                $filters['category_id'] !== null,
                fn (Builder $query) => $query->where('products.category_id', $filters['category_id']),
            )
            ->when(
                $filters['channel'] !== null,
                fn (Builder $query) => $query->where('catalogs.channel', $filters['channel']),
            )
            ->when(
                $filters['store_id'] !== null,
                fn (Builder $query) => $query->where('catalogs.store_id', $filters['store_id']),
            )
            ->when(
                $filters['store_id'] !== null,
                fn (Builder $query) => $query->whereExists(function (Builder $sub) use ($filters): void {
                    $sub->selectRaw('1')
                        ->from('store_products')
                        ->whereColumn('store_products.product_id', 'products.id')
                        ->where('store_products.store_id', $filters['store_id'])
                        ->where('store_products.is_active', true);
                }),
            );

        $zeroSalesCount = (clone $zeroSalesQuery)->count('products.id');
        $zeroSales = $zeroSalesQuery
            ->select(['products.sku', 'products.name', 'categories.name as category'])
            ->orderBy('products.name')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'sku' => (string) $row->sku,
                'name' => (string) $row->name,
                'category' => $row->category === null ? '—' : (string) $row->category,
            ])
            ->all();

        $categoryPerformance = (clone $sales)
            ->selectRaw(
                'COALESCE(categories.name, ?) as category, '.
                'COALESCE(SUM(order_items.quantity), 0) as quantity, '.
                'COALESCE(SUM(order_items.line_total), 0) as revenue',
                ['Uncategorized'],
            )
            ->groupBy('categories.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn (object $row): array => [
                'category' => (string) $row->category,
                'quantity' => round((float) $row->quantity, 3),
                'revenue' => round((float) $row->revenue, 3),
            ])
            ->all();

        $rows = $byQuantity
            ->slice(max(0, $offset), $limit)
            ->map(fn (object $row): array => [
                'sku' => (string) $row->sku,
                'name' => (string) $row->name,
                'category' => $row->category === null ? '—' : (string) $row->category,
                'quantity' => round((float) $row->quantity, 3),
                'revenue' => round((float) $row->revenue, 3),
            ])
            ->all();

        $totals = [
            'quantity_sold' => round((float) $ranked->sum('quantity'), 3),
            'product_revenue' => round((float) $ranked->sum('revenue'), 3),
            'selling_products' => $ranked->count(),
            'zero_sale_products' => $zeroSalesCount,
        ];

        return $this->reportPayload(
            'products',
            $filters,
            $totals,
            ['sku', 'name', 'category', 'quantity', 'revenue'],
            $rows,
            $ranked->count(),
            $limit,
            [
                'best_by_quantity' => $this->productRank($byQuantity->first()),
                'best_by_revenue' => $this->productRank($byRevenue->first()),
                'least_by_quantity' => $this->productRank($leastByQuantity->first()),
                'least_by_revenue' => $this->productRank($leastByRevenue->first()),
                'zero_sales' => $zeroSales,
                'category_performance' => $categoryPerformance,
                'period_comparison' => $this->productPeriodComparison($filters),
            ],
        );
    }

    /** @param array<string, mixed> $filters */
    private function customersReport(array $filters, int $limit, int $offset): array
    {
        $query = DB::table('orders')
            ->leftJoin('b2c_customers', 'b2c_customers.id', '=', 'orders.b2c_customer_id')
            ->leftJoin('b2b_customers', 'b2b_customers.id', '=', 'orders.b2b_customer_id')
            ->leftJoin('customers as legacy_customers', 'legacy_customers.id', '=', 'orders.customer_id')
            ->leftJoin('b2b_accounts', 'b2b_accounts.b2b_customer_id', '=', 'b2b_customers.id');

        $this->applyOrderFilters($query, $filters);

        $rows = (clone $query)
            ->select([
                'orders.channel as type',
                'orders.b2b_customer_id',
                'orders.b2c_customer_id',
                'orders.customer_id as legacy_customer_id',
                'b2b_accounts.company_name',
            ])
            ->selectRaw('COALESCE(b2c_customers.name, b2b_customers.name, legacy_customers.name) as name')
            ->selectRaw('COUNT(orders.id) as order_count')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN orders.status NOT IN (\'cancelled\',\'refunded\') THEN orders.grand_total ELSE 0 END), 0) as order_value',
            )
            ->selectSub(
                DB::table('orders as first_orders')
                    ->selectRaw('MIN(first_orders.created_at)')
                    ->where(function (Builder $identity): void {
                        $identity
                            ->where(function (Builder $b2c): void {
                                $b2c->whereColumn('first_orders.b2c_customer_id', 'orders.b2c_customer_id')
                                    ->whereNotNull('orders.b2c_customer_id');
                            })
                            ->orWhere(function (Builder $b2b): void {
                                $b2b->whereColumn('first_orders.b2b_customer_id', 'orders.b2b_customer_id')
                                    ->whereNotNull('orders.b2b_customer_id');
                            })
                            ->orWhere(function (Builder $legacy): void {
                                $legacy->whereColumn('first_orders.customer_id', 'orders.customer_id')
                                    ->whereNull('orders.b2c_customer_id')
                                    ->whereNull('orders.b2b_customer_id');
                            });
                    }),
                'first_order_at',
            )
            ->groupBy(
                'orders.channel',
                'orders.b2b_customer_id',
                'orders.b2c_customer_id',
                'orders.customer_id',
                'b2c_customers.name',
                'b2b_customers.name',
                'legacy_customers.name',
                'b2b_accounts.company_name',
            )
            ->orderByDesc('order_value')
            ->get();

        [$fromUtc] = $this->dateBounds($filters);
        $newCustomers = $rows->filter(
            fn (object $row): bool => $row->first_order_at !== null
                && CarbonImmutable::parse((string) $row->first_order_at, 'UTC')->greaterThanOrEqualTo(CarbonImmutable::parse($fromUtc, 'UTC')),
        )->count();

        $formatted = $rows
            ->slice(max(0, $offset), $limit)
            ->map(function (object $row) use ($fromUtc): array {
                $isNew = $row->first_order_at !== null
                    && CarbonImmutable::parse((string) $row->first_order_at, 'UTC')
                        ->greaterThanOrEqualTo(CarbonImmutable::parse($fromUtc, 'UTC'));

                return [
                    'customer' => (string) $row->name,
                    'type' => $this->channelLabel((string) $row->type),
                    'company' => $row->company_name === null ? '—' : (string) $row->company_name,
                    'orders' => (int) $row->order_count,
                    'value' => round((float) $row->order_value, 3),
                    'segment' => $isNew ? 'new' : 'returning',
                ];
            })
            ->all();

        return $this->reportPayload(
            'customers',
            $filters,
            [
                'active_customers' => $rows->count(),
                'new_customers' => $newCustomers,
                'returning_customers' => max(0, $rows->count() - $newCustomers),
                'customer_value' => round((float) $rows->sum('order_value'), 3),
            ],
            ['customer', 'type', 'company', 'orders', 'value', 'segment'],
            $formatted,
            $rows->count(),
            $limit,
        );
    }

    /** @param array<string, mixed> $filters */
    private function operationsReport(array $filters, int $limit, int $offset): array
    {
        $orders = DB::table('orders')
            ->join('stores', 'stores.id', '=', 'orders.store_id');

        $this->applyOrderFilters($orders, $filters);

        $rows = (clone $orders)
            ->selectRaw(
                'stores.name as store, orders.channel, COUNT(*) as orders, '.
                'COALESCE(SUM(CASE WHEN orders.status NOT IN (\'cancelled\',\'refunded\') THEN orders.grand_total ELSE 0 END), 0) as revenue, '.
                'SUM(CASE WHEN orders.status = \'delivered\' THEN 1 ELSE 0 END) as delivered, '.
                'SUM(CASE WHEN orders.status = \'cancelled\' THEN 1 ELSE 0 END) as cancelled',
            )
            ->groupBy('stores.id', 'stores.name', 'orders.channel')
            ->orderByDesc('revenue')
            ->get();

        $deliveries = DB::table('driver_assignments')
            ->join('orders', 'orders.id', '=', 'driver_assignments.order_id');

        $this->applyOrderFilters($deliveries, $filters);

        $deliveryRows = $deliveries
            ->select([
                'driver_assignments.status',
                'driver_assignments.assigned_at',
                'driver_assignments.completed_at',
            ])
            ->get();

        $completedMinutes = $deliveryRows
            ->filter(fn (object $row): bool => $row->assigned_at !== null && $row->completed_at !== null)
            ->map(fn (object $row): float => CarbonImmutable::parse((string) $row->assigned_at, 'UTC')
                ->diffInMinutes(CarbonImmutable::parse((string) $row->completed_at, 'UTC')));

        $deliveryBreakdown = $deliveryRows
            ->countBy(fn (object $row): string => (string) $row->status)
            ->map(fn (int $count, string $status): array => ['status' => $status, 'count' => $count])
            ->values()
            ->all();

        $inventory = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->join('stores', 'stores.id', '=', 'warehouses.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->when(
                $filters['store_id'] !== null,
                fn (Builder $query) => $query->where('warehouses.store_id', $filters['store_id']),
            )
            ->when(
                $filters['channel'] !== null,
                fn (Builder $query) => $query->where('store_types.code', strtoupper($filters['channel'])),
            );

        $formattedRows = $rows
            ->slice(max(0, $offset), $limit)
            ->map(fn (object $row): array => [
                'store' => (string) $row->store,
                'channel' => $this->channelLabel((string) $row->channel),
                'orders' => (int) $row->orders,
                'revenue' => round((float) $row->revenue, 3),
                'delivered' => (int) $row->delivered,
                'cancelled' => (int) $row->cancelled,
            ])
            ->all();

        return $this->reportPayload(
            'operations',
            $filters,
            [
                'orders' => (int) $rows->sum('orders'),
                'recognized_revenue' => round((float) $rows->sum('revenue'), 3),
                'delivery_assignments' => $deliveryRows->count(),
                'completed_deliveries' => $deliveryRows->where('status', 'delivered')->count()
                    + $deliveryRows->where('status', 'completed')->count(),
                'average_delivery_minutes' => $completedMinutes->isEmpty()
                    ? 0
                    : round((float) $completedMinutes->average(), 1),
                'inventory_quantity' => round((float) (clone $inventory)->sum('inventories.quantity'), 3),
                'reserved_quantity' => round((float) (clone $inventory)->sum('inventories.reserved_quantity'), 3),
            ],
            ['store', 'channel', 'orders', 'revenue', 'delivered', 'cancelled'],
            $formattedRows,
            $rows->count(),
            $limit,
            ['delivery_breakdown' => $deliveryBreakdown],
        );
    }

    /** @param array<string, mixed> $filters */
    private function fieldOperationsReport(User $user, array $filters, int $limit, int $offset): array
    {
        if ($filters['channel'] !== null && $filters['channel'] !== 'b2b') {
            throw ValidationException::withMessages([
                'channel' => [__('reports.field_operations_b2b_only')],
            ]);
        }

        $filters['channel'] = 'b2b';
        $query = DB::table('order_van_assignments')
            ->join('orders', 'orders.id', '=', 'order_van_assignments.order_id')
            ->join('stores', 'stores.id', '=', 'orders.store_id')
            ->join('vans', 'vans.id', '=', 'order_van_assignments.van_id')
            ->leftJoin(
                'order_van_execution_states',
                'order_van_execution_states.order_van_assignment_id',
                '=',
                'order_van_assignments.id',
            );

        $this->applyOrderFilters($query, $filters, false);
        $totalRows = (clone $query)->count('order_van_assignments.id');

        $rows = $query
            ->select([
                'orders.id as order_id',
                'orders.created_at',
                'stores.name as store',
                'vans.code as van',
                'order_van_assignments.assigned_at',
                'order_van_execution_states.status as execution_status',
                'order_van_execution_states.failure_reason_code',
                'order_van_execution_states.last_transition_at',
            ])
            ->latest('order_van_assignments.assigned_at')
            ->offset(max(0, $offset))
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'order_number' => DisplayNumber::reference($row->order_id),
                'date' => $this->localDateTime($row->created_at),
                'store' => (string) $row->store,
                'van' => (string) $row->van,
                'status' => $row->execution_status === null ? 'assigned' : (string) $row->execution_status,
                'failure_reason' => $row->failure_reason_code === null ? '—' : (string) $row->failure_reason_code,
                'assigned_at' => $row->assigned_at === null ? '—' : $this->localDateTime($row->assigned_at),
                'last_transition_at' => $row->last_transition_at === null ? '—' : $this->localDateTime($row->last_transition_at),
            ])
            ->all();

        $storeIds = $filters['store_id'] === null
            ? DB::table('stores')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->where('stores.is_active', true)
                ->where('store_types.code', 'B2B')
                ->pluck('stores.id')
                ->map(static fn ($id): int => (int) $id)
                ->all()
            : [(int) $filters['store_id']];

        $dashboard = app(FieldOperationsDashboardService::class)->build(
            $storeIds,
            $filters['store_id'],
            (string) $filters['from'],
            (string) $filters['to'],
        );

        return $this->reportPayload(
            'field_operations',
            $filters,
            [
                'active_vans' => (int) $dashboard['kpis']['active_vans'],
                'active_drivers' => (int) $dashboard['kpis']['active_drivers'],
                'assigned_deliveries' => (int) $dashboard['kpis']['assigned_deliveries'],
                'in_transit_deliveries' => (int) $dashboard['kpis']['in_transit_deliveries'],
                'late_deliveries' => (int) $dashboard['kpis']['late_deliveries'],
                'unassigned_orders' => (int) $dashboard['kpis']['unassigned_orders'],
                'failed_returned_deliveries' => (int) $dashboard['kpis']['failed_returned_deliveries'],
                'route_sla_percent' => $dashboard['kpis']['route_sla_percent'] ?? 0,
                'fleet_utilization_percent' => $dashboard['kpis']['fleet_utilization_percent'] ?? 0,
                'pending_remittances' => (int) $dashboard['kpis']['pending_remittances'],
                'stale_location_signals' => (int) $dashboard['kpis']['stale_location_signals'],
            ],
            ['order_number', 'date', 'store', 'van', 'status', 'failure_reason', 'assigned_at', 'last_transition_at'],
            $rows,
            $totalRows,
            $limit,
            [
                'status_breakdown' => $dashboard['status_breakdown'],
                'van_workload' => $dashboard['van_workload'],
                'territory_productivity' => $dashboard['territory_productivity'],
                'failed_reasons' => $dashboard['failed_reasons'],
                'remittance_trend' => $dashboard['remittance_trend'],
            ],
        );
    }

    /** @param array<string, mixed> $filters */
    private function salesExceptionsReport(array $filters, int $limit, int $offset): array
    {
        $delayCutoff = CarbonImmutable::now('UTC')
            ->subHours(SalesOrderOperationsDashboardService::DELAY_HOURS)
            ->toDateTimeString();

        $base = DB::table('orders')
            ->join('stores', 'stores.id', '=', 'orders.store_id');
        $this->applyOrderFilters($base, $filters);

        $delayed = fn (): Builder => (clone $base)
            ->whereNotIn('orders.status', ['delivered', 'cancelled', 'refunded', 'failed'])
            ->where('orders.created_at', '<=', $delayCutoff);
        $awaitingDispatch = fn (): Builder => (clone $base)
            ->whereExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('order_dispatch_states')
                    ->whereColumn('order_dispatch_states.order_id', 'orders.id')
                    ->where('order_dispatch_states.status', 'awaiting_dispatch');
            });
        $payment = fn (): Builder => (clone $base)
            ->whereExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('payments')
                    ->whereColumn('payments.order_id', 'orders.id')
                    ->whereIn('payments.status', ['failed', 'voided']);
            });
        $invoice = fn (): Builder => (clone $base)
            ->where('orders.status', 'delivered')
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('invoices')
                    ->whereColumn('invoices.order_id', 'orders.id')
                    ->whereNotIn('invoices.status', ['cancelled', 'void', 'voided']);
            });
        $cancelledRefunded = fn (): Builder => (clone $base)
            ->whereIn('orders.status', self::NON_REVENUE_STATUSES);

        $exceptionQuery = (clone $base)
            ->where(function (Builder $query) use ($delayCutoff): void {
                $query
                    ->where(function (Builder $delayed) use ($delayCutoff): void {
                        $delayed
                            ->whereNotIn('orders.status', ['delivered', 'cancelled', 'refunded', 'failed'])
                            ->where('orders.created_at', '<=', $delayCutoff);
                    })
                    ->orWhereExists(function (Builder $dispatch): void {
                        $dispatch->selectRaw('1')
                            ->from('order_dispatch_states')
                            ->whereColumn('order_dispatch_states.order_id', 'orders.id')
                            ->where('order_dispatch_states.status', 'awaiting_dispatch');
                    })
                    ->orWhereExists(function (Builder $payments): void {
                        $payments->selectRaw('1')
                            ->from('payments')
                            ->whereColumn('payments.order_id', 'orders.id')
                            ->whereIn('payments.status', ['failed', 'voided']);
                    })
                    ->orWhere(function (Builder $invoice): void {
                        $invoice
                            ->where('orders.status', 'delivered')
                            ->whereNotExists(function (Builder $invoices): void {
                                $invoices->selectRaw('1')
                                    ->from('invoices')
                                    ->whereColumn('invoices.order_id', 'orders.id')
                                    ->whereNotIn('invoices.status', ['cancelled', 'void', 'voided']);
                            });
                    })
                    ->orWhereIn('orders.status', self::NON_REVENUE_STATUSES);
            });

        $totalRows = (clone $exceptionQuery)->count('orders.id');

        $rows = $exceptionQuery
            ->select([
                'orders.id',
                'orders.order_number',
                'orders.created_at',
                'stores.name as store',
                'orders.channel',
                'orders.status',
                'orders.grand_total',
            ])
            ->selectRaw(
                'CASE '.
                "WHEN orders.status NOT IN ('delivered','cancelled','refunded','failed') AND orders.created_at <= ? THEN 'delayed' ".
                "WHEN EXISTS (SELECT 1 FROM order_dispatch_states ods WHERE ods.order_id = orders.id AND ods.status = 'awaiting_dispatch') THEN 'awaiting_dispatch' ".
                "WHEN EXISTS (SELECT 1 FROM payments ep WHERE ep.order_id = orders.id AND ep.status IN ('failed','voided')) THEN 'payment' ".
                "WHEN orders.status = 'delivered' AND NOT EXISTS (SELECT 1 FROM invoices ei WHERE ei.order_id = orders.id AND ei.status NOT IN ('cancelled','void','voided')) THEN 'invoice' ".
                "WHEN orders.status IN ('cancelled','refunded') THEN 'cancelled_refunded' ".
                "ELSE 'exception' END as exception",
                [$delayCutoff],
            )
            ->selectSub(
                DB::table('payments as latest_payment')
                    ->select('latest_payment.provider')
                    ->whereColumn('latest_payment.order_id', 'orders.id')
                    ->latest('latest_payment.id')
                    ->limit(1),
                'payment_provider',
            )
            ->latest('orders.created_at')
            ->offset(max(0, $offset))
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'order_number' => DisplayNumber::reference($row->id),
                'date' => $this->localDateTime($row->created_at),
                'store' => (string) $row->store,
                'channel' => strtolower((string) $row->channel),
                'status' => (string) $row->status,
                'exception' => (string) $row->exception,
                'total' => round((float) $row->grand_total, 3),
                'payment_method' => $row->payment_provider === null ? '—' : (string) $row->payment_provider,
            ])
            ->all();

        return $this->reportPayload(
            'sales_exceptions',
            $filters,
            [
                'exception_orders' => $totalRows,
                'delayed_orders' => $delayed()->count('orders.id'),
                'awaiting_dispatch' => $awaitingDispatch()->count('orders.id'),
                'payment_exceptions' => $payment()->count('orders.id'),
                'invoice_exceptions' => $invoice()->count('orders.id'),
                'cancelled_refunded' => $cancelledRefunded()->count('orders.id'),
            ],
            ['order_number', 'date', 'store', 'channel', 'status', 'exception', 'total', 'payment_method'],
            $rows,
            $totalRows,
            $limit,
        );
    }

    /** @param array<string, mixed> $filters */
    private function productPeriodComparison(array $filters): array
    {
        $from = CarbonImmutable::parse((string) $filters['from'], self::TIMEZONE);
        $to = CarbonImmutable::parse((string) $filters['to'], self::TIMEZONE);
        $days = $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();
        $previousFrom = $previousTo->subDays($days - 1);

        $previousFilters = [
            ...$filters,
            'from' => $previousFrom->toDateString(),
            'to' => $previousTo->toDateString(),
        ];

        $current = $this->productTotals($filters);
        $previous = $this->productTotals($previousFilters);

        return [
            'current' => $current,
            'previous' => $previous,
            'previous_from' => $previousFilters['from'],
            'previous_to' => $previousFilters['to'],
        ];
    }

    /** @param array<string, mixed> $filters */
    private function productTotals(array $filters): array
    {
        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->whereNotIn('orders.status', self::NON_REVENUE_STATUSES)
            ->when(
                $filters['product_id'] !== null,
                fn (Builder $builder) => $builder->where('products.id', $filters['product_id']),
            )
            ->when(
                $filters['category_id'] !== null,
                fn (Builder $builder) => $builder->where('products.category_id', $filters['category_id']),
            );

        $this->applyOrderFilters($query, $filters, false);

        $totals = $query
            ->selectRaw(
                'COALESCE(SUM(order_items.quantity), 0) as quantity, '.
                'COALESCE(SUM(order_items.line_total), 0) as revenue',
            )
            ->first();

        return [
            'quantity' => round((float) ($totals->quantity ?? 0), 3),
            'revenue' => round((float) ($totals->revenue ?? 0), 3),
        ];
    }

    private function productRank(?object $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'product_id' => (int) $row->id,
            'sku' => (string) $row->sku,
            'name' => (string) $row->name,
            'quantity' => round((float) $row->quantity, 3),
            'revenue' => round((float) $row->revenue, 3),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, int|float>  $kpis
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $extra
     */
    private function reportPayload(
        string $report,
        array $filters,
        array $kpis,
        array $columns,
        array $rows,
        int $totalRows,
        int $limit,
        array $extra = [],
    ): array {
        return [
            'report' => $report,
            'timezone' => self::TIMEZONE,
            'currency' => 'EGP',
            'generated_at' => CarbonImmutable::now(self::TIMEZONE)->toIso8601String(),
            'filters' => $filters,
            'kpis' => $kpis,
            'columns' => $columns,
            'rows' => $rows,
            'meta' => [
                'row_count' => $totalRows,
                'row_limit' => $limit,
                'truncated' => $totalRows > $limit,
            ],
            ...$extra,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function applyOrderFilters(Builder $query, array $filters, bool $applyProductFilter = true): void
    {
        [$fromUtc, $toUtc] = $this->dateBounds($filters);

        $query->whereBetween('orders.created_at', [$fromUtc, $toUtc])
            ->when(
                $filters['store_id'] !== null,
                fn (Builder $builder) => $builder->where('orders.store_id', $filters['store_id']),
            )
            ->when(
                $filters['channel'] !== null,
                fn (Builder $builder) => $builder->where('orders.channel', $filters['channel']),
            )
            ->when(
                $filters['status'] !== null,
                fn (Builder $builder) => $builder->where('orders.status', $filters['status']),
            )
            ->when(
                $filters['customer_id'] !== null,
                function (Builder $builder) use ($filters): void {
                    if ($filters['channel'] === 'b2b') {
                        $builder->where('orders.b2b_customer_id', $filters['customer_id']);

                        return;
                    }

                    if ($filters['channel'] === 'b2c') {
                        $builder->where('orders.b2c_customer_id', $filters['customer_id']);

                        return;
                    }

                    throw ValidationException::withMessages([
                        'channel' => ['A channel is required when filtering by customer_id.'],
                    ]);
                },
            );

        if ($applyProductFilter && ($filters['product_id'] !== null || $filters['category_id'] !== null)) {
            $query->whereExists(function (Builder $sub) use ($filters): void {
                $sub->selectRaw('1')
                    ->from('order_items as filter_items')
                    ->join('products as filter_products', 'filter_products.id', '=', 'filter_items.product_id')
                    ->whereColumn('filter_items.order_id', 'orders.id')
                    ->when(
                        $filters['product_id'] !== null,
                        fn (Builder $builder) => $builder->where('filter_products.id', $filters['product_id']),
                    )
                    ->when(
                        $filters['category_id'] !== null,
                        fn (Builder $builder) => $builder->where('filter_products.category_id', $filters['category_id']),
                    );
            });
        }

        if ($filters['payment_provider'] !== null) {
            $query->whereExists(function (Builder $sub) use ($filters): void {
                $sub->selectRaw('1')
                    ->from('payments as filter_payments')
                    ->whereColumn('filter_payments.order_id', 'orders.id')
                    ->where('filter_payments.provider', $filters['payment_provider']);
            });
        }
    }

    /** @param array<string, mixed> $filters */
    private function dateBounds(array $filters): array
    {
        return [
            CarbonImmutable::parse($filters['from'].' 00:00:00', self::TIMEZONE)
                ->utc()
                ->toDateTimeString(),
            CarbonImmutable::parse($filters['to'].' 23:59:59', self::TIMEZONE)
                ->utc()
                ->toDateTimeString(),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function resolveStoreScope(User $user, array $filters, string $permission): ?int
    {
        $requested = $filters['store_id'];

        if ($user->hasPermission($permission)) {
            return $requested;
        }

        if ($requested === null) {
            throw ValidationException::withMessages([
                'store_id' => __('reports.store_required'),
            ]);
        }

        abort_unless($user->hasPermission($permission, $requested), 403);
        abort_unless(
            $user->storeRoleAssignments()->where('store_id', $requested)->exists(),
            403,
        );

        return $requested;
    }

    /** @param array<string, mixed> $filters */
    private function enforceChannelScope(User $user, array $filters): array
    {
        if ($user->hasRole('SUPER_ADMIN')) {
            return $filters;
        }

        $scope = app(OperationalTenantScope::class);
        $b2bStoreIds = $scope->allowedStoreIds($user, 'reports.view', 'b2b');
        $b2cStoreIds = $scope->allowedStoreIds($user, 'reports.view', 'b2c');

        $forcedChannel = null;
        if ($b2bStoreIds !== [] && $b2cStoreIds === []) {
            $forcedChannel = 'b2b';
        } elseif ($b2cStoreIds !== [] && $b2bStoreIds === []) {
            $forcedChannel = 'b2c';
        }

        if ($forcedChannel === null) {
            return $filters;
        }

        if ($filters['channel'] !== null && $filters['channel'] !== $forcedChannel) {
            abort(403);
        }

        $filters['channel'] = $forcedChannel;

        return $filters;
    }

    private function channelLabel(string $channel): string
    {
        return strtolower($channel) === 'b2c'
            ? (app()->getLocale() === 'ar' ? 'التجزئة' : 'Retail')
            : 'B2B';
    }

    private function positiveInt(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function localDateTime(mixed $value): string
    {
        return CarbonImmutable::parse((string) $value, 'UTC')
            ->setTimezone(self::TIMEZONE)
            ->format('Y-m-d H:i');
    }
}
