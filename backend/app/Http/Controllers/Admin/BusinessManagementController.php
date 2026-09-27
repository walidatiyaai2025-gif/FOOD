<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BusinessManagementController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        $tab = in_array((string) $request->query('tab'), ['inventory', 'customers', 'promotions', 'content', 'drivers'], true)
            ? (string) $request->query('tab')
            : 'inventory';

        $allowed = match ($tab) {
            'inventory' => $actor->hasPermission('inventory.view') || $actor->hasPermission('inventory.manage'),
            'customers' => $actor->hasPermission('customers.view') || $actor->hasPermission('customers.manage'),
            'promotions', 'content' => $actor->hasPermission('promotions.view') || $actor->hasPermission('promotions.manage'),
            'drivers' => $actor->hasPermission('drivers.b2c.view') || $actor->hasPermission('drivers.b2b.view')
                || $actor->hasPermission('drivers.b2c.manage') || $actor->hasPermission('drivers.b2b.manage'),
            default => false,
        };
        abort_unless($allowed, 403);

        return view('admin.business-management', [
            'user' => $actor,
            'navGroups' => app(AdminNavigation::class)->groupsFor($actor),
            'navContext' => 'business_management',
            'tab' => $tab,
            'stores' => DB::table('stores')->orderBy('name')->get(['id', 'name', 'code']),
            'products' => DB::table('products')->orderBy('name')->get(['id', 'name', 'sku']),
            'warehouses' => DB::table('warehouses')->leftJoin('stores', 'stores.id', '=', 'warehouses.store_id')->orderBy('warehouses.name')->get([
                'warehouses.id', 'warehouses.store_id', 'warehouses.code', 'warehouses.name', 'warehouses.is_active', 'stores.name as store',
            ]),
            'inventories' => DB::table('inventories')
                ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                ->join('products', 'products.id', '=', 'inventories.product_id')
                ->leftJoin('stores', 'stores.id', '=', 'warehouses.store_id')
                ->orderBy('products.name')
                ->get([
                    'inventories.id', 'inventories.quantity', 'inventories.reserved_quantity',
                    'warehouses.name as warehouse', 'products.name as product', 'products.sku', 'stores.name as store',
                ]),
            'customers' => DB::table('customers')->orderByDesc('id')->limit(250)->get(),
            'promotions' => DB::table('promotions')->leftJoin('stores', 'stores.id', '=', 'promotions.store_id')->orderByDesc('promotions.id')->get([
                'promotions.id', 'promotions.store_id', 'promotions.name', 'promotions.type', 'promotions.value',
                'promotions.starts_at', 'promotions.ends_at', 'promotions.is_active', 'stores.name as store',
            ]),
            'banners' => DB::table('banners')->leftJoin('stores', 'stores.id', '=', 'banners.store_id')->orderBy('banners.sort_order')->orderByDesc('banners.id')->get([
                'banners.id', 'banners.store_id', 'banners.title', 'banners.image_path', 'banners.target_url',
                'banners.sort_order', 'banners.is_active', 'stores.name as store',
            ]),
            'drivers' => DB::table('drivers')->join('users', 'users.id', '=', 'drivers.user_id')->orderBy('users.name')->get([
                'drivers.id', 'drivers.user_id', 'drivers.driver_type', 'drivers.is_available', 'drivers.is_active',
                'users.name', 'users.email',
            ]),
        ]);
    }

    public function storeWarehouse(Request $request): RedirectResponse
    {
        Gate::authorize('inventory.manage');
        $data = $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'code' => ['required', 'string', 'max:80', 'unique:warehouses,code'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        DB::table('warehouses')->insert([
            'store_id' => $data['store_id'] ?? null,
            'code' => $data['code'],
            'name' => $data['name'],
            'is_active' => $request->boolean('is_active', true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('status', $this->msg('تمت إضافة المخزن.', 'Warehouse added.'));
    }

    public function updateWarehouse(Request $request, int $warehouse): RedirectResponse
    {
        Gate::authorize('inventory.manage');
        $data = $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'code' => ['required', 'string', 'max:80', Rule::unique('warehouses', 'code')->ignore($warehouse)],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        DB::table('warehouses')->where('id', $warehouse)->update([
            'store_id' => $data['store_id'] ?? null,
            'code' => $data['code'],
            'name' => $data['name'],
            'is_active' => $request->boolean('is_active'),
            'updated_at' => now(),
        ]);

        return back()->with('status', $this->msg('تم تعديل المخزن.', 'Warehouse updated.'));
    }

    public function ensureInventory(Request $request): RedirectResponse
    {
        Gate::authorize('inventory.manage');
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'min:0'],
        ]);
        DB::table('inventories')->updateOrInsert(
            ['warehouse_id' => $data['warehouse_id'], 'product_id' => $data['product_id']],
            ['quantity' => $data['quantity'], 'reserved_quantity' => 0, 'created_at' => now(), 'updated_at' => now()],
        );

        return back()->with('status', $this->msg('تم إنشاء/تحديث رصيد المخزون.', 'Inventory balance created/updated.'));
    }

    public function adjustInventory(Request $request, int $inventory): RedirectResponse
    {
        Gate::authorize('inventory.adjust');
        $data = $request->validate([
            'quantity_delta' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data, $inventory, $request): void {
            $row = DB::table('inventories')->lockForUpdate()->where('id', $inventory)->first();
            abort_unless($row !== null, 404);
            $next = (float) $row->quantity + (float) $data['quantity_delta'];

            if ($next < (float) $row->reserved_quantity) {
                throw ValidationException::withMessages([
                    'quantity_delta' => [$this->msg('لا يمكن خفض المخزون عن الكمية المحجوزة.', 'Stock cannot be reduced below reserved quantity.')],
                ]);
            }

            DB::table('inventories')->where('id', $inventory)->update(['quantity' => $next, 'updated_at' => now()]);
            DB::table('stock_movements')->insert([
                'inventory_id' => $inventory,
                'user_id' => $request->user()?->id,
                'type' => 'adjustment',
                'quantity' => $data['quantity_delta'],
                'reference_type' => 'admin_inventory_adjustment',
                'reference_id' => $inventory,
                'reason' => $data['reason'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return back()->with('status', $this->msg('تم تعديل المخزون.', 'Inventory adjusted.'));
    }

    public function storeCustomer(Request $request): RedirectResponse
    {
        Gate::authorize('customers.create');
        $data = $request->validate([
            'type' => ['required', 'in:b2b,b2c'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
        DB::table('customers')->insert([...$data, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', $this->msg('تمت إضافة العميل.', 'Customer added.'));
    }

    public function updateCustomer(Request $request, int $customer): RedirectResponse
    {
        Gate::authorize('customers.edit');
        $data = $request->validate([
            'type' => ['required', 'in:b2b,b2c'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
        DB::table('customers')->where('id', $customer)->update([...$data, 'updated_at' => now()]);

        return back()->with('status', $this->msg('تم تعديل العميل.', 'Customer updated.'));
    }

    public function destroyCustomer(Request $request, int $customer): RedirectResponse
    {
        Gate::authorize('customers.delete');

        if (DB::table('orders')->where('customer_id', $customer)->exists()
            || DB::table('invoices')->where('customer_id', $customer)->exists()) {
            return back()->withErrors([
                'customer' => $this->msg('لا يمكن حذف عميل مرتبط بطلبات أو فواتير.', 'A customer linked to orders or invoices cannot be deleted.'),
            ]);
        }

        DB::table('customers')->where('id', $customer)->delete();

        return back()->with('status', $this->msg('تم حذف العميل.', 'Customer deleted.'));
    }

    public function storePromotion(Request $request): RedirectResponse
    {
        Gate::authorize('promotions.manage');
        $data = $this->promotionData($request);
        DB::table('promotions')->insert([...$data, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', $this->msg('تمت إضافة العرض.', 'Promotion added.'));
    }

    public function updatePromotion(Request $request, int $promotion): RedirectResponse
    {
        Gate::authorize('promotions.manage');
        DB::table('promotions')->where('id', $promotion)->update([...$this->promotionData($request), 'updated_at' => now()]);

        return back()->with('status', $this->msg('تم تعديل العرض.', 'Promotion updated.'));
    }

    public function destroyPromotion(Request $request, int $promotion): RedirectResponse
    {
        Gate::authorize('promotions.manage');
        DB::table('promotions')->where('id', $promotion)->delete();

        return back()->with('status', $this->msg('تم حذف العرض.', 'Promotion deleted.'));
    }

    public function storeBanner(Request $request): RedirectResponse
    {
        Gate::authorize('promotions.manage');
        $data = $this->bannerData($request);
        DB::table('banners')->insert([...$data, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', $this->msg('تمت إضافة البانر.', 'Banner added.'));
    }

    public function updateBanner(Request $request, int $banner): RedirectResponse
    {
        Gate::authorize('promotions.manage');
        DB::table('banners')->where('id', $banner)->update([...$this->bannerData($request), 'updated_at' => now()]);

        return back()->with('status', $this->msg('تم تعديل البانر.', 'Banner updated.'));
    }

    public function destroyBanner(Request $request, int $banner): RedirectResponse
    {
        Gate::authorize('promotions.manage');
        DB::table('banners')->where('id', $banner)->delete();

        return back()->with('status', $this->msg('تم حذف البانر.', 'Banner deleted.'));
    }

    public function storeDriver(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'driver_type' => ['required', 'in:b2c,b2b'],
            'is_available' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        Gate::authorize($data['driver_type'] === 'b2b' ? 'drivers.b2b.manage' : 'drivers.b2c.manage');

        DB::transaction(function () use ($data, $request): void {
            $userId = DB::table('users')->insertGetId([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'locale' => 'ar',
                'is_active' => $request->boolean('is_active', true),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $roleCode = $data['driver_type'] === 'b2b' ? 'B2B_DRIVER' : 'B2C_DRIVER';
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
            DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $userId]);
            DB::table('drivers')->insert([
                'user_id' => $userId,
                'driver_type' => $data['driver_type'],
                'is_available' => $request->boolean('is_available'),
                'is_active' => $request->boolean('is_active', true),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return back()->with('status', $this->msg('تمت إضافة السائق.', 'Driver added.'));
    }

    public function updateDriver(Request $request, int $driver): RedirectResponse
    {
        $data = $request->validate([
            'driver_type' => ['required', 'in:b2c,b2b'],
            'is_available' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        Gate::authorize($data['driver_type'] === 'b2b' ? 'drivers.b2b.manage' : 'drivers.b2c.manage');

        DB::table('drivers')->where('id', $driver)->update([
            'driver_type' => $data['driver_type'],
            'is_available' => $request->boolean('is_available'),
            'is_active' => $request->boolean('is_active'),
            'updated_at' => now(),
        ]);

        return back()->with('status', $this->msg('تم تعديل السائق.', 'Driver updated.'));
    }

    public function destroyDriver(Request $request, int $driver): RedirectResponse
    {
        $row = DB::table('drivers')->where('id', $driver)->first();
        abort_unless($row !== null, 404);
        Gate::authorize($row->driver_type === 'b2b' ? 'drivers.b2b.manage' : 'drivers.b2c.manage');

        if (DB::table('driver_assignments')->where('driver_id', $driver)->exists()) {
            DB::table('drivers')->where('id', $driver)->update(['is_active' => false, 'is_available' => false, 'updated_at' => now()]);
            DB::table('users')->where('id', $row->user_id)->update(['is_active' => false, 'updated_at' => now()]);

            return back()->with('status', $this->msg('السائق مرتبط بعمليات سابقة، لذلك تم تعطيله.', 'Driver has historical assignments and was deactivated.'));
        }

        DB::table('users')->where('id', $row->user_id)->delete();

        return back()->with('status', $this->msg('تم حذف السائق.', 'Driver deleted.'));
    }

    private function promotionData(Request $request): array
    {
        $data = $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:80'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return [...$data, 'is_active' => $request->boolean('is_active')];
    }

    private function bannerData(Request $request): array
    {
        $data = $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'title' => ['required', 'string', 'max:255'],
            'image_path' => ['required', 'string', 'max:2048'],
            'target_url' => ['nullable', 'string', 'max:2048'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return [...$data, 'is_active' => $request->boolean('is_active')];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function msg(string $ar, string $en): string
    {
        return app()->getLocale() === 'ar' ? $ar : $en;
    }
}
