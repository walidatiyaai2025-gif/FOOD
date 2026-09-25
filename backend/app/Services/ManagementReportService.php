<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
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
        ];
    }

    /** @param array<string, mixed> $filters */
    public function run(User $user, string $report, array $filters, int $limit = self::UI_LIMIT): array
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
            'orders' => $this->ordersReport($filters, $limit),
            'products' => $this->productsReport($filters, $limit),
            'customers' => $this->customersReport($filters, $limit),
            'operations' => $this->operationsReport($filters, $limit),
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

        return [
            'from' => (string) ($filters['from'] ?? CarbonImmutable::now(self::TIMEZONE)->subDays(29)->toDateString()),
            'to' => (string) ($filters['to'] ?? $today),
            'store_id' => $this->positiveInt($filters['store_id'] ?? null),
            'channel' => $this->nullableString($filters['channel'] ?? null),
            'status' => $this->nullableString($filters['status'] ?? null),
            'product_id' => $this->positiveInt($filters['product_id'] ?? null),
            'category_id' => $this->positiveInt($filters['category_id'] ?? null),
            'customer_id' => $this->positiveInt($filters['customer_id'] ?? null),
            'payment_provider' => $this->nullableString($filters['payment_provider'] ?? null),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function ordersReport(array $filters, int $limit): array
    {
        $query = DB::table('orders')
            ->join('stores', 'stores.id', '=', 'orders.store_id')
            ->join('customers', 'customers.id', '=', 'orders.customer_id');

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
                'customers.name as customer',
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
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'order_number' => (string) $row->order_number,
                'date' => $this->localDateTime($row->created_at),
                'store' => (string) $row->store,
                'channel' => strtoupper((string) $row->channel),
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
    private function productsReport(array $filters, int $limit): array
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

        $zeroSalesQuery = DB::table('products')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
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
                $filters['store_id'] !== null,
                fn (Builder $query) => $query->whereExists(function (Builder $sub) use ($filters): void {
                    $sub->selectRaw('1')
                        ->from('store_products')
                        ->whereColumn('store_products.product_id', 'products.id')
                        ->where('store_products.store_id', $filters['store_id'])
                        ->where('store_products.is_active', true);
                }),
            )
            ->whereNotExists(function (Builder $sub) use ($filters): void {
                $sub->selectRaw('1')
                    ->from('order_items')
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->whereColumn('order_items.product_id', 'products.id')
                    ->whereNotIn('orders.status', self::NON_REVENUE_STATUSES);

                $this->applyOrderFilters($sub, $filters, false);
            });

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
            ->take($limit)
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
    private function customersReport(array $filters, int $limit): array
    {
        $query = DB::table('orders')
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->leftJoin('b2b_accounts', 'b2b_accounts.customer_id', '=', 'customers.id');

        $this->applyOrderFilters($query, $filters);

        $rows = (clone $query)
            ->select([
                'customers.id',
                'customers.name',
                'customers.type',
                'b2b_accounts.company_name',
            ])
            ->selectRaw('COUNT(orders.id) as order_count')
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN orders.status NOT IN ('cancelled','refunded') THEN orders.grand_total ELSE 0 END), 0) as order_value",
            )
            ->selectSub(
                DB::table('orders as first_orders')
                    ->selectRaw('MIN(first_orders.created_at)')
                    ->whereColumn('first_orders.customer_id', 'customers.id'),
                'first_order_at',
            )
            ->groupBy('customers.id', 'customers.name', 'customers.type', 'b2b_accounts.company_name')
            ->orderByDesc('order_value')
            ->get();

        [$fromUtc] = $this->dateBounds($filters);
        $newCustomers = $rows->filter(
            fn (object $row): bool => $row->first_order_at !== null
                && CarbonImmutable::parse((string) $row->first_order_at, 'UTC')->greaterThanOrEqualTo(CarbonImmutable::parse($fromUtc, 'UTC')),
        )->count();

        $formatted = $rows
            ->take($limit)
            ->map(function (object $row) use ($fromUtc): array {
                $isNew = $row->first_order_at !== null
                    && CarbonImmutable::parse((string) $row->first_order_at, 'UTC')
                        ->greaterThanOrEqualTo(CarbonImmutable::parse($fromUtc, 'UTC'));

                return [
                    'customer' => (string) $row->name,
                    'type' => strtoupper((string) $row->type),
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
    private function operationsReport(array $filters, int $limit): array
    {
        $orders = DB::table('orders')
            ->join('stores', 'stores.id', '=', 'orders.store_id');

        $this->applyOrderFilters($orders, $filters);

        $rows = (clone $orders)
            ->selectRaw(
                "stores.name as store, orders.channel, COUNT(*) as orders, ".
                "COALESCE(SUM(CASE WHEN orders.status NOT IN ('cancelled','refunded') THEN orders.grand_total ELSE 0 END), 0) as revenue, ".
                "SUM(CASE WHEN orders.status = 'delivered' THEN 1 ELSE 0 END) as delivered, ".
                "SUM(CASE WHEN orders.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled",
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
            ->map(fn (object $row): int => CarbonImmutable::parse((string) $row->assigned_at, 'UTC')
                ->diffInMinutes(CarbonImmutable::parse((string) $row->completed_at, 'UTC')));

        $deliveryBreakdown = $deliveryRows
            ->countBy(fn (object $row): string => (string) $row->status)
            ->map(fn (int $count, string $status): array => ['status' => $status, 'count' => $count])
            ->values()
            ->all();

        $inventory = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->when(
                $filters['store_id'] !== null,
                fn (Builder $query) => $query->where('warehouses.store_id', $filters['store_id']),
            );

        $formattedRows = $rows
            ->take($limit)
            ->map(fn (object $row): array => [
                'store' => (string) $row->store,
                'channel' => strtoupper((string) $row->channel),
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

        return [
            'quantity' => round((float) (clone $query)->sum('order_items.quantity'), 3),
            'revenue' => round((float) (clone $query)->sum('order_items.line_total'), 3),
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
     * @param array<string, mixed> $filters
     * @param array<string, int|float> $kpis
     * @param list<string> $columns
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $extra
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
            'currency' => 'KWD',
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
                fn (Builder $builder) => $builder->where('orders.customer_id', $filters['customer_id']),
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
        $b2bOnly = $user->hasRole('B2B_ADMIN')
            && ! $user->hasRole('SUPER_ADMIN')
            && ! $user->hasRole('OPERATIONS')
            && ! $user->hasRole('FINANCE');

        if (! $b2bOnly) {
            return $filters;
        }

        if ($filters['channel'] !== null && $filters['channel'] !== 'b2b') {
            abort(403);
        }

        $filters['channel'] = 'b2b';

        return $filters;
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
