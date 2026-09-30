<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BannerImageService;
use App\Services\OperationalTenantScope;
use App\Services\StorefrontDraftEditorService;
use App\Services\StoreLogoService;
use App\Services\WholesalePrincipal;
use App\Support\TenantContextResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

final class StorefrontDraftEditorController extends Controller
{
    private const RETAIL_THEMES = ['retail_grocery', 'retail_pharmacy', 'retail_default'];

    private const RETAIL_SECTION_TYPES = [
        'hero', 'banner_slider', 'categories', 'products', 'new_arrivals',
        'featured_products', 'best_sellers', 'offers', 'brands', 'promo_banner',
        'product_grid', 'product_carousel',
    ];

    private const WHOLESALE_SECTION_TYPES = [
        'hero', 'banner_slider', 'categories', 'offers', 'featured_products',
        'best_sellers', 'reorder', 'brands', 'product_grid', 'product_carousel',
    ];

    public function __construct(
        private readonly StorefrontDraftEditorService $drafts,
        private readonly TenantContextResolver $tenantContext,
        private readonly OperationalTenantScope $scope,
        private readonly WholesalePrincipal $principal,
    ) {}

    public function retailSettings(Request $request, StoreLogoService $logos): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->settingsData($request, false);
        $storeId = (int) $data['store_id'];
        $this->authorizeRetail($actor, $storeId, 'settings.manage', $request);

        $logoPath = null;
        $logo = $request->file('logo');
        if ($logo instanceof UploadedFile) {
            $logoPath = $logos->store($logo, $storeId);
        }

        $this->drafts->updateSettings(
            $actor,
            $storeId,
            'b2c',
            $this->settingsPatch($data, false),
            $logoPath,
            $request,
        );

