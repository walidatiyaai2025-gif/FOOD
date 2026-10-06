<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\V1\B2bAccountController;
use App\Http\Controllers\Api\V1\B2bPricingController;
use App\Http\Controllers\Api\V1\DriverAssignmentController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Controller;
use App\Models\B2bAccount;
use App\Models\CollectionAccount;
use App\Models\B2bCustomer;
use App\Models\Category;
use App\Models\Driver;
use App\Models\Inventory;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Remittance;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminOrderManagementService;
use App\Services\AuditLogger;
use App\Services\B2bAccountLedgerService;
use App\Services\B2bCustomerService;
use App\Services\B2bDashboardService;
use App\Services\B2bFinanceInvoiceService;
use App\Services\CatalogOwnership;
use App\Services\CollectionCustodyService;
use App\Services\DashboardOperationalNotifier;
use App\Services\FieldOperationsFinanceService;
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
        private readonly FieldOperationsFinanceService $fieldFinance,
        private readonly B2bAccountLedgerService $accountLedger,
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
    ): RedirectResponse
    {
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
    ): RedirectResponse
    {
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
    ): RedirectResponse
    {
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
    ): RedirectResponse
    {
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
    ): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->hasPermission('b2b.accounts.manage'), 403);

        $accounts->store($request, $customers);

        return back()->with('status', $this->msg('تم إنشاء حساب عميل الجملة.', 'Wholesale customer account created.'));
    }

    public function updateClientStatus(
        Request $request,
        B2bAccount $account,
        B2bAccountController $accounts,
    ): RedirectResponse
    {
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

    public function reviewRemittance(
        Request $request,
        Remittance $remittance,
        string $action,
        CollectionCustodyService $custody,
    ): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless(in_array($action, ['approve', 'reject', 'reconcile'], true), 404);

        $account = CollectionAccount::query()
            ->whereKey($remittance->collection_account_id)
            ->firstOrFail();
        $storeId = $account->store_id === null ? null : (int) $account->store_id;

        abort_unless($storeId !== null && in_array($storeId, $this->wholesaleStoreIds($actor), true), 404);
        abort_unless($actor->hasPermission('finance.manage', $storeId), 403);

        $before = $remittance->toArray();
        $updated = match ($action) {
            'approve' => $custody->approveRemittance($remittance, $actor),
            'reject' => $custody->rejectRemittance($remittance, $actor),
            'reconcile' => $custody->reconcileRemittance($remittance, $actor),
        };

        $this->audit->record(
            'b2b.finance.remittance.'.$action,
            $actor,
            $updated,
            $before,
            $updated->toArray(),
            $request,
        );

        return redirect()
            ->route('admin.b2b.module', [
                'module' => 'finance',
                'ops_tab' => $action === 'reconcile' ? 'reconciliation' : 'remittances',
            ])
            ->with('status', $this->msg(
                match ($action) {
                    'approve' => 'تم اعتماد التوريد.',
                    'reject' => 'تم رفض التوريد.',
                    default => 'تمت مطابقة التوريد.',
                },
                match ($action) {
                    'approve' => 'Remittance approved.',
                    'reject' => 'Remittance rejected.',
                    default => 'Remittance reconciled.',
                },
            ));
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

    private function financeModuleData(array $storeIds, User $user, Request $request): array

    {
        $filters = $request->validate($this->financeFilterRules());
        $opsFilters = $request->validate($this->fieldOperationsFinanceFilterRules());

        $data = $this->financeInvoices->viewModel($storeIds, $filters, app()->getLocale());
        $data['field_operations'] = $this->fieldFinance->viewModel($storeIds, $opsFilters);
        $data['field_operations']['can_manage'] = $user->hasPermission('finance.manage', (int) ($storeIds[0] ?? 0));

        return $data;
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