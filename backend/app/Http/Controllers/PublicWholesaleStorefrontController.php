<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Services\StorefrontRevisionService;
use App\Services\WholesalePrincipal;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

final class PublicWholesaleStorefrontController extends Controller
{
    public function home(Request $request): View|RedirectResponse
    {
        $context = $this->context();
        if (! $context['enabled']) {
            return redirect()->route('admin.b2b.login');
        }

        $storeId = (int) $context['store']['id'];

        return $this->renderHome(
            $request,
            $context,
            $this->liveSections($storeId),
            $this->liveBanners($storeId),
        );
    }

    public function preview(
        Request $request,
        StorefrontRevisionService $revisions,
        WholesalePrincipal $principal,
        OperationalTenantScope $scope,
    ): View {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $storeId = $principal->storeId();
        $scope->assertStore($actor, $storeId, 'settings.view', 'b2b');

        $revision = $revisions->findCurrent($storeId, 'b2b_web', 'draft')
            ?? $revisions->ensurePublished($storeId, 'b2b_web', $actor);
        $payload = (array) $revision->payload;
        $settings = (array) ($payload['settings'] ?? []);
        $branding = (array) ($settings['branding'] ?? []);

        $context = $this->context();
        abort_unless((int) ($context['store']['id'] ?? 0) === $storeId, 404);

        $context['enabled'] = true;
        $context['branding'] = [
            'hero_title_ar' => (string) ($branding['hero_title_ar'] ?? ''),
            'hero_title_en' => (string) ($branding['hero_title_en'] ?? ''),
            'hero_subtitle_ar' => (string) ($branding['hero_subtitle_ar'] ?? ''),
            'hero_subtitle_en' => (string) ($branding['hero_subtitle_en'] ?? ''),
            'hero_cta_ar' => (string) ($branding['hero_cta_ar'] ?? ''),
            'hero_cta_en' => (string) ($branding['hero_cta_en'] ?? ''),
            'facebook_url' => (string) ($branding['facebook_url'] ?? ''),
            'facebook_title_ar' => (string) ($branding['facebook_title_ar'] ?? ''),
            'facebook_title_en' => (string) ($branding['facebook_title_en'] ?? ''),
            'facebook_text_ar' => (string) ($branding['facebook_text_ar'] ?? ''),
            'facebook_text_en' => (string) ($branding['facebook_text_en'] ?? ''),
        ];

        $suffix = app()->getLocale() === 'ar' ? 'ar' : 'en';
        $pageTitle = trim((string) ($branding['meta_title_'.$suffix] ?? ''));
        $metaDescription = trim((string) ($branding['meta_description_'.$suffix] ?? ''));
        $context['pageTitle'] = $pageTitle !== '' ? $pageTitle : __('wholesale_public.page_title');
        $context['metaDescription'] = $metaDescription !== '' ? $metaDescription : __('wholesale_public.meta_description');
        $context['webPreview'] = [
            'active' => true,
            'revision_id' => (string) $revision->public_id,
            'status' => (string) $revision->status,
        ];

        return $this->renderHome(
            $request,
            $context,
            $this->payloadSections((array) ($payload['sections'] ?? [])),
            $this->payloadBanners((array) ($payload['banners'] ?? [])),
        );
    }

