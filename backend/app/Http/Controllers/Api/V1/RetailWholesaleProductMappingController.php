<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RetailWholesaleProductMappingController extends Controller
{
    public function index(Request $request, Store $store): JsonResponse
    {
        $actor = $this->authorizeStore($request, $store);

        $rows = DB::table('retail_wholesale_product_mappings')
            ->join('products as wholesale_products', 'wholesale_products.id', '=', 'retail_wholesale_product_mappings.wholesale_product_id')
            ->join('products as retail_products', 'retail_products.id', '=', 'retail_wholesale_product_mappings.retail_product_id')
            ->where('retail_wholesale_product_mappings.retail_store_id', $store->getKey())
            ->orderBy('wholesale_products.name')
            ->get([
                'retail_wholesale_product_mappings.id',
                'retail_wholesale_product_mappings.wholesale_product_id',
                'wholesale_products.sku as wholesale_sku',
                'wholesale_products.name as wholesale_name',
                'retail_wholesale_product_mappings.retail_product_id',
                'retail_products.sku as retail_sku',
                'retail_products.name as retail_name',
                'retail_wholesale_product_mappings.quantity_conversion_factor',
            ]);

        return response()->json([
            'data' => $rows,
            'store_id' => (int) $store->getKey(),
            'managed_by' => (int) $actor->getKey(),
        ]);
    }

    public function upsert(Request $request, Store $store, int $wholesaleProduct): JsonResponse
    {
        $actor = $this->authorizeStore($request, $store);
        $data = $request->validate([
            'retail_product_id' => ['required', 'integer', 'min:1'],
            'quantity_conversion_factor' => ['required', 'numeric', 'gt:0'],
        ]);

        $retailProductOwned = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->where('products.id', $data['retail_product_id'])
            ->where('catalogs.store_id', $store->getKey())
            ->where('catalogs.channel', 'b2c')
            ->exists();
        abort_unless($retailProductOwned, 422, 'Retail product must belong to the selected Retail store.');

        $wholesaleProductOwned = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('stores', 'stores.id', '=', 'catalogs.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('products.id', $wholesaleProduct)
            ->where('catalogs.channel', 'b2b')
            ->where('store_types.code', 'B2B')
            ->exists();
        abort_unless($wholesaleProductOwned, 422, 'Wholesale product is not part of the Wholesale catalog.');

        DB::table('retail_wholesale_product_mappings')->updateOrInsert(
            [
                'retail_store_id' => $store->getKey(),
                'wholesale_product_id' => $wholesaleProduct,
            ],
            [
                'retail_product_id' => (int) $data['retail_product_id'],
                'quantity_conversion_factor' => (float) $data['quantity_conversion_factor'],
                'updated_by_user_id' => $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return response()->json([
            'retail_store_id' => (int) $store->getKey(),
            'wholesale_product_id' => $wholesaleProduct,
            'retail_product_id' => (int) $data['retail_product_id'],
            'quantity_conversion_factor' => (float) $data['quantity_conversion_factor'],
        ]);
    }

    private function authorizeStore(Request $request, Store $store): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $isRetail = DB::table('store_types')
            ->where('id', $store->store_type_id)
            ->where('code', 'B2C')
            ->exists();
        abort_unless($isRetail, 404);

        abort_unless(
            $actor->hasRole('SUPER_ADMIN') || $actor->hasRole('B2C_STORE_ADMIN', (int) $store->getKey()),
            403,
        );

        return $actor;
    }
}