        return back()->with('status', $this->msg(
            'تم حفظ الهوية والتصميم في المسودة فقط. لم يتغير التطبيق المنشور.',
            'Branding and theme saved to Draft only. The published app is unchanged.',
        ));
    }

    public function wholesaleSettings(Request $request, StoreLogoService $logos): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->settingsData($request, true);
        $storeId = (int) $data['store_id'];
        $this->authorizeWholesale($actor, $storeId, 'settings.manage');

        $logoPath = null;
        $logo = $request->file('logo');
        if ($logo instanceof UploadedFile) {
            $logoPath = $logos->store($logo, $storeId);
        }

        $this->drafts->updateSettings(
            $actor,
            $storeId,
            'b2b',
            $this->settingsPatch($data, true),
            $logoPath,
            $request,
        );

        return back()->with('status', $this->msg(
            'تم حفظ هوية متجر الجملة في المسودة فقط.',
            'Wholesale storefront branding saved to Draft only.',
        ));
    }

    public function retailSectionStore(Request $request): RedirectResponse
    {
        return $this->storeSection($request, 'b2c');
    }

    public function wholesaleSectionStore(Request $request): RedirectResponse
    {
        return $this->storeSection($request, 'b2b');
    }

    public function retailSectionUpdate(Request $request, string $section): RedirectResponse
    {
        return $this->updateSection($request, 'b2c', $section);
    }

    public function wholesaleSectionUpdate(Request $request, string $section): RedirectResponse
    {
        return $this->updateSection($request, 'b2b', $section);
    }

    public function retailSectionDestroy(Request $request, string $section): RedirectResponse
    {
        return $this->destroySection($request, 'b2c', $section);
    }

    public function wholesaleSectionDestroy(Request $request, string $section): RedirectResponse
    {
        return $this->destroySection($request, 'b2b', $section);
    }

    public function retailZoneStore(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'city' => ['nullable', 'string', 'max:120'],
            'area' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $storeId = (int) $data['store_id'];
        $this->authorizeRetail($actor, $storeId, 'settings.manage', $request);

        $this->drafts->addZone($actor, $storeId, [
            ...$data,
            'is_active' => $request->boolean('is_active', true),
        ], $request);

        return back()->with('status', $this->msg('تم حفظ منطقة الخدمة في المسودة.', 'Service zone saved to Draft.'));
    }

    public function retailZoneDestroy(Request $request, string $zone): RedirectResponse
    {
        $actor = $this->actor($request);
        $storeId = $request->integer('store_id');
        abort_unless($storeId > 0, 422);
        $this->authorizeRetail($actor, $storeId, 'settings.manage', $request);
        $this->drafts->removeZone($actor, $storeId, $zone, $request);

        return back()->with('status', $this->msg('تم حذف منطقة الخدمة من المسودة.', 'Service zone removed from Draft.'));
    }

    public function retailBannerStore(Request $request, BannerImageService $images): RedirectResponse
    {
        return $this->storeBanner($request, $images, 'b2c');
    }

    public function wholesaleBannerStore(Request $request, BannerImageService $images): RedirectResponse
    {
        return $this->storeBanner($request, $images, 'b2b');
    }

    public function retailBannerUpdate(Request $request, string $banner, BannerImageService $images): RedirectResponse
    {
        return $this->updateBanner($request, $banner, $images, 'b2c');
    }

    public function wholesaleBannerUpdate(Request $request, string $banner, BannerImageService $images): RedirectResponse
    {
        return $this->updateBanner($request, $banner, $images, 'b2b');
    }

    public function retailBannerDestroy(Request $request, string $banner): RedirectResponse
    {
        return $this->destroyBanner($request, $banner, 'b2c');
    }

    public function wholesaleBannerDestroy(Request $request, string $banner): RedirectResponse
    {
        return $this->destroyBanner($request, $banner, 'b2b');
    }

    public function retailPublish(Request $request): RedirectResponse
    {
        return $this->publish($request, 'b2c');
    }

    public function wholesalePublish(Request $request): RedirectResponse
    {
        return $this->publish($request, 'b2b');
    }

    public function retailDiscard(Request $request): RedirectResponse
    {
        return $this->discard($request, 'b2c');
    }

    public function wholesaleDiscard(Request $request): RedirectResponse
    {
        return $this->discard($request, 'b2b');
    }

    private function storeSection(Request $request, string $channel): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->sectionData($request, $channel);
        $storeId = (int) $data['store_id'];
        $this->authorize($actor, $storeId, $channel, 'settings.manage', $request);

        $this->drafts->addSection($actor, $storeId, $channel, [
            'key' => $data['section_key'],
            'type' => $data['section_type'],
            'title_ar' => $data['title_ar'] ?? null,
            'title_en' => $data['title_en'] ?? null,
            'sort_order' => (int) $data['sort_order'],
            'config' => $this->decodeJson((string) ($data['config_json'] ?? '')),
            'is_active' => $request->boolean('is_active'),
        ], $request);

        return back()->with('status', $this->msg('تمت إضافة القسم إلى المسودة.', 'Section added to Draft.'));
    }

    private function updateSection(Request $request, string $channel, string $editorId): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->sectionData($request, $channel);
        $storeId = (int) $data['store_id'];
        $this->authorize($actor, $storeId, $channel, 'settings.manage', $request);

        $this->drafts->updateSection($actor, $storeId, $channel, $editorId, [
            'key' => $data['section_key'],
            'type' => $data['section_type'],
            'title_ar' => $data['title_ar'] ?? null,
            'title_en' => $data['title_en'] ?? null,
            'sort_order' => (int) $data['sort_order'],
            'config' => $this->decodeJson((string) ($data['config_json'] ?? '')),
            'is_active' => $request->boolean('is_active'),
        ], $request);

        return back()->with('status', $this->msg('تم تحديث القسم في المسودة.', 'Section updated in Draft.'));
    }

    private function destroySection(Request $request, string $channel, string $editorId): RedirectResponse
    {
        $actor = $this->actor($request);
        $storeId = $request->integer('store_id');
        abort_unless($storeId > 0, 422);
        $this->authorize($actor, $storeId, $channel, 'settings.manage', $request);
        $this->drafts->removeSection($actor, $storeId, $channel, $editorId, $request);

        return back()->with('status', $this->msg('تم حذف القسم من المسودة.', 'Section removed from Draft.'));
    }

    private function storeBanner(Request $request, BannerImageService $images, string $channel): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->bannerData($request, true, $channel);
        $storeId = (int) $data['store_id'];
        $ability = $channel === 'b2c' ? 'promotions.manage' : 'settings.manage';
        $this->authorize($actor, $storeId, $channel, $ability, $request);

        $file = $request->file('banner_image');
        abort_unless($file instanceof UploadedFile, 422);
        $path = $images->store($file, $storeId);

        $this->drafts->addBanner($actor, $storeId, $channel, [
            ...$data,
            'image_path' => $path,
        ], $request);

        return back()->with('status', $this->msg('تمت إضافة البانر إلى المسودة.', 'Banner added to Draft.'));
    }

    private function updateBanner(
        Request $request,
        string $editorId,
        BannerImageService $images,
        string $channel,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $data = $this->bannerData($request, false, $channel);
        $storeId = (int) $data['store_id'];
        $ability = $channel === 'b2c' ? 'promotions.manage' : 'settings.manage';
        $this->authorize($actor, $storeId, $channel, $ability, $request);

        $newPath = null;
        $file = $request->file('banner_image');
        if ($file instanceof UploadedFile) {
            $newPath = $images->store($file, $storeId);
        }

        $this->drafts->updateBanner($actor, $storeId, $channel, $editorId, [
            ...$data,
            ...($newPath !== null ? ['image_path' => $newPath] : []),
        ], $request);

        return back()->with('status', $this->msg('تم تحديث البانر في المسودة.', 'Banner updated in Draft.'));
    }

    private function destroyBanner(Request $request, string $editorId, string $channel): RedirectResponse
    {
        $actor = $this->actor($request);
        $storeId = $request->integer('store_id');
        abort_unless($storeId > 0, 422);
        $ability = $channel === 'b2c' ? 'promotions.manage' : 'settings.manage';
        $this->authorize($actor, $storeId, $channel, $ability, $request);
        $this->drafts->removeBanner($actor, $storeId, $channel, $editorId, $request);

        return back()->with('status', $this->msg('تم حذف البانر من المسودة.', 'Banner removed from Draft.'));
    }

    private function publish(Request $request, string $channel): RedirectResponse
    {
        $actor = $this->actor($request);
        $storeId = $request->integer('store_id');
        abort_unless($storeId > 0, 422);
        $this->authorize($actor, $storeId, $channel, 'settings.manage', $request);
        abort_unless($actor->hasPermission('app_preview.publish', $storeId), 403);

        $this->drafts->publish($actor, $storeId, $channel, $request);

        return back()->with('status', $this->msg(
            'تم نشر المسودة. التطبيق الحقيقي يستخدم الآن هذه النسخة.',
            'Draft published. The real app now uses this revision.',
        ));
    }

    private function discard(Request $request, string $channel): RedirectResponse
    {
        $actor = $this->actor($request);
        $storeId = $request->integer('store_id');
        abort_unless($storeId > 0, 422);
        $this->authorize($actor, $storeId, $channel, 'settings.manage', $request);
        abort_unless($actor->hasPermission('app_preview.publish', $storeId), 403);

        $this->drafts->discard($actor, $storeId, $channel, $request);

        return back()->with('status', $this->msg(
            'تم إرجاع المسودة إلى النسخة المنشورة الحالية.',
            'Draft reset to the current Published revision.',
        ));
    }

    /** @return array<string,mixed> */
    private function settingsData(Request $request, bool $wholesale): array
    {
        $themes = $wholesale ? ['wholesale_b2b'] : self::RETAIL_THEMES;

        return $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'theme_code' => ['required', 'string', 'in:'.implode(',', $themes)],
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
    }

    /** @param array<string,mixed> $data
     *  @return array<string,mixed>
     */
    private function settingsPatch(array $data, bool $wholesale): array
    {
        $brandingKeys = ['brand_title_ar', 'brand_title_en', 'brand_subtitle_ar', 'brand_subtitle_en'];
        if ($wholesale) {
            $brandingKeys[] = 'hero_cta_ar';
            $brandingKeys[] = 'hero_cta_en';
        }

        $branding = [];
        foreach ($brandingKeys as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            $branding[$key] = $value === '' ? null : $value;
        }

        return [
            'theme_code' => $wholesale ? 'wholesale_b2b' : (string) $data['theme_code'],
            'primary_color' => $data['primary_color'] ?? null,
            'primary_dark_color' => $data['primary_dark_color'] ?? null,
            'accent_color' => $data['accent_color'] ?? null,
            'background_color' => $data['background_color'] ?? null,
            'header_address' => $data['header_address'] ?? null,
            'branding' => $branding,
        ];
    }

    /** @return array<string,mixed> */
    private function sectionData(Request $request, string $channel): array
    {
        $types = $channel === 'b2b' ? self::WHOLESALE_SECTION_TYPES : self::RETAIL_SECTION_TYPES;

        return $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'section_key' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'section_type' => ['required', 'string', 'in:'.implode(',', $types)],
            'title_ar' => ['nullable', 'string', 'max:255'],
            'title_en' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'config_json' => ['nullable', 'json', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /** @return array<string,mixed> */
    private function bannerData(Request $request, bool $imageRequired, string $channel): array
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
        }

        return [
            'store_id' => (int) $data['store_id'],
            'title' => (string) $data['title'],
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_url' => $targetType === 'product'
                ? ($channel === 'b2b' ? '/b2b/products/' : '/products/').$targetId
                : ($targetType === 'category'
                    ? ($channel === 'b2b' ? '/b2b/categories/' : '/categories/').$targetId
                    : null),
            'sort_order' => (int) $data['sort_order'],
            'is_active' => $request->boolean('is_active'),
        ];
    }

    private function authorize(
        User $actor,
        int $storeId,
        string $channel,
        string $ability,
        Request $request,
    ): void {
        if ($channel === 'b2b') {
            $this->authorizeWholesale($actor, $storeId, $ability);

            return;
        }

        $this->authorizeRetail($actor, $storeId, $ability, $request);
    }

    private function authorizeRetail(
        User $actor,
        int $storeId,
        string $ability,
        Request $request,
    ): void {
        $this->tenantContext->retail(
            $actor,
            $storeId,
            $actor->hasRole('SUPER_ADMIN') && $request->boolean('support_access'),
            $request,
        );
        $this->scope->assertStore($actor, $storeId, $ability, 'b2c');
    }

    private function authorizeWholesale(User $actor, int $storeId, string $ability): void
    {
        abort_unless($storeId === $this->principal->storeId(), 404);
        $this->scope->assertStore($actor, $storeId, $ability, 'b2b');
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

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
