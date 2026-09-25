<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AdminReportController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless(
            Gate::allows('reports.view')
            || $user->storeRoleAssignments()
                ->whereHas('role', fn ($query) => $query
                    ->where('roles.is_active', true)
                    ->whereIn('roles.scope', ['store', 'both'])
                    ->whereHas('permissions', fn ($permissions) => $permissions->where('code', 'reports.view')))
                ->exists(),
            403,
        );
        $storeId = $this->storeScope($request, $user);

        $orders = DB::table('orders');
        if ($storeId !== null) {
            $orders->where('store_id', $storeId);
        }
        $orderCount = (clone $orders)->count();
        $revenue = (float) (clone $orders)->whereNotIn('status', ['cancelled'])->sum('grand_total');

        $inventory = DB::table('inventories')->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id');
        if ($storeId !== null) {
            $inventory->where('warehouses.store_id', $storeId);
        }

        return response()->json(['data' => [
            'scope' => $storeId === null ? 'platform' : 'store',
            'store_id' => $storeId,
            'orders' => $orderCount,
            'revenue' => round($revenue, 3),
            'inventory_quantity' => (float) $inventory->sum('inventories.quantity'),
            'reserved_quantity' => (float) $inventory->sum('inventories.reserved_quantity'),
        ]]);
    }

    private function storeScope(Request $request, User $user): ?int
    {
        if ($user->hasRole('SUPER_ADMIN')) {
            return $request->filled('store_id') ? $request->integer('store_id') : null;
        }
        $requested = $request->integer('store_id');
        if ($requested <= 0) {
            throw ValidationException::withMessages(['store_id' => ['A store_id is required for store-scoped reports.']]);
        }
        abort_unless($user->hasPermission('reports.view', $requested), 403);
        abort_unless($user->storeRoleAssignments()->where('store_id', $requested)->exists(), 403);

        return $requested;
    }
}
