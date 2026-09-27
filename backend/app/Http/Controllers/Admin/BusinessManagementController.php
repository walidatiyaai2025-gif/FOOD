<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\B2cCustomerService;
use App\Services\OperationalTenantScope;
use App\Support\TenantContextResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BusinessManagementController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        // Legacy mixed-domain page is retired. Operational administration now lives
        // in the authoritative wholesale or exact Retail store workspace.
        return redirect()->route('admin.index')
            ->with('status', $this->msg(
                'تم نقل إدارة العمليات إلى مساحات الجملة والتجزئة المنفصلة لمنع خلط بيانات المتاجر.',
                'Operations management now lives in the separated Wholesale and Retail workspaces.',
            ));
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
        app(OperationalTenantScope::class)->assertStore($actor, $storeId, 'inventory.manage');

        DB::table('warehouses')->insert([
            'store_id' => $storeId,
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
        $actor = $this->actor($request);
        $current = DB::table('warehouses')->where('id', $warehouse)->first();
        abort_unless($current !== null && $current->store_id !== null, 404);
        app(OperationalTenantScope::class)->assertStore($actor, (int) $current->store_id, 'inventory.manage');

        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'code' => ['required', 'string', 'max:80', Rule::unique('warehouses', 'code')->ignore($warehouse)],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $storeId = (int) $data['store_id'];
        app(OperationalTenantScope::class)->assertStore($actor, $storeId, 'inventory.manage');

        $productIds = DB::table('inventories')->where('warehouse_id', $warehouse)->pluck('product_id');
        foreach ($productIds as $productId) {
            app(OperationalTenantScope::class)->assertProductOwnedByStore((int) $productId, $storeId);
        }

        DB::table('warehouses')->where('id', $warehouse)->update([
            'store_id' => $storeId,
            'code' => $data['code'],
            'name' => $data['name'],
            'is_active' => $request->boolean('is_active'),
            'updated_at' => now(),
        ]);

        return back()->with('status', $this->msg('تم تعديل المخزن.', 'Warehouse updated.'));
    }

    public function ensureInventory(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'min:0'],
        ]);

        $warehouse = DB::table('warehouses')->where('id', $data['warehouse_id'])->first();
        abort_unless($warehouse !== null && $warehouse->store_id !== null, 404);
        $storeId = (int) $warehouse->store_id;
        app(OperationalTenantScope::class)->assertStore($actor, $storeId, 'inventory.manage');
        app(OperationalTenantScope::class)->assertProductOwnedByStore((int) $data['product_id'], $storeId);

        DB::table('inventories')->updateOrInsert(
            ['warehouse_id' => $data['warehouse_id'], 'product_id' => $data['product_id']],
            ['quantity' => $data['quantity'], 'reserved_quantity' => 0, 'created_at' => now(), 'updated_at' => now()],
        );

        return back()->with('status', $this->msg('تم إنشاء/تحديث رصيد المخزون.', 'Inventory balance created/updated.'));
    }

    public function adjustInventory(Request $request, int $inventory): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'quantity_delta' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $scope = app(OperationalTenantScope::class);
        $row = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('inventories.id', $inventory)
            ->first(['inventories.*', 'warehouses.store_id']);
        abort_unless($row !== null && $row->store_id !== null, 404);
        $storeId = (int) $row->store_id;
        $scope->assertStore($actor, $storeId, 'inventory.adjust');
        $scope->assertProductOwnedByStore((int) $row->product_id, $storeId);

        DB::transaction(function () use ($data, $inventory, $actor, $storeId): void {
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
                'store_id' => $storeId,
                'user_id' => $actor->id,
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
        $actor = $this->actor($request);
        $data = $request->validate([
            'type' => ['required', 'in:b2b,b2c'],
            'store_id' => ['nullable', 'required_if:type,b2c', 'integer', 'exists:stores,id'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        if ($data['type'] === 'b2b') {
            Gate::forUser($actor)->authorize('customers.create');
            app(TenantContextResolver::class)->wholesale($actor);
            app(B2bCustomerService::class)->create([
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
            ]);
        } else {
            $storeId = (int) $data['store_id'];
            $this->assertRetailCustomerStore($actor, $storeId, $request);
            Gate::forUser($actor)->authorize('customers.create', $storeId);
            app(B2cCustomerService::class)->create($storeId, [
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
            ]);
        }

        return back()->with('status', $this->msg('تمت إضافة العميل.', 'Customer added.'));
    }

    public function updateCustomer(Request $request, int $customer): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'type' => ['required', 'in:b2b,b2c'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        if ($data['type'] === 'b2b') {
            Gate::forUser($actor)->authorize('customers.edit');
            app(TenantContextResolver::class)->wholesale($actor);
            $model = B2bCustomer::query()->findOrFail($customer);
            $model->update([
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
            ]);
        } else {
            $model = B2cCustomer::query()->findOrFail($customer);
            $this->assertRetailCustomerStore($actor, (int) $model->store_id, $request);
            Gate::forUser($actor)->authorize('customers.edit', (int) $model->store_id);
            app(B2cCustomerService::class)->update($model, [
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
            ]);
        }

        return back()->with('status', $this->msg('تم تعديل العميل.', 'Customer updated.'));
    }

    public function destroyCustomer(Request $request, int $customer): RedirectResponse
    {
        $actor = $this->actor($request);
        $type = $request->validate(['type' => ['required', 'in:b2b,b2c']])['type'];

        if ($type === 'b2b') {
            Gate::forUser($actor)->authorize('customers.delete');
            app(TenantContextResolver::class)->wholesale($actor);
            $model = B2bCustomer::query()->findOrFail($customer);

            if (DB::table('orders')->where('b2b_customer_id', $model->getKey())->exists()
                || DB::table('invoices')->where('b2b_customer_id', $model->getKey())->exists()
                || DB::table('b2b_accounts')->where('b2b_customer_id', $model->getKey())->exists()) {
                return back()->withErrors([
                    'customer' => $this->msg('لا يمكن حذف عميل جملة مرتبط بسجل تشغيلي.', 'A B2B customer with operational history cannot be deleted.'),
                ]);
            }

            $model->delete();
        } else {
            $model = B2cCustomer::query()->findOrFail($customer);
            $this->assertRetailCustomerStore($actor, (int) $model->store_id, $request);
            Gate::forUser($actor)->authorize('customers.delete', (int) $model->store_id);

            if (DB::table('orders')->where('b2c_customer_id', $model->getKey())->exists()
                || DB::table('invoices')->where('b2c_customer_id', $model->getKey())->exists()
                || DB::table('addresses')->where('b2c_customer_id', $model->getKey())->exists()
                || DB::table('customer_favorites')->where('b2c_customer_id', $model->getKey())->exists()) {
                return back()->withErrors([
                    'customer' => $this->msg('لا يمكن حذف عميل متجر مرتبط بسجل تشغيلي.', 'A B2C customer with operational history cannot be deleted.'),
                ]);
            }

            $model->delete();
        }

        return back()->with('status', $this->msg('تم حذف العميل.', 'Customer deleted.'));
    }

    public function storePromotion(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->promotionData($request);
        app(OperationalTenantScope::class)->assertStore($actor, (int) $data['store_id'], 'promotions.manage');
        DB::table('promotions')->insert([...$data, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', $this->msg('تمت إضافة العرض.', 'Promotion added.'));
    }

    public function updatePromotion(Request $request, int $promotion): RedirectResponse
    {
        $actor = $this->actor($request);
        $current = DB::table('promotions')->where('id', $promotion)->first();
        abort_unless($current !== null && $current->store_id !== null, 404);
        app(OperationalTenantScope::class)->assertStore($actor, (int) $current->store_id, 'promotions.manage');

        $data = $this->promotionData($request);
        app(OperationalTenantScope::class)->assertStore($actor, (int) $data['store_id'], 'promotions.manage');
        DB::table('promotions')->where('id', $promotion)->update([...$data, 'updated_at' => now()]);

        return back()->with('status', $this->msg('تم تعديل العرض.', 'Promotion updated.'));
    }

    public function destroyPromotion(Request $request, int $promotion): RedirectResponse
    {
        $actor = $this->actor($request);
        $current = DB::table('promotions')->where('id', $promotion)->first();
        abort_unless($current !== null && $current->store_id !== null, 404);
        app(OperationalTenantScope::class)->assertStore($actor, (int) $current->store_id, 'promotions.manage');
        DB::table('promotions')->where('id', $promotion)->delete();

        return back()->with('status', $this->msg('تم حذف العرض.', 'Promotion deleted.'));
    }

    public function storeBanner(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->bannerData($request);
        app(OperationalTenantScope::class)->assertStore($actor, (int) $data['store_id'], 'promotions.manage');
        DB::table('banners')->insert([...$data, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('status', $this->msg('تمت إضافة البانر.', 'Banner added.'));
    }

    public function updateBanner(Request $request, int $banner): RedirectResponse
    {
        $actor = $this->actor($request);
        $current = DB::table('banners')->where('id', $banner)->first();
        abort_unless($current !== null && $current->store_id !== null, 404);
        app(OperationalTenantScope::class)->assertStore($actor, (int) $current->store_id, 'promotions.manage');

        $data = $this->bannerData($request);
        app(OperationalTenantScope::class)->assertStore($actor, (int) $data['store_id'], 'promotions.manage');
        DB::table('banners')->where('id', $banner)->update([...$data, 'updated_at' => now()]);

        return back()->with('status', $this->msg('تم تعديل البانر.', 'Banner updated.'));
    }

    public function destroyBanner(Request $request, int $banner): RedirectResponse
    {
        $actor = $this->actor($request);
        $current = DB::table('banners')->where('id', $banner)->first();
        abort_unless($current !== null && $current->store_id !== null, 404);
        app(OperationalTenantScope::class)->assertStore($actor, (int) $current->store_id, 'promotions.manage');
        DB::table('banners')->where('id', $banner)->delete();

        return back()->with('status', $this->msg('تم حذف البانر.', 'Banner deleted.'));
    }

    public function storeDriver(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'driver_type' => ['required', 'in:b2c,b2b'],
            'is_available' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $storeId = (int) $data['store_id'];
        $ability = $data['driver_type'] === 'b2b' ? 'drivers.b2b.manage' : 'drivers.b2c.manage';
        app(OperationalTenantScope::class)->assertStore($actor, $storeId, $ability, $data['driver_type']);

        DB::transaction(function () use ($data, $request, $storeId): void {
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
                'store_id' => $storeId,
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
        $actor = $this->actor($request);
        $current = DB::table('drivers')->where('id', $driver)->first();
        abort_unless($current !== null, 404);
        if ($current->store_id === null) {
            abort_unless($actor->hasRole('SUPER_ADMIN'), 404);
        } else {
            $oldAbility = $current->driver_type === 'b2b' ? 'drivers.b2b.manage' : 'drivers.b2c.manage';
            app(OperationalTenantScope::class)->assertStore($actor, (int) $current->store_id, $oldAbility, (string) $current->driver_type);
        }

        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'driver_type' => ['required', 'in:b2c,b2b'],
            'is_available' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $storeId = (int) $data['store_id'];
        $ability = $data['driver_type'] === 'b2b' ? 'drivers.b2b.manage' : 'drivers.b2c.manage';
        app(OperationalTenantScope::class)->assertStore($actor, $storeId, $ability, $data['driver_type']);

        $hasAssignments = DB::table('driver_assignments')->where('driver_id', $driver)->exists();
        if ($hasAssignments && (
            $current->store_id === null
            || (int) $current->store_id !== $storeId
            || (string) $current->driver_type !== (string) $data['driver_type']
        )) {
            throw ValidationException::withMessages([
                'store_id' => [$this->msg(
                    'لا يمكن نقل سائق لديه سجل توصيل إلى متجر أو قناة أخرى.',
                    'A driver with delivery history cannot be moved to another store or channel.',
                )],
            ]);
        }

        DB::table('drivers')->where('id', $driver)->update([
            'store_id' => $storeId,
            'driver_type' => $data['driver_type'],
            'is_available' => $request->boolean('is_available'),
            'is_active' => $request->boolean('is_active'),
            'updated_at' => now(),
        ]);

        return back()->with('status', $this->msg('تم تعديل السائق.', 'Driver updated.'));
    }

    public function destroyDriver(Request $request, int $driver): RedirectResponse
    {
        $actor = $this->actor($request);
        $row = DB::table('drivers')->where('id', $driver)->first();
        abort_unless($row !== null, 404);

        if ($row->store_id === null) {
            abort_unless($actor->hasRole('SUPER_ADMIN'), 404);
        } else {
            $ability = $row->driver_type === 'b2b' ? 'drivers.b2b.manage' : 'drivers.b2c.manage';
            app(OperationalTenantScope::class)->assertStore($actor, (int) $row->store_id, $ability, (string) $row->driver_type);
        }

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
            'store_id' => ['required', 'integer', 'exists:stores,id'],
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
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'title' => ['required', 'string', 'max:255'],
            'image_path' => ['required', 'string', 'max:2048'],
            'target_url' => ['nullable', 'string', 'max:2048'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return [...$data, 'is_active' => $request->boolean('is_active')];
    }

    private function assertRetailCustomerStore(User $actor, int $storeId, Request $request): void
    {
        app(TenantContextResolver::class)->retail(
            $actor,
            $storeId,
            $actor->hasRole('SUPER_ADMIN') && $request->boolean('support_access'),
            $request,
        );
    }

    /** @param list<int> $storeIds
     * @return list<int>
     */
    private function filterRequestedStoreIds(User $actor, Request $request, array $storeIds): array
    {
        $requested = $request->integer('store_id');
        if ($requested <= 0) {
            return $storeIds;
        }

        abort_unless(in_array($requested, $storeIds, true), 404);

        if ($actor->hasRole('SUPER_ADMIN')) {
            $channel = app(OperationalTenantScope::class)->storeChannel($requested);
            if ($channel === 'b2c') {
                abort_unless($request->boolean('support_access'), 403);
                app(TenantContextResolver::class)->retail($actor, $requested, true, $request);
            }
        }

        return [$requested];
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
