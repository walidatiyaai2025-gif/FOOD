<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\V1\B2bPricingController;
use App\Http\Controllers\Api\V1\DriverAssignmentController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ManagementReportService;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

class B2bWorkspaceController extends Controller
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly ManagementReportService $reports,
    ) {}

    public function show(Request $request, string $module = 'dashboard'): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->roles()->whereIn('roles.code', ['SUPER_ADMIN', 'B2B_ADMIN'])->exists(), 403);
        $allowed = ['dashboard', 'stores', 'clients', 'products', 'orders', 'drivers', 'pricing', 'reports', 'settings'];
        abort_unless(in_array($module, $allowed, true), 404);
        App::setLocale(in_array($user->locale, ['ar', 'en'], true) ? $user->locale : 'ar');

        $storeIds = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('store_types.code', 'B2B')
            ->pluck('stores.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $counts = [
            'stores' => count($storeIds),
            'clients' => DB::table('b2b_accounts')->count(),
            'products' => DB::table('store_products')->whereIn('store_id', $storeIds)->count(),
            'orders' => DB::table('orders')->whereIn('store_id', $storeIds)->where('channel', 'b2b')->count(),
            'drivers' => DB::table('users')->join('role_user', 'role_user.user_id', '=', 'users.id')->join('roles', 'roles.id', '=', 'role_user.role_id')->where('roles.code', 'B2B_DRIVER')->distinct('users.id')->count('users.id'),
            'pricing' => DB::table('b2b_price_rules')->count(),
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
    ): RedirectResponse {
        $isB2b = DB::table('orders')
            ->join('stores', 'stores.id', '=', 'orders.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('orders.id', $order)
            ->where('orders.channel', 'b2b')
            ->where('store_types.code', 'B2B')
            ->exists();
        abort_unless($isB2b, 404);
        $orders->transition($request, $order, $audit);

        return back()->with('status', app()->getLocale() === 'ar' ? 'تم تحديث حالة الطلب.' : 'Order status updated.');
    }

    public function assignDriver(
        Request $request,
        DriverAssignmentController $deliveries,
        AuditLogger $audit
    ): RedirectResponse {
        $driverId = $request->integer('driver_id');
        $orderId = $request->integer('order_id');
        abort_unless(DB::table('drivers')->where('id', $driverId)->where('driver_type', 'b2b')->exists(), 422);
        abort_unless(DB::table('orders')->where('id', $orderId)->where('channel', 'b2b')->exists(), 422);
        $deliveries->assign($request, $audit);

        return back()->with('status', app()->getLocale() === 'ar' ? 'تم تعيين السائق.' : 'Driver assigned.');
    }

    public function savePriceRule(Request $request, B2bPricingController $pricing): RedirectResponse
    {
        $storeId = $request->integer('store_id');
        $isB2b = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('store_types.code', 'B2B')
            ->exists();
        abort_unless($isB2b, 422);
        $pricing->upsert($request);

        return back()->with('status', app()->getLocale() === 'ar' ? 'تم حفظ قاعدة السعر.' : 'Price rule saved.');
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
                    'label' => app()->getLocale() === 'ar' ? 'فتح مركز التقارير' : 'Open reports',
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
        if ($user->hasPermission('security.view')) {
            $actions[] = ['label' => app()->getLocale() === 'ar' ? 'الصلاحيات والمستخدمون' : 'Security & users', 'url' => route('admin.security.index')];
        }
        if ($user->hasPermission('translations.manage')) {
            $actions[] = ['label' => app()->getLocale() === 'ar' ? 'إدارة الترجمات' : 'Translations', 'url' => route('admin.translations.index')];
        }
        if ($user->hasPermission('mobile_settings.manage') || $user->hasPermission('push_settings.manage') || $user->hasPermission('push_settings.test')) {
            $actions[] = ['label' => app()->getLocale() === 'ar' ? 'إعدادات الموبايل والإشعارات' : 'Mobile & push settings', 'url' => route('admin.mobile-settings.index')];
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

    private function moduleData(string $module, array $storeIds, User $user): array
    {
        return match ($module) {
            'dashboard' => [
                'columns' => ['number', 'client', 'store', 'status', 'amount', 'created'],
                'rows' => DB::table('orders')
                    ->join('customers', 'customers.id', '=', 'orders.customer_id')
                    ->join('stores', 'stores.id', '=', 'orders.store_id')
                    ->whereIn('orders.store_id', $storeIds)
                    ->where('orders.channel', 'b2b')
                    ->orderByDesc('orders.created_at')
                    ->limit(20)
                    ->get([
                        'orders.order_number as number',
                        'customers.name as client',
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
                        'products' => DB::table('store_products')->where('store_id', $store->id)->count(),
                        'orders' => DB::table('orders')->where('store_id', $store->id)->where('channel', 'b2b')->count(),
                        'status' => (bool) $store->is_active,
                    ])->all(),
            ],
            'clients' => [
                'columns' => ['company', 'name', 'email', 'phone', 'tax_number', 'status'],
                'rows' => DB::table('b2b_accounts')
                    ->join('customers', 'customers.id', '=', 'b2b_accounts.customer_id')
                    ->orderBy('b2b_accounts.company_name')
                    ->limit(100)
                    ->get([
                        'b2b_accounts.company_name as company',
                        'customers.name',
                        'customers.email',
                        'customers.phone',
                        'b2b_accounts.tax_number',
                        'b2b_accounts.status',
                    ])
                    ->map(fn ($row) => [
                        'company' => $row->company,
                        'name' => $row->name,
                        'email' => $row->email ?: '-',
                        'phone' => $row->phone ?: '-',
                        'tax_number' => $row->tax_number ?: '-',
                        'status' => $row->status,
                    ])->all(),
            ],
            'orders' => [
                'columns' => ['number', 'client', 'store', 'status', 'amount', 'created', 'actions'],
                'rows' => DB::table('orders')
                    ->join('customers', 'customers.id', '=', 'orders.customer_id')
                    ->join('stores', 'stores.id', '=', 'orders.store_id')
                    ->whereIn('orders.store_id', $storeIds)
                    ->where('orders.channel', 'b2b')
                    ->orderByDesc('orders.created_at')
                    ->limit(100)
                    ->get([
                        'orders.id',
                        'orders.order_number as number',
                        'customers.name as client',
                        'stores.name as store',
                        'orders.status',
                        'orders.currency',
                        'orders.grand_total',
                        'orders.created_at as created',
                    ])
                    ->map(fn ($row) => [
                        '_id' => (int) $row->id,
                        'number' => $row->number,
                        'client' => $row->client,
                        'store' => $row->store,
                        'status' => $row->status,
                        'amount' => $row->currency.' '.number_format((float) $row->grand_total, 3),
                        'created' => (string) $row->created,
                        'actions' => true,
                    ])->all(),
            ],
            'drivers' => [
                'columns' => ['name', 'email', 'availability', 'active', 'assignments'],
                'rows' => DB::table('drivers')
                    ->join('users', 'users.id', '=', 'drivers.user_id')
                    ->where('drivers.driver_type', 'b2b')
                    ->orderBy('users.name')
                    ->get(['drivers.id', 'users.name', 'users.email', 'drivers.is_available', 'drivers.is_active'])
                    ->map(fn ($row) => [
                        '_id' => (int) $row->id,
                        'name' => $row->name,
                        'email' => $row->email,
                        'availability' => (bool) $row->is_available,
                        'active' => (bool) $row->is_active,
                        'assignments' => DB::table('driver_assignments')
                            ->where('driver_id', $row->id)
                            ->where('assignment_type', 'b2b')
                            ->count(),
                    ])->all(),
                'drivers' => DB::table('drivers')
                    ->join('users', 'users.id', '=', 'drivers.user_id')
                    ->where('drivers.driver_type', 'b2b')
                    ->where('drivers.is_active', true)
                    ->orderBy('users.name')
                    ->get(['drivers.id', 'users.name'])
                    ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name])
                    ->all(),
                'orders' => DB::table('orders')
                    ->whereIn('store_id', $storeIds)
                    ->where('channel', 'b2b')
                    ->whereNotIn('status', ['delivered', 'cancelled'])
                    ->orderByDesc('id')
                    ->limit(100)
                    ->get(['id', 'order_number'])
                    ->map(fn ($row) => ['id' => (int) $row->id, 'number' => $row->order_number])
                    ->all(),
            ],
            'pricing' => [
                'columns' => ['tier', 'sku', 'product', 'store', 'unit_price', 'minimum_quantity', 'status'],
                'rows' => DB::table('b2b_price_rules')
                    ->join('b2b_price_tiers', 'b2b_price_tiers.id', '=', 'b2b_price_rules.price_tier_id')
                    ->join('products', 'products.id', '=', 'b2b_price_rules.product_id')
                    ->join('stores', 'stores.id', '=', 'b2b_price_rules.store_id')
                    ->whereIn('b2b_price_rules.store_id', $storeIds)
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
                'products' => DB::table('store_products')
                    ->join('products', 'products.id', '=', 'store_products.product_id')
                    ->whereIn('store_products.store_id', $storeIds)
                    ->select('products.id', 'products.name', 'products.sku')
                    ->distinct()
                    ->orderBy('products.name')
                    ->get()
                    ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name, 'sku' => $row->sku])
                    ->all(),
            ],
            'reports' => $this->reportModuleData($user, $storeIds),
            'settings' => $this->settingsModuleData($user, $storeIds),
            'products' => [
                'columns' => ['sku', 'name', 'store', 'price', 'available', 'status'],
                'rows' => DB::table('store_products')
                    ->join('products', 'products.id', '=', 'store_products.product_id')
                    ->join('stores', 'stores.id', '=', 'store_products.store_id')
                    ->whereIn('store_products.store_id', $storeIds)
                    ->orderBy('products.name')
                    ->limit(100)
                    ->get([
                        'store_products.store_id',
                        'products.id as product_id',
                        'products.sku',
                        'products.name',
                        'stores.name as store',
                        'store_products.price',
                        'store_products.is_active as status',
                    ])
                    ->map(function ($row) {
                        $stock = DB::table('inventories')
                            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                            ->where('warehouses.store_id', $row->store_id)
                            ->where('inventories.product_id', $row->product_id)
                            ->selectRaw('COALESCE(SUM(inventories.quantity - inventories.reserved_quantity), 0) as available')
                            ->value('available');

                        return [
                            'sku' => $row->sku,
                            'name' => $row->name,
                            'store' => $row->store,
                            'price' => $row->price === null ? '-' : number_format((float) $row->price, 3).' KWD',
                            'available' => number_format((float) $stock, 3),
                            'status' => (bool) $row->status,
                        ];
                    })->all(),
            ],
            default => ['columns' => [], 'rows' => []],
        };
    }
}
