<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RetailWholesaleLineageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RetailWholesaleProductMappingController extends Controller
{
    public function index(
        Request $request,
        RetailWholesaleLineageService $lineage,
    ): JsonResponse {
        $data = $request->validate([
            'retail_store_id' => ['required', 'integer', 'min:1'],
        ]);

        $storeId = (int) $data['retail_store_id'];
        $this->authorizeRetailMapping($request, $storeId);

        $rows = $lineage->mappingsForStore($storeId);

        return response()->json(['data' => $rows]);
    }

    public function upsert(Request $request, int $sourceProduct): JsonResponse
    {
        $data = $request->validate([
            'retail_store_id' => ['required', 'integer', 'min:1'],
            'retail_product_id' => ['required', 'integer', 'min:1'],
            'quantity_conversion_factor' => ['required', 'numeric', 'gt:0'],
        ]);

        $storeId = (int) $data['retail_store_id'];
        $retailProductId = (int) $data['retail_product_id'];
        $factor = round((float) $data['quantity_conversion_factor'], 3);
        $actor = $this->authorizeRetailMapping($request, $storeId);

        $sourceIsWholesale = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('stores', 'stores.id', '=', 'catalogs.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('products.id', $sourceProduct)
            ->where('catalogs.channel', 'b2b')
            ->where('store_types.code', 'B2B')
            ->where('stores.is_active', true)
            ->exists();

        if (! $sourceIsWholesale) {
            throw ValidationException::withMessages([
                'source_product' => ['The source product must belong to an active wholesale catalog.'],
            ]);
        }

        $targetIsRetailTenant = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->where('products.id', $retailProductId)
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2c')
            ->exists();

        if (! $targetIsRetailTenant) {
            throw ValidationException::withMessages([
                'retail_product_id' => ['The retail product must belong to the selected retail store.'],
            ]);
        }

        DB::table('retail_wholesale_product_mappings')->updateOrInsert(
            [
                'retail_store_id' => $storeId,
                'source_wholesale_product_id' => $sourceProduct,
            ],
            [
                'retail_product_id' => $retailProductId,
                'quantity_conversion_factor' => $factor,
                'mapped_by_user_id' => $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $row = DB::table('retail_wholesale_product_mappings')
            ->where('retail_store_id', $storeId)
            ->where('source_wholesale_product_id', $sourceProduct)
            ->first();

        return response()->json([
            'id' => (int) $row->id,
            'retail_store_id' => (int) $row->retail_store_id,
            'source_wholesale_product_id' => (int) $row->source_wholesale_product_id,
            'retail_product_id' => (int) $row->retail_product_id,
            'quantity_conversion_factor' => (float) $row->quantity_conversion_factor,
        ]);
    }

    private function authorizeRetailMapping(Request $request, int $storeId): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $retailStore = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->exists();
        abort_unless($retailStore, 404);

        abort_unless(
            $actor->hasPermission('inventory.replenishment_mapping.manage', $storeId),
            403,
        );

        return $actor;
    }
}
