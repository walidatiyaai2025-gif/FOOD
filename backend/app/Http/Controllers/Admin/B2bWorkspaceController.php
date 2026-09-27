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
use App\Services\CatalogOwnership;
use App\Services\DashboardOperationalNotifier;
use App\Services\LookupScopeService;
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

class B2bWorkspaceController extends Controller
{
    /** @var array<string, string|null> */
    private const MODULE_PERMISSIONS = [
        'dashboard' => null,
        'stores' => 'stores.view',
        'clients' => 'b2b.accounts.view',
        'products' => 'catalog.view',
        'inventory' => 'inventory.view',
        'orders' => 'orders.view',
        'drivers' => 'drivers.b2b.view',
        'pricing' => 'b2b.pricing.view',
        'finance' => 'finance.view',
        'reports' => 'reports.view',
        'settings' => 'settings.view',
    ];

    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly ManagementReportService $reports,
        private readonly TenantContextResolver $tenantContext,
        private readonly OperationalTenantScope $operationalScope,
        private readonly CatalogOwnership $catalogs,
        private readonly LookupScopeService $lookups,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request, string $module = 'dashboard'): View
    {
        $user = $this->actor($request);
        abort_unless(array_key_exists($module, self::MODULE_PERMISSIONS), 404);
        $this->authorizeModule($user, $module);
        App::setLocale(in_array($user->locale, ['ar', 'en'], true) ? $user->locale : 'ar');

        $storeIds = $this->wholesaleStoreIds($user);
        $counts = [
            'stores' => count($storeIds),
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
            'finance' => DB::table('invoices')->whereNotNull('b2b_customer_id')->count(),
        ];

        $navGroups = $this->navigation->groupsFor($user);
        $navContext = 'b2b_'.$module;
        $moduleData = $this->moduleData($module, $storeIds, $user);

        return view('admin.b2b-workspace', compact('user', 'module', 'storeIds', 'counts', 'navGroups', 'navContext', 'moduleData'));
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
        $this->operationalScope->assertStore($actor, (int) $row->store_id, 'orders.manage', 'b2b');

        $orders->transition($request, $order, $audit, $dashboardNotifier);

        return back()->with('status', $this->msg('تم تحديث حالة الطلب.', 'Order status updated.'));
    }

    public function storeOrder(Request $request, AdminOrderManagementService $orders): RedirectResponse
    {
        $actor = $this->actor($request);
        $storeId = $request->integer('store_id');
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
        abort_unless((int) $driver->store_id === (int) $order->store_id, 422, 'Driver and order must belong to the same wholesale store.');
        $this->operationalScope->assertStore($actor, (int) $order->store_id, 'drivers.b2b.manage', 'b2b');

        $deliveries->assign($request, $audit, $dashboardNotifier);

        return back()->with('status', $this->msg('تم تعيين السائق.', 'Driver assigned.'));
    }

    public function storeDriver(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $storeId = (int) $data['store_id'];
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

    public function savePriceRule(Request $request, B2bPricingController $pricing): RedirectResponse
    {
        $actor = $this->actor($request);
        $storeId = $request->integer('store_id');
        $this->operationalScope->assertStore($actor, $storeId, 'b2b.pricing.manage', 'b2b');

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
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $storeId = (int) $data['store_id'];
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
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'sku' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric', 'gte:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $storeId = (int) $data['store_id'];
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
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'code' => ['required', 'string', 'max:80', 'unique:warehouses,code'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $storeId = (int) $data['store_id'];
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
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'key' => ['required', 'string', 'max:255'],
            'value' => ['nullable', 'string', 'max:5000'],
        ]);
        $storeId = (int) $data['store_id'];
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
        $stores = DB::table('stores')->whereIn('id', $storeIds)->orderBy('name')->get(['id', 'name']);

        return [
            'columns' => ['store', 'orders', 'revenue', 'average', 'actions'],
            'rows' => $stores->map(function ($store) use ($user): array {
                $storeId = (int) $store->id;
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

    private function financeModuleData(array $storeIds): array
    {
        $rows = DB::table('invoices')
            ->join('b2b_customers', 'b2b_customers.id', '=', 'invoices.b2b_customer_id')
            ->leftJoin('b2b_accounts', 'b2b_accounts.b2b_customer_id', '=', 'b2b_customers.id')
            ->leftJoin('orders', 'orders.id', '=', 'invoices.order_id')
            ->whereNotNull('invoices.b2b_customer_id')
            ->where(function ($query) use ($storeIds): void {
                $query->whereNull('invoices.order_id')
                    ->orWhere(function ($order) use ($storeIds): void {
                        $order->where('orders.channel', 'b2b')->whereIn('orders.store_id', $storeIds);
                    });
            })
            ->orderByDesc('invoices.id')
            ->limit(150)
            ->get([
                'invoices.id',
                'invoices.invoice_number',
                'invoices.status',
                'invoices.currency',
                'invoices.total',
                'invoices.due_at',
                'b2b_customers.name as client',
                'b2b_accounts.company_name as company',
            ])
            ->map(function ($row): array {
                $paid = (float) DB::table('payments')
                    ->where('invoice_id', $row->id)
                    ->where('status', 'paid')
                    ->sum('amount');
                $total = (float) $row->total;

                return [
                    'invoice' => $row->invoice_number,
                    'company' => $row->company ?: '-',
                    'client' => $row->client,
                    'status' => $row->status,
                    'amount' => $row->currency.' '.number_format($total, 3),
                    'paid' => $row->currency.' '.number_format($paid, 3),
                    'balance' => $row->currency.' '.number_format(max(0, $total - $paid), 3),
                    'due' => $row->due_at === null ? '-' : (string) $row->due_at,
                ];
            })
            ->all();

        return [
            'columns' => ['invoice', 'company', 'client', 'status', 'amount', 'paid', 'balance', 'due'],
            'rows' => $rows,
        ];
    }

    private function inventoryModuleData(array $storeIds): array
    {
        $rows = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->join('products', 'products.id', '=', 'inventories.product_id')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('stores', 'stores.id', '=', 'warehouses.store_id')
            ->whereIn('warehouses.store_id', $storeIds)
            ->whereColumn('catalogs.store_id', 'warehouses.store_id')
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_migration_quarantine', false)
            ->orderBy('stores.name')
            ->orderBy('products.name')
            ->get([
                'inventories.id',
                'inventories.quantity',
                'inventories.reserved_quantity',
                'warehouses.name as warehouse',
                'stores.name as store',
                'products.sku',
                'products.name as product',
            ])
            ->map(fn ($row) => [
                '_id' => (int) $row->id,
                'store' => $row->store,
                'warehouse' => $row->warehouse,
                'sku' => $row->sku,
                'product' => $row->product,
                'quantity' => number_format((float) $row->quantity, 3),
                'reserved' => number_format((float) $row->reserved_quantity, 3),
                'available' => number_format(max(0, (float) $row->quantity - (float) $row->reserved_quantity), 3),
                'actions' => true,
            ])->all();

        return [
            'columns' => ['store', 'warehouse', 'sku', 'product', 'quantity', 'reserved', 'available', 'actions'],
            'rows' => $rows,
            'stores' => DB::table('stores')
                ->whereIn('id', $storeIds)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name])
                ->all(),
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
        $stores = DB::table('stores')
            ->whereIn('id', $storeIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name])
            ->all();

        $rows = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('stores', 'stores.id', '=', 'catalogs.store_id')
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
                'stores.name as store',
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
                    'store' => $row->store,
                    'price' => $row->price === null ? '-' : number_format((float) $row->price, 3).' KWD',
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
            'columns' => ['sku', 'name', 'store', 'price', 'available', 'status', 'actions'],
            'rows' => $rows,
            'stores' => $stores,
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

    private function moduleData(string $module, array $storeIds, User $user): array
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
            'stores' => [
                'columns' => ['code', 'name', 'products', 'orders', 'status'],
                'rows' => DB::table('stores')
                    ->whereIn('stores.id', $storeIds)
                    ->orderBy('stores.name')
                    ->get(['stores.id', 'stores.code', 'stores.name', 'stores.is_active'])
                    ->map(fn ($store) => [
                        'code' => $store->code,
                        'name' => $store->name,
                        'products' => DB::table('products')
                            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                            ->where('catalogs.store_id', $store->id)
                            ->where('catalogs.channel', 'b2b')
                            ->where('catalogs.is_migration_quarantine', false)
                            ->count(),
                        'orders' => DB::table('orders')
                            ->where('store_id', $store->id)
                            ->where('channel', 'b2b')
                            ->whereNotNull('b2b_customer_id')
                            ->count(),
                        'status' => (bool) $store->is_active,
                    ])->all(),
            ],
            'clients' => [
                'columns' => ['company', 'name', 'email', 'phone', 'tax_number', 'status', 'actions'],
                'rows' => DB::table('b2b_accounts')
                    ->join('b2b_customers', 'b2b_customers.id', '=', 'b2b_accounts.b2b_customer_id')
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
                    ])
                    ->map(fn ($row) => [
                        '_id' => (int) $row->id,
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
                'columns' => ['name', 'email', 'store', 'availability', 'active', 'assignments'],
                'rows' => DB::table('drivers')
                    ->join('users', 'users.id', '=', 'drivers.user_id')
                    ->join('stores', 'stores.id', '=', 'drivers.store_id')
                    ->where('drivers.driver_type', 'b2b')
                    ->whereIn('drivers.store_id', $storeIds)
                    ->orderBy('users.name')
                    ->get(['drivers.id', 'drivers.store_id', 'users.name', 'users.email', 'stores.name as store', 'drivers.is_available', 'drivers.is_active'])
                    ->map(fn ($row) => [
                        '_id' => (int) $row->id,
                        'name' => $row->name,
                        'email' => $row->email,
                        'store' => $row->store,
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
                    ->get(['drivers.id', 'drivers.store_id', 'users.name'])
                    ->map(fn ($row) => ['id' => (int) $row->id, 'store_id' => (int) $row->store_id, 'name' => $row->name])
                    ->all(),
                'orders' => DB::table('orders')
                    ->whereIn('store_id', $storeIds)
                    ->where('channel', 'b2b')
                    ->whereNotNull('b2b_customer_id')
                    ->whereNotIn('status', ['delivered', 'cancelled'])
                    ->orderByDesc('id')
                    ->limit(100)
                    ->get(['id', 'store_id', 'order_number'])
                    ->map(fn ($row) => ['id' => (int) $row->id, 'store_id' => (int) $row->store_id, 'number' => $row->order_number])
                    ->all(),
                'stores' => DB::table('stores')
                    ->whereIn('id', $storeIds)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name])
                    ->all(),
            ],
            'pricing' => [
                'columns' => ['tier', 'sku', 'product', 'store', 'unit_price', 'minimum_quantity', 'status'],
                'rows' => DB::table('b2b_price_rules')
                    ->join('b2b_price_tiers', 'b2b_price_tiers.id', '=', 'b2b_price_rules.price_tier_id')
                    ->join('products', 'products.id', '=', 'b2b_price_rules.product_id')
                    ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                    ->join('stores', 'stores.id', '=', 'b2b_price_rules.store_id')
                    ->whereIn('b2b_price_rules.store_id', $storeIds)
                    ->whereColumn('catalogs.store_id', 'b2b_price_rules.store_id')
                    ->where('catalogs.channel', 'b2b')
                    ->where('catalogs.is_migration_quarantine', false)
                    ->orderBy('stores.name')
                    ->orderBy('products.name')
                    ->limit(100)
                    ->get([
                        'b2b_price_tiers.name as tier',
                        'products.sku',
                        'products.name as product',
                        'stores.name as store',
                        'b2b_price_rules.unit_price',
                        'b2b_price_rules.minimum_quantity',
                        'b2b_price_rules.is_active as status',
                    ])
                    ->map(fn ($row) => [
                        'tier' => $row->tier,
                        'sku' => $row->sku,
                        'product' => $row->product,
                        'store' => $row->store,
                        'unit_price' => number_format((float) $row->unit_price, 3).' KWD',
                        'minimum_quantity' => number_format((float) $row->minimum_quantity, 3),
                        'status' => (bool) $row->status,
                    ])->all(),
                'tiers' => DB::table('b2b_price_tiers')
                    ->orderBy('priority')
                    ->get(['id', 'name'])
                    ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name])
                    ->all(),
                'stores' => DB::table('stores')
                    ->whereIn('id', $storeIds)
                    ->orderBy('name')
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
            'finance' => $this->financeModuleData($storeIds),
            'reports' => $this->reportModuleData($user, $storeIds),
            'settings' => $this->settingsModuleData($user, $storeIds),
            default => ['columns' => [], 'rows' => []],
        };
    }

    private function orderModuleData(array $storeIds): array
    {
        $customers = DB::table('b2b_customers')
            ->join('b2b_accounts', 'b2b_accounts.b2b_customer_id', '=', 'b2b_customers.id')
            ->where('b2b_accounts.status', 'active')
            ->orderBy('b2b_accounts.company_name')
            ->orderBy('b2b_customers.name')
            ->get([
                'b2b_customers.id',
                'b2b_customers.name',
                'b2b_accounts.company_name',
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => trim(($row->company_name ? $row->company_name.' · ' : '').$row->name),
            ])
            ->all();

        $rows = DB::table('orders')
            ->join('b2b_customers', 'b2b_customers.id', '=', 'orders.b2b_customer_id')
            ->join('stores', 'stores.id', '=', 'orders.store_id')
            ->whereIn('orders.store_id', $storeIds)
            ->where('orders.channel', 'b2b')
            ->whereNotNull('orders.b2b_customer_id')
            ->orderByDesc('orders.created_at')
            ->limit(100)
            ->get([
                'orders.id',
                'orders.store_id',
                'orders.b2b_customer_id',
                'orders.address_id',
                'orders.order_number as number',
                'b2b_customers.name as client',
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
                    '_customer_id' => (int) $row->b2b_customer_id,
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
                    'client' => $row->client,
                    'store' => $row->store,
                    'status' => $row->status,
                    'amount' => $row->currency.' '.number_format((float) $row->grand_total, 3),
                    'created' => (string) $row->created,
                    'actions' => true,
                ];
            })->all();

        return [
            'columns' => ['number', 'client', 'store', 'status', 'amount', 'created', 'actions'],
            'rows' => $rows,
            'customers' => $customers,
            'stores' => DB::table('stores')
                ->whereIn('id', $storeIds)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name])
                ->all(),
            'products' => DB::table('products')
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
                ->get(['products.id', 'catalogs.store_id', 'products.sku', 'products.name'])
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'store_id' => (int) $row->store_id,
                    'sku' => $row->sku,
                    'name' => $row->name,
                ])->all(),
            'addresses' => DB::table('addresses')
                ->whereIn('b2b_customer_id', collect($customers)->pluck('id')->all())
                ->orderBy('id')
                ->get(['id', 'b2b_customer_id'])
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'customer_id' => (int) $row->b2b_customer_id,
                    'label' => 'Address #'.$row->id,
                ])->all(),
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
                ])->all(),
            'payment_methods' => array_values((array) config('checkout.payment_methods', ['cash_on_delivery'])),
        ];
    }

    /** @return list<int> */
    private function wholesaleStoreIds(User $user): array
    {
        return $this->operationalScope->allowedStoreIds($user, 'stores.view', 'b2b');
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->tenantContext->wholesale($user);

        return $user;
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
