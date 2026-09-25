<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

class B2cWorkspaceController extends Controller
{
    public function show(Request $request, string $module = 'dashboard'): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $allowed = ['dashboard', 'products', 'inventory', 'orders', 'customers', 'promotions', 'drivers', 'storefront', 'content', 'reports', 'settings'];
        abort_unless(in_array($module, $allowed, true), 404);
        $storeIds = $this->storeIds($user);
        abort_if($storeIds === [], 403, 'No assigned B2C store.');
        App::setLocale(in_array($user->locale, ['ar','en'], true) ? $user->locale : 'ar');

        $counts = [
            'products' => DB::table('store_products')->whereIn('store_id', $storeIds)->count(),
            'orders' => DB::table('orders')->whereIn('store_id', $storeIds)->where('channel', 'b2c')->count(),
            'customers' => DB::table('orders')->whereIn('store_id', $storeIds)->where('channel', 'b2c')->distinct()->count('customer_id'),
            'inventory' => DB::table('inventories')->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')->whereIn('warehouses.store_id', $storeIds)->count(),
        ];

        return view('admin.b2c-workspace', compact('user', 'module', 'storeIds', 'counts'));
    }

    private function storeIds(User $user): array
    {
        if ($user->roles()->where('roles.code', 'SUPER_ADMIN')->exists()) {
            return DB::table('stores')->join('store_types', 'store_types.id', '=', 'stores.store_type_id')->where('store_types.code', 'B2C')->pluck('stores.id')->map(fn ($id) => (int) $id)->all();
        }
        return $user->storeRoleAssignments()->whereHas('role', fn ($q) => $q->where('code', 'B2C_STORE_ADMIN'))->pluck('store_id')->map(fn ($id)=>(int)$id)->unique()->values()->all();
    }
}
