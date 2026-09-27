<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\V1\DriverAssignmentController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Controller;
use App\Models\Inventory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\B2cDashboardService;
use App\Services\DashboardOperationalNotifier;
use App\Services\ManagementReportService;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

class B2cWorkspaceController extends Controller
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly B2cDashboardService $dashboard,
        private readonly ManagementReportService $reports,
    ) {}

    public function show(Request $request, string $module = 'dashboard'): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $allowed = ['dashboard', 'products', 'inventory', 'orders', 'customers', 'promotions', 'drivers', 'storefront', 'content', 'reports', 'settings'];
        abort_unless(in_array($module, $allowed, true), 404);
        $storeIds = $this->storeIds($user);
        abort_if($storeIds === [] && ! $user->hasRole('SUPER_ADMIN'), 403, 'No assigned B2C store.');
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
        $moduleData = in_array($module, ['products', 'inventory', 'orders', 'customers', 'promotions', 'drivers', 'storefront', 'content', 'reports', 'settings'], true)
            ? $this->moduleData($module, $storeIds, $user)
            : null;

        return view('admin.b2c-workspace', compact('user', 'module', 'storeIds', 'counts', 'navGroups', 'navContext', 'dashboard', 'moduleData'));
    }

    public function transitionOrder(
        Request $request,
        int $order,
        OrderController $orders,
        AuditLogger $audit,
        DashboardOperationalNotifier $dashboardNotifier,
    ): RedirectResponse {
        $isB2c = DB::table('orders')
            ->join('stores', 'stores.id', '=', 'orders.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('orders.id', $order)
            ->where('orders.channel', 'b2c')
            ->where('store_types.code', 'B2C')
            ->exists();
        abort_unless($isB2c, 404);

        $orders->transition($request, $order, $audit, $dashboardNotifier);

        return back()->with('status', app()->getLocale() === 'ar' ? 'تم تحديث حالة الطلب.' : 'Order status updated.');
    }

    public function assignDriver(
        Request $request,
        DriverAssignmentController $deliveries,
        AuditLogger $audit,
        DashboardOperationalNotifier $dashboardNotifier,
    ): RedirectResponse {
        $driverId = $request->integer('driver_id');
        $orderId = $request->integer('order_id');
        abort_unless(DB::table('drivers')->where('id', $driverId)->where('driver_type', 'b2c')->exists(), 422);
        abort_unless(DB::table('orders')->where('id', $orderId)->where('channel', 'b2c')->exists(), 422);

        $deliveries->assign($request, $audit, $dashboardNotifier);

        return back()->with('status', app()->getLocale() === 'ar' ? 'تم تعيين السائق.' : 'Driver assigned.');
    }

    public function adjustInventory(
        Request $request,
        int $inventory,
        InventoryController $inventoryApi,
        AuditLogger $audit,
    ): RedirectResponse {
        $model = Inventory::query()->findOrFail($inventory);
        $isB2c = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->join('stores', 'stores.id', '=', 'warehouses.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('inventories.id', $inventory)
            ->where('store_types.code', 'B2C')
            ->exists();
        abort_unless($isB2c, 404);

        $inventoryApi->adjust($request, $model, $audit);

        return back()->with('status', app()->getLocale() === 'ar' ? 'تم تعديل المخزون.' : 'Inventory adjusted.');
    }

    private function moduleData(string $module, array $storeIds, User $user): array
    {
        return match ($module) {
            'products' => [
                'columns' => ['sku', 'name', 'category', 'store', 'price', 'status'],
                'actions' => $user->hasPermission('catalog.view') ? [
                    ['label' => app()->getLocale() === 'ar' ? 'إضافة / تعديل المنتجات' : 'Add / edit products', 'url' => route('admin.manage.products')],
                    ['label' => app()->getLocale() === 'ar' ? 'إدارة التصنيفات' : 'Manage categories', 'url' => route('admin.manage.categories')],
                    ['label' => app()->getLocale() === 'ar' ? 'العلامات والوحدات' : 'Brands & units', 'url' => route('admin.manage.brands')],
                ] : [],
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
                'inventory_options' => DB::table('inventories')
                    ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                    ->join('products', 'products.id', '=', 'inventories.product_id')
                    ->whereIn('warehouses.store_id', $storeIds)
                    ->orderBy('products.name')
                    ->get(['inventories.id', 'products.sku', 'products.name', 'warehouses.name as warehouse'])
                    ->map(fn ($row) => ['id' => (int) $row->id, 'label' => $row->sku.' · '.$row->name.' · '.$row->warehouse])
                    ->all(),
                'actions' => $user->hasPermission('inventory.view') ? [
                    ['label' => app()->getLocale() === 'ar' ? 'إدارة المخازن' : 'Manage warehouses', 'url' => route('admin.manage.warehouses')],
                    ['label' => app()->getLocale() === 'ar' ? 'إدارة المنتجات' : 'Manage products', 'url' => route('admin.manage.products')],
                ] : [],
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
                'columns' => ['number', 'customer', 'store', 'status', 'amount', 'created', 'actions'],
                'rows' => DB::table('orders')
                    ->join('customers', 'customers.id', '=', 'orders.customer_id')
                    ->join('stores', 'stores.id', '=', 'orders.store_id')
                    ->whereIn('orders.store_id', $storeIds)
                    ->where('orders.channel', 'b2c')
                    ->orderByDesc('orders.created_at')
                    ->limit(100)
                    ->get([
                        'orders.id',
                        'orders.store_id',
                        'orders.order_number as number',
                        'customers.name as customer',
                        'stores.name as store',
                        'orders.status',
                        'orders.currency',
                        'orders.grand_total',
                        'orders.created_at as created',
                    ])->map(fn ($row) => [
                        '_id' => (int) $row->id,
                        '_store_id' => (int) $row->store_id,
                        'number' => $row->number,
                        'customer' => $row->customer,
                        'store' => $row->store,
                        'status' => $row->status,
                        'amount' => $row->currency.' '.number_format((float) $row->grand_total, 3),
                        'created' => (string) $row->created,
                        'actions' => true,
                    ])->all(),
            ],
            'customers' => [
                'columns' => ['name', 'phone', 'email', 'orders', 'spent', 'last_order'],
                'actions' => $user->hasPermission('customers.view') ? [
                    ['label' => app()->getLocale() === 'ar' ? 'إضافة / تعديل العملاء' : 'Add / edit customers', 'url' => route('admin.manage.customers')],
                ] : [],
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
            'promotions' => [
                'columns' => ['name', 'store', 'type', 'value', 'period', 'status'],
                'actions' => $user->hasPermission('promotions.view') ? [
                    ['label' => app()->getLocale() === 'ar' ? 'إضافة / تعديل العروض' : 'Add / edit promotions', 'url' => route('admin.manage.promotions')],
                ] : [],
                'rows' => DB::table('promotions')
                    ->join('stores', 'stores.id', '=', 'promotions.store_id')
                    ->whereIn('promotions.store_id', $storeIds)
                    ->orderByDesc('promotions.created_at')
                    ->limit(100)
                    ->get([
                        'promotions.name',
                        'stores.name as store',
                        'promotions.type',
                        'promotions.value',
                        'promotions.starts_at',
                        'promotions.ends_at',
                        'promotions.is_active as status',
                    ])->map(fn ($row) => [
                        'name' => $row->name,
                        'store' => $row->store,
                        'type' => $row->type,
                        'value' => $row->value === null ? '-' : number_format((float) $row->value, 3),
                        'period' => trim(($row->starts_at ?: '-').' → '.($row->ends_at ?: '-')),
                        'status' => (bool) $row->status,
                    ])->all(),
            ],
            'drivers' => [
                'columns' => ['name', 'driver_type', 'order', 'assignment_status', 'availability'],
                'drivers' => DB::table('drivers')
                    ->join('users', 'users.id', '=', 'drivers.user_id')
                    ->where('drivers.driver_type', 'b2c')
                    ->where('drivers.is_active', true)
                    ->orderBy('users.name')
                    ->get(['drivers.id', 'users.name'])
                    ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name])
                    ->all(),
                'orders' => DB::table('orders')
                    ->whereIn('store_id', $storeIds)
                    ->where('channel', 'b2c')
                    ->whereNotIn('status', ['delivered', 'cancelled'])
                    ->orderByDesc('id')
                    ->limit(100)
                    ->get(['id', 'order_number'])
                    ->map(fn ($row) => ['id' => (int) $row->id, 'number' => $row->order_number])
                    ->all(),
                'rows' => DB::table('driver_assignments')
                    ->join('orders', 'orders.id', '=', 'driver_assignments.order_id')
                    ->join('drivers', 'drivers.id', '=', 'driver_assignments.driver_id')
                    ->join('users', 'users.id', '=', 'drivers.user_id')
                    ->whereIn('orders.store_id', $storeIds)
                    ->where('orders.channel', 'b2c')
                    ->orderByDesc('driver_assignments.created_at')
                    ->limit(100)
                    ->get([
                        'users.name',
                        'drivers.driver_type',
                        'drivers.is_available',
                        'orders.order_number as order_number',
                        'driver_assignments.status as assignment_status',
                    ])->map(fn ($row) => [
                        'name' => $row->name,
                        'driver_type' => $row->driver_type,
                        'order' => $row->order_number,
                        'assignment_status' => $row->assignment_status,
                        'availability' => (bool) $row->is_available,
                    ])->all(),
            ],
            'storefront' => [
                'columns' => ['store', 'products', 'banners', 'status'],
                'actions' => $user->hasPermission('stores.view') ? [
                    ['label' => app()->getLocale() === 'ar' ? 'إدارة المتاجر' : 'Manage stores', 'url' => route('admin.manage.stores')],
                    ['label' => app()->getLocale() === 'ar' ? 'إدارة البانرات' : 'Manage banners', 'url' => route('admin.manage.banners')],
                ] : [],
                'rows' => DB::table('stores')
                    ->whereIn('stores.id', $storeIds)
                    ->orderBy('stores.name')
                    ->get(['stores.id', 'stores.name', 'stores.is_active'])
                    ->map(fn ($store) => [
                        'store' => $store->name,
                        'products' => DB::table('store_products')->where('store_id', $store->id)->where('is_active', true)->count(),
                        'banners' => DB::table('banners')->where('store_id', $store->id)->where('is_active', true)->count(),
                        'status' => (bool) $store->is_active,
                    ])->all(),
            ],
            'content' => [
                'columns' => ['title', 'store', 'image', 'target', 'sort_order', 'status'],
                'actions' => $user->hasPermission('promotions.view') ? [
                    ['label' => app()->getLocale() === 'ar' ? 'إضافة / تعديل البانرات' : 'Add / edit banners', 'url' => route('admin.manage.banners')],
                    ['label' => app()->getLocale() === 'ar' ? 'إدارة العروض' : 'Manage promotions', 'url' => route('admin.manage.promotions')],
                ] : [],
                'rows' => DB::table('banners')
                    ->join('stores', 'stores.id', '=', 'banners.store_id')
                    ->whereIn('banners.store_id', $storeIds)
                    ->orderBy('banners.sort_order')
                    ->orderByDesc('banners.id')
                    ->limit(100)
                    ->get([
                        'banners.title',
                        'stores.name as store',
                        'banners.image_path as image',
                        'banners.target_url as target',
                        'banners.sort_order',
                        'banners.is_active as status',
                    ])->map(fn ($row) => [
                        'title' => $row->title,
                        'store' => $row->store,
                        'image' => $row->image,
                        'target' => $row->target ?: '-',
                        'sort_order' => (int) $row->sort_order,
                        'status' => (bool) $row->status,
                    ])->all(),
            ],
            'reports' => $this->reportModuleData($user, $storeIds),
            'settings' => $this->settingsModuleData($user, $storeIds),
            default => ['columns' => [], 'rows' => []],
        };
    }

    private function reportModuleData(User $user, array $storeIds): array
    {
        $stores = DB::table('stores')->whereIn('id', $storeIds)->orderBy('name')->get(['id', 'name']);

        return [
            'columns' => ['store', 'orders', 'revenue', 'average', 'actions'],
            'rows' => $stores->map(function ($store) use ($user): array {
                $data = $this->reports->run($user, 'orders', [
                    'store_id' => (int) $store->id,
                    'channel' => 'b2c',
                ]);

                $actions = [[
                    'label' => app()->getLocale() === 'ar' ? 'فتح مركز التقارير' : 'Open reports',
                    'url' => route('admin.reports.index', [
                        'report' => 'orders',
                        'store_id' => (int) $store->id,
                        'channel' => 'b2c',
                    ]),
                ]];

                if ($user->hasPermission('reports.export', (int) $store->id)) {
                    foreach (['xlsx', 'docx', 'pdf'] as $format) {
                        $actions[] = [
                            'label' => strtoupper($format),
                            'url' => route('admin.reports.export', [
                                'report' => 'orders',
                                'format' => $format,
                                'store_id' => (int) $store->id,
                                'channel' => 'b2c',
                            ]),
                        ];
                    }
                }

                return [
                    'store' => $store->name,
                    'orders' => (int) data_get($data, 'kpis.orders', 0),
                    'revenue' => 'KWD '.number_format((float) data_get($data, 'kpis.recognized_revenue', 0), 3),
                    'average' => 'KWD '.number_format((float) data_get($data, 'kpis.average_order_value', 0), 3),
                    'actions' => $actions,
                ];
            })->all(),
        ];
    }

    private function settingsModuleData(User $user, array $storeIds): array
    {
        $actions = [];

        if ($user->hasPermission('security.view')) {
            $actions[] = [
                'label' => app()->getLocale() === 'ar' ? 'الصلاحيات والمستخدمون' : 'Security & users',
                'url' => route('admin.security.index'),
            ];
        }
        if ($user->hasPermission('translations.manage')) {
            $actions[] = [
                'label' => app()->getLocale() === 'ar' ? 'إدارة الترجمات' : 'Translations',
                'url' => route('admin.translations.index'),
            ];
        }
        if ($user->hasPermission('mobile_settings.manage')
            || $user->hasPermission('push_settings.manage')
            || $user->hasPermission('push_settings.test')) {
            $actions[] = [
                'label' => app()->getLocale() === 'ar' ? 'إعدادات الموبايل والإشعارات' : 'Mobile & push settings',
                'url' => route('admin.mobile-settings.index'),
            ];
        }

        return [
            'columns' => ['store', 'setting', 'value'],
            'rows' => DB::table('settings')
                ->join('stores', 'stores.id', '=', 'settings.store_id')
                ->whereIn('settings.store_id', $storeIds)
                ->where('settings.is_secret', false)
                ->orderBy('stores.name')
                ->orderBy('settings.key')
                ->limit(150)
                ->get(['stores.name as store', 'settings.key as setting', 'settings.value'])
                ->map(fn ($row) => [
                    'store' => $row->store,
                    'setting' => $row->setting,
                    'value' => $this->displaySettingValue($row->value),
                ])->all(),
            'actions' => $actions,
        ];
    }

    private function displaySettingValue(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (is_array($decoded)) {
            return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-';
        }

        return is_scalar($decoded) ? (string) $decoded : '-';
    }

    private function storeIds(User $user): array
    {
        if ($user->roles()->where('roles.code', 'SUPER_ADMIN')->exists()) {
            return DB::table('stores')->join('store_types', 'store_types.id', '=', 'stores.store_type_id')->where('store_types.code', 'B2C')->pluck('stores.id')->map(fn ($id) => (int) $id)->all();
        }

        return $user->storeRoleAssignments()->whereHas('role', fn ($q) => $q->where('code', 'B2C_STORE_ADMIN'))->pluck('store_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
