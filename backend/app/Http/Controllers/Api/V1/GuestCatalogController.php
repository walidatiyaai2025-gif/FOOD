<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Store;
use App\Models\User;
use App\Services\ProductAvailabilityService;
use App\Services\RetailMerchantIdentityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GuestCatalogController extends Controller
{
    public function __construct(
        private readonly RetailMerchantIdentityService $retailMerchants,
        private readonly ProductAvailabilityService $availability,
    ) {}

    public function categories(Request $request, int $store): JsonResponse
    {
        $this->assertCanBrowse($request, $store);
        $this->activeB2cStore($store);
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $categories = Category::query()
            ->select('categories.*')
            ->join('catalogs', 'catalogs.id', '=', 'categories.catalog_id')
            ->join('products', function ($join): void {
                $join->on('products.category_id', '=', 'categories.id')
                    ->on('products.catalog_id', '=', 'categories.catalog_id');
            })
            ->join('store_products', 'store_products.product_id', '=', 'products.id')
            ->where('catalogs.store_id', $store)
            ->where('catalogs.channel', 'b2c')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('store_products.store_id', $store)
            ->where('store_products.is_active', true)
            ->where('products.is_active', true)
            ->where('categories.is_active', true)
            ->distinct()
            ->orderBy('categories.id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($categories->items())
                ->map(fn (Category $category): array => [
                    'id' => (int) $category->id,
                    'parent_id' => $category->parent_id === null ? null : (int) $category->parent_id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'is_active' => (bool) $category->is_active,
                    'image_url' => $this->categoryImageUrl($category, $store),
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
        $this->assertCanBrowse($request, $store);
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
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', 'store_products.product_id', '=', 'products.id')
            ->where('catalogs.store_id', $store)
            ->where('catalogs.channel', 'b2c')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
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
                    $store,
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
        $this->assertCanBrowse($request, $storeId);
        $this->activeB2cStore($storeId);

        $item = Product::query()
            ->forStore($storeId)
            ->whereKey($product)
            ->where('products.is_active', true)
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
            ->map(fn ($path) => $this->assetUrl($path))
            ->filter()
            ->values()
            ->all();

        return response()->json([
            ...$this->productSummary($item, $price, $storeId),
            'description' => $item->description,
            'images' => $images,
        ]);
    }

    public function offers(Request $request, int $store): JsonResponse
    {
        $this->assertCanBrowse($request, $store);
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

    public function banners(Request $request, int $store): JsonResponse
    {
        $this->assertCanBrowse($request, $store);
        $this->activeB2cStore($store);
        $perPage = min(max($request->integer('per_page', 10), 1), 50);

        $banners = DB::table('banners')
            ->where('store_id', $store)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($banners->items())
                ->map(fn ($banner): array => [
                    'id' => (int) $banner->id,
                    'title' => (string) $banner->title,
                    'image_url' => $this->bannerImageUrl($banner->image_path),
                    'target_type' => $banner->target_type,
                    'target_id' => $banner->target_id === null ? null : (int) $banner->target_id,
                    'target_url' => $banner->target_url,
                    'sort_order' => (int) $banner->sort_order,
                ])
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $banners->currentPage(),
                'per_page' => $banners->perPage(),
                'total' => $banners->total(),
            ],
        ]);
    }

    private function assertCanBrowse(Request $request, int $storeId): void
    {
        $user = $request->user('sanctum');
        if ($request->bearerToken() !== null && $user instanceof User) {
            $this->retailMerchants->assertCanPurchaseFromRetailStore($user, $storeId);
        }
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

    private function productSummary(Product $product, mixed $price, int $storeId): array
    {
        $primaryImage = DB::table('product_images')
            ->where('product_id', $product->id)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first(['id', 'path']);

        $brand = $product->brand_id === null
            ? null
            : DB::table('brands')
                ->where('id', $product->brand_id)
                ->first(['name', 'image_path']);

        $availability = $this->availability->forStoreProduct($storeId, (int) $product->id);

        return [
            'id' => (int) $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'category_id' => $product->category_id === null ? null : (int) $product->category_id,
            'brand_id' => $product->brand_id === null ? null : (int) $product->brand_id,
            'brand_name' => $brand?->name,
            'brand_image_url' => $this->assetUrl($brand?->image_path),
            'is_active' => (bool) $product->is_active,
            'price' => $price === null ? null : (float) $price,
            'currency' => 'EGP',
            'image_url' => $this->assetUrl($primaryImage?->path),
            'thumbnail_url' => $primaryImage === null
                ? null
                : route('api.customer.catalog-image.thumbnail', ['image' => (int) $primaryImage->id]),
            ...$availability,
        ];
    }

    private function categoryImageUrl(Category $category, int $storeId): ?string
    {
        $ownImage = $this->assetUrl($category->getAttribute('image_path'));
        if ($ownImage !== null) {
            return $ownImage;
        }

        $fallback = DB::table('product_images')
            ->join('products', 'products.id', '=', 'product_images.product_id')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->on('store_products.store_id', '=', 'catalogs.store_id');
            })
            ->where('products.category_id', $category->id)
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2c')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('products.is_active', true)
            ->where('store_products.is_active', true)
            ->orderByDesc('product_images.is_primary')
            ->orderBy('product_images.sort_order')
            ->orderBy('product_images.id')
            ->value('product_images.path');

        return $this->assetUrl($fallback);
    }

    private function bannerImageUrl(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $value = ltrim(trim($path), '/');
        if (str_starts_with($value, 'storage/')) {
            $relative = substr($value, strlen('storage/'));
            if ($relative === '' || ! Storage::disk('public')->exists($relative)) {
                return null;
            }
        }

        return $this->assetUrl($path);
    }

    private function assetUrl(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $value = trim($path);
        if (str_starts_with($value, 'https://') || str_starts_with($value, 'http://')) {
            return $value;
        }

        return url('/'.ltrim($value, '/'));
    }
}
