<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\V1\DriverAssignmentController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\User;
use App\Services\AdminOrderManagementService;
use App\Services\AuditLogger;
use App\Services\B2cDashboardService;
use App\Services\DashboardOperationalNotifier;
use App\Services\DriverAssignmentManagementService;
use App\Services\ManagementReportService;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use App\Support\TenantContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class B2cWorkspaceController extends Controller
{
    /** @var array<string, string|null> */
    private const MODULE_PERMISSIONS = [
        'dashboard' => null,
        'products' => 'catalog.view',
        'inventory' => 'inventory.view',
        'orders' => 'orders.view',
        'customers' => 'customers.view',
        'promotions' => 'promotions.view',
        'drivers' => 'drivers.b2c.view',
        'storefront' => 'stores.view',
        'content' => 'promotions.view',
        'reports' => 'reports.view',
        'settings' => 'settings.view',
    ];

    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly B2cDashboardService $dashboard,
        private readonly ManagementReportService $reports,
        private readonly TenantContextResolver $tenantContext,
    ) {}

    public function show(Request $request, string $module = 'dashboard'): View|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless(array_key_exists($module, self::MODULE_PERMISSIONS), 404);

        if ($user->hasRole('SUPER_ADMIN')
            && $request->integer('store_id') <= 0
            && $request->integer('store') <= 0) {
            return redirect()->route('admin.retail-stores.index')
                ->with('status', $this->msg(
                    'اختر متجر تجزئة ثم استخدم «إدارة / فحص المتجر» للدخول إلى سياقه بشكل صريح.',
                    'Choose a Retail store and use Manage / Inspect Store to enter its explicit support context.',
                ));
        }

        $workspace = $this->workspaceContext($request, $user);
        $storeId = $workspace['selected_store_id'];
        $this->authorizeModule($user, $module, $storeId);
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
        $visibleModules = array_values(array_filter(
            array_keys(self::MODULE_PERMISSIONS),
            fn (string $candidate): bool => $this->canOpenModule($user, $candidate, $storeId),
        ));

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
            'visibleModules',
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

    public function storeOrder(Request $request, AdminOrderManagementService $orders): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $storeId = $this->workspaceStoreId($request, $user);
        app(OperationalTenantScope::class)->assertStore($user, $storeId, 'orders.manage', 'b2c');

        $order = $orders->create($request, $user, 'b2c', $storeId);

        return back()->with('status', app()->getLocale() === 'ar'
            ? 'تم إنشاء الطلب '.$order->order_number.'.'
            : 'Order '.$order->order_number.' created.');
    }

    public function updateOrder(Request $request, int $order, AdminOrderManagementService $orders): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $storeId = $this->workspaceStoreId($request, $user);
        app(OperationalTenantScope::class)->assertStore($user, $storeId, 'orders.manage', 'b2c');

        $model = Order::query()
            ->whereKey($order)
            ->where('store_id', $storeId)
            ->where('channel', 'b2c')
            ->whereNotNull('b2c_customer_id')
            ->firstOrFail();

        $orders->update($request, $user, $model);

        return back()->with('status', app()->getLocale() === 'ar' ? 'تم تحديث الطلب.' : 'Order updated.');
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

    public function withdrawDriverAssignment(
        Request $request,
        int $assignment,
        DriverAssignmentManagementService $assignments,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $storeId = $this->workspaceStoreId($request, $actor);
        app(OperationalTenantScope::class)->assertStore($actor, $storeId, 'drivers.b2c.manage', 'b2c');

        $model = DriverAssignment::query()
            ->whereKey($assignment)
            ->where('store_id', $storeId)
            ->where('assignment_type', 'b2c')
            ->firstOrFail();

        $assignments->withdraw($model, $actor, $request, $request->string('note')->toString() ?: null);

        return back()->with('status', $this->msg('تم سحب الطلب من السائق وأصبح متاحًا لإعادة التعيين.', 'Order withdrawn from the driver and is ready for reassignment.'));
    }

    public function reassignDriverAssignment(
        Request $request,
        int $assignment,
        DriverAssignmentManagementService $assignments,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $data = $request->validate([
            'driver_id' => ['required', 'integer', 'exists:drivers,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $storeId = $this->workspaceStoreId($request, $actor);
        app(OperationalTenantScope::class)->assertStore($actor, $storeId, 'drivers.b2c.manage', 'b2c');

        $model = DriverAssignment::query()
            ->whereKey($assignment)
            ->where('store_id', $storeId)
            ->where('assignment_type', 'b2c')
            ->firstOrFail();
        $driver = Driver::query()
            ->whereKey((int) $data['driver_id'])
            ->where('driver_type', 'b2c')
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->firstOrFail();

        $assignments->reassign($model, $driver, $actor, $request, $data['note'] ?? null);

        return back()->with('status', $this->msg('تم إعادة تعيين الطلب للسائق الجديد.', 'Order reassigned to the new driver.'));
    }

    public function resetDriverPassword(
        Request $request,
        int $driver,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $storeId = $this->workspaceStoreId($request, $actor);
        app(OperationalTenantScope::class)->assertStore($actor, $storeId, 'drivers.b2c.manage', 'b2c');

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $driverRow = DB::table('drivers')
            ->where('id', $driver)
            ->where('store_id', $storeId)
            ->where('driver_type', 'b2c')
            ->first(['id', 'user_id']);
        abort_unless($driverRow !== null, 404);

        $driverUser = User::query()->findOrFail((int) $driverRow->user_id);
        $driverUser->forceFill(['password' => Hash::make($data['password'])])->save();
        $driverUser->tokens()->delete();

        $audit->record(
            'b2c.driver.password_reset',
            $actor,
            $driverUser,
            ['driver_id' => (int) $driverRow->id, 'user_id' => $driverUser->id],
            ['driver_id' => (int) $driverRow->id, 'user_id' => $driverUser->id, 'tokens_revoked' => true],
            $request,
        );

        return back()->with('status', $this->msg(
            'تم تعيين كلمة مرور جديدة للسائق وإلغاء جلساته الحالية.',
            'Driver password reset and existing sessions were revoked.',
        ));
    }

    public function storeWarehouse(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'code' => ['required', 'string', 'max:80', 'unique:warehouses,code'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $storeId = $this->workspaceStoreId($request, $user);
        abort_unless($storeId === (int) $data['store_id'], 404);
        app(OperationalTenantScope::class)->assertStore($user, $storeId, 'inventory.manage', 'b2c');

        DB::table('warehouses')->insert([
            'store_id' => $storeId,
            'code' => $data['code'],
            'name' => $data['name'],
            'is_active' => $request->boolean('is_active', true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('status', $this->msg('تم إنشاء المخزن.', 'Warehouse created.'));
    }

    public function ensureInventory(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'gte:0'],
        ]);
        $storeId = $this->workspaceStoreId($request, $user);
        abort_unless($storeId === (int) $data['store_id'], 404);
        app(OperationalTenantScope::class)->assertStore($user, $storeId, 'inventory.manage', 'b2c');

        $warehouseOwned = DB::table('warehouses')
            ->where('id', $data['warehouse_id'])
            ->where('store_id', $storeId)
            ->exists();
        $productOwned = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->where('products.id', $data['product_id'])
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2c')
            ->where('catalogs.is_migration_quarantine', false)
            ->exists();
        abort_unless($warehouseOwned && $productOwned, 404);

        $existing = DB::table('inventories')
            ->where('warehouse_id', $data['warehouse_id'])
            ->where('product_id', $data['product_id'])
            ->first(['id', 'reserved_quantity']);
        if ($existing !== null && (float) $data['quantity'] < (float) $existing->reserved_quantity) {
            throw ValidationException::withMessages([
                'quantity' => [$this->msg('لا يمكن جعل الكمية أقل من الكمية المحجوزة.', 'Quantity cannot be below the reserved quantity.')],
            ]);
        }

        if ($existing === null) {
            DB::table('inventories')->insert([
                'warehouse_id' => $data['warehouse_id'],
                'product_id' => $data['product_id'],
                'quantity' => $data['quantity'],
                'reserved_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('inventories')->where('id', $existing->id)->update([
                'quantity' => $data['quantity'],
                'updated_at' => now(),
            ]);
        }

        return back()->with('status', $this->msg('تم إنشاء/تحديث رصيد المخزون.', 'Inventory balance created/updated.'));
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

    public function saveSetting(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $storeId = $this->workspaceStoreId($request, $user);
        app(OperationalTenantScope::class)->assertStore($user, $storeId, 'settings.manage', 'b2c');

        $data = $request->validate([
            'key' => ['required', 'string', 'max:255'],
            'value' => ['nullable', 'string', 'max:5000'],
        ]);

        $existing = DB::table('settings')
            ->where('store_id', $storeId)
            ->where('key', $data['key'])
            ->first(['id', 'is_secret']);
        abort_if($existing !== null && (bool) $existing->is_secret, 403);

        DB::table('settings')->updateOrInsert(
            ['store_id' => $storeId, 'key' => $data['key']],
            [
                'value' => json_encode($data['value'] ?? null, JSON_THROW_ON_ERROR),
                'is_secret' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $audit->record('b2c.setting.saved', $user, null, null, [
            'store_id' => $storeId,
            'key' => $data['key'],
        ], $request);

        return back()->with('status', app()->getLocale() === 'ar' ? 'تم حفظ إعداد المتجر.' : 'Store setting saved.');
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
                    ['label' => app()->getLocale() === 'ar' ? 'إدارة المخزون' : 'Manage Inventory', 'url' => route('admin.b2c.module', array_merge(['module' => 'inventory'], $scopeParams))],
                ],
                'columns' => ['sku', 'name', 'category', 'store', 'cost', 'price', 'status'],
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
                        'store_products.cost_price',
                        'store_products.price',
                        'store_products.is_active as status',
                    ])->map(fn ($row) => [
                        'sku' => $row->sku,
                        'name' => $row->name,
                        'category' => $row->category,
                        'store' => $row->store,
                        'cost' => $row->cost_price === null ? '-' : number_format((float) $row->cost_price, 3).' EGP',
                        'price' => $row->price === null ? '-' : number_format((float) $row->price, 3).' EGP',
                        'status' => (bool) $row->status,
                    ])->all(),
            ],
            'inventory' => $this->inventoryModuleData($storeIds),
            'orders' => $this->orderModuleData($storeIds),
            'customers' => $this->customerModuleData($storeIds),
            'promotions' => [
                'actions' => [],
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
                'actions' => [],
                'drivers' => DB::table('drivers')
                    ->join('users', 'users.id', '=', 'drivers.user_id')
                    ->where('drivers.driver_type', 'b2c')
                    ->whereIn('drivers.store_id', $storeIds)
                    ->where('drivers.is_active', true)
                    ->orderBy('users.name')
                    ->get(['drivers.id', 'users.name', 'users.email'])
                    ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name, 'email' => $row->email])
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
                        'driver_type' => app()->getLocale() === 'ar' ? 'التجزئة' : 'Retail',
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
            'content' => $this->contentModuleData($storeIds),
            'reports' => $this->reportModuleData($user, $storeIds),
            'settings' => $this->settingsModuleData($user, $storeIds),
            default => ['columns' => [], 'rows' => []],
        };
    }

    /** @param list<int> $storeIds */
    private function inventoryModuleData(array $storeIds): array
    {
        return [
            'actions' => [],
            'inventory_options' => DB::table('inventories')
                ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                ->join('products', 'products.id', '=', 'inventories.product_id')
                ->whereIn('warehouses.store_id', $storeIds)
                ->orderBy('products.name')
                ->get(['inventories.id', 'products.sku', 'products.name', 'warehouses.name as warehouse'])
                ->map(fn ($row) => ['id' => (int) $row->id, 'label' => $row->sku.' · '.$row->name.' · '.$row->warehouse])
                ->all(),
            'warehouses' => DB::table('warehouses')
                ->whereIn('store_id', $storeIds)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'store_id', 'code', 'name'])
                ->map(fn ($row) => ['id' => (int) $row->id, 'store_id' => (int) $row->store_id, 'code' => $row->code, 'name' => $row->name])
                ->all(),
            'products' => DB::table('products')
                ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                ->whereIn('catalogs.store_id', $storeIds)
                ->where('catalogs.channel', 'b2c')
                ->where('catalogs.is_migration_quarantine', false)
                ->where('catalogs.is_active', true)
                ->where('products.is_active', true)
                ->orderBy('products.name')
                ->get(['products.id', 'catalogs.store_id', 'products.sku', 'products.name'])
                ->map(fn ($row) => ['id' => (int) $row->id, 'store_id' => (int) $row->store_id, 'sku' => $row->sku, 'name' => $row->name])
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
        ];
    }

    /** @param list<int> $storeIds */
    private function customerModuleData(array $storeIds): array
    {
        return [
            'actions' => [],
            'columns' => ['image', 'name', 'phone', 'email', 'orders', 'spent', 'last_order', 'actions'],
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
                    'b2c_customers.image_path',
                )
                ->orderByDesc(DB::raw('MAX(orders.created_at)'))
                ->orderBy('b2c_customers.name')
                ->limit(100)
                ->get([
                    'b2c_customers.id',
                    'b2c_customers.store_id',
                    'b2c_customers.image_path',
                    'b2c_customers.name',
                    'b2c_customers.phone',
                    'b2c_customers.email',
                    DB::raw('COUNT(orders.id) as orders_count'),
                    DB::raw('COALESCE(SUM(orders.grand_total), 0) as total_spent'),
                    DB::raw('MAX(orders.created_at) as last_order'),
                ])->map(fn ($row) => [
                    '_id' => (int) $row->id,
                    '_store_id' => (int) $row->store_id,
                    'image' => $row->image_path ?: '',
                    'name' => $row->name,
                    'phone' => $row->phone ?: '-',
                    'email' => $row->email ?: '-',
                    'orders' => (int) $row->orders_count,
                    'spent' => 'EGP '.number_format((float) $row->total_spent, 3),
                    'last_order' => $row->last_order === null ? '-' : (string) $row->last_order,
                    'actions' => true,
                ])->all(),
        ];
    }

    /** @param list<int> $storeIds */
    private function contentModuleData(array $storeIds): array
    {
        $targets = collect();

        DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->whereIn('catalogs.store_id', $storeIds)
            ->where('catalogs.channel', 'b2c')
            ->where('catalogs.is_migration_quarantine', false)
            ->where('products.is_active', true)
            ->orderBy('products.name')
            ->get(['products.id', 'products.name'])
            ->each(fn ($row) => $targets->push([
                'ref' => 'product:'.(int) $row->id,
                'label' => $this->msg('منتج · ', 'Product · ').$row->name,
            ]));

        DB::table('categories')
            ->join('catalogs', 'catalogs.id', '=', 'categories.catalog_id')
            ->whereIn('catalogs.store_id', $storeIds)
            ->where('catalogs.channel', 'b2c')
            ->where('catalogs.is_migration_quarantine', false)
            ->where('categories.is_active', true)
            ->orderBy('categories.name')
            ->get(['categories.id', 'categories.name'])
            ->each(fn ($row) => $targets->push([
                'ref' => 'category:'.(int) $row->id,
                'label' => $this->msg('تصنيف · ', 'Category · ').$row->name,
            ]));

        $targetLabels = $targets->pluck('label', 'ref');

        return [
            'actions' => [],
            'targets' => $targets->values()->all(),
            'columns' => ['image', 'title', 'target', 'sort_order', 'status', 'actions'],
            'rows' => DB::table('banners')
                ->join('stores', 'stores.id', '=', 'banners.store_id')
                ->whereIn('banners.store_id', $storeIds)
                ->orderBy('banners.sort_order')
                ->orderByDesc('banners.id')
                ->limit(100)
                ->get([
                    'banners.id',
                    'banners.store_id',
                    'banners.title',
                    'stores.name as store',
                    'banners.image_path as image',
                    'banners.target_type',
                    'banners.target_id',
                    'banners.sort_order',
                    'banners.is_active as status',
                ])->map(function ($row) use ($targetLabels): array {
                    $targetRef = $row->target_type !== null && $row->target_id !== null
                        ? $row->target_type.':'.(int) $row->target_id
                        : '';

                    return [
                        '_id' => (int) $row->id,
                        '_store_id' => (int) $row->store_id,
                        '_target_ref' => $targetRef,
                        'title' => $row->title,
                        'store' => $row->store,
                        'image' => $row->image,
                        'target' => $targetRef === '' ? '—' : (string) ($targetLabels[$targetRef] ?? $targetRef),
                        'sort_order' => (int) $row->sort_order,
                        'status' => (bool) $row->status,
                        'actions' => [],
                    ];
                })->all(),
        ];
    }

    private function orderModuleData(array $storeIds): array
    {
        $customers = DB::table('b2c_customers')
            ->whereIn('store_id', $storeIds)
            ->orderBy('name')
            ->get(['id', 'store_id', 'name'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'store_id' => (int) $row->store_id,
                'name' => $row->name,
            ])
            ->all();

        $rows = DB::table('orders')
            ->join('b2c_customers', 'b2c_customers.id', '=', 'orders.b2c_customer_id')
            ->join('stores', 'stores.id', '=', 'orders.store_id')
            ->whereIn('orders.store_id', $storeIds)
            ->where('orders.channel', 'b2c')
            ->whereNotNull('orders.b2c_customer_id')
            ->orderByDesc('orders.created_at')
            ->limit(100)
            ->get([
                'orders.id',
                'orders.store_id',
                'orders.b2c_customer_id',
                'orders.address_id',
                'orders.order_number as number',
                'b2c_customers.name as customer',
                'stores.name as store',
                'orders.status',
                'orders.currency',
                'orders.subtotal',
                'orders.discount_total',
                'orders.delivery_total',
                'orders.grand_total',
                'orders.payment_method',
                'orders.customer_note',
                'orders.created_at as created',
            ])
            ->map(function ($row): array {
                $items = DB::table('order_items')
                    ->where('order_id', $row->id)
                    ->orderBy('id')
                    ->get(['product_id', 'sku_snapshot', 'name_snapshot', 'quantity', 'unit_price', 'line_total'])
                    ->map(fn ($item) => [
                        'product_id' => (int) $item->product_id,
                        'sku' => $item->sku_snapshot,
                        'name' => $item->name_snapshot,
                        'quantity' => (float) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'line_total' => (float) $item->line_total,
                    ])->all();

                $payment = DB::table('payments')
                    ->where('order_id', $row->id)
                    ->orderByDesc('id')
                    ->first(['provider', 'status', 'amount', 'currency']);

                $history = DB::table('order_status_history')
                    ->where('order_id', $row->id)
                    ->where('store_id', $row->store_id)
                    ->orderByDesc('id')
                    ->limit(20)
                    ->get(['from_status', 'to_status', 'note', 'created_at'])
                    ->map(fn ($entry) => [
                        'from' => $entry->from_status,
                        'to' => $entry->to_status,
                        'note' => $entry->note,
                        'created_at' => (string) $entry->created_at,
                    ])->all();

                $invoice = DB::table('invoices')
                    ->where('order_id', $row->id)
                    ->orderByDesc('id')
                    ->first(['invoice_number', 'status', 'total', 'currency']);

                return [
                    '_id' => (int) $row->id,
                    '_store_id' => (int) $row->store_id,
                    '_customer_id' => (int) $row->b2c_customer_id,
                    '_address_id' => $row->address_id === null ? null : (int) $row->address_id,
                    '_subtotal' => (float) $row->subtotal,
                    '_discount_total' => (float) $row->discount_total,
                    '_delivery_total' => (float) $row->delivery_total,
                    '_grand_total' => (float) $row->grand_total,
                    '_payment_method' => $row->payment_method,
                    '_customer_note' => $row->customer_note,
                    '_items' => $items,
                    '_payment' => $payment === null ? null : [
                        'provider' => $payment->provider,
                        'status' => $payment->status,
                        'amount' => (float) $payment->amount,
                        'currency' => $payment->currency,
                    ],
                    '_history' => $history,
                    '_invoice' => $invoice === null ? null : [
                        'number' => $invoice->invoice_number,
                        'status' => $invoice->status,
                        'total' => (float) $invoice->total,
                        'currency' => $invoice->currency,
                    ],
                    'number' => $row->number,
                    'customer' => $row->customer,
                    'store' => $row->store,
                    'status' => $row->status,
                    'amount' => $row->currency.' '.number_format((float) $row->grand_total, 3),
                    'created' => (string) $row->created,
                    'actions' => true,
                ];
            })->all();

        return [
            'columns' => ['number', 'customer', 'store', 'status', 'amount', 'created', 'actions'],
            'rows' => $rows,
            'customers' => $customers,
            'products' => DB::table('store_products')
                ->join('products', 'products.id', '=', 'store_products.product_id')
                ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                ->whereIn('store_products.store_id', $storeIds)
                ->whereColumn('catalogs.store_id', 'store_products.store_id')
                ->where('catalogs.channel', 'b2c')
                ->where('catalogs.is_migration_quarantine', false)
                ->where('catalogs.is_active', true)
                ->where('products.is_active', true)
                ->where('store_products.is_active', true)
                ->whereNotNull('store_products.price')
                ->orderBy('products.name')
                ->get(['products.id', 'store_products.store_id', 'products.sku', 'products.name', 'store_products.price'])
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'store_id' => (int) $row->store_id,
                    'sku' => $row->sku,
                    'name' => $row->name,
                    'price' => (float) $row->price,
                ])->all(),
            'addresses' => DB::table('addresses')
                ->whereIn('b2c_customer_id', collect($customers)->pluck('id')->all())
                ->orderBy('id')
                ->get(['id', 'b2c_customer_id'])
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'customer_id' => (int) $row->b2c_customer_id,
                    'label' => 'Address #'.$row->id,
                ])->all(),
            'drivers' => DB::table('drivers')
                ->join('users', 'users.id', '=', 'drivers.user_id')
                ->where('drivers.driver_type', 'b2c')
                ->whereIn('drivers.store_id', $storeIds)
                ->where('drivers.is_active', true)
                ->orderBy('users.name')
                ->get(['drivers.id', 'drivers.store_id', 'users.name'])
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'store_id' => (int) $row->store_id,
                    'name' => $row->name,
                ])->all(),
            'payment_methods' => array_values((array) config('checkout.payment_methods', ['cash_on_delivery'])),
        ];
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
                    'revenue' => 'EGP '.number_format((float) data_get($data, 'kpis.recognized_revenue', 0), 3),
                    'average' => 'EGP '.number_format((float) data_get($data, 'kpis.average_order_value', 0), 3),
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
            'stores' => DB::table('stores')
                ->whereIn('id', $storeIds)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name])
                ->all(),
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
            'content' => [],
            'drivers' => [['label' => app()->getLocale() === 'ar' ? 'إضافة / إدارة السائقين' : 'Add / Manage Drivers', 'url' => route('admin.retail-stores.index')]],
            default => [],
        };

        return [
            'actions' => $actions,
            'columns' => [],
            'rows' => [],
        ];
    }

    private function canOpenModule(User $user, string $module, int $storeId): bool
    {
        $permission = self::MODULE_PERMISSIONS[$module] ?? null;
        if ($permission === null) {
            return true;
        }

        if ($storeId > 0 && $user->hasPermission($permission, $storeId)) {
            return true;
        }

        return ! $user->hasRole('SUPER_ADMIN') && $user->hasPermission($permission);
    }

    private function authorizeModule(User $user, string $module, int $storeId): void
    {
        $permission = self::MODULE_PERMISSIONS[$module] ?? null;
        if ($permission === null || $user->hasPermission($permission)) {
            return;
        }

        abort_unless($storeId > 0 && $user->hasPermission($permission, $storeId), 403);
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
        abort_if($storeIds === [], 403, 'No assigned Retail store.');

        if ($requestedStoreId > 0) {
            if (! in_array($requestedStoreId, $storeIds, true)) {
                abort(404);
            }
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

    private function msg(string $ar, string $en): string
    {
        return app()->getLocale() === 'ar' ? $ar : $en;
    }
}
