<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class PublicWholesaleStorefrontController extends Controller
{
    public function home(Request $request): View|RedirectResponse
    {
        $context = $this->context();
        if (! $context['enabled']) {
            return redirect()->route('admin.b2b.login');
        }

        $storeId = $context['store']['id'];
        $brandId = max(0, (int) $request->query('brand', 0));
        $categoryId = max(0, (int) $request->query('category', 0));

        $sections = DB::table('storefront_sections')
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $section): array => [
                'key' => (string) $section->section_key,
                'type' => (string) $section->section_type,
                'title' => app()->getLocale() === 'ar'
                    ? ((string) ($section->title_ar ?: $section->title_en ?: ''))
                    : ((string) ($section->title_en ?: $section->title_ar ?: '')),
                'config' => is_string($section->config)
                    ? (json_decode($section->config, true) ?: [])
                    : (array) ($section->config ?? []),
            ])
            ->values()
            ->all();

        $banners = $this->bannerQuery($storeId)
            ->get(['id', 'title', 'image_path', 'target_type', 'target_id', 'target_url', 'sort_order'])
            ->map(fn (object $banner): array => [
                'id' => (int) $banner->id,
                'title' => (string) $banner->title,
                'image_url' => $this->assetUrl($banner->image_path),
                'target_type' => $banner->target_type,
                'target_id' => $banner->target_id === null ? null : (int) $banner->target_id,
                'target_url' => $this->publicTargetUrl($banner),
            ])
            ->values()
            ->all();

        $categories = DB::table('categories')
            ->join('products', 'products.category_id', '=', 'categories.id')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join) use ($storeId): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->where('store_products.store_id', $storeId)
                    ->where('store_products.is_active', true);
            })
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('products.is_active', true)
            ->where('categories.is_active', true)
            ->select(['categories.id', 'categories.name', 'categories.image_path'])
            ->distinct()
            ->orderBy('categories.name')
            ->limit(24)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'image_url' => $this->assetUrl($row->image_path),
            ])
            ->values()
            ->all();

        $brands = $this->brandsQuery($storeId)
            ->limit(18)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'image_url' => $this->assetUrl($row->image_path),
            ])
            ->values()
            ->all();

        $products = $this->productsQuery($storeId)
            ->when($brandId > 0, fn (Builder $query) => $query->where('products.brand_id', $brandId))
            ->when($categoryId > 0, fn (Builder $query) => $query->where('products.category_id', $categoryId))
            ->orderBy('products.name')
            ->limit(30)
            ->get()
            ->map(fn (object $product): array => $this->productCard($product))
            ->values()
            ->all();

        return view('public.wholesale.home', [
            ...$context,
            'sections' => $sections,
            'banners' => $banners,
            'categories' => $categories,
            'brands' => $brands,
            'products' => $products,
            'activeBrand' => $brandId,
            'activeCategory' => $categoryId,
        ]);
    }

    public function brands(Request $request): View|RedirectResponse
    {
        $context = $this->context();
        if (! $context['enabled']) {
            return redirect()->route('admin.b2b.login');
        }

        $query = trim((string) $request->query('q', ''));
        $brands = $this->brandsQuery($context['store']['id'])
            ->when($query !== '', fn (Builder $builder) => $builder->where('brands.name', 'like', '%'.$query.'%'))
            ->orderBy('brands.name')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'image_url' => $this->assetUrl($row->image_path),
            ])
            ->values()
            ->all();

        return view('public.wholesale.brands', [
            ...$context,
            'brands' => $brands,
            'query' => $query,
        ]);
    }

    public function product(int $product): View|RedirectResponse
    {
        $context = $this->context();
        if (! $context['enabled']) {
            return redirect()->route('admin.b2b.login');
        }

        $storeId = $context['store']['id'];
        $row = $this->productsQuery($storeId)
            ->leftJoin('units', 'units.id', '=', 'products.unit_id')
            ->where('products.id', $product)
            ->select([
                'products.id',
                'products.sku',
                'products.name',
                'products.description',
                'products.category_id',
                'products.brand_id',
                'categories.name as category_name',
                'brands.name as brand_name',
                'brands.image_path as brand_image_path',
                'units.name as unit_name',
                DB::raw('(select path from product_images where product_images.product_id = products.id order by is_primary desc, sort_order asc, id asc limit 1) as primary_image_path'),
            ])
            ->first();

        abort_if($row === null, 404);

        $images = DB::table('product_images')
            ->where('product_id', $product)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('path')
            ->map(fn (mixed $path): ?string => $this->assetUrl($path))
            ->filter()
            ->values()
            ->all();

        $related = $this->productsQuery($storeId)
            ->where('products.id', '!=', $product)
            ->when($row->category_id !== null, fn (Builder $query) => $query->where('products.category_id', (int) $row->category_id))
            ->orderBy('products.name')
            ->limit(8)
            ->get()
            ->map(fn (object $item): array => $this->productCard($item))
            ->values()
            ->all();

        return view('public.wholesale.product', [
            ...$context,
            'product' => [
                'id' => (int) $row->id,
                'sku' => (string) $row->sku,
                'name' => (string) $row->name,
                'description' => trim((string) ($row->description ?? '')),
                'category_name' => $row->category_name,
                'brand_name' => $row->brand_name,
                'brand_image_url' => $this->assetUrl($row->brand_image_path),
                'unit_name' => $row->unit_name,
                'image_url' => $this->assetUrl($row->primary_image_path),
                'images' => $images,
            ],
            'related' => $related,
        ]);
    }

    /** @return array<string,mixed> */
    private function context(): array
    {
        $row = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->leftJoin('storefront_settings', 'storefront_settings.store_id', '=', 'stores.id')
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B')
            ->orderBy('stores.id')
            ->first([
                'stores.id',
                'stores.code',
                'stores.name',
                'stores.logo_path',
                'storefront_settings.header_address',
                'storefront_settings.branding',
            ]);

        if ($row === null) {
            return [
                'enabled' => false,
                'store' => ['id' => 0, 'name' => 'FOODEX', 'logo_url' => null],
                'branding' => [],
                'appUrl' => route('public.mobile-apps.latest', ['app' => 'customer']),
                'adminUrl' => route('admin.b2b.login'),
            ];
        }

        $branding = $this->decodedJson($row->branding ?? null);

        return [
            'enabled' => filter_var($branding['public_web_enabled'] ?? false, FILTER_VALIDATE_BOOL),
            'store' => [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'logo_url' => $this->assetUrl($row->logo_path),
                'address' => $row->header_address,
            ],
            'branding' => $branding,
            'appUrl' => route('public.mobile-apps.latest', ['app' => 'customer']),
            'adminUrl' => route('admin.b2b.login'),
        ];
    }

    private function productsQuery(int $storeId): Builder
    {
        return DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join) use ($storeId): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->where('store_products.store_id', $storeId)
                    ->where('store_products.is_active', true);
            })
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('products.is_active', true)
            ->select([
                'products.id',
                'products.sku',
                'products.name',
                'products.description',
                'products.category_id',
                'products.brand_id',
                'categories.name as category_name',
                'brands.name as brand_name',
                DB::raw('(select path from product_images where product_images.product_id = products.id order by is_primary desc, sort_order asc, id asc limit 1) as primary_image_path'),
            ]);
    }

    private function brandsQuery(int $storeId): Builder
    {
        return DB::table('brands')
            ->join('products', 'products.brand_id', '=', 'brands.id')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join) use ($storeId): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->where('store_products.store_id', $storeId)
                    ->where('store_products.is_active', true);
            })
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('products.is_active', true)
            ->select(['brands.id', 'brands.name', 'brands.image_path'])
            ->distinct();
    }

    private function bannerQuery(int $storeId): Builder
    {
        return DB::table('banners')
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('target_type')->orWhere('target_type', '!=', 'retail_store');
            })
            ->where(function (Builder $query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** @return array<string,mixed> */
    private function productCard(object $product): array
    {
        return [
            'id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'name' => (string) $product->name,
            'category_name' => $product->category_name,
            'brand_name' => $product->brand_name,
            'image_url' => $this->assetUrl($product->primary_image_path),
        ];
    }

    private function publicTargetUrl(object $banner): ?string
    {
        if ($banner->target_type === 'product' && $banner->target_id !== null) {
            return route('public.wholesale.product', ['product' => (int) $banner->target_id]);
        }

        if ($banner->target_type === 'category' && $banner->target_id !== null) {
            return route('public.wholesale.home', ['category' => (int) $banner->target_id]).'#products';
        }

        return null;
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
