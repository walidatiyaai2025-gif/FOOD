<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\User;
use App\Services\CustomerDomainResolver;
use App\Services\ProductAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class B2bReportController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $customer = $this->approvedCustomer($request);

        $orders = DB::table('orders')
            ->where('b2b_customer_id', $customer->getKey())
            ->where('channel', 'b2b');

        $openOrders = (clone $orders)->whereNotIn('status', ['delivered', 'cancelled'])->count();
        $purchased = (float) (clone $orders)->where('status', '!=', 'cancelled')->sum('grand_total');

        $invoiceTotal = (float) DB::table('invoices')
            ->where('b2b_customer_id', $customer->getKey())
            ->whereIn('status', ['issued', 'overdue'])
            ->sum('total');

        $paidTotal = (float) DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->where('invoices.b2b_customer_id', $customer->getKey())
            ->where('payments.status', 'paid')
            ->sum('payments.amount');

        $outstanding = max(0.0, $invoiceTotal - $paidTotal);

        $top = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.b2b_customer_id', $customer->getKey())
            ->where('orders.channel', 'b2b')
            ->where('orders.status', '!=', 'cancelled')
            ->groupBy('order_items.product_id', 'order_items.sku_snapshot', 'order_items.name_snapshot')
            ->orderByDesc(DB::raw('SUM(order_items.quantity)'))
            ->limit(5)
            ->get([
                'order_items.product_id',
                'order_items.sku_snapshot as sku',
                'order_items.name_snapshot as name',
                DB::raw('SUM(order_items.quantity) as quantity'),
                DB::raw('SUM(order_items.line_total) as total'),
            ]);

        return response()->json([
            'open_orders' => $openOrders,
            'purchase_total' => round($purchased, 3),
            'outstanding_balance' => round($outstanding, 3),
            'currency' => 'KWD',
            'top_products' => $top,
        ]);
    }

    public function purchases(Request $request): JsonResponse
    {
        $customer = $this->approvedCustomer($request);
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $query = DB::table('orders')
            ->where('b2b_customer_id', $customer->getKey())
            ->where('channel', 'b2b')
            ->where('status', '!=', 'cancelled');

        if (isset($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }
        if (isset($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        $rows = $query
            ->selectRaw('DATE(created_at) as period, COUNT(*) as orders_count, SUM(grand_total) as purchase_total')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('period')
            ->get();

        return response()->json(['data' => $rows, 'currency' => 'KWD']);
    }

    public function topProducts(Request $request): JsonResponse
    {
        $customer = $this->approvedCustomer($request);
        $account = B2bAccount::query()
            ->where('b2b_customer_id', $customer->getKey())
            ->where('status', 'active')
            ->first();

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'in:quantity,value'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.b2b_customer_id', $customer->getKey())
            ->where('orders.channel', 'b2b')
            ->where('orders.status', '!=', 'cancelled');

        if (isset($validated['from'])) {
            $query->whereDate('orders.created_at', '>=', $validated['from']);
        }
        if (isset($validated['to'])) {
            $query->whereDate('orders.created_at', '<=', $validated['to']);
        }
        if (isset($validated['store_id'])) {
            $query->where('orders.store_id', (int) $validated['store_id']);
        }
        $search = trim((string) ($validated['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $like = '%'.$search.'%';
                $builder->where('order_items.name_snapshot', 'like', $like)
                    ->orWhere('order_items.sku_snapshot', 'like', $like);
            });
        }

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['limit'] ?? $validated['per_page'] ?? 20);
        $sort = (string) ($validated['sort'] ?? 'quantity');
        $total = (clone $query)->distinct()->count('order_items.product_id');

        $aggregate = $query
            ->groupBy('order_items.product_id')
            ->select([
                'order_items.product_id',
                DB::raw('MAX(order_items.sku_snapshot) as sku'),
                DB::raw('MAX(order_items.name_snapshot) as name'),
                DB::raw('SUM(order_items.quantity) as quantity'),
                DB::raw('SUM(order_items.line_total) as total'),
                DB::raw('MAX(orders.created_at) as last_purchased_at'),
                DB::raw('MAX(orders.currency) as currency'),
            ]);

        if ($sort === 'value') {
            $aggregate->orderByDesc(DB::raw('SUM(order_items.line_total)'))
                ->orderByDesc(DB::raw('SUM(order_items.quantity)'));
        } else {
            $aggregate->orderByDesc(DB::raw('SUM(order_items.quantity)'))
                ->orderByDesc(DB::raw('SUM(order_items.line_total)'));
        }

        $requestedStoreId = isset($validated['store_id']) ? (int) $validated['store_id'] : null;
        $offset = ($page - 1) * $perPage;
        $availabilityService = app(ProductAvailabilityService::class);

        $rows = $aggregate
            ->orderBy('order_items.product_id')
            ->offset($offset)
            ->limit($perPage)
            ->get()
            ->values()
            ->map(function (object $row, int $index) use (
                $account,
                $availabilityService,
                $customer,
                $requestedStoreId,
                $offset,
            ): array {
                $productId = (int) $row->product_id;
                $storeId = $requestedStoreId;

                if ($storeId === null) {
                    $storeId = DB::table('order_items as recent_items')
                        ->join('orders as recent_orders', 'recent_orders.id', '=', 'recent_items.order_id')
                        ->where('recent_orders.b2b_customer_id', $customer->getKey())
                        ->where('recent_orders.channel', 'b2b')
                        ->where('recent_orders.status', '!=', 'cancelled')
                        ->where('recent_items.product_id', $productId)
                        ->orderByDesc('recent_orders.created_at')
                        ->orderByDesc('recent_orders.id')
                        ->value('recent_orders.store_id');
                    $storeId = $storeId === null ? null : (int) $storeId;
                }

                $current = null;
                if ($storeId !== null && $account?->price_tier_id !== null) {
                    $current = DB::table('b2b_price_rules')
                        ->join('products', 'products.id', '=', 'b2b_price_rules.product_id')
                        ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                        ->join('store_products', function ($join): void {
                            $join->on('store_products.product_id', '=', 'products.id')
                                ->on('store_products.store_id', '=', 'b2b_price_rules.store_id');
                        })
                        ->join('stores', 'stores.id', '=', 'b2b_price_rules.store_id')
                        ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                        ->where('b2b_price_rules.price_tier_id', $account->price_tier_id)
                        ->where('b2b_price_rules.store_id', $storeId)
                        ->where('b2b_price_rules.product_id', $productId)
                        ->where('b2b_price_rules.is_active', true)
                        ->where('products.is_active', true)
                        ->where('catalogs.store_id', $storeId)
                        ->where('catalogs.channel', 'b2b')
                        ->where('catalogs.is_active', true)
                        ->where('catalogs.is_migration_quarantine', false)
                        ->where('store_products.is_active', true)
                        ->where('stores.is_active', true)
                        ->where('store_types.code', 'B2B')
                        ->first([
                            'products.sku',
                            'products.name',
                            'b2b_price_rules.unit_price',
                            'b2b_price_rules.minimum_quantity',
                            'b2b_price_rules.ordering_increment',
                            'b2b_price_rules.pack_size',
                            'b2b_price_rules.case_size',
                            'b2b_price_rules.pack_label',
                            DB::raw('(select path from product_images where product_images.product_id = products.id order by is_primary desc, sort_order asc, id asc limit 1) as primary_image_path'),
                        ]);
                }

                $availability = $current === null || $storeId === null
                    ? [
                        'available_quantity' => null,
                        'is_available' => false,
                        'availability_state' => 'UNAVAILABLE_FOR_ACCOUNT',
                    ]
                    : $availabilityService->forStoreProduct($storeId, $productId);

                $minimum = $current === null ? null : (float) $current->minimum_quantity;
                $availableQuantity = $availability['available_quantity'];
                $meetsMinimum = $minimum === null
                    || $availableQuantity === null
                    || $availableQuantity >= $minimum;
                $canRepurchase = $current !== null
                    && $availability['is_available'] === true
                    && $meetsMinimum;

                $unavailableReason = null;
                if ($current === null) {
                    $unavailableReason = 'UNAVAILABLE_FOR_ACCOUNT';
                } elseif ($availability['is_available'] !== true) {
                    $unavailableReason = 'OUT_OF_STOCK';
                } elseif (! $meetsMinimum) {
                    $unavailableReason = 'BELOW_MINIMUM_ORDER';
                }

                return [
                    'rank' => $offset + $index + 1,
                    'product_id' => $productId,
                    'sku' => $current?->sku ?? (string) $row->sku,
                    'name' => $current?->name ?? (string) $row->name,
                    'quantity' => (float) $row->quantity,
                    'total' => round((float) $row->total, 3),
                    'currency' => (string) ($row->currency ?? ''),
                    'last_purchased_at' => $row->last_purchased_at,
                    'store_id' => $storeId,
                    'image_url' => $this->assetUrl($current?->primary_image_path),
                    'account_price' => $current === null ? null : (float) $current->unit_price,
                    'current_price_currency' => $current === null ? null : 'EGP',
                    'minimum_order_quantity' => $minimum,
                    'ordering_increment' => $current === null ? null : (float) $current->ordering_increment,
                    'pack_size' => $current === null ? null : (float) $current->pack_size,
                    'case_size' => $current?->case_size === null ? null : (float) $current->case_size,
                    'pack_label' => $current?->pack_label,
                    'available_quantity' => $availableQuantity,
                    'availability_state' => $availability['availability_state'],
                    'can_repurchase' => $canRepurchase,
                    'unavailable_reason' => $unavailableReason,
                ];
            })
            ->all();

        return response()->json([
            'data' => $rows,
            'period' => [
                'from' => $validated['from'] ?? null,
                'to' => $validated['to'] ?? null,
            ],
            'sort' => $sort,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => ($offset + count($rows)) < $total,
            ],
        ]);
    }

    private function assetUrl(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $value = trim($path);
        if (str_starts_with($value, 'https://') || str_starts_with($value, 'http://')) {
            return $value;
        }

        return url('/'.ltrim($value, '/'));
    }

    private function approvedCustomer(Request $request): B2bCustomer
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $customer = app(CustomerDomainResolver::class)->b2bFromRequest($user, $request);
        abort_unless(
            B2bAccount::query()
                ->where('b2b_customer_id', $customer->getKey())
                ->where('status', 'active')
                ->exists(),
            403,
            'Approved B2B account is required.',
        );

        return $customer;
    }
}
