<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\B2bCustomer;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StorefrontAccessController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401, 'Unauthenticated.');

        $retailStoreIds = $user->storeRoleAssignments()
            ->select('user_store_roles.store_id')
            ->join('roles', 'roles.id', '=', 'user_store_roles.role_id')
            ->join('stores', 'stores.id', '=', 'user_store_roles.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('roles.is_active', true)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->whereIn('roles.code', ['B2C_STORE_ADMIN'])
            ->distinct()
            ->pluck('user_store_roles.store_id')
            ->map(static fn ($id): int => (int) $id)
            ->values();

        $entitledRetailStoreIds = DB::table('retail_wholesale_accounts')
            ->join('b2b_accounts', 'b2b_accounts.b2b_customer_id', '=', 'retail_wholesale_accounts.b2b_customer_id')
            ->whereIn('retail_wholesale_accounts.retail_store_id', $retailStoreIds)
            ->where('b2b_accounts.status', 'active')
            ->pluck('retail_wholesale_accounts.retail_store_id')
            ->map(static fn ($id): int => (int) $id)
            ->values();

        $directB2b = B2bCustomer::query()
            ->where('user_id', $user->getKey())
            ->whereHas('account', fn ($query) => $query->where('status', 'active'))
            ->exists();

        $support = $user->hasRole('SUPER_ADMIN');
        $canWholesale = $support || $directB2b || $entitledRetailStoreIds->isNotEmpty();

        return response()->json([
            'can_wholesale' => $canWholesale,
            'support_inspection' => $support,
            'retail_store_ids' => $retailStoreIds->all(),
            'wholesale_entitled_retail_store_ids' => $entitledRetailStoreIds->all(),
            'wholesale_store_id' => $canWholesale ? app(WholesalePrincipal::class)->storeId() : null,
        ]);
    }
}
