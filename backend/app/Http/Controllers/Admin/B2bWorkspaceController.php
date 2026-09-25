<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

class B2bWorkspaceController extends Controller
{
    public function show(Request $request, string $module = 'dashboard'): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->roles()->whereIn('roles.code', ['SUPER_ADMIN', 'B2B_ADMIN'])->exists(), 403);
        $allowed = ['dashboard', 'stores', 'clients', 'products', 'orders', 'drivers', 'pricing', 'reports', 'settings'];
        abort_unless(in_array($module, $allowed, true), 404);
        App::setLocale(in_array($user->locale, ['ar', 'en'], true) ? $user->locale : 'ar');
        $storeIds = DB::table('stores')->join('store_types', 'store_types.id', '=', 'stores.store_type_id')->where('store_types.code', 'B2B')->pluck('stores.id')->map(fn ($id) => (int) $id)->all();
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

        return view('admin.b2b-workspace', compact('user', 'module', 'counts', 'navGroups', 'navContext'));
    }
}
