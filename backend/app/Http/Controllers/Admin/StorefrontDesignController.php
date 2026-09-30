<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OperationalTenantScope;
use App\Services\StoreLogoService;
use App\Services\StorefrontRevisionService;
use App\Support\TenantContextResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class StorefrontDesignController extends Controller
{
    private const THEMES = [
        'retail_grocery',
        'retail_pharmacy',
        'retail_default',
    ];

    private const SECTION_TYPES = [
        'hero',
        'banner_slider',
        'categories',
        'departments',
        'products',
        'new_arrivals',
        'featured_products',
        'best_sellers',
        'offers',
        'brands',
        'promo_banner',
        'product_grid',
        'product_carousel',
    ];

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
            'logo' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:4096'],
        ]);

        $storeId = (int) $data['store_id'];
        $this->assertStore($actor, $storeId, $request);

        $store = DB::table('stores')->where('id', $storeId)->first(['id', 'logo_path']);
        abort_unless($store !== null, 404);

        $currentSettings = DB::table('storefront_settings')->where('store_id', $storeId)->first();
        $branding = $this->decodeJson(is_object($currentSettings) ? ($currentSettings->branding ?? null) : null);
        foreach (['brand_title_ar', 'brand_title_en', 'brand_subtitle_ar', 'brand_subtitle_en'] as $key) {
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
            app(StorefrontRevisionService::class)->preserveLiveAssetsForScope($storeId, 'b2c');
            $newLogoPath = $logos->store($logo, $storeId);
        }

        try {
            DB::transaction(function () use ($data, $storeId, $branding, $newLogoPath): void {
                DB::table('storefront_settings')->updateOrInsert(
                    ['store_id' => $storeId],
                    [
                        'theme_code' => $data['theme_code'],
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

        $audit->record('b2c.storefront.settings.updated', $actor, null, null, [
            'store_id' => $storeId,
            'theme_code' => $data['theme_code'],
            'logo_changed' => $newLogoPath !== null,
        ], $request);

        $this->syncPublished($actor, $storeId, $request);

        return back()->with('status', $this->msg(
            'تم حفظ هوية وتصميم واجهة المتجر.',
            'Storefront branding and theme saved.',
        ));
    }

    public function storeSection(Request $request, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $this->sectionData($request);
        $storeId = (int) $data['store_id'];
        $this->assertStore($actor, $storeId, $request);

        if (DB::table('storefront_sections')
            ->where('store_id', $storeId)
            ->where('section_key', $data['section_key'])
            ->exists()) {
            throw ValidationException::withMessages([
                'section_key' => [$this->msg(
                    'مفتاح القسم مستخدم بالفعل داخل هذا المتجر.',
                    'Section key already exists in this store.',
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

        $audit->record('b2c.storefront.section.created', $actor, null, null, [
            'store_id' => $storeId,
            'section_key' => $data['section_key'],
        ], $request);

        $this->syncPublished($actor, $storeId, $request);

        return back()->with('status', $this->msg('تمت إضافة قسم الواجهة.', 'Storefront section added.'));
    }

    public function updateSection(Request $request, int $section, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->actor($request);
        $current = DB::table('storefront_sections')->where('id', $section)->first();
        abort_unless($current !== null, 404);
        $storeId = (int) $current->store_id;
        $this->assertStore($actor, $storeId, $request);

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
                    'مفتاح القسم مستخدم بالفعل داخل هذا المتجر.',
                    'Section key already exists in this store.',
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

        $audit->record('b2c.storefront.section.updated', $actor, null, null, [
            'store_id' => $storeId,
            'section_id' => $section,
            'section_key' => $data['section_key'],
        ], $request);

        $this->syncPublished($actor, $storeId, $request);

        return back()->with('status', $this->msg('تم تحديث قسم الواجهة.', 'Storefront section updated.'));
    }

    public function destroySection(Request $request, int $section, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->actor($request);
        $current = DB::table('storefront_sections')->where('id', $section)->first();
        abort_unless($current !== null, 404);
        $storeId = (int) $current->store_id;
        $this->assertStore($actor, $storeId, $request);

        DB::table('storefront_sections')->where('id', $section)->delete();

        $audit->record('b2c.storefront.section.deleted', $actor, null, [
            'store_id' => $storeId,
            'section_id' => $section,
            'section_key' => $current->section_key,
        ], null, $request);

        $this->syncPublished($actor, $storeId, $request);

        return back()->with('status', $this->msg('تم حذف قسم الواجهة.', 'Storefront section deleted.'));
    }

    public function storeZone(Request $request, AuditLogger $audit): RedirectResponse
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
        $this->assertStore($actor, $storeId, $request);
        $country = strtoupper($data['country_code']);
        $city = $this->nullableTrim($data['city'] ?? null);
        $area = $this->nullableTrim($data['area'] ?? null);

        DB::table('store_service_zones')->updateOrInsert(
            [
                'store_id' => $storeId,
                'country_code' => $country,
                'city' => $city,
                'area' => $area,
            ],
            [
                'is_active' => $request->boolean('is_active', true),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $audit->record('b2c.storefront.service_zone.saved', $actor, null, null, [
            'store_id' => $storeId,
            'country_code' => $country,
            'city' => $city,
            'area' => $area,
        ], $request);

        $this->syncPublished($actor, $storeId, $request);

        return back()->with('status', $this->msg('تم حفظ منطقة الخدمة.', 'Service zone saved.'));
    }

    public function destroyZone(Request $request, int $zone, AuditLogger $audit): RedirectResponse
    {
        $actor = $this->actor($request);
        $current = DB::table('store_service_zones')->where('id', $zone)->first();
        abort_unless($current !== null, 404);
        $storeId = (int) $current->store_id;
        $this->assertStore($actor, $storeId, $request);

        DB::table('store_service_zones')->where('id', $zone)->delete();

        $audit->record('b2c.storefront.service_zone.deleted', $actor, null, [
            'store_id' => $storeId,
            'zone_id' => $zone,
        ], null, $request);

        $this->syncPublished($actor, $storeId, $request);

        return back()->with('status', $this->msg('تم حذف منطقة الخدمة.', 'Service zone deleted.'));
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

    private function assertStore(User $actor, int $storeId, Request $request): void
    {
        app(TenantContextResolver::class)->retail(
            $actor,
            $storeId,
            $actor->hasRole('SUPER_ADMIN') && $request->boolean('support_access'),
            $request,
        );
        app(OperationalTenantScope::class)->assertStore($actor, $storeId, 'settings.manage', 'b2c');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
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

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function syncPublished(User $actor, int $storeId, Request $request): void
    {
        app(StorefrontRevisionService::class)->synchronizePublishedFromLive(
            $actor,
            $storeId,
            'b2c',
            $request,
        );
    }

    private function msg(string $ar, string $en): string
    {
        return app()->getLocale() === 'ar' ? $ar : $en;
    }
}
