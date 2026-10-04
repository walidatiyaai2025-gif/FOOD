<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\V1\B2bAccountController;
use App\Http\Controllers\Api\V1\B2bPricingController;
use App\Http\Controllers\Api\V1\DriverAssignmentController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\Category;
use App\Models\Driver;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminOrderManagementService;
use App\Services\AuditLogger;
use App\Services\B2bCustomerService;
use App\Services\B2bDashboardService;
use App\Services\B2bFinanceInvoiceService;
use App\Services\CatalogOwnership;
use App\Services\DashboardOperationalNotifier;
use App\Services\LookupScopeService;
use App\Services\ManagementReportService;
use App\Services\OperationalTenantScope;
use App\Services\ReportExportService;
use App\Services\StorefrontDraftEditorService;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use App\Support\TenantContextResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class B2bWorkspaceController extends Controller
{
    /** @var array<string, string|null> */
    private const MODULE_PERMISSIONS = [
        'dashboard' => null,
        'clients' => 'b2b.accounts.view',
        'products' => 'catalog.view',
        'inventory' => 'inventory.view',
        'orders' => 'orders.view',
        'drivers' => 'drivers.b2b.view',
        'pricing' => 'b2b.pricing.view',
        'finance' => 'finance.view',
        'reports' => 'reports.view',
        'storefront' => 'settings.view',
        'settings' => 'settings.view',
    ];

    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly B2bDashboardService $dashboard,
        private readonly B2bFinanceInvoiceService $financeInvoices,
        private readonly ManagementReportService $reports,
        private readonly ReportExportService $reportExports,
        private readonly TenantContextResolver $tenantContext,
        private readonly OperationalTenantScope $operationalScope,
        private readonly CatalogOwnership $catalogs,
        private readonly LookupScopeService $lookups,
        private readonly AuditLogger $audit,
        private readonly WholesalePrincipal $principal,
    ) {}

    public function show(Request $request, string $module = 'dashboard'): View|Response
    {
        $user = $this->actor($request);
        abort_unless(array_key_exists($module, self::MODULE_PERMISSIONS), 404);
        $this->authorizeModule($user, $module);
        App::setLocale(in_array($user->locale, ['ar', 'en'], true) ? $user->locale : 'ar');

        $storeIds = $this->wholesaleStoreIds($user);
        if ($module === 'finance' && $request->filled('export')) {
            return $this->financeExportResponse($request, $user, $storeIds);
        }

        $counts = [
            'warehouses' => DB::table('warehouses')->whereIn('store_id', $storeIds)->where('is_active', true)->count(),
            'clients' => DB::table('b2b_accounts')->whereNotNull('b2b_customer_id')->count(),
            'products' => DB::table('products')
                ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                ->whereIn('catalogs.store_id', $storeIds)
                ->where('catalogs.channel', 'b2b')
                ->where('catalogs.is_migration_quarantine', false)
                ->count(),
            'inventory' => DB::table('inventories')
                ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                ->whereIn('warehouses.store_id', $storeIds)
                ->count(),
            'orders' => DB::table('orders')
                ->whereIn('store_id', $storeIds)
                ->where('channel', 'b2b')
                ->whereNotNull('b2b_customer_id')
                ->count(),
            'drivers' => DB::table('drivers')
                ->where('driver_type', 'b2b')
                ->whereIn('store_id', $storeIds)
                ->count(),
            'pricing' => DB::table('b2b_price_rules')->whereIn('store_id', $storeIds)->count(),
            'finance' => DB::table('invoices')
                ->whereIn('store_id', $storeIds)
                ->where('channel', 'b2b')
                ->whereNotNull('b2b_customer_id')
                ->count(),
        ];

        $navGroups = $this->navigation->groupsFor($user);
        $navContext = 'b2b_'.$module;
        $principalStoreId = $this->principal->storeId();
        $canViewDriverTracking = $module === 'dashboard'
            && in_array(
                $principalStoreId,
                $this->operationalScope->allowedStoreIds($user, 'drivers.tracking.view', 'b2b'),
                true,
            );
        $driverTrackingFeedUrl = $canViewDriverTracking
            ? route('admin.driver-live-tracking.feed', ['channel' => 'b2b', 'store_id' => $principalStoreId])
            : null;
        $driverTrackingPageUrl = $canViewDriverTracking
            ? route('admin.driver-live-tracking.index')
            : null;
        [$dashboardFrom, $dashboardTo] = $module === 'dashboard'
            ? $this->dashboardRange($request)
            : [null, null];
        $dashboard = $module === 'dashboard'
            ? $this->dashboard->build($user, $storeIds, $dashboardFrom, $dashboardTo)
            : null;
        $moduleData = $module === 'dashboard' ? null : $this->moduleData($module, $storeIds, $user, $request);
        $visibleModules = array_values(array_filter(
            array_keys(self::MODULE_PERMISSIONS),
            fn (string $candidate): bool => $this->canOpenModule($user, $candidate),
        ));

        return view('admin.b2b-workspace', compact(
            'user',
            'module',
            'storeIds',
            'counts',
            'navGroups',
            'navContext',
            'dashboard',
            'moduleData',
            'visibleModules',
            'canViewDriverTracking',
            'driverTrackingFeedUrl',
            'driverTrackingPageUrl',
        ));
    }

    public function transitionOrder(
        Request $request,
        int $order,
        OrderController $orders,
        AuditLogger $audit,
        DashboardOperationalNotifier $dashboardNotifier,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $row = DB::table('orders')
            ->where('id', $order)
            ->where('channel', 'b2b')
            ->whereNotNull('b2b_customer_id')
            ->first(['id', 'store_id']);
        abort_unless($row !== null, 404);
        abort_unless((int) $row->store_id === $this->principal->storeId(), 404);
        $this->operationalScope->assertStore($actor, (int) $row->store_id, 'orders.manage', 'b2b');

        $orders->transition($request, $order, $audit, $dashboardNotifier);

        return back()->with('status', $this->msg('تم تحديث حالة الطلب.', 'Order status updated.'));
    }

    public function quoteOrder(Request $request, AdminOrderManagementService $orders): JsonResponse
    {
        $actor = $this->actor($request);
        $storeId = $this->principal->storeId();
        $this->operationalScope->assertStore($actor, $storeId, 'orders.manage', 'b2b');

        return response()->json([
            'data' => $orders->quote($request, 'b2b', $storeId),
        ]);
    }

    public function storeOrder(Request $request, AdminOrderManagementService $orders): RedirectResponse
    {
        $actor = $this->actor($request);
        $warehouseId = $request->integer('warehouse_id');
        $warehouse = DB::table('warehouses')
            ->where('id', $warehouseId)
            ->where('is_active', true)
            ->first(['id', 'store_id']);
        abort_unless($warehouse !== null, 422, 'A valid Wholesale warehouse is required.');

        $storeId = $this->principal->storeId();
        abort_unless((int) $warehouse->store_id === $storeId, 422, 'Warehouse must belong to the main Wholesale operation.');
        $this->operationalScope->assertStore($actor, $storeId, 'orders.manage', 'b2b');

        $order = $orders->create($request, $actor, 'b2b', $storeId);

        return back()->with('status', $this->msg(
            'تم إنشاء الطلب '.$order->order_number.'.',
            'Order '.$order->order_number.' created.',
        ));
    }

    public function updateOrder(Request $request, int $order, AdminOrderManagementService $orders): RedirectResponse
    {
        $actor = $this->actor($request);
        $model = Order::query()
            ->whereKey($order)
            ->where('channel', 'b2b')
            ->whereNotNull('b2b_customer_id')
            ->firstOrFail();

        abort_unless((int) $model->store_id === $this->principal->storeId(), 404);
        $this->operationalScope->assertStore($actor, (int) $model->store_id, 'orders.manage', 'b2b');
        $orders->update($request, $actor, $model);

        return back()->with('status', $this->msg('تم تحديث الطلب.', 'Order updated.'));
    }

    public function assignDriver(
        Request $request,
        DriverAssignmentController $deliveries,
        AuditLogger $audit,
        DashboardOperationalNotifier $dashboardNotifier,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $driverId = $request->integer('driver_id');
        $orderId = $request->integer('order_id');

        $driver = DB::table('drivers')
            ->where('id', $driverId)
            ->where('driver_type', 'b2b')
            ->where('is_active', true)
            ->first(['id', 'store_id']);
        $order = DB::table('orders')
            ->where('id', $orderId)
            ->where('channel', 'b2b')
            ->whereNotNull('b2b_customer_id')
            ->first(['id', 'store_id']);

        abort_unless($driver !== null && $driver->store_id !== null && $order !== null, 422);
        $principalStoreId = $this->principal->storeId();
        abort_unless((int) $driver->store_id === $principalStoreId && (int) $order->store_id === $principalStoreId, 422, 'Driver and order must belong to the main Wholesale operation.');
        $this->operationalScope->assertStore($actor, $principalStoreId, 'drivers.b2b.manage', 'b2b');

        $deliveries->assign($request, $audit, $dashboardNotifier);

        return back()->with('status', $this->msg('تم تعيين السائق.', 'Driver assigned.'));
    }

    public function reassignDriver(
        Request $request,
        int $order,
        DriverAssignmentController $deliveries,
        AuditLogger $audit,
        DashboardOperationalNotifier $dashboardNotifier,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $principalStoreId = $this->principal->storeId();
        $orderModel = Order::query()
            ->whereKey($order)
            ->where('store_id', $principalStoreId)
            ->where('channel', 'b2b')
            ->whereNotNull('b2b_customer_id')
            ->firstOrFail();
        $this->operationalScope->assertStore($actor, $principalStoreId, 'drivers.b2b.manage', 'b2b');

        $request->merge([
            'order_id' => (int) $orderModel->getKey(),
            'replace_existing' => true,
        ]);
        $deliveries->assign($request, $audit, $dashboardNotifier);

        return back()->with('status', $this->msg(
            'تم إعادة تعيين الطلب إلى السائق الجديد.',
            'Order reassigned to the new driver.',
        ));
    }

    public function unassignDriver(
        Request $request,
        int $order,
        DriverAssignmentController $deliveries,
        AuditLogger $audit,
        DashboardOperationalNotifier $dashboardNotifier,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $principalStoreId = $this->principal->storeId();
        $orderModel = Order::query()
            ->whereKey($order)
            ->where('store_id', $principalStoreId)
            ->where('channel', 'b2b')
            ->whereNotNull('b2b_customer_id')
            ->firstOrFail();
        $this->operationalScope->assertStore($actor, $principalStoreId, 'drivers.b2b.manage', 'b2b');

        $deliveries->unassign($request, (int) $orderModel->getKey(), $audit, $dashboardNotifier);

        return back()->with('status', $this->msg(
            'تم سحب الطلب من السائق وإعادته لقائمة الطلبات غير المعينة.',
            'Order removed from the driver and returned to the unassigned queue.',
        ));
    }

    public function storeDriver(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $storeId = $this->principal->storeId();
        $this->operationalScope->assertStore($actor, $storeId, 'drivers.b2b.manage', 'b2b');

        $driver = DB::transaction(function () use ($data, $storeId): Driver {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'locale' => 'ar',
                'is_active' => true,
            ]);
            $role = Role::query()->where('code', 'B2B_DRIVER')->where('is_active', true)->firstOrFail();
            $user->roles()->attach($role);

            return Driver::query()->create([
                'user_id' => $user->id,
                'store_id' => $storeId,
                'driver_type' => 'b2b',
                'is_available' => true,
                'is_active' => true,
            ]);
        });

        $this->audit->record('b2b.driver.created', $actor, $driver, null, $driver->toArray(), $request);

        return back()->with('status', $this->msg('تم إنشاء سائق الجملة.', 'Wholesale driver created.'));
    }

    public function resetDriverPassword(Request $request, int $driver): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $storeId = $this->principal->storeId();
        $this->operationalScope->assertStore($actor, $storeId, 'drivers.b2b.manage', 'b2b');

        $driverModel = Driver::query()
            ->whereKey($driver)
            ->where('driver_type', 'b2b')
            ->where('store_id', $storeId)
            ->firstOrFail();
        $driverUser = User::query()->findOrFail($driverModel->user_id);

        $before = ['driver_id' => $driverModel->id, 'user_id' => $driverUser->id];
        $driverUser->forceFill(['password' => Hash::make($data['password'])])->save();
        $driverUser->tokens()->delete();

        $this->audit->record(
            'b2b.driver.password_reset',
            $actor,
            $driverUser,
            $before,
            ['driver_id' => $driverModel->id, 'user_id' => $driverUser->id, 'tokens_revoked' => true],
            $request,
        );

        return back()->with('status', $this->msg(
            'تم تعيين كلمة مرور جديدة للسائق وإلغاء جلساته الحالية.',
            'Driver password reset and existing sessions were revoked.',
        ));
    }

    public function savePriceRule(Request $request, B2bPricingController $pricing): RedirectResponse
    {
        $actor = $this->actor($request);
        $storeId = $this->principal->storeId();
        $this->operationalScope->assertStore($actor, $storeId, 'b2b.pricing.manage', 'b2b');
        $request->merge(['store_id' => $storeId]);

        $pricing->upsert($request);

        return back()->with('status', $this->msg('تم حفظ قاعدة السعر.', 'Price rule saved.'));
    }

    public function storeClient(
        Request $request,
        B2bAccountController $accounts,
        B2bCustomerService $customers,
    ): RedirectResponse {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('b2b.accounts.manage'), 403);

        $accounts->store($request, $customers);

        return back()->with('status', $this->msg('تم إنشاء حساب عميل الجملة.', 'Wholesale customer account created.'));
    }

    public function updateClientStatus(
        Request $request,
        B2bAccount $account,
        B2bAccountController $accounts,
    ): RedirectResponse {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('b2b.accounts.manage'), 403);
        abort_unless($account->b2b_customer_id !== null, 404);

        $accounts->updateStatus($request, $account);

        return back()->with('status', $this->msg('تم تحديث حالة حساب الجملة.', 'Wholesale account status updated.'));
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $storeId = $this->principal->storeId();
        $this->operationalScope->assertStore($actor, $storeId, 'catalog.manage', 'b2b');
        $catalog = $this->catalogs->defaultCatalogForStore($storeId, 'b2b');
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        $this->catalogs->assertParentInCatalog($parentId, (int) $catalog->id);

        if (DB::table('categories')->where('catalog_id', $catalog->id)->where('slug', $data['slug'])->exists()) {
            throw ValidationException::withMessages(['slug' => [$this->msg('الرابط مستخدم داخل كتالوج الجملة.', 'The slug is already used in this wholesale catalog.')]]);
        }

        $category = Category::query()->create([
            'catalog_id' => $catalog->id,
            'parent_id' => $parentId,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'is_active' => $request->boolean('is_active', true),
        ]);
        $this->audit->record('b2b.category.created', $actor, $category, null, [
            ...$category->toArray(),
            'store_id' => $storeId,
        ], $request);

        return back()->with('status', $this->msg('تم إنشاء تصنيف الجملة.', 'Wholesale category created.'));
    }

    public function storeProduct(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'sku' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric', 'gte:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $storeId = $this->principal->storeId();
        $this->operationalScope->assertStore($actor, $storeId, 'catalog.create', 'b2b');
        $catalog = $this->catalogs->defaultCatalogForStore($storeId, 'b2b');
        $categoryId = isset($data['category_id']) ? (int) $data['category_id'] : null;
        $brandId = isset($data['brand_id']) ? (int) $data['brand_id'] : null;
        $this->catalogs->assertSameCatalog($categoryId, (int) $catalog->id);
        $this->lookups->assertAssignableToStore('units', (int) $data['unit_id'], $storeId);
        if ($brandId !== null) {
            $this->lookups->assertAssignableToStore('brands', $brandId, $storeId);
        }

        if (DB::table('products')->where('catalog_id', $catalog->id)->where('sku', $data['sku'])->exists()) {
            throw ValidationException::withMessages(['sku' => [$this->msg('SKU مستخدم داخل كتالوج الجملة.', 'The SKU is already used in this wholesale catalog.')]]);
        }

        $product = DB::transaction(function () use ($data, $request, $catalog, $categoryId, $brandId, $storeId): Product {
            $product = Product::query()->create([
                'catalog_id' => $catalog->id,
                'category_id' => $categoryId,
                'brand_id' => $brandId,
                'unit_id' => $data['unit_id'],
                'sku' => $data['sku'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $request->boolean('is_active', true),
            ]);

            DB::table('store_products')->insert([
                'store_id' => $storeId,
                'product_id' => $product->id,
                'price' => $data['price'] ?? null,
                'is_active' => $request->boolean('is_active', true),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $product;
        });

        $this->audit->record('b2b.product.created', $actor, $product, null, [
            ...$product->toArray(),
            'store_id' => $storeId,
            'price' => $data['price'] ?? null,
        ], $request);

        return back()->with('status', $this->msg('تم إنشاء منتج الجملة.', 'Wholesale product created.'));
    }

    public function updateProduct(Request $request, Product $product): RedirectResponse
    {
        $actor = $this->actor($request);
        $owner = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->where('products.id', $product->id)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_migration_quarantine', false)
            ->first(['catalogs.id as catalog_id', 'catalogs.store_id']);
        abort_unless($owner !== null, 404);
        $storeId = (int) $owner->store_id;
        abort_unless($storeId === $this->principal->storeId(), 404);
        $this->operationalScope->assertStore($actor, $storeId, 'catalog.edit', 'b2b');

        $data = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'sku' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric', 'gte:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $categoryId = isset($data['category_id']) ? (int) $data['category_id'] : null;
        $brandId = isset($data['brand_id']) ? (int) $data['brand_id'] : null;
        $this->catalogs->assertSameCatalog($categoryId, (int) $owner->catalog_id);
        $this->lookups->assertAssignableToStore('units', (int) $data['unit_id'], $storeId);
        if ($brandId !== null) {
            $this->lookups->assertAssignableToStore('brands', $brandId, $storeId);
        }

        $duplicate = DB::table('products')
            ->where('catalog_id', $owner->catalog_id)
            ->where('sku', $data['sku'])
            ->where('id', '!=', $product->id)
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['sku' => [$this->msg('SKU مستخدم داخل كتالوج الجملة.', 'The SKU is already used in this wholesale catalog.')]]);
        }

        $before = $product->toArray();
        DB::transaction(function () use ($product, $data, $request, $categoryId, $brandId, $storeId): void {
            $product->update([
                'category_id' => $categoryId,
                'brand_id' => $brandId,
                'unit_id' => $data['unit_id'],
                'sku' => $data['sku'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $request->boolean('is_active'),
            ]);
            DB::table('store_products')->updateOrInsert(
                ['store_id' => $storeId, 'product_id' => $product->id],
                [
                    'price' => $data['price'] ?? null,
                    'is_active' => $request->boolean('is_active'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        });
        $product->refresh();
        $this->audit->record('b2b.product.updated', $actor, $product, [...$before, 'store_id' => $storeId], [
            ...$product->toArray(),
            'store_id' => $storeId,
            'price' => $data['price'] ?? null,
        ], $request);

        return back()->with('status', $this->msg('تم تحديث منتج الجملة.', 'Wholesale product updated.'));
    }

    public function storeWarehouse(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80', 'unique:warehouses,code'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $storeId = $this->principal->storeId();
        $this->operationalScope->assertStore($actor, $storeId, 'inventory.manage', 'b2b');

        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => $data['code'],
            'name' => $data['name'],
            'is_active' => $request->boolean('is_active', true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit->record('b2b.warehouse.created', $actor, null, null, [
            'id' => $warehouseId,
            'store_id' => $storeId,
            'code' => $data['code'],
            'name' => $data['name'],
        ], $request);

        return back()->with('status', $this->msg('تم إنشاء مخزن الجملة.', 'Wholesale warehouse created.'));
    }

    public function ensureInventory(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'gte:0'],
        ]);

        $warehouse = DB::table('warehouses')->where('id', $data['warehouse_id'])->first(['id', 'store_id']);
        abort_unless($warehouse !== null && $warehouse->store_id !== null, 404);
        $storeId = (int) $warehouse->store_id;
        abort_unless($storeId === $this->principal->storeId(), 404);
        $this->operationalScope->assertStore($actor, $storeId, 'inventory.manage', 'b2b');
        $this->operationalScope->assertProductOwnedByStore((int) $data['product_id'], $storeId);

        DB::table('inventories')->updateOrInsert(
            ['warehouse_id' => $warehouse->id, 'product_id' => $data['product_id']],
            [
                'quantity' => $data['quantity'],
                'reserved_quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return back()->with('status', $this->msg('تم إنشاء/تحديث رصيد الجملة.', 'Wholesale inventory balance created/updated.'));
    }

    public function adjustInventory(Request $request, int $inventory): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'quantity_delta' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $row = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('inventories.id', $inventory)
            ->first(['inventories.id', 'warehouses.store_id']);
        abort_unless($row !== null && $row->store_id !== null, 404);
        $storeId = (int) $row->store_id;
        abort_unless($storeId === $this->principal->storeId(), 404);
        $this->operationalScope->assertStore($actor, $storeId, 'inventory.adjust', 'b2b');

        DB::transaction(function () use ($inventory, $data, $actor, $storeId): void {
            $locked = Inventory::query()->lockForUpdate()->findOrFail($inventory);
            $next = (float) $locked->quantity + (float) $data['quantity_delta'];
            if ($next < (float) $locked->reserved_quantity) {
                throw ValidationException::withMessages([
                    'quantity_delta' => [$this->msg('لا يمكن خفض المخزون عن الكمية المحجوزة.', 'Stock cannot be reduced below reserved quantity.')],
                ]);
            }

            $locked->update(['quantity' => $next]);
            DB::table('stock_movements')->insert([
                'inventory_id' => $locked->id,
                'store_id' => $storeId,
                'user_id' => $actor->id,
                'type' => 'adjustment',
                'quantity' => $data['quantity_delta'],
                'reference_type' => 'b2b_workspace_adjustment',
                'reference_id' => $locked->id,
                'reason' => $data['reason'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record('b2b.inventory.adjusted', $actor, $locked, null, [
                'store_id' => $storeId,
                'quantity_delta' => (float) $data['quantity_delta'],
                'quantity' => $next,
                'reason' => $data['reason'],
            ]);
        });

        return back()->with('status', $this->msg('تم تعديل مخزون الجملة.', 'Wholesale inventory adjusted.'));
    }

    public function saveSetting(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'key' => ['required', 'string', 'max:255'],
            'value' => ['nullable', 'string', 'max:5000'],
        ]);
        $storeId = $this->principal->storeId();
        $this->operationalScope->assertStore($actor, $storeId, 'settings.manage', 'b2b');

        DB::table('settings')->updateOrInsert(
            ['store_id' => $storeId, 'key' => $data['key']],
            [
                'value' => json_encode($data['value'] ?? null, JSON_THROW_ON_ERROR),
                'is_secret' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        $this->audit->record('b2b.setting.saved', $actor, null, null, [
            'store_id' => $storeId,
            'key' => $data['key'],
        ], $request);

        return back()->with('status', $this->msg('تم حفظ إعداد الجملة.', 'Wholesale setting saved.'));
    }

    private function reportModuleData(User $user, array $storeIds): array
    {
        $storeId = (int) ($storeIds[0] ?? $this->principal->storeId());
        $data = $this->reports->run($user, 'orders', ['store_id' => $storeId, 'channel' => 'b2b']);

        $actions = [[
            'label' => $this->msg('فتح مركز التقارير', 'Open reports'),
            'url' => route('admin.reports.index', ['report' => 'orders', 'store_id' => $storeId, 'channel' => 'b2b']),
        ]];

        if ($user->hasPermission('reports.export', $storeId)) {
            foreach (['xlsx', 'docx', 'pdf'] as $format) {
                $actions[] = [
                    'label' => strtoupper($format),
                    'url' => route('admin.reports.export', ['report' => 'orders', 'format' => $format, 'store_id' => $storeId, 'channel' => 'b2b']),
                ];
            }
        }

        return [
            'columns' => ['orders', 'revenue', 'average', 'actions'],
            'rows' => [[
                'orders' => (int) data_get($data, 'kpis.orders', 0),
                'revenue' => 'EGP '.number_format((float) data_get($data, 'kpis.recognized_revenue', 0), 3),
                'average' => 'EGP '.number_format((float) data_get($data, 'kpis.average_order_value', 0), 3),
                'actions' => $actions,
            ]],
        ];
    }

    /** @param list<int> $storeIds */
    private function storefrontModuleData(array $storeIds): array
    {
        $store = DB::table('stores')
            ->whereIn('id', $storeIds)
            ->orderBy('id')
            ->first(['id', 'code', 'name', 'logo_path', 'is_active']);

        if ($store === null) {
            return ['columns' => [], 'rows' => []];
        }

        $storeId = (int) $store->id;
        $editor = app(StorefrontDraftEditorService::class)->viewModel($storeId, 'b2b');

        $targets = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_migration_quarantine', false)
            ->where('products.is_active', true)
            ->orderBy('products.name')
            ->limit(200)
            ->get(['products.id', 'products.name'])
            ->map(fn ($row) => [
                'ref' => 'product:'.(int) $row->id,
                'label' => $this->msg('منتج · '.$row->name, 'Product · '.$row->name),
            ])
            ->values()
            ->all();

        $categoryTargets = DB::table('categories')
            ->join('catalogs', 'catalogs.id', '=', 'categories.catalog_id')
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_migration_quarantine', false)
            ->where('categories.is_active', true)
            ->orderBy('categories.name')
            ->limit(120)
            ->get(['categories.id', 'categories.name'])
            ->map(fn ($row) => [
                'ref' => 'category:'.(int) $row->id,
                'label' => $this->msg('تصنيف · '.$row->name, 'Category · '.$row->name),
            ])
            ->values()
            ->all();

        $retailStoreTargets = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->orderBy('stores.name')
            ->get(['stores.id', 'stores.name'])
            ->map(fn ($row) => [
                'ref' => 'retail_store:'.(int) $row->id,
                'label' => $this->msg('متجر تجزئة · '.$row->name, 'Retail Store · '.$row->name),
            ])
            ->values()
            ->all();

        return [
            'columns' => [],
            'rows' => [],
            'store' => $editor['store'],
            'settings' => $editor['settings'],
            'revision' => $editor['revision'],
            'section_types' => [
                'hero' => 'Hero',
                'banner_slider' => $this->msg('سلايدر بانرات', 'Banner Slider'),
                'categories' => $this->msg('التصنيفات', 'Categories'),
                'offers' => $this->msg('عروض الجملة', 'Wholesale Offers'),
                'featured_products' => $this->msg('منتجات مميزة', 'Featured Products'),
                'best_sellers' => $this->msg('الأكثر مبيعاً', 'Best Sellers'),
                'reorder' => $this->msg('إعادة الطلب', 'Reorder'),
                'brands' => $this->msg('العلامات التجارية', 'Brands'),
                'product_grid' => $this->msg('شبكة منتجات', 'Product Grid'),
                'product_carousel' => $this->msg('سلايدر منتجات', 'Product Carousel'),
            ],
            'sections' => $editor['sections'],
            'banners' => $editor['banners'],
            'targets' => [...$retailStoreTargets, ...$categoryTargets, ...$targets],
        ];
    }

    private function settingsModuleData(User $user, array $storeIds): array
    {
        $actions = [];
        if ($user->hasPermission('lookups.view')) {
            $actions[] = [
                'label' => $this->msg('العلامات والوحدات', 'Brands & units'),
                'url' => route('admin.lookups.index', ['scope' => 'b2b']),
            ];
        }
        if ($user->hasPermission('security.view')) {
            $actions[] = ['label' => $this->msg('الصلاحيات والمستخدمون', 'Security & users'), 'url' => route('admin.security.index')];
        }
        if ($user->hasPermission('translations.manage')) {
            $actions[] = ['label' => $this->msg('إدارة الترجمات', 'Translations'), 'url' => route('admin.translations.index')];
        }
        if ($user->hasPermission('mobile_settings.manage') || $user->hasPermission('push_settings.manage') || $user->hasPermission('push_settings.test')) {
            $actions[] = ['label' => $this->msg('إعدادات الموبايل والإشعارات', 'Mobile & push settings'), 'url' => route('admin.mobile-settings.index')];
        }

        return [
            'columns' => ['setting', 'value'],
            'rows' => DB::table('settings')
                ->whereIn('settings.store_id', $storeIds)
                ->where('settings.is_secret', false)
                ->orderBy('settings.key')
                ->limit(150)
                ->get(['settings.key as setting', 'settings.value'])
                ->map(fn ($row) => [
                    'setting' => $row->setting,
                    'value' => $this->displaySettingValue($row->value),
                ])->all(),
            'actions' => $actions,
        ];
    }

    private function financeModuleData(array $storeIds, Request $request): array
    {
        $filters = $request->validate($this->financeFilterRules());

        return $this->financeInvoices->viewModel($storeIds, $filters, app()->getLocale());
    }

    private function inventoryModuleData(array $storeIds): array
    {
        $rows = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->join('products', 'products.id', '=', 'inventories.product_id')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->whereIn('warehouses.store_id', $storeIds)
            ->whereColumn('catalogs.store_id', 'warehouses.store_id')
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_migration_quarantine', false)
            ->orderBy('warehouses.name')
            ->orderBy('products.name')
            ->get([
                'inventories.id',
                'inventories.quantity',
                'inventories.reserved_quantity',
                'warehouses.name as warehouse',
                'products.sku',
                'products.name as product',
            ])
            ->map(fn ($row) => [
                '_id' => (int) $row->id,
                'warehouse' => $row->warehouse,
                'sku' => $row->sku,
                'product' => $row->product,
                'quantity' => number_format((float) $row->quantity, 3),
                'reserved' => number_format((float) $row->reserved_quantity, 3),
                'available' => number_format(max(0, (float) $row->quantity - (float) $row->reserved_quantity), 3),
                'actions' => true,
            ])->all();

        return [
            'columns' => ['warehouse', 'sku', 'product', 'quantity', 'reserved', 'available', 'actions'],
            'rows' => $rows,
            'warehouses' => DB::table('warehouses')
                ->whereIn('store_id', $storeIds)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'store_id', 'name'])
                ->map(fn ($row) => ['id' => (int) $row->id, 'store_id' => (int) $row->store_id, 'name' => $row->name])
                ->all(),
            'products' => DB::table('products')
                ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                ->whereIn('catalogs.store_id', $storeIds)
                ->where('catalogs.channel', 'b2b')
                ->where('catalogs.is_migration_quarantine', false)
                ->where('products.is_active', true)
                ->orderBy('products.name')
                ->get(['products.id', 'catalogs.store_id', 'products.sku', 'products.name'])
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'store_id' => (int) $row->store_id,
                    'sku' => $row->sku,
                    'name' => $row->name,
                ])
                ->all(),
        ];
    }

    private function productModuleData(array $storeIds, User $user): array
    {
        $rows = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->leftJoin('store_products', function ($join): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->on('store_products.store_id', '=', 'catalogs.store_id');
            })
            ->whereIn('catalogs.store_id', $storeIds)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_migration_quarantine', false)
            ->orderBy('products.name')
            ->limit(150)
            ->get([
                'catalogs.store_id',
                'products.id as product_id',
                'products.category_id',
                'products.brand_id',
                'products.unit_id',
                'products.sku',
                'products.name',
                'products.description',
                'products.is_active',
                'store_products.price',
            ])
            ->map(function ($row): array {
                $stock = DB::table('inventories')
                    ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                    ->where('warehouses.store_id', $row->store_id)
                    ->where('inventories.product_id', $row->product_id)
                    ->selectRaw('COALESCE(SUM(inventories.quantity - inventories.reserved_quantity), 0) as available')
                    ->value('available');

                return [
                    '_id' => (int) $row->product_id,
                    '_store_id' => (int) $row->store_id,
                    '_category_id' => $row->category_id === null ? null : (int) $row->category_id,
                    '_brand_id' => $row->brand_id === null ? null : (int) $row->brand_id,
                    '_unit_id' => (int) $row->unit_id,
                    '_description' => $row->description,
                    'sku' => $row->sku,
                    'name' => $row->name,
                    'price' => $row->price === null ? '-' : number_format((float) $row->price, 3).' EGP',
                    'available' => number_format((float) $stock, 3),
                    'status' => (bool) $row->is_active,
                    'actions' => true,
                ];
            })->all();

        $categories = DB::table('categories')
            ->join('catalogs', 'catalogs.id', '=', 'categories.catalog_id')
            ->whereIn('catalogs.store_id', $storeIds)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_migration_quarantine', false)
            ->orderBy('categories.name')
            ->get(['categories.id', 'categories.parent_id', 'categories.name', 'catalogs.store_id'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
                'store_id' => (int) $row->store_id,
                'name' => $row->name,
            ])
            ->all();

        return [
            'columns' => ['sku', 'name', 'price', 'available', 'status', 'actions'],
            'rows' => $rows,
            'categories' => $categories,
            'brands' => DB::table('brands')
                ->where('is_active', true)
                ->whereIn('scope', [LookupScopeService::GLOBAL, LookupScopeService::B2B])
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name])
                ->all(),
            'units' => DB::table('units')
                ->where('is_active', true)
                ->whereIn('scope', [LookupScopeService::GLOBAL, LookupScopeService::B2B])
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name, 'code' => $row->code])
                ->all(),
            'actions' => $user->hasPermission('lookups.view') ? [[
                'label' => $this->msg('إدارة العلامات والوحدات', 'Manage brands & units'),
                'url' => route('admin.lookups.index', ['scope' => 'b2b']),
            ]] : [],
        ];
    }

    private function moduleData(string $module, array $storeIds, User $user, Request $request): array
    {
        return match ($module) {
            'dashboard' => [
                'columns' => ['number', 'client', 'store', 'status', 'amount', 'created'],
                'rows' => DB::table('orders')
                    ->join('b2b_customers', 'b2b_customers.id', '=', 'orders.b2b_customer_id')
                    ->join('stores', 'stores.id', '=', 'orders.store_id')
                    ->whereIn('orders.store_id', $storeIds)
                    ->where('orders.channel', 'b2b')
                    ->whereNotNull('orders.b2b_customer_id')
                    ->orderByDesc('orders.created_at')
                    ->limit(20)
                    ->get([
                        'orders.order_number as number',
                        'b2b_customers.name as client',
                        'stores.name as store',
                        'orders.status',
                        'orders.currency',
                        'orders.grand_total',
                        'orders.created_at as created',
                    ])
                    ->map(fn ($row) => [
                        'number' => $row->number,
                        'client' => $row->client,
                        'store' => $row->store,
                        'status' => $row->status,
                        'amount' => $row->currency.' '.number_format((float) $row->grand_total, 3),
                        'created' => (string) $row->created,
                    ])->all(),
            ],
            'clients' => [
                'columns' => ['company', 'name', 'email', 'phone', 'tax_number', 'status', 'actions'],
                'rows' => DB::table('b2b_accounts')
                    ->join('b2b_customers', 'b2b_customers.id', '=', 'b2b_accounts.b2b_customer_id')
                    ->leftJoin('retail_wholesale_accounts', 'retail_wholesale_accounts.b2b_customer_id', '=', 'b2b_customers.id')
                    ->leftJoin('stores as retail_linked_store', 'retail_linked_store.id', '=', 'retail_wholesale_accounts.retail_store_id')
                    ->whereNotNull('b2b_accounts.b2b_customer_id')
                    ->orderBy('b2b_accounts.company_name')
                    ->limit(150)
                    ->get([
                        'b2b_accounts.id',
                        'b2b_accounts.company_name as company',
                        'b2b_customers.name',
                        'b2b_customers.email',
                        'b2b_customers.phone',
                        'b2b_accounts.tax_number',
                        'b2b_accounts.status',
                        'retail_wholesale_accounts.retail_store_id',
                        'retail_linked_store.name as retail_store_name',
                    ])
                    ->map(fn ($row) => [
                        '_id' => (int) $row->id,
                        '_retail_linked' => $row->retail_store_id !== null,
                        '_retail_store_name' => $row->retail_store_name,
                        'company' => $row->company,
                        'name' => $row->name,
                        'email' => $row->email ?: '-',
                        'phone' => $row->phone ?: '-',
                        'tax_number' => $row->tax_number ?: '-',
                        'status' => $row->status,
                        'actions' => true,
                    ])->all(),
            ],
            'products' => $this->productModuleData($storeIds, $user),
            'inventory' => $this->inventoryModuleData($storeIds),
            'orders' => $this->orderModuleData($storeIds),
            'drivers' => [
                'columns' => ['name', 'email', 'availability', 'active', 'assignments'],
                'rows' => DB::table('drivers')
                    ->join('users', 'users.id', '=', 'drivers.user_id')
                    ->where('drivers.driver_type', 'b2b')
                    ->whereIn('drivers.store_id', $storeIds)
                    ->orderBy('users.name')
                    ->get(['drivers.id', 'drivers.store_id', 'users.name', 'users.username', 'users.email', 'drivers.is_available', 'drivers.is_active'])
                    ->map(fn ($row) => [
                        '_id' => (int) $row->id,
                        'name' => $row->name,
                        'username' => $row->username,
                        'email' => $row->email,
                        'availability' => (bool) $row->is_available,
                        'active' => (bool) $row->is_active,
                        'assignments' => DB::table('driver_assignments')
                            ->where('driver_id', $row->id)
                            ->where('store_id', $row->store_id)
                            ->where('assignment_type', 'b2b')
                            ->count(),
                    ])->all(),
                'drivers' => DB::table('drivers')
                    ->join('users', 'users.id', '=', 'drivers.user_id')
                    ->where('drivers.driver_type', 'b2b')
                    ->whereIn('drivers.store_id', $storeIds)
                    ->where('drivers.is_active', true)
                    ->orderBy('users.name')
                    ->get(['drivers.id', 'drivers.store_id', 'users.name', 'users.username', 'users.email'])
                    ->map(fn ($row) => ['id' => (int) $row->id, 'store_id' => (int) $row->store_id, 'name' => $row->name, 'username' => $row->username, 'email' => $row->email])
                    ->all(),
                'orders' => DB::table('orders')
                    ->whereIn('orders.store_id', $storeIds)
                    ->where('orders.channel', 'b2b')
                    ->whereNotNull('orders.b2b_customer_id')
                    ->whereNotIn('orders.status', ['delivered', 'cancelled'])
                    ->whereNotExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from('driver_assignments')
                            ->whereColumn('driver_assignments.order_id', 'orders.id')
                            ->whereNotIn('driver_assignments.status', ['delivered', 'failed', 'unassigned']);
                    })
                    ->orderByDesc('orders.id')
                    ->limit(100)
                    ->get(['orders.id', 'orders.store_id', 'orders.order_number'])
                    ->map(fn ($row) => ['id' => (int) $row->id, 'store_id' => (int) $row->store_id, 'number' => $row->order_number])
                    ->all(),
                'assignments_list' => DB::table('driver_assignments')
                    ->join('orders', 'orders.id', '=', 'driver_assignments.order_id')
                    ->join('drivers', 'drivers.id', '=', 'driver_assignments.driver_id')
                    ->join('users', 'users.id', '=', 'drivers.user_id')
                    ->whereIn('driver_assignments.store_id', $storeIds)
                    ->where('driver_assignments.assignment_type', 'b2b')
                    ->where('orders.channel', 'b2b')
                    ->orderByDesc('driver_assignments.id')
                    ->limit(150)
                    ->get([
                        'driver_assignments.id',
                        'driver_assignments.order_id',
                        'driver_assignments.driver_id',
                        'driver_assignments.store_id',
                        'driver_assignments.status',
                        'driver_assignments.assigned_at',
                        'driver_assignments.completed_at',
                        'orders.order_number',
                        'users.name as driver_name',
                    ])
                    ->map(function ($row): array {
                        $proof = DB::table('delivery_proofs')
                            ->where('driver_assignment_id', $row->id)
                            ->where(function ($query): void {
                                $query->whereNotNull('file_path')
                                    ->orWhereNotNull('reason_code')
                                    ->orWhereNotNull('note');
                            })
                            ->orderByDesc('id')
                            ->first(['id', 'proof_type', 'file_path', 'reason_code', 'note', 'captured_at']);

                        return [
                            'id' => (int) $row->id,
                            'order_id' => (int) $row->order_id,
                            'driver_id' => (int) $row->driver_id,
                            'store_id' => (int) $row->store_id,
                            'order' => $row->order_number,
                            'driver' => $row->driver_name,
                            'status' => $row->status,
                            'assigned_at' => (string) $row->assigned_at,
                            'completed_at' => $row->completed_at === null ? null : (string) $row->completed_at,
                            'proof' => $proof === null ? null : [
                                'id' => (int) $proof->id,
                                'type' => (string) $proof->proof_type,
                                'file_path' => $proof->file_path === null ? null : (string) $proof->file_path,
                                'reason_code' => $proof->reason_code === null ? null : (string) $proof->reason_code,
                                'note' => $proof->note === null ? null : (string) $proof->note,
                                'captured_at' => $proof->captured_at === null ? null : (string) $proof->captured_at,
                            ],
                        ];
                    })->all(),
            ],
            'pricing' => [
                'columns' => ['tier', 'sku', 'product', 'unit_price', 'minimum_quantity', 'status'],
                'rows' => DB::table('b2b_price_rules')
                    ->join('b2b_price_tiers', 'b2b_price_tiers.id', '=', 'b2b_price_rules.price_tier_id')
                    ->join('products', 'products.id', '=', 'b2b_price_rules.product_id')
                    ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                    ->whereIn('b2b_price_rules.store_id', $storeIds)
                    ->whereColumn('catalogs.store_id', 'b2b_price_rules.store_id')
                    ->where('catalogs.channel', 'b2b')
                    ->where('catalogs.is_migration_quarantine', false)
                    ->orderBy('products.name')
                    ->limit(100)
                    ->get([
                        'b2b_price_tiers.name as tier',
                        'products.sku',
                        'products.name as product',
                        'b2b_price_rules.unit_price',
                        'b2b_price_rules.minimum_quantity',
                        'b2b_price_rules.is_active as status',
                    ])
                    ->map(fn ($row) => [
                        'tier' => $row->tier,
                        'sku' => $row->sku,
                        'product' => $row->product,
                        'unit_price' => number_format((float) $row->unit_price, 3).' EGP',
                        'minimum_quantity' => number_format((float) $row->minimum_quantity, 3),
                        'status' => (bool) $row->status,
                    ])->all(),
                'tiers' => DB::table('b2b_price_tiers')
                    ->orderBy('priority')
                    ->get(['id', 'name'])
                    ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name])
                    ->all(),
                'products' => DB::table('products')
                    ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                    ->whereIn('catalogs.store_id', $storeIds)
                    ->where('catalogs.channel', 'b2b')
                    ->where('catalogs.is_migration_quarantine', false)
                    ->where('products.is_active', true)
                    ->orderBy('products.name')
                    ->get(['products.id', 'products.name', 'products.sku'])
                    ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name, 'sku' => $row->sku])
                    ->all(),
            ],
            'finance' => $this->financeModuleData($storeIds, $request),
            'reports' => $this->reportModuleData($user, $storeIds),
            'storefront' => $this->storefrontModuleData($storeIds),
            'settings' => $this->settingsModuleData($user, $storeIds),
            default => ['columns' => [], 'rows' => []],
        };
    }

    private function orderModuleData(array $storeIds): array
    {
        $customers = DB::table('b2b_customers')
            ->join('b2b_accounts', 'b2b_accounts.b2b_customer_id', '=', 'b2b_customers.id')
            ->leftJoin('retail_wholesale_accounts', 'retail_wholesale_accounts.b2b_customer_id', '=', 'b2b_customers.id')
            ->leftJoin('stores as retail_customer_store', 'retail_customer_store.id', '=', 'retail_wholesale_accounts.retail_store_id')
            ->where('b2b_accounts.status', 'active')
            ->where(function ($query): void {
                $query->whereNotNull('b2b_accounts.price_tier_id')
                    ->orWhereNotNull('retail_wholesale_accounts.retail_store_id');
            })
            ->orderByRaw('retail_customer_store.id IS NULL')
            ->orderBy('b2b_accounts.company_name')
            ->orderBy('b2b_customers.name')
            ->get([
                'b2b_customers.id',
                'b2b_customers.name',
                'b2b_accounts.company_name',
                'b2b_accounts.price_tier_id',
                'retail_wholesale_accounts.retail_store_id',
                'retail_customer_store.name as retail_store_name',
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->retail_store_id === null
                    ? trim(($row->company_name ? $row->company_name.' · ' : '').$row->name)
                    : (app()->getLocale() === 'ar' ? 'التجزئة · ' : 'Retail · ').$row->retail_store_name,
                'price_tier_id' => $row->price_tier_id === null ? null : (int) $row->price_tier_id,
                'platform_fallback' => $row->retail_store_id !== null,
            ])
            ->all();

        $warehouses = DB::table('warehouses')
            ->whereIn('store_id', $storeIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'code' => $row->code,
            ])
            ->all();

        $warehouseIdsByProduct = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->whereIn('warehouses.store_id', $storeIds)
            ->where('warehouses.is_active', true)
            ->get(['inventories.product_id', 'warehouses.id as warehouse_id'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->pluck('warehouse_id')->map(fn ($id) => (int) $id)->unique()->values()->all());

        $priceTierIdsByProduct = DB::table('b2b_price_rules')
            ->whereIn('store_id', $storeIds)
            ->where('is_active', true)
            ->get(['product_id', 'price_tier_id'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->pluck('price_tier_id')->map(fn ($id) => (int) $id)->unique()->values()->all());

        $products = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->on('store_products.store_id', '=', 'catalogs.store_id');
            })
            ->whereIn('catalogs.store_id', $storeIds)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_migration_quarantine', false)
            ->where('catalogs.is_active', true)
            ->where('products.is_active', true)
            ->where('store_products.is_active', true)
            ->orderBy('products.name')
            ->get(['products.id', 'catalogs.store_id', 'products.sku', 'products.name', 'store_products.price'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'store_id' => (int) $row->store_id,
                'sku' => $row->sku,
                'name' => $row->name,
                'warehouse_ids' => $warehouseIdsByProduct->get($row->id, []),
                'price_tier_ids' => $priceTierIdsByProduct->get($row->id, []),
                'has_fallback_price' => $row->price !== null,
            ])
            ->all();

        $rows = DB::table('orders')
            ->join('b2b_customers', 'b2b_customers.id', '=', 'orders.b2b_customer_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'orders.warehouse_id')
            ->whereIn('orders.store_id', $storeIds)
            ->where('orders.channel', 'b2b')
            ->whereNotNull('orders.b2b_customer_id')
            ->orderByDesc('orders.created_at')
            ->limit(100)
            ->get([
                'orders.id',
                'orders.store_id',
                'orders.warehouse_id',
                'orders.b2b_customer_id',
                'orders.address_id',
                'orders.order_number as number',
                'b2b_customers.name as client',
                'warehouses.name as warehouse',
                'orders.status',
                'orders.currency',
                'orders.subtotal',
                'orders.discount_total',
                'orders.delivery_total',
                'orders.tax_total',
                'orders.grand_total',
                'orders.payment_method',
                'orders.pricing_snapshot',
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

                $activeAssignment = DB::table('driver_assignments')
                    ->join('drivers', 'drivers.id', '=', 'driver_assignments.driver_id')
                    ->join('users', 'users.id', '=', 'drivers.user_id')
                    ->where('driver_assignments.order_id', $row->id)
                    ->where('driver_assignments.assignment_type', 'b2b')
                    ->whereNotIn('driver_assignments.status', ['delivered', 'failed', 'unassigned'])
                    ->orderByDesc('driver_assignments.id')
                    ->first([
                        'driver_assignments.id',
                        'driver_assignments.driver_id',
                        'driver_assignments.status',
                        'users.name as driver_name',
                    ]);

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

                $driverHistory = DB::table('delivery_proofs')
                    ->join('driver_assignments', 'driver_assignments.id', '=', 'delivery_proofs.driver_assignment_id')
                    ->join('drivers', 'drivers.id', '=', 'driver_assignments.driver_id')
                    ->join('users', 'users.id', '=', 'drivers.user_id')
                    ->where('driver_assignments.order_id', $row->id)
                    ->where('driver_assignments.store_id', $row->store_id)
                    ->where('driver_assignments.assignment_type', 'b2b')
                    ->whereIn('delivery_proofs.proof_type', ['status_note', 'failure_note'])
                    ->orderByDesc('delivery_proofs.id')
                    ->limit(50)
                    ->get([
                        'delivery_proofs.from_status',
                        'delivery_proofs.to_status',
                        'delivery_proofs.note',
                        'delivery_proofs.captured_at',
                        'users.name as actor_name',
                    ])
                    ->map(fn ($entry) => [
                        'from' => $entry->from_status,
                        'to' => $entry->to_status,
                        'note' => $entry->note,
                        'actor' => $entry->actor_name,
                        'created_at' => $entry->captured_at === null ? null : (string) $entry->captured_at,
                    ])->all();

                $invoice = DB::table('invoices')
                    ->where('order_id', $row->id)
                    ->orderByDesc('id')
                    ->first(['id', 'invoice_number', 'status', 'total', 'currency']);
                $pricingSnapshot = json_decode((string) ($row->pricing_snapshot ?? ''), true);

                return [
                    '_id' => (int) $row->id,
                    '_store_id' => (int) $row->store_id,
                    '_warehouse_id' => $row->warehouse_id === null ? null : (int) $row->warehouse_id,
                    '_customer_id' => (int) $row->b2b_customer_id,
                    '_address_id' => $row->address_id === null ? null : (int) $row->address_id,
                    '_subtotal' => (float) $row->subtotal,
                    '_discount_total' => (float) $row->discount_total,
                    '_delivery_total' => (float) $row->delivery_total,
                    '_tax_total' => (float) ($row->tax_total ?? 0),
                    '_grand_total' => (float) $row->grand_total,
                    '_payment_method' => $row->payment_method,
                    '_coupon_code' => is_array($pricingSnapshot) ? data_get($pricingSnapshot, 'coupon.code') : null,
                    '_customer_note' => $row->customer_note,
                    '_assignment_id' => $activeAssignment === null ? null : (int) $activeAssignment->id,
                    '_driver_id' => $activeAssignment === null ? null : (int) $activeAssignment->driver_id,
                    '_items' => $items,
                    '_payment' => $payment === null ? null : [
                        'provider' => $payment->provider,
                        'status' => $payment->status,
                        'amount' => (float) $payment->amount,
                        'currency' => $payment->currency,
                    ],
                    '_history' => $history,
                    '_driver_history' => $driverHistory,
                    '_invoice' => $invoice === null ? null : [
                        'id' => (int) $invoice->id,
                        'number' => $invoice->invoice_number,
                        'status' => $invoice->status,
                        'total' => (float) $invoice->total,
                        'currency' => $invoice->currency,
                    ],
                    'number' => $row->number,
                    'client' => $row->client,
                    'warehouse' => $row->warehouse ?: $this->msg('طلب قديم - مخزن غير محدد', 'Legacy order - warehouse not set'),
                    'driver' => $activeAssignment?->driver_name ?: $this->msg('غير معين', 'Unassigned'),
                    'assignment_status' => $activeAssignment?->status ?: $this->msg('غير معين', 'Unassigned'),
                    'status' => $row->status,
                    'amount' => $row->currency.' '.number_format((float) $row->grand_total, 3),
                    'created' => (string) $row->created,
                    'actions' => true,
                ];
            })
            ->all();

        return [
            'columns' => ['number', 'client', 'warehouse', 'driver', 'assignment_status', 'status', 'amount', 'created', 'actions'],
            'rows' => $rows,
            'customers' => $customers,
            'warehouses' => $warehouses,
            'products' => $products,
            'addresses' => DB::table('addresses')
                ->whereIn('b2b_customer_id', collect($customers)->pluck('id')->all())
                ->orderBy('id')
                ->get(['id', 'b2b_customer_id'])
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'customer_id' => (int) $row->b2b_customer_id,
                    'label' => 'Address #'.$row->id,
                ])
                ->all(),
            'drivers' => DB::table('drivers')
                ->join('users', 'users.id', '=', 'drivers.user_id')
                ->where('drivers.driver_type', 'b2b')
                ->whereIn('drivers.store_id', $storeIds)
                ->where('drivers.is_active', true)
                ->orderBy('users.name')
                ->get(['drivers.id', 'drivers.store_id', 'users.name'])
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'store_id' => (int) $row->store_id,
                    'name' => $row->name,
                ])
                ->all(),
            'payment_methods' => array_values((array) config('checkout.payment_methods', ['cash_on_delivery'])),
        ];
    }

    /** @param list<int> $storeIds */
    private function financeExportResponse(Request $request, User $user, array $storeIds): Response
    {
        $validated = $request->validate([
            ...$this->financeFilterRules(),
            'export' => ['required', 'in:xlsx,pdf'],
        ]);
        $format = (string) $validated['export'];
        unset($validated['export']);

        $report = $this->financeInvoices->exportReport($storeIds, $validated, app()->getLocale());
        $file = $this->reportExports->build($report, $format, app()->getLocale());
        $filename = $this->reportExports->filename($report, $file['extension']);

        $this->audit->record('b2b.finance.exported', $user, null, null, [
            'format' => $format,
            'store_ids' => $storeIds,
            'filters' => $report['filters'],
            'rows' => count((array) $report['rows']),
        ], $request);

        return response($file['content'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array<string, list<string>> */
    private function financeFilterRules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return list<int> */
    private function wholesaleStoreIds(User $user): array
    {
        return [$this->principal->storeId()];
    }

    /** @return array{0:?string,1:?string} */
    private function dashboardRange(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if (! isset($data['from']) && ! isset($data['to']) && isset($data['date'])) {
            return [$data['date'], $data['date']];
        }

        $from = $data['from'] ?? null;
        $to = $data['to'] ?? null;

        if ($from !== null && $to !== null) {
            $fromDay = CarbonImmutable::parse($from, 'Asia/Kuwait')->startOfDay();
            $toDay = CarbonImmutable::parse($to, 'Asia/Kuwait')->startOfDay();

            if ($toDay->lt($fromDay)) {
                throw ValidationException::withMessages([
                    'to' => [$this->msg(
                        'تاريخ «إلى» يجب أن يكون مساويًا لتاريخ «من» أو بعده.',
                        'The To date must be the same as or later than the From date.',
                    )],
                ]);
            }
        }

        return [$from, $to];
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->tenantContext->wholesale($user);

        return $user;
    }

    private function canOpenModule(User $user, string $module): bool
    {
        $permission = self::MODULE_PERMISSIONS[$module] ?? null;

        return $permission === null || $user->hasPermission($permission);
    }

    private function authorizeModule(User $user, string $module): void
    {
        $permission = self::MODULE_PERMISSIONS[$module] ?? null;
        if ($permission !== null) {
            abort_unless($user->hasPermission($permission), 403);
        }
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

    private function msg(string $ar, string $en): string
    {
        return app()->getLocale() === 'ar' ? $ar : $en;
    }
}
