<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ProductAvailabilityService;
use App\Services\RetailMerchantIdentityService;
use App\Services\WholesalePrincipal;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PlatformMarketplaceController extends Controller
{
    public function __construct(
        private readonly RetailMerchantIdentityService $retailMerchants,
        private readonly ProductAvailabilityService $availability,
        private readonly WholesalePrincipal $wholesalePrincipal,
    ) {}

    public function home(Request $request): JsonResponse
    {
        $store = $this->principalStore();
        $storeId = (int) $store->id;
        $wholesaleBanners = $this->wholesaleBanners($storeId);

        return response()->json([
            'store' => $this->storePayload($store),
            'theme' => $this->themePayload($storeId),
            'branding' => $this->brandingPayload($store),
            'hero' => $wholesaleBanners[0] ?? null,
            'banners' => $wholesaleBanners,
            'sections' => $this->sections($storeId),
            'categories' => $this->categories($storeId),
            'brands' => $this->brands($storeId),
            'offers' => $this->offers($storeId),
            'products' => [
                'data' => $this->products($request, $storeId),
            ],
            'retail_banners' => $this->retailPlacements($request, $storeId),
            'currency' => 'EGP',
        ]);
    }

    public function product(Request $request, int $product): JsonResponse
    {
        $store = $this->principalStore();
        $storeId = (int) $store->id;
        $row = $this->productQuery($storeId)
            ->where('products.id', $product)
            ->first();

        abort_if($row === null, 404, 'Product is not available in the platform Wholesale store.');

        $images = DB::table('product_images')
            ->where('product_id', $product)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('path')
            ->map(fn ($path): ?string => $this->assetUrl($path))
            ->filter()
            ->values()
            ->all();

        $availability = $this->availability->forStoreProduct($storeId, $product);

        return response()->json([
            ...$this->productPayload($row),
            'description' => $row->description,
            'images' => $images,
            ...$availability,
        ]);
    }

    private function principalStore(): object
    {
        $storeId = $this->wholesalePrincipal->storeId();

        $store = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B')
            ->first(['stores.id', 'stores.code', 'stores.name', 'stores.logo_path']);

        abort_if($store === null, 503, 'Platform Wholesale store is not available.');

        return $store;
    }

    /** @return array<string,mixed> */
    private function storePayload(object $store): array
    {
        return [
            'id' => (int) $store->id,
            'code' => (string) $store->code,
            'name' => (string) $store->name,
            'logo_url' => $this->assetUrl($store->logo_path ?? null),
            'store_type' => 'B2B',
            'channel' => 'b2b',
            'theme_code' => 'wholesale_b2b',
            'is_active' => true,
            'is_platform_principal' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function themePayload(int $storeId): array
    {
        $settings = DB::table('storefront_settings')->where('store_id', $storeId)->first();

        return [
            'code' => (string) ($settings->theme_code ?? 'wholesale_b2b'),
            'primary' => $settings->primary_color ?? '#5D2A91',
            'primary_dark' => $settings->primary_dark_color ?? '#35195E',
            'accent' => $settings->accent_color ?? '#B983F0',
            'background' => $settings->background_color ?? '#FBFAFD',
        ];
    }

    /** @return array<string,mixed> */
    private function brandingPayload(object $store): array
    {
        $settings = DB::table('storefront_settings')->where('store_id', $store->id)->first();

        return [
            'logo_url' => $this->assetUrl($store->logo_path ?? null),
            'address' => $settings->header_address ?? null,
            'custom' => $this->decodedJson($settings->branding ?? null),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function sections(int $storeId): array
    {
        return DB::table('storefront_sections')
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $section): array => [
                'key' => (string) $section->section_key,
                'type' => (string) $section->section_type,
                'title_ar' => $section->title_ar,
                'title_en' => $section->title_en,
                'sort_order' => (int) $section->sort_order,
                'config' => is_string($section->config)
                    ? (json_decode($section->config, true) ?: [])
                    : ((array) ($section->config ?? [])),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function categories(int $storeId): array
    {
        return DB::table('categories')
            ->join('catalogs', 'catalogs.id', '=', 'categories.catalog_id')
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('categories.is_active', true)
            ->orderBy('categories.name')
            ->get([
                'categories.id',
                'categories.name',
                'categories.slug',
                'categories.image_path',
            ])
            ->map(fn (object $category): array => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'slug' => (string) $category->slug,
                'image_url' => $this->assetUrl($category->image_path),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function brands(int $storeId): array
    {
        return DB::table('brands')
            ->join('products', 'products.brand_id', '=', 'brands.id')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join) use ($storeId): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->where('store_products.store_id', '=', $storeId);
            })
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('products.is_active', true)
            ->where('store_products.is_active', true)
            ->where('brands.is_active', true)
            ->orderBy('brands.name')
            ->distinct()
            ->get(['brands.id', 'brands.name', 'brands.image_path'])
            ->map(fn (object $brand): array => [
                'id' => (int) $brand->id,
                'name' => (string) $brand->name,
                'image_url' => $this->assetUrl($brand->image_path),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function wholesaleBanners(int $storeId): array
    {
        $now = now();

        return DB::table('banners')
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('target_type')
                    ->orWhere('target_type', '!=', 'retail_store');
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (object $banner): array => [
                'id' => (int) $banner->id,
                'title' => (string) $banner->title,
                'image_url' => $this->assetUrl($banner->image_path),
                'target_type' => $banner->target_type,
                'target_id' => $banner->target_id === null ? null : (int) $banner->target_id,
                'target_url' => $banner->target_url,
                'sort_order' => (int) $banner->sort_order,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function offers(int $storeId): array
    {
        $now = now();

        return DB::table('promotions')
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderByRaw('ends_at is null desc')
            ->orderBy('ends_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'name', 'type', 'value', 'starts_at', 'ends_at'])
            ->map(static fn (object $offer): array => [
                'id' => (int) $offer->id,
                'name' => (string) $offer->name,
                'type' => (string) $offer->type,
                'value' => $offer->value === null ? null : (float) $offer->value,
                'starts_at' => $offer->starts_at === null ? null : (string) $offer->starts_at,
                'ends_at' => $offer->ends_at === null ? null : (string) $offer->ends_at,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function retailPlacements(Request $request, int $platformStoreId): array
    {
        $countryCode = strtoupper(trim((string) $request->query('country_code', '')));
        $city = trim((string) $request->query('city', ''));
        $area = trim((string) $request->query('area', ''));
        $now = now();

        $query = DB::table('banners')
            ->join('stores', 'stores.id', '=', 'banners.target_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->leftJoin('storefront_settings', 'storefront_settings.store_id', '=', 'stores.id')
            ->where('banners.store_id', $platformStoreId)
            ->where('banners.target_type', 'retail_store')
            ->where('banners.is_active', true)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('banners.starts_at')->orWhere('banners.starts_at', '<=', $now);
            })
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('banners.ends_at')->orWhere('banners.ends_at', '>=', $now);
            })
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C');

        $user = $request->user('sanctum');
        if ($request->bearerToken() !== null && $user instanceof User) {
            $excludedStoreIds = $this->retailMerchants->retailStoreIds($user);
            if ($excludedStoreIds !== []) {
                $query->whereNotIn('stores.id', $excludedStoreIds);
            }
        }

        if ($countryCode !== '' || $city !== '' || $area !== '') {
            $query->where(function (Builder $scope) use ($countryCode, $city, $area): void {
                $scope->whereNotExists(function (Builder $zones): void {
                    $zones->selectRaw('1')
                        ->from('store_service_zones')
                        ->whereColumn('store_service_zones.store_id', 'stores.id')
                        ->where('store_service_zones.is_active', true);
                })->orWhereExists(function (Builder $zones) use ($countryCode, $city, $area): void {
                    $zones->selectRaw('1')
                        ->from('store_service_zones')
                        ->whereColumn('store_service_zones.store_id', 'stores.id')
                        ->where('store_service_zones.is_active', true)
                        ->when($countryCode !== '', fn (Builder $q) => $q->where('store_service_zones.country_code', $countryCode))
                        ->when($city !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($city): void {
                            $q->whereNull('store_service_zones.city')->orWhere('store_service_zones.city', $city);
                        }))
                        ->when($area !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($area): void {
                            $q->whereNull('store_service_zones.area')->orWhere('store_service_zones.area', $area);
                        }));
                });
            });
        }

        return $query
            ->orderBy('banners.sort_order')
            ->orderBy('banners.id')
            ->get([
                'banners.id as placement_id',
                'banners.title',
                'banners.image_path',
                'banners.sort_order',
                'banners.starts_at',
                'banners.ends_at',
                'stores.id as store_id',
                'stores.code',
                'stores.name',
                'stores.logo_path',
                'storefront_settings.theme_code',
                'storefront_settings.header_address',
            ])
            ->map(fn (object $placement): array => [
                'id' => (int) $placement->store_id,
                'store_id' => (int) $placement->store_id,
                'placement_id' => (int) $placement->placement_id,
                'banner_id' => (int) $placement->placement_id,
                'code' => (string) $placement->code,
                'name' => (string) $placement->name,
                'title' => (string) $placement->title,
                'banner_url' => $this->assetUrl($placement->image_path),
                'logo_url' => $this->assetUrl($placement->logo_path),
                'theme_code' => (string) ($placement->theme_code ?? 'retail_grocery'),
                'address' => $placement->header_address,
                'channel' => 'b2c',
                'placement_scope' => 'platform_retail_store',
                'target_type' => 'retail_store',
                'target_id' => (int) $placement->store_id,
                'target_url' => '/retail/'.(int) $placement->store_id.'/home',
                'sort_order' => (int) $placement->sort_order,
                'starts_at' => $placement->starts_at === null ? null : (string) $placement->starts_at,
                'ends_at' => $placement->ends_at === null ? null : (string) $placement->ends_at,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function products(Request $request, int $storeId): array
    {
        $search = trim((string) $request->query('q', ''));
        $categoryId = $request->integer('category_id');

        return $this->productQuery($storeId)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function (Builder $query) use ($like): void {
                    $query->where('products.name', 'like', $like)
                        ->orWhere('products.sku', 'like', $like)
                        ->orWhere('products.barcode', 'like', $like);
                });
            })
            ->when($categoryId > 0, fn (Builder $query) => $query->where('products.category_id', $categoryId))
            ->orderBy('products.name')
            ->limit(100)
            ->get()
            ->map(fn (object $row): array => $this->productPayload($row))
            ->values()
            ->all();
    }

    private function productQuery(int $storeId): Builder
    {
        $tierId = DB::table('b2b_price_tiers')
            ->where('code', 'STANDARD')
            ->value('id');

        if ($tierId === null) {
            $tierId = DB::table('b2b_price_tiers')
                ->orderBy('priority')
                ->orderBy('id')
                ->value('id');
        }

        $query = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join) use ($storeId): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->where('store_products.store_id', '=', $storeId);
            })
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('products.is_active', true)
            ->where('store_products.is_active', true)
            ->whereNotNull('store_products.price');

        if ($tierId !== null) {
            $query->leftJoin('b2b_price_rules', function ($join) use ($storeId, $tierId): void {
                $join->on('b2b_price_rules.product_id', '=', 'products.id')
                    ->where('b2b_price_rules.store_id', '=', $storeId)
                    ->where('b2b_price_rules.price_tier_id', '=', (int) $tierId)
                    ->where('b2b_price_rules.is_active', '=', true);
            });

            return $query->select([
                'products.id',
                'products.sku',
                'products.barcode',
                'products.name',
                'products.description',
                'products.category_id',
                'products.brand_id',
                'store_products.store_id',
                'store_products.price as base_wholesale_price',
                DB::raw('COALESCE(b2b_price_rules.unit_price, store_products.price) as account_price'),
                DB::raw('COALESCE(b2b_price_rules.unit_price, store_products.price) as unit_price'),
                DB::raw('COALESCE(b2b_price_rules.minimum_quantity, 1) as minimum_quantity'),
                DB::raw('COALESCE(b2b_price_rules.ordering_increment, 1) as ordering_increment'),
                DB::raw('COALESCE(b2b_price_rules.pack_size, 1) as pack_size'),
                'b2b_price_rules.case_size',
                'b2b_price_rules.pack_label',
                'b2b_price_rules.retail_reference_price',
                DB::raw('(select path from product_images where product_images.product_id = products.id order by is_primary desc, sort_order asc, id asc limit 1) as primary_image_path'),
                DB::raw('(select name from brands where brands.id = products.brand_id limit 1) as brand_name'),
                DB::raw('(select image_path from brands where brands.id = products.brand_id limit 1) as brand_image_path'),
            ]);
        }

        return $query->select([
            'products.id',
            'products.sku',
            'products.barcode',
            'products.name',
            'products.description',
            'products.category_id',
            'products.brand_id',
            'store_products.store_id',
            'store_products.price as base_wholesale_price',
            'store_products.price as account_price',
            'store_products.price as unit_price',
            DB::raw('1 as minimum_quantity'),
            DB::raw('1 as ordering_increment'),
            DB::raw('1 as pack_size'),
            DB::raw('NULL as case_size'),
            DB::raw('NULL as pack_label'),
            DB::raw('NULL as retail_reference_price'),
            DB::raw('(select path from product_images where product_images.product_id = products.id order by is_primary desc, sort_order asc, id asc limit 1) as primary_image_path'),
            DB::raw('(select name from brands where brands.id = products.brand_id limit 1) as brand_name'),
            DB::raw('(select image_path from brands where brands.id = products.brand_id limit 1) as brand_image_path'),
        ]);
    }

    /** @return array<string,mixed> */
    private function productPayload(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'sku' => (string) $row->sku,
            'barcode' => $row->barcode,
            'name' => (string) $row->name,
            'category_id' => $row->category_id === null ? null : (int) $row->category_id,
            'brand_id' => $row->brand_id === null ? null : (int) $row->brand_id,
            'brand_name' => $row->brand_name,
            'brand_image_url' => $this->assetUrl($row->brand_image_path),
            'base_wholesale_price' => (float) $row->base_wholesale_price,
            'unit_price' => (float) $row->unit_price,
            'account_price' => (float) $row->account_price,
            'retail_reference_price' => $row->retail_reference_price === null ? null : (float) $row->retail_reference_price,
            'minimum_quantity' => (float) $row->minimum_quantity,
            'minimum_order_quantity' => (float) $row->minimum_quantity,
            'ordering_increment' => (float) $row->ordering_increment,
            'pack_size' => (float) $row->pack_size,
            'case_size' => $row->case_size === null ? null : (float) $row->case_size,
            'pack_label' => $row->pack_label,
            'image_url' => $this->assetUrl($row->primary_image_path),
            'store_id' => (int) $row->store_id,
            'currency' => 'EGP',
            ...$this->availability->forStoreProduct((int) $row->store_id, (int) $row->id),
        ];
    }

    /** @return array<string,mixed> */
    private function decodedJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
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