    /**
     * @param array<string,mixed> $context
     * @param list<array<string,mixed>> $sections
     * @param list<array<string,mixed>> $banners
     */
    private function renderHome(
        Request $request,
        array $context,
        array $sections,
        array $banners,
    ): View {
        $storeId = (int) $context['store']['id'];
        $brandId = max(0, (int) $request->query('brand', 0));
        $categoryId = max(0, (int) $request->query('category', 0));

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

    /** @return list<array<string,mixed>> */
    private function liveSections(int $storeId): array
    {
        return DB::table('wholesale_web_sections')
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (object $section): array => $this->sectionView([
                'key' => (string) $section->section_key,
                'type' => (string) $section->section_type,
                'title_ar' => $section->title_ar,
                'title_en' => $section->title_en,
                'config' => is_string($section->config)
                    ? (json_decode($section->config, true) ?: [])
                    : (array) ($section->config ?? []),
                'is_active' => (bool) $section->is_active,
            ]))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param list<mixed> $sections
     * @return list<array<string,mixed>>
     */
    private function payloadSections(array $sections): array
    {
        return collect($sections)
            ->filter(fn (mixed $section): bool => is_array($section) && (bool) ($section['is_active'] ?? false))
            ->sortBy(fn (array $section): int => (int) ($section['sort_order'] ?? 0))
            ->map(fn (array $section): ?array => $this->sectionView($section))
            ->filter()
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $section
     * @return array<string,mixed>|null
     */
    private function sectionView(array $section): ?array
    {
        if (! (bool) ($section['is_active'] ?? true)) {
            return null;
        }

        return [
            'key' => (string) ($section['key'] ?? ''),
            'type' => (string) ($section['type'] ?? ''),
            'title' => app()->getLocale() === 'ar'
                ? ((string) (($section['title_ar'] ?? null) ?: ($section['title_en'] ?? null) ?: ''))
                : ((string) (($section['title_en'] ?? null) ?: ($section['title_ar'] ?? null) ?: '')),
            'config' => is_array($section['config'] ?? null) ? $section['config'] : [],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function liveBanners(int $storeId): array
    {
        return $this->bannerQuery($storeId)
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
    }

    /**
     * @param list<mixed> $banners
     * @return list<array<string,mixed>>
     */
    private function payloadBanners(array $banners): array
    {
        $now = now()->timestamp;

        return collect($banners)
            ->filter(function (mixed $banner) use ($now): bool {
                if (! is_array($banner) || ! (bool) ($banner['is_active'] ?? false)) {
                    return false;
                }

                $startsAt = $banner['starts_at'] ?? null;
                $endsAt = $banner['ends_at'] ?? null;
                $starts = $startsAt === null ? null : strtotime((string) $startsAt);
                $ends = $endsAt === null ? null : strtotime((string) $endsAt);

                return ($starts === null || $starts <= $now)
                    && ($ends === null || $ends >= $now)
                    && ($banner['target_type'] ?? null) !== 'retail_store';
            })
            ->sortBy(fn (array $banner): int => (int) ($banner['sort_order'] ?? 0))
            ->map(function (array $banner): array {
                $targetType = $banner['target_type'] ?? null;
                $targetId = isset($banner['target_id']) ? (int) $banner['target_id'] : null;
                $targetUrl = null;
                if ($targetType === 'product' && $targetId !== null) {
                    $targetUrl = route('public.wholesale.product', ['product' => $targetId]);
                } elseif ($targetType === 'category' && $targetId !== null) {
                    $targetUrl = route('public.wholesale.home', ['category' => $targetId]).'#products';
                }

                return [
                    'id' => 0,
                    'title' => (string) ($banner['title'] ?? ''),
                    'image_url' => $this->assetUrl($banner['image_path'] ?? null),
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'target_url' => $targetUrl,
                ];
            })
            ->values()
            ->all();
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
        if (! Schema::hasTable('stores')
            || ! Schema::hasTable('store_types')
            || ! Schema::hasTable('storefront_settings')
            || ! Schema::hasTable('wholesale_web_settings')
            || ! Schema::hasTable('wholesale_web_sections')
            || ! Schema::hasTable('wholesale_web_banners')) {
            return $this->disabledContext();
        }

        $row = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->leftJoin('storefront_settings', 'storefront_settings.store_id', '=', 'stores.id')
            ->leftJoin('wholesale_web_settings', 'wholesale_web_settings.store_id', '=', 'stores.id')
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B')
            ->orderBy('stores.id')
            ->first([
                'stores.id',
                'stores.code',
                'stores.name',
                'stores.logo_path',
                'storefront_settings.header_address',
                'storefront_settings.primary_color',
                'storefront_settings.primary_dark_color',
                'storefront_settings.accent_color',
                'storefront_settings.background_color',
                'storefront_settings.branding as shared_branding',
                'wholesale_web_settings.is_enabled',
                'wholesale_web_settings.hero_title_ar',
                'wholesale_web_settings.hero_title_en',
                'wholesale_web_settings.hero_subtitle_ar',
                'wholesale_web_settings.hero_subtitle_en',
                'wholesale_web_settings.hero_cta_ar',
                'wholesale_web_settings.hero_cta_en',
                'wholesale_web_settings.facebook_url',
                'wholesale_web_settings.facebook_title_ar',
                'wholesale_web_settings.facebook_title_en',
                'wholesale_web_settings.facebook_text_ar',
                'wholesale_web_settings.facebook_text_en',
                'wholesale_web_settings.meta_title_ar',
                'wholesale_web_settings.meta_title_en',
                'wholesale_web_settings.meta_description_ar',
                'wholesale_web_settings.meta_description_en',
            ]);

        if ($row === null) {
            return $this->disabledContext();
        }

        $sharedBranding = $this->decodedJson($row->shared_branding ?? null);
        $branding = [
            'hero_title_ar' => (string) ($row->hero_title_ar ?? ''),
            'hero_title_en' => (string) ($row->hero_title_en ?? ''),
            'hero_subtitle_ar' => (string) ($row->hero_subtitle_ar ?? ''),
            'hero_subtitle_en' => (string) ($row->hero_subtitle_en ?? ''),
            'hero_cta_ar' => (string) ($row->hero_cta_ar ?? ''),
            'hero_cta_en' => (string) ($row->hero_cta_en ?? ''),
            'facebook_url' => (string) ($row->facebook_url ?? ''),
            'facebook_title_ar' => (string) ($row->facebook_title_ar ?? ''),
            'facebook_title_en' => (string) ($row->facebook_title_en ?? ''),
            'facebook_text_ar' => (string) ($row->facebook_text_ar ?? ''),
            'facebook_text_en' => (string) ($row->facebook_text_en ?? ''),
        ];
        $suffix = app()->getLocale() === 'ar' ? 'ar' : 'en';
        $pageTitle = trim((string) ($row->{'meta_title_'.$suffix} ?? ''));
        $metaDescription = trim((string) ($row->{'meta_description_'.$suffix} ?? ''));

        return [
            'enabled' => (bool) ($row->is_enabled ?? false),
            'store' => [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'logo_url' => $this->assetUrl($row->logo_path),
                'address' => $row->header_address,
            ],
            'branding' => $branding,
            'identity' => [
                'primary_color' => (string) ($row->primary_color ?: '#00452F'),
                'primary_dark_color' => (string) ($row->primary_dark_color ?: '#003C2A'),
                'accent_color' => (string) ($row->accent_color ?: '#9BE252'),
                'background_color' => (string) ($row->background_color ?: '#EDF7F1'),
                'brand_title_ar' => (string) ($sharedBranding['brand_title_ar'] ?? ''),
                'brand_title_en' => (string) ($sharedBranding['brand_title_en'] ?? ''),
            ],
            'pageTitle' => $pageTitle !== '' ? $pageTitle : __('wholesale_public.page_title'),
            'metaDescription' => $metaDescription !== '' ? $metaDescription : __('wholesale_public.meta_description'),
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
            ->where('brands.is_active', true)
            ->select(['brands.id', 'brands.name', 'brands.image_path'])
            ->distinct();
    }

    private function bannerQuery(int $storeId): Builder
    {
        return DB::table('wholesale_web_banners')
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

    /** @return array<string,mixed> */
    private function disabledContext(): array
    {
        return [
            'enabled' => false,
            'store' => ['id' => 0, 'name' => 'FOODEX', 'logo_url' => null],
            'branding' => [],
            'identity' => [
                'primary_color' => '#00452F',
                'primary_dark_color' => '#003C2A',
                'accent_color' => '#9BE252',
                'background_color' => '#EDF7F1',
                'brand_title_ar' => 'فوديكس',
                'brand_title_en' => 'FOODEX',
            ],
            'pageTitle' => __('wholesale_public.page_title'),
            'metaDescription' => __('wholesale_public.meta_description'),
            'appUrl' => route('public.mobile-apps.latest', ['app' => 'customer']),
            'adminUrl' => route('admin.b2b.login'),
        ];
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
