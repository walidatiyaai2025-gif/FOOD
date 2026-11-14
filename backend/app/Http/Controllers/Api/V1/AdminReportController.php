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
        $isSuperAdmin = $user->hasRole('SUPER_ADMIN');
        // Only an absent store_id allows platform-wide Super Admin reporting.
        // A supplied array or malformed scalar must never become null or be
        // coerced into another store's identifier.
        if ($isSuperAdmin && ! $request->exists('store_id')) {
            return null;
        }

        $rawStoreId = $request->input('store_id');
        $requested = is_int($rawStoreId) && $rawStoreId > 0 ? $rawStoreId : null;
        if (is_string($rawStoreId) && preg_match('/^[1-9][0-9]*$/D', $rawStoreId) === 1) {
            $parsed = filter_var($rawStoreId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $requested = $parsed === false ? null : $parsed;
        }

        if ($requested === null) {
            $locale = in_array($user->locale, ['ar', 'en'], true) ? $user->locale : app()->getLocale();
            // Symfony may synthesize a default language when no Accept-Language
            // header was supplied; that must not override the user's locale.
            if ($request->headers->has('Accept-Language')) {
                foreach ($request->getLanguages() as $preferredLanguage) {
                    $candidate = strtolower(substr((string) $preferredLanguage, 0, 2));
                    if (in_array($candidate, ['ar', 'en'], true)) {
                        $locale = $candidate;
                        break;
                    }
                }
            }

            $locale = in_array($locale, ['ar', 'en'], true) ? $locale : 'en';

            throw ValidationException::withMessages(['store_id' => [__('reports.store_required', [], $locale)]]);
        }
        if ($isSuperAdmin) {
            return $requested;
        }

        abort_unless($user->hasPermission('reports.view', $requested), 403);
        abort_unless($user->storeRoleAssignments()->where('store_id', $requested)->exists(), 403);

        return $requested;
    }
}
