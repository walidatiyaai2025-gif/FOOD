<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GuestCatalogController extends Controller
{
    public function categories(Request $request, int $store): JsonResponse
    {
        $this->activeB2cStore($store);
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $categories = Category::query()
            ->select('categories.*')
            ->join('products', 'products.category_id', '=', 'categories.id')
            ->join('store_products', 'store_products.product_id', '=', 'products.id')
            ->where('store_products.store_id', $store)
            ->where('store_products.is_active', true)
            ->where('products.is_active', true)
            ->where('categories.is_active', true)
            ->distinct()
            ->orderBy('categories.id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($categories->items())
                ->map(static fn (Category $category): array => [
                    'id' => (int) $category->id,
                    'parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'is_active' => (bool) $category->is_active,
                ])
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $categories->currentPage(),
                'per_page' => $categories->perPage(),
                'total' => $categories->total(),
            ],
        ]);
    }

    public function products(Request $request, int $store): JsonResponse
    {
        $this->activeB2cStore($store);

        $validated = $request->validate([
            'category' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:120'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'gte:min_price'],
            'sort' => ['nullable', 'in:name,price'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);

        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $query = Product::query()
            ->select('products.*', 'store_products.price as store_price')
            ->join('store_products', 'store_products.product_id', '=', 'products.id')
            ->where('store_products.store_id', $store)
            ->where('store_products.is_active', true)
            ->where('products.is_active', true);

        if (isset($validated['category'])) {
            $query->where('products.category_id', (int) $validated['category']);
        }

        if (isset($validated['q']) && trim((string) $validated['q']) !== '') {
            $term = '%'.trim((string) $validated['q']).'%';
            $query->where(function (Builder $query) use ($term): void {
                $query
                    ->where('products.name', 'like', $term)
                    ->orWhere('products.sku', 'like', $term);
            });
        }

        if (isset($validated['min_price'])) {
            $query->where('store_products.price', '>=', $validated['min_price']);
        }

        if (isset($validated['max_price'])) {
            $query->where('store_products.price', '<=', $validated['max_price']);
        }

        $sort = $validated['sort'] ?? 'name';
        $direction = $validated['direction'] ?? 'asc';
        $sortColumn = $sort === 'price' ? 'store_products.price' : 'products.name';

        $products = $query
            ->orderBy($sortColumn, $direction)
            ->orderBy('products.id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($products->items())
                ->map(fn (Product $product): array => $this->productSummary(
                    $product,
                    $product->getAttribute('store_price'),
                ))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function product(Request $request, int $product): JsonResponse
    {
        $validated = $request->validate([
            'store' => ['required', 'integer', 'min:1'],
        ]);
        $storeId = (int) $validated['store'];
        $this->activeB2cStore($storeId);

        $item = Product::query()
            ->whereKey($product)
            ->where('is_active', true)
            ->firstOrFail();

        $storeProduct = DB::table('store_products')
                ->where('store_id', $storeId)
                ->where('product_id', $item->id)
                ->where('is_active', true)
            ->first();

        abort_if($storeProduct === null, 404);
        $price = $storeProduct->price;

        $images = DB::table('product_images')
            ->where('product_id', $item->id)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->pluck('path')
            ->all();

        return response()->json([
            ...$this->productSummary($item, $price),
            'description' => $item->description,
            'images' => array_values($images),
        ]);
    }

    public function offers(Request $request, int $store): JsonResponse
    {
        $this->activeB2cStore($store);
        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $now = now();

        $offers = Promotion::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($store): void {
                $query->whereNull('store_id')->orWhere('store_id', $store);
            })
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($offers->items())
                ->map(static fn (Promotion $offer): array => [
                    'id' => (int) $offer->id,
                    'name' => $offer->name,
                    'type' => $offer->type,
                    'value' => $offer->value === null ? null : (float) $offer->value,
                    'starts_at' => $offer->starts_at,
                    'ends_at' => $offer->ends_at,
                    'is_active' => (bool) $offer->is_active,
                ])
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $offers->currentPage(),
                'per_page' => $offers->perPage(),
                'total' => $offers->total(),
            ],
        ]);
    }

    private function activeB2cStore(int $storeId): Store
    {
        return Store::query()
            ->select('stores.*')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->whereKey($storeId)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->firstOrFail();
    }

    private function productSummary(Product $product, mixed $price): array
    {
        return [
            'id' => (int) $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'category_id' => $product->category_id === null ? null : (int) $product->category_id,
            'brand_id' => $product->brand_id === null ? null : (int) $product->brand_id,
            'is_active' => (bool) $product->is_active,
            'price' => $price === null ? null : (float) $price,
            'currency' => 'KWD',
        ];
    }
}
