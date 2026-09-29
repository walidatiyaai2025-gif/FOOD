<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BannerImageService;
use App\Services\OperationalTenantScope;
use App\Services\StoreLogoService;
use App\Services\WholesalePrincipal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class WholesaleStorefrontDesignController extends Controller
{
    private const THEMES = ['wholesale_b2b'];

    private const SECTION_TYPES = [
        'hero',
        'banner_slider',
        'categories',
        'departments',
        'offers',
        'featured_products',
        'best_sellers',
        'reorder',
        'brands',
        'product_grid',
        'product_carousel',
    ];

    public function __construct(
        private readonly WholesalePrincipal $principal,
        private readonly OperationalTenantScope $scope,
    ) {}

    public function updateSettings(
        Request $request,
        StoreLogoService $logos,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'theme_code' => ['required', 'string', 'in:'.implode(',', self::THEMES)],
            'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'primary_dark_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'background_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'header_address' => ['nullable', 'string', 'max:255'],
            'brand_title_ar' => ['nullable', 'string', 'max:255'],
            'brand_title_en' => ['nullable', 'string', 'max:255'],
            'brand_subtitle_ar' => ['nullable', 'string', 'max:500'],
            'brand_subtitle_en' => ['nullable', 'string', 'max:500'],
            'hero_cta_ar' => ['nullable', 'string', 'max:120'],
            'hero_cta_en' => ['nullable', 'string', 'max:120'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $storeId = (int) $data['store_id'];
        $this->assertWholesaleStore($actor, $storeId);

        $store = DB::table('stores')->where('id', $storeId)->first(['id', 'logo_path']);
        abort_unless($store !== null, 404);

        $settings = DB::table('storefront_settings')->where('store_id', $storeId)->first();
        $branding = $this->decodeJson(is_object($settings) ? ($settings->branding ?? null) : null);
        foreach ([
            'brand_title_ar',
            'brand_title_en',
            'brand_subtitle_ar',
            'brand_subtitle_en',
            'hero_cta_ar',
            'hero_cta_en',
        ] as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value === '') {
                unset($branding[$key]);
            } else {
                $branding[$key] = $value;
            }
        }

        $newLogoPath = null;
        $logo = $request->file('logo');
        if ($logo instanceof UploadedFile) {
            $newLogoPath = $logos->store($logo, $storeId);
        }

        try {
            DB::transaction(function () use ($data, $storeId, $branding, $newLogoPath): void {
                DB::table('storefront_settings')->updateOrInsert(
                    ['store_id' => $storeId],
                    [
                        'theme_code' => 'wholesale_b2b',
                        'primary_color' => $data['primary_color'] ?? null,
                        'primary_dark_color' => $data['primary_dark_color'] ?? null,
                        'accent_color' => $data['accent_color'] ?? null,
                        'background_color' => $data['background_color'] ?? null,
                        'header_address' => $data['header_address'] ?? null,
                        'branding' => $branding === [] ? null : json_encode($branding, JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                if ($newLogoPath !== null) {
                    DB::table('stores')->where('id', $storeId)->update([
                        'logo_path' => $newLogoPath,
                        'updated_at' => now(),
                    ]);
                }
            });
        } catch (Throwable $exception) {
            if ($newLogoPath !== null) {
                $logos->delete($newLogoPath);
            }
            throw $exception;
        }

        if ($newLogoPath !== null) {
            $logos->delete($store->logo_path);
        }

        $audit->record('b2b.storefront.settings.updated', $actor, null, null, [
            'store_id' => $storeId,
            'theme_code' => 'wholesale_b2b',
            'logo_changed' => $newLogoPath !== null,
        ], $request);

        return back()->with('status', $this->msg(
            'تم حفظ هوية وتصميم واجهة متجر الجملة.',
            'Wholesale storefront branding and theme saved.',
        ));
    }

    public function storeSection(Request $request, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->sectionData($request);
        $storeId = (int) $data['store_id'];
        $this->assertWholesaleStore($actor, $storeId);

        if (DB::table('storefront_sections')
            ->where('store_id', $storeId)
            ->where('section_key', $data['section_key'])
            ->exists()) {
            throw ValidationException::withMessages([
                'section_key' => [$this->msg(
                    'مفتاح القسم مستخدم بالفعل داخل واجهة الجملة.',
                    'Section key already exists in the Wholesale storefront.',
                )],
            ]);
        }

        DB::table('storefront_sections')->insert([
            'store_id' => $storeId,
            'section_key' => $data['section_key'],
            'section_type' => $data['section_type'],
            'title_ar' => $data['title_ar'] ?? null,
            'title_en' => $data['title_en'] ?? null,
            'sort_order' => (int) $data['sort_order'],
            'config' => $this->normalizedJson($data['config_json'] ?? null),
            'is_active' => $request->boolean('is_active'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $audit->record('b2b.storefront.section.created', $actor, null, null, [
            'store_id' => $storeId,
            'section_key' => $data['section_key'],
        ], $request);

        return back()->with('status', $this->msg('تمت إضافة قسم واجهة الجملة.', 'Wholesale storefront section added.'));
    }

    public function updateSection(Request $request, int $section, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->actor($request);
        $current = DB::table('storefront_sections')->where('id', $section)->first();
        abort_unless($current !== null, 404);
        $storeId = (int) $current->store_id;
        $this->assertWholesaleStore($actor, $storeId);

        $data = $this->sectionData($request);
        abort_unless((int) $data['store_id'] === $storeId, 404);

        $duplicate = DB::table('storefront_sections')
            ->where('store_id', $storeId)
            ->where('section_key', $data['section_key'])
            ->where('id', '!=', $section)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'section_key' => [$this->msg(
                    'مفتاح القسم مستخدم بالفعل داخل واجهة الجملة.',
                    'Section key already exists in the Wholesale storefront.',
                )],
            ]);
        }

        DB::table('storefront_sections')->where('id', $section)->update([
            'section_key' => $data['section_key'],
            'section_type' => $data['section_type'],
            'title_ar' => $data['title_ar'] ?? null,
            'title_en' => $data['title_en'] ?? null,
            'sort_order' => (int) $data['sort_order'],
            'config' => $this->normalizedJson($data['config_json'] ?? null),
            'is_active' => $request->boolean('is_active'),
            'updated_at' => now(),
        ]);

        $audit->record('b2b.storefront.section.updated', $actor, null, null, [
            'store_id' => $storeId,
            'section_id' => $section,
        ], $request);

        return back()->with('status', $this->msg('تم تحديث قسم واجهة الجملة.', 'Wholesale storefront section updated.'));
    }

    public function destroySection(Request $request, int $section, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->actor($request);
        $current = DB::table('storefront_sections')->where('id', $section)->first();
        abort_unless($current !== null, 404);
        $storeId = (int) $current->store_id;
        $this->assertWholesaleStore($actor, $storeId);

        DB::table('storefront_sections')->where('id', $section)->delete();

        $audit->record('b2b.storefront.section.deleted', $actor, null, [
            'store_id' => $storeId,
            'section_id' => $section,
        ], null, $request);

        return back()->with('status', $this->msg('تم حذف قسم واجهة الجملة.', 'Wholesale storefront section deleted.'));
    }

    public function storeBanner(
        Request $request,
        BannerImageService $images,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $data = $this->bannerData($request, true);
        $storeId = (int) $data['store_id'];
        $this->assertWholesaleStore($actor, $storeId);

        $file = $request->file('banner_image');
        abort_unless($file instanceof UploadedFile, 422);
        $path = $images->store($file, $storeId);

        try {
            unset($data['banner_image']);
            $id = DB::table('banners')->insertGetId([
                ...$data,
                'image_path' => $path,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $images->delete($path);
            throw $exception;
        }

        $audit->record('b2b.storefront.banner.created', $actor, null, null, [
            'store_id' => $storeId,
            'banner_id' => $id,
        ], $request);

        return back()->with('status', $this->msg('تم رفع بانر متجر الجملة.', 'Wholesale storefront banner uploaded.'));
    }

    public function updateBanner(
        Request $request,
        int $banner,
        BannerImageService $images,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $current = DB::table('banners')->where('id', $banner)->first();
        abort_unless($current !== null && $current->store_id !== null, 404);
        $storeId = (int) $current->store_id;
        $this->assertWholesaleStore($actor, $storeId);

        $data = $this->bannerData($request, false);
        abort_unless((int) $data['store_id'] === $storeId, 404);

        $newPath = null;
        if ($request->hasFile('banner_image')) {
            $file = $request->file('banner_image');
            abort_unless($file instanceof UploadedFile, 422);
            $newPath = $images->store($file, $storeId);
        }
        unset($data['banner_image']);

        try {
            DB::table('banners')->where('id', $banner)->update([
                ...$data,
                ...($newPath !== null ? ['image_path' => $newPath] : []),
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            if ($newPath !== null) {
                $images->delete($newPath);
            }
            throw $exception;
        }

        if ($newPath !== null) {
            $images->delete((string) $current->image_path);
        }

        $audit->record('b2b.storefront.banner.updated', $actor, null, null, [
            'store_id' => $storeId,
            'banner_id' => $banner,
        ], $request);

        return back()->with('status', $this->msg('تم تحديث بانر متجر الجملة.', 'Wholesale storefront banner updated.'));
    }

    public function destroyBanner(
        Request $request,
        int $banner,
        BannerImageService $images,
        AuditLogger $audit,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $current = DB::table('banners')->where('id', $banner)->first();
        abort_unless($current !== null && $current->store_id !== null, 404);
        $storeId = (int) $current->store_id;
        $this->assertWholesaleStore($actor, $storeId);

        DB::table('banners')->where('id', $banner)->delete();
        $images->delete((string) $current->image_path);

        $audit->record('b2b.storefront.banner.deleted', $actor, null, [
            'store_id' => $storeId,
            'banner_id' => $banner,
        ], null, $request);

        return back()->with('status', $this->msg('تم حذف بانر متجر الجملة.', 'Wholesale storefront banner deleted.'));
    }

    private function sectionData(Request $request): array
    {
        return $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'section_key' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'section_type' => ['required', 'string', 'in:'.implode(',', self::SECTION_TYPES)],
            'title_ar' => ['nullable', 'string', 'max:255'],
            'title_en' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'config_json' => ['nullable', 'json', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function bannerData(Request $request, bool $imageRequired): array
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'title' => ['required', 'string', 'max:255'],
            'banner_image' => [$imageRequired ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
            'target_ref' => ['nullable', 'string', 'regex:/^(product|category):[1-9][0-9]*$/'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $targetType = null;
        $targetId = null;
        if (! empty($data['target_ref'])) {
            [$targetType, $targetIdText] = explode(':', (string) $data['target_ref'], 2);
            $targetId = (int) $targetIdText;
            $this->assertTarget((int) $data['store_id'], $targetType, $targetId);
        }
        unset($data['target_ref']);

        return [
            ...$data,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_url' => $targetType === 'product'
                ? '/b2b/products/'.$targetId
                : ($targetType === 'category' ? '/b2b/categories/'.$targetId : null),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    private function assertTarget(int $storeId, string $targetType, int $targetId): void
    {
        $table = $targetType === 'product' ? 'products' : 'categories';
        $valid = DB::table($table)
            ->join('catalogs', 'catalogs.id', '=', $table.'.catalog_id')
            ->where($table.'.id', $targetId)
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_migration_quarantine', false)
            ->exists();

        if (! $valid) {
            throw ValidationException::withMessages([
                'target_ref' => [$this->msg(
                    'المنتج أو التصنيف المحدد لا يتبع كتالوج الجملة.',
                    'The selected product or category does not belong to the Wholesale catalog.',
                )],
            ]);
        }
    }

    private function assertWholesaleStore(User $actor, int $storeId): void
    {
        abort_unless($storeId === $this->principal->storeId(), 404);
        $this->scope->assertStore($actor, $storeId, 'settings.manage', 'b2b');
    }

    private function normalizedJson(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return json_encode(json_decode($value, true, 512, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $value): array
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

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function msg(string $ar, string $en): string
    {
        return app()->getLocale() === 'ar' ? $ar : $en;
    }
}
