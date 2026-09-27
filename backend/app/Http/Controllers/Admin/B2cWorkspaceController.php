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
use App\Support\TenantContextResolver;
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
        private readonly TenantContextResolver $tenantContext,
    ) {}

    public function show(Request $request, string $module = 'dashboard'): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $allowed = ['dashboard', 'products', 'inventory', 'orders', 'customers', 'promotions', 'drivers', 'storefront', 'content', 'reports', 'settings'];
        abort_unless(in_array($module, $allowed, true), 404);
        $workspace = $this->workspaceContext($request, $user);
        $storeId = $workspace['selected_store_id'];
        $storeIds = [$storeId];
        $availableStores = $workspace['stores'];
        $supportAccess = $workspace['support_access'];
        App::setLocale(in_array($user->locale, ['ar', 'en'], true) ? $user->locale : 'ar');

        $counts = [
            'products' => DB::table('store_products')->whereIn('store_id', $storeIds)->count(),
            'orders' => DB::table('orders')->whereIn('store_id', $storeIds)->where('channel', 'b2c')->count(),
            'customers' => DB::table('b2c_customers')->whereIn('store_id', $storeIds)->count(),
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
            ? $this->moduleData($module, $storeIds, $user, $storeId, $supportAccess)
            : null;

        return view('admin.b2c-workspace', compact(
            'user',
            'module',
            'storeIds',
            'storeId',
            'availableStores',
            'supportAccess',
            'counts',
            'navGroups',
            'navContext',
            'dashboard',
            'moduleData',
        ));
    }

    public function transitionOrder(
        Request $request,
        int $order,
        OrderController $orders,
        AuditLogger $audit,
        DashboardOperationalNotifier $dashboardNotifier,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $storeId = $this->workspaceStoreId($request, $user);
        $storeIds = [$storeId];
        $request->merge(['store' => $storeId, 'store_id' => $storeId]);
        $isOwnedB2c = DB::table('orders')
            ->join('stores', 'stores.id', '=', 'orders.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('orders.id', $order)
            ->whereIn('orders.store_id', $storeIds)
            ->where('orders.channel', 'b2c')
            ->where('store_types.code', 'B2C')
            ->exists();
        abort_unless($isOwnedB2c, 404);
        $orders->transition($request, $order, $audit, $dashboardNotifier);

        return back()->with('status', app()->getLocale() === 'ar' ? 'تم تحديث حالة الطلب.' : 'Order status updated.');
    }

    public function assignDriver(
        Request $request,
        DriverAssignmentController $deliveries,
        AuditLogger $audit,
        DashboardOperationalNotifier $dashboardNotifier,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $storeId = $this->workspaceStoreId($request, $user);
        $storeIds = [$storeId];
        $request->merge(['store' => $storeId, 'store_id' => $storeId]);
        $driverId = $request->integer('driver_id');
        $orderId = $request->integer('order_id');
        abort_unless(
            DB::table('drivers')
                ->where('id', $driverId)
                ->where('store_id', $storeId)
                ->where('driver_type', 'b2c')
                ->exists(),
            404,
        );
        abort_unless(
            DB::table('orders')->where('id', $orderId)->whereIn('store_id', $storeIds)->where('channel', 'b2c')->exists(),
            404,
        );
        $deliveries->assign($request, $audit, $dashboardNotifier);

        return back()->with('status', app()->getLocale() === 'ar' ? 'تم تعيين السائق.' : 'Driver assigned.');
    }

    public function adjustInventory(
        Request $request,
        int $inventory,
        InventoryController $inventoryApi,
        AuditLogger $audit,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $storeId = $this->workspaceStoreId($request, $user);
        $storeIds = [$storeId];
        $request->merge(['store' => $storeId, 'store_id' => $storeId]);
        $isOwnedB2c = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->join('stores', 'stores.id', '=', 'warehouses.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('inventories.id', $inventory)
            ->whereIn('warehouses.store_id', $storeIds)
            ->where('store_types.code', 'B2C')
            ->exists();
        abort_unless($isOwnedB2c, 404);
        $model = Inventory::query()->findOrFail($inventory);
        $inventoryApi->adjust($request, $model, $audit);

        return back()->with('status', app()->getLocale() === 'ar' ? 'تم تعديل المخزون.' : 'Inventory adjusted.');
    }

    private function moduleData(
        string $module,
        array $storeIds,
        User $user,
        int $selectedStoreId,
        bool $supportAccess,
    ): array {
        if ($selectedStoreId <= 0) {
            return $this->controlPlanePreviewData($module);
        }

        $scopeParams = ['store_id' => $selectedStoreId];
        if ($supportAccess) {
            $scopeParams['support_access'] = 1;
        }

        return match ($module) {
            'products' => [
                'actions' => [
                    ['label' => app()->getLocale() === 'ar' ? 'إضافة / تعديل المنتجات' : 'Add / Edit Products', 'url' => route('admin.catalog.index', array_merge(['tab' => 'products'], $scopeParams))],
                    ['label' => app()->getLocale() === 'ar' ? 'إدارة التصنيفات' : 'Manage Categories', 'url' => route('admin.catalog.index', array_merge(['tab' => 'categories'], $scopeParams))],
                    ['label' => app()->getLocale() === 'ar' ? 'إدارة المتاجر' : 'Manage Stores', 'url' => route('admin.catalog.index', array_merge(['tab' => 'stores'], $scopeParams))],
                ],
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
                'actions' => [['label' => app()->getLocale() === 'ar' ? 'إدارة المخازن والأرصدة' : 'Manage Warehouses & Stock', 'url' => route('admin.business.index', array_merge(['tab' => 'inventory'], $scopeParams))]],
                'inventory_options' => DB::table('inventories')
                    ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                    ->join('products', 'products.id', '=', 'inventories.product_id')
                    ->whereIn('warehouses.store_id', $storeIds)
                    ->orderBy('products.name')
                    ->get(['inventories.id', 'products.sku', 'products.name', 'warehouses.name as warehouse'])
                    ->map(fn ($row) => ['id' => (int) $row->id, 'label' => $row->sku.' · '.$row->name.' · '.$row->warehouse])
                    ->all(),
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
                'columns' => ['number', 'customer', 'store', 'status', 'amount', 'created', 'actions'],
                'rows' => DB::table('orders')
                    ->join('b2c_customers', 'b2c_customers.id', '=', 'orders.b2c_customer_id')
                    ->join('stores', 'stores.id', '=', 'orders.store_id')
                    ->whereIn('orders.store_id', $storeIds)
                    ->where('orders.channel', 'b2c')
                    ->orderByDesc('orders.created_at')
                    ->limit(100)
                    ->get([
                        'orders.id',
                        'orders.store_id',
                        'orders.order_number as number',
                        'b2c_customers.name as customer',
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
                'actions' => [['label' => app()->getLocale() === 'ar' ? 'إضافة / تعديل العملاء' : 'Add / Edit Customers', 'url' => route('admin.business.index', array_merge(['tab' => 'customers'], $scopeParams))]],
                'columns' => ['name', 'phone', 'email', 'orders', 'spent', 'last_order'],
                'rows' => DB::table('b2c_customers')
                    ->leftJoin('orders', function ($join): void {
                        $join->on('orders.b2c_customer_id', '=', 'b2c_customers.id')
                            ->on('orders.store_id', '=', 'b2c_customers.store_id')
                            ->where('orders.channel', '=', 'b2c');
                    })
                    ->whereIn('b2c_customers.store_id', $storeIds)
                    ->groupBy(
                        'b2c_customers.id',
                        'b2c_customers.store_id',
                        'b2c_customers.name',
                        'b2c_customers.phone',
                        'b2c_customers.email',
                    )
                    ->orderByDesc(DB::raw('MAX(orders.created_at)'))
                    ->orderBy('b2c_customers.name')
                    ->limit(100)
                    ->get([
                        'b2c_customers.name',
                        'b2c_customers.phone',
                        'b2c_customers.email',
                        DB::raw('COUNT(orders.id) as orders_count'),
                        DB::raw('COALESCE(SUM(orders.grand_total), 0) as total_spent'),
                        DB::raw('MAX(orders.created_at) as last_order'),
                    ])->map(fn ($row) => [
                        'name' => $row->name,
                        'phone' => $row->phone ?: '-',
                        'email' => $row->email ?: '-',
                        'orders' => (int) $row->orders_count,
                        'spent' => 'KWD '.number_format((float) $row->total_spent, 3),
                        'last_order' => $row->last_order === null ? '-' : (string) $row->last_order,
                    ])->all(),
            ],
            'promotions' => [
                'actions' => [['label' => app()->getLocale() === 'ar' ? 'إضافة / تعديل العروض' : 'Add / Edit Promotions', 'url' => route('admin.business.index', array_merge(['tab' => 'promotions'], $scopeParams))]],
                'columns' => ['name', 'store', 'type', 'value', 'period', 'status'],
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
                'actions' => [['label' => app()->getLocale() === 'ar' ? 'إضافة / إدارة السائقين' : 'Add / Manage Drivers', 'url' => route('admin.business.index', array_merge(['tab' => 'drivers'], $scopeParams))]],
                'drivers' => DB::table('drivers')
                    ->join('users', 'users.id', '=', 'drivers.user_id')
                    ->where('drivers.driver_type', 'b2c')
                    ->whereIn('drivers.store_id', $storeIds)
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
                'columns' => ['name', 'driver_type', 'order', 'assignment_status', 'availability'],
                'rows' => DB::table('driver_assignments')
                    ->join('orders', 'orders.id', '=', 'driver_assignments.order_id')
                    ->join('drivers', 'drivers.id', '=', 'driver_assignments.driver_id')
                    ->join('users', 'users.id', '=', 'drivers.user_id')
                    ->whereIn('orders.store_id', $storeIds)
                    ->whereIn('driver_assignments.store_id', $storeIds)
                    ->whereColumn('driver_assignments.store_id', 'orders.store_id')
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
                'actions' => [['label' => app()->getLocale() === 'ar' ? 'إضافة / تعديل البنرات' : 'Add / Edit Banners', 'url' => route('admin.business.index', array_merge(['tab' => 'content'], $scopeParams))]],
                'columns' => ['title', 'store', 'image', 'target', 'sort_order', 'status'],
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

    private function controlPlanePreviewData(string $module): array
    {
        $actions = match ($module) {
            'products' => [
                ['label' => app()->getLocale() === 'ar' ? 'إضافة / تعديل المنتجات' : 'Add / Edit Products', 'url' => route('admin.retail-stores.index')],
                ['label' => app()->getLocale() === 'ar' ? 'إدارة التصنيفات' : 'Manage Categories', 'url' => route('admin.retail-stores.index')],
            ],
            'inventory' => [['label' => app()->getLocale() === 'ar' ? 'إدارة المخازن والأرصدة' : 'Manage Warehouses & Stock', 'url' => route('admin.retail-stores.index')]],
            'customers' => [['label' => app()->getLocale() === 'ar' ? 'إضافة / تعديل العملاء' : 'Add / Edit Customers', 'url' => route('admin.retail-stores.index')]],
            'promotions' => [['label' => app()->getLocale() === 'ar' ? 'إضافة / تعديل العروض' : 'Add / Edit Promotions', 'url' => route('admin.retail-stores.index')]],
            'content' => [['label' => app()->getLocale() === 'ar' ? 'إضافة / تعديل البنرات' : 'Add / Edit Banners', 'url' => route('admin.retail-stores.index')]],
            'drivers' => [['label' => app()->getLocale() === 'ar' ? 'إضافة / إدارة السائقين' : 'Add / Manage Drivers', 'url' => route('admin.retail-stores.index')]],
            default => [],
        };

        return [
            'actions' => $actions,
            'columns' => [],
            'rows' => [],
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

    /**
     * @return array{selected_store_id:int,stores:list<object>,support_access:bool}
     */
    private function workspaceContext(Request $request, User $user): array
    {
        $requestedStoreId = $request->integer('store_id') ?: $request->integer('store');

        if ($user->hasRole('SUPER_ADMIN')) {
            if ($requestedStoreId <= 0) {
                return [
                    'selected_store_id' => 0,
                    'stores' => [],
                    'support_access' => false,
                ];
            }

            abort_unless($request->boolean('support_access'), 403);
            $this->tenantContext->retail($user, $requestedStoreId, true, $request);

            return [
                'selected_store_id' => $requestedStoreId,
                'stores' => DB::table('stores')
                    ->where('id', $requestedStoreId)
                    ->get(['id', 'name', 'code'])
                    ->all(),
                'support_access' => true,
            ];
        }

        $storeIds = $this->tenantContext->retailStoreIds($user);
        abort_if($storeIds === [], 403, 'No assigned B2C store.');

        if ($requestedStoreId > 0) {
            abort_unless(in_array($requestedStoreId, $storeIds, true), 404);
            $selectedStoreId = $requestedStoreId;
        } else {
            $selectedStoreId = $storeIds[0];
        }

        $this->tenantContext->retail($user, $selectedStoreId, false, $request);

        return [
            'selected_store_id' => $selectedStoreId,
            'stores' => DB::table('stores')
                ->whereIn('id', $storeIds)
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->all(),
            'support_access' => false,
        ];
    }

    private function workspaceStoreId(Request $request, User $user): int
    {
        return $this->workspaceContext($request, $user)['selected_store_id'];
    }
}
