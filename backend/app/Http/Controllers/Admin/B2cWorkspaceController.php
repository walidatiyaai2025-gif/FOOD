<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\B2cDashboardService;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

class B2cWorkspaceController extends Controller
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly B2cDashboardService $dashboard,
    ) {}

    public function show(Request $request, string $module = 'dashboard'): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $allowed = ['dashboard', 'products', 'inventory', 'orders', 'customers', 'promotions', 'drivers', 'storefront', 'content', 'reports', 'settings'];
        abort_unless(in_array($module, $allowed, true), 404);
        $storeIds = $this->storeIds($user);
        abort_if($storeIds === [], 403, 'No assigned B2C store.');
        App::setLocale(in_array($user->locale, ['ar', 'en'], true) ? $user->locale : 'ar');

        $counts = [
            'products' => DB::table('store_products')->whereIn('store_id', $storeIds)->count(),
            'orders' => DB::table('orders')->whereIn('store_id', $storeIds)->where('channel', 'b2c')->count(),
            'customers' => DB::table('orders')->whereIn('store_id', $storeIds)->where('channel', 'b2c')->distinct()->count('customer_id'),
            'inventory' => DB::table('inventories')->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')->whereIn('warehouses.store_id', $storeIds)->count(),
        ];

        $navGroups = $this->navigation->groupsFor($user);
        $navContext = 'b2c_'.$module;
        $dashboard = $module === 'dashboard'
            ? $this->dashboard->build(
                $user,
                $storeIds,
                $request->filled('date') ? $request->string('date')->toString() : null,
                $request->filled('q') ? $request->string('q')->toString() : null,
            )
            : null;
        $moduleData = in_array($module, ['products', 'inventory', 'orders', 'customers'], true)
            ? $this->moduleData($module, $storeIds)
            : null;

        return view('admin.b2c-workspace', compact('user', 'module', 'storeIds', 'counts', 'navGroups', 'navContext', 'dashboard', 'moduleData'));
    }

    private function moduleData(string $module, array $storeIds): array
    {
        return match ($module) {
            'products' => [
                'columns' => ['sku', 'name', 'category', 'store', 'price', 'status'],
                'rows' => DB::table('store_products')
                    ->join('products', 'products.id', '=', 'store_products.product_id')
                    ->join('stores', 'stores.id', '=', 'store_products.store_id')
                    ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                    ->whereIn('store_products.store_id', $storeIds)
                    ->orderBy('products.name')
                    ->limit(100)
                    ->get([
                        'products.sku',
                        'products.name',
                        DB::raw("COALESCE(categories.name, '-') as category"),
                        'stores.name as store',
                        'store_products.price',
                        'store_products.is_active as status',
                    ])->map(fn ($row) => [
                        'sku' => $row->sku,
                        'name' => $row->name,
                        'category' => $row->category,
                        'store' => $row->store,
                        'price' => $row->price === null ? '-' : number_format((float) $row->price, 3).' KWD',
                        'status' => (bool) $row->status,
                    ])->all(),
            ],
            'inventory' => [
                'columns' => ['sku', 'name', 'warehouse', 'quantity', 'reserved', 'available'],
                'rows' => DB::table('inventories')
                    ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                    ->join('products', 'products.id', '=', 'inventories.product_id')
                    ->whereIn('warehouses.store_id', $storeIds)
                    ->orderBy('products.name')
                    ->limit(100)
                    ->get([
                        'products.sku',
                        'products.name',
                        'warehouses.name as warehouse',
                        'inventories.quantity',
                        'inventories.reserved_quantity as reserved',
                    ])->map(fn ($row) => [
                        'sku' => $row->sku,
                        'name' => $row->name,
                        'warehouse' => $row->warehouse,
                        'quantity' => number_format((float) $row->quantity, 3),
                        'reserved' => number_format((float) $row->reserved, 3),
                        'available' => number_format((float) $row->quantity - (float) $row->reserved, 3),
                    ])->all(),
            ],
            'orders' => [
                'columns' => ['number', 'customer', 'store', 'status', 'amount', 'created'],
                'rows' => DB::table('orders')
                    ->join('customers', 'customers.id', '=', 'orders.customer_id')
                    ->join('stores', 'stores.id', '=', 'orders.store_id')
                    ->whereIn('orders.store_id', $storeIds)
                    ->where('orders.channel', 'b2c')
                    ->orderByDesc('orders.created_at')
                    ->limit(100)
                    ->get([
                        'orders.order_number as number',
                        'customers.name as customer',
                        'stores.name as store',
                        'orders.status',
                        'orders.currency',
                        'orders.grand_total',
                        'orders.created_at as created',
                    ])->map(fn ($row) => [
                        'number' => $row->number,
                        'customer' => $row->customer,
                        'store' => $row->store,
                        'status' => $row->status,
                        'amount' => $row->currency.' '.number_format((float) $row->grand_total, 3),
                        'created' => (string) $row->created,
                    ])->all(),
            ],
            'customers' => [
                'columns' => ['name', 'phone', 'email', 'orders', 'spent', 'last_order'],
                'rows' => DB::table('customers')
                    ->join('orders', 'orders.customer_id', '=', 'customers.id')
                    ->whereIn('orders.store_id', $storeIds)
                    ->where('orders.channel', 'b2c')
                    ->groupBy('customers.id', 'customers.name', 'customers.phone', 'customers.email')
                    ->orderByDesc(DB::raw('MAX(orders.created_at)'))
                    ->limit(100)
                    ->get([
                        'customers.name',
                        'customers.phone',
                        'customers.email',
                        DB::raw('COUNT(orders.id) as orders_count'),
                        DB::raw('SUM(orders.grand_total) as total_spent'),
                        DB::raw('MAX(orders.created_at) as last_order'),
                    ])->map(fn ($row) => [
                        'name' => $row->name,
                        'phone' => $row->phone ?: '-',
                        'email' => $row->email ?: '-',
                        'orders' => (int) $row->orders_count,
                        'spent' => 'KWD '.number_format((float) $row->total_spent, 3),
                        'last_order' => (string) $row->last_order,
                    ])->all(),
            ],
            default => ['columns' => [], 'rows' => []],
        };
    }

    private function storeIds(User $user): array
    {
        if ($user->roles()->where('roles.code', 'SUPER_ADMIN')->exists()) {
            return DB::table('stores')->join('store_types', 'store_types.id', '=', 'stores.store_type_id')->where('store_types.code', 'B2C')->pluck('stores.id')->map(fn ($id) => (int) $id)->all();
        }

        return $user->storeRoleAssignments()->whereHas('role', fn ($q) => $q->where('code', 'B2C_STORE_ADMIN'))->pluck('store_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
