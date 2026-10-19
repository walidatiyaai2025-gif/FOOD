<?php

namespace App\Http\Controllers\Admin;

use App\Domain\RestaurantSaaS\RestaurantOnboardingService;
use App\Domain\RestaurantSaaS\RestaurantSaasStoreAccess;
use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Models\SmartOrderingMenu;
use App\Domain\SmartOrdering\Models\SmartOrderingMenuCategory;
use App\Domain\SmartOrdering\Models\SmartOrderingMenuItem;
use App\Domain\SmartOrdering\Models\SmartOrderingModifierGroup;
use App\Domain\SmartOrdering\Models\SmartOrderingModifierOption;
use App\Domain\SmartOrdering\Models\SmartOrderingRevision;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\SmartOrderingMenuRevisionService;
use App\Domain\SmartOrdering\SmartOrderingMenuWriteService;
use App\Domain\SmartOrdering\SmartOrderingPriceResolver;
use App\Domain\SmartOrdering\SmartOrderingRevisionService;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Domain\SmartOrdering\Themes\RestaurantTemplateSelectionService;
use App\Domain\SmartOrdering\Themes\ThemeRegistry;
use App\Http\Controllers\Controller;
use App\Models\SmartOrderingQrCode;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CommerceQuoteService;
use App\Services\SmartOrderingQrAssetService;
use App\Services\SmartOrderingQrManager;
use App\Support\DisplayNumber;
use App\Support\TenantContextResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

final class SmartOrderingMenuAdminController extends Controller
{
    public function index(
        Request $request,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingPriceResolver $prices,
        CommerceQuoteService $quotes,
        ThemeRegistry $themes,
        RestaurantTemplateSelectionService $templateSelection,
    ): View {
        [$actor, $site, $stores] = $this->context($request, $tenants, $sites);
        $draft = $revisions->draft($actor, $site, $request);
        $publishedRevision = $revisions->published($site);
        $scheduledRevision = $revisions->scheduled($site);

        $setupFlow = ! $request->boolean('legacy')
            && ($request->has('setup') || $request->boolean('review') || $request->boolean('guided'));
        $setupSteps = ['menu', 'design', 'identity', 'review', 'publish'];
        $requestedSetupStep = $request->has('setup')
            ? strtolower(trim((string) $request->query('setup')))
            : ($request->boolean('review') ? 'review' : 'menu');
        $setupStep = in_array($requestedSetupStep, $setupSteps, true) ? $requestedSetupStep : 'menu';

        $draftPayload = (array) $draft->payload;
        $setupContent = is_array($draftPayload['content'] ?? null) ? $draftPayload['content'] : [];
        $setupIdentity = is_array($setupContent['identity'] ?? null) ? $setupContent['identity'] : [];
        $setupSections = is_array($setupContent['sections'] ?? null) ? $setupContent['sections'] : [];
        $selectedTemplate = $templateSelection->selected($draft);
        $setupCurrentTheme = (string) ($selectedTemplate['id'] ?? $themes->resolveProduction(null));
        $setupThemes = $themes->production();

        $menuIndex = SmartOrderingMenu::query()
            ->where('site_id', $site->id)
            ->where('revision_id', $draft->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'public_id', 'name_ar', 'name_en', 'sort_order', 'is_default', 'is_visible']);

        $reviewMode = $request->boolean('review') || ($setupFlow && in_array($setupStep, ['review', 'publish'], true));
        $requestedMenuId = $request->integer('menu_id');
        $focusedMenuId = $menuIndex->contains(fn (SmartOrderingMenu $menu): bool => (int) $menu->id === $requestedMenuId)
            ? $requestedMenuId
            : ($menuIndex->first() === null ? null : (int) $menuIndex->first()->id);

        $menusQuery = SmartOrderingMenu::query()
            ->where('site_id', $site->id)
            ->where('revision_id', $draft->id)
            ->with([
                'categories' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                'categories.items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                'categories.items.modifierGroups' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                'categories.items.modifierGroups.options' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            ])
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($reviewMode === false && $setupFlow === false && $focusedMenuId !== null) {
            $menusQuery->whereKey($focusedMenuId);
        }

        $menus = $menusQuery->get();
        $itemMenuIds = [];
        foreach ($menus as $menu) {
            foreach ($menu->categories as $category) {
                if (! $category instanceof SmartOrderingMenuCategory) {
                    continue;
                }

                foreach ($category->items as $item) {
                    if (! $item instanceof SmartOrderingMenuItem) {
                        continue;
                    }

                    $itemMenuIds[(int) $item->id] = (int) $menu->id;
                }
            }
        }

        $focusQuery = trim((string) $request->query('q', ''));
        $focusResults = $this->focusResults($site, $draft, $focusQuery);

        $menuIds = $menus->pluck('id');
        $menuBranchIds = DB::table('smart_ordering_menu_branches')
            ->whereIn('menu_id', $menuIds)
            ->get()
            ->groupBy('menu_id')
            ->map(fn ($rows) => $rows->pluck('branch_id')->map(fn ($id): int => (int) $id)->all())
            ->all();

        $branches = SmartOrderingBranch::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name_en')
            ->get();

        $publishTimezone = (string) ($branches->first()?->timezone ?: config('app.timezone', 'UTC'));
        $scheduleMinimum = CarbonImmutable::now($publishTimezone)->addMinute()->format('Y-m-d\\TH:i');

        $categoryIds = SmartOrderingMenuCategory::query()
            ->whereIn('menu_id', $menuIds)
            ->pluck('id');
        $itemIds = SmartOrderingMenuItem::query()
            ->whereIn('category_id', $categoryIds)
            ->pluck('id');
        $groupIds = SmartOrderingModifierGroup::query()
            ->whereIn('item_id', $itemIds)
            ->pluck('id');
        $sourceIds = SmartOrderingMenuItem::query()
            ->whereIn('id', $itemIds)
            ->whereNotNull('source_product_id')
            ->pluck('source_product_id')
            ->merge(
                SmartOrderingModifierOption::query()
                    ->whereIn('group_id', $groupIds)
                    ->whereNotNull('source_product_id')
                    ->pluck('source_product_id')
            )
            ->unique()
            ->values();

        $checkoutCurrency = $quotes->currencyForStore((int) $site->store_id);
        $setupMenuCount = SmartOrderingMenu::query()
            ->where('site_id', $site->id)
            ->where('revision_id', $draft->id)
            ->count();
        $setupCategoryCount = SmartOrderingMenuCategory::query()
            ->where('site_id', $site->id)
            ->where('revision_id', $draft->id)
            ->count();
        $setupItemCount = SmartOrderingMenuItem::query()
            ->where('site_id', $site->id)
            ->where('revision_id', $draft->id)
            ->count();
        $setupPreviewUrl = route('admin.smart-ordering.appearance.preview', [
            'site' => $site->public_id,
            'theme' => $setupCurrentTheme,
            'revision' => 'draft',
            'lang' => app()->getLocale() === 'ar' ? 'ar' : 'en',
            'appearance' => (($setupThemes[$setupCurrentTheme]['dark'] ?? false) ? 'dark' : 'light'),
        ]);
        $moneyStep = DisplayNumber::moneyStep($checkoutCurrency);
        $editableItems = SmartOrderingMenuItem::query()->whereIn('id', $itemIds)->get();
        $resolvedPrices = $prices->displayPrices($site, $editableItems);
        $itemPrices = collect($resolvedPrices['prices'])
            ->map(fn (array $resolved): array => array_merge($resolved, [
                'currency' => $checkoutCurrency,
                'display' => DisplayNumber::money($resolved['amount'], $checkoutCurrency),
            ]))
            ->all();
        $itemPriceErrors = $resolvedPrices['errors'];

        $productLabels = DB::table('products')
            ->whereIn('id', $sourceIds)
            ->get(['id', 'name', 'sku'])
            ->mapWithKeys(fn ($product): array => [
                (int) $product->id => trim((string) $product->name.($product->sku ? ' · '.$product->sku : '')),
            ])
            ->all();

        return view('admin.smart-ordering-menu', [
            'stores' => $stores,
            'site' => $site,
            'draft' => $draft,
            'publishedRevision' => $publishedRevision,
            'publicMenuUrl' => $publishedRevision instanceof SmartOrderingRevision && (bool) $site->master_enabled
                ? route('public.smart-ordering.storefront', ['siteKey' => $site->slug])
                : null,
            'scheduledRevision' => $scheduledRevision,
            'publishTimezone' => $publishTimezone,
            'scheduleMinimum' => $scheduleMinimum,
            'menus' => $menus,
            'menuIndex' => $menuIndex,
            'focusedMenuId' => $focusedMenuId,
            'reviewMode' => $reviewMode,
            'focusQuery' => $focusQuery,
            'focusResults' => $focusResults,
            'itemMenuIds' => $itemMenuIds,
            'branches' => $branches,
            'menuBranchIds' => $menuBranchIds,
            'productLabels' => $productLabels,
            'itemPrices' => $itemPrices,
            'itemPriceErrors' => $itemPriceErrors,
            'checkoutCurrency' => $checkoutCurrency,
            'moneyStep' => $moneyStep,
            'setupFlow' => $setupFlow,
            'setupStep' => $setupStep,
            'setupThemes' => $setupThemes,
            'setupCurrentTheme' => $setupCurrentTheme,
            'setupIdentity' => $setupIdentity,
            'setupSections' => $setupSections,
            'setupMenuCount' => $setupMenuCount,
            'setupCategoryCount' => $setupCategoryCount,
            'setupItemCount' => $setupItemCount,
            'setupPreviewUrl' => $setupPreviewUrl,
            'canManageSetup' => $actor->hasPermission('smart_ordering.manage', (int) $site->store_id),
            'canPublishSetup' => $actor->hasPermission('smart_ordering.publish', (int) $site->store_id),
            'supportAccess' => $actor->hasRole('SUPER_ADMIN'),
        ]);
    }

    public function products(
        Request $request,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        CommerceQuoteService $quotes,
    ): JsonResponse {
        [, $site] = $this->context($request, $tenants, $sites);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $query = trim((string) ($data['q'] ?? ''));

        $products = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join) use ($site): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->where('store_products.store_id', '=', $site->store_id);
            })
            ->where('catalogs.store_id', $site->store_id)
            ->where('catalogs.channel', 'b2c')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('products.is_active', true)
            ->where('store_products.is_active', true)
            ->when($query !== '', function ($builder) use ($query): void {
                $needle = '%'.$query.'%';
                $builder->where(function ($nested) use ($needle): void {
                    $nested->where('products.name', 'like', $needle)
                        ->orWhere('products.sku', 'like', $needle)
                        ->orWhere('products.barcode', 'like', $needle);
                });
            })
            ->orderBy('products.name')
            ->limit(25)
            ->get(['products.id', 'products.name', 'products.sku', 'products.barcode', 'store_products.price']);

        $checkoutCurrency = $quotes->currencyForStore((int) $site->store_id);

        return response()->json([
            'currency' => $checkoutCurrency,
            'money_step' => DisplayNumber::moneyStep($checkoutCurrency),
            'products' => $products->map(fn ($product): array => [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'price' => $product->price === null ? null : round((float) $product->price, 3),
                'currency' => $checkoutCurrency,
                'display_price' => $product->price === null
                    ? null
                    : DisplayNumber::money((float) $product->price, $checkoutCurrency),
            ])->values(),
        ]);
    }

    public function storeMenu(
        Request $request,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingMenuWriteService $writer,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $draft = $revisions->draft($actor, $site, $request);
        $data = $this->menuData($request, $site);
        $writer->createMenu($actor, $site, $draft, $data, $request);

        return back()->with('status', __('smart_ordering_menu.saved'));
    }

    public function quickCreate(
        Request $request,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingMenuWriteService $writer,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $draft = $revisions->draft($actor, $site, $request);

        $data = $request->validate([
            'menu_name_ar' => ['nullable', 'string', 'max:255'],
            'menu_name_en' => ['nullable', 'string', 'max:255'],
            'menu_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],

            'categories' => ['required', 'array', 'min:1', 'max:20'],
            'categories.*.name_ar' => ['nullable', 'string', 'max:255'],
            'categories.*.name_en' => ['nullable', 'string', 'max:255'],
            'categories.*.image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],

            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.category_key' => ['required', 'string', 'max:64'],
            'items.*.name_ar' => ['nullable', 'string', 'max:255'],
            'items.*.name_en' => ['nullable', 'string', 'max:255'],
            'items.*.price' => ['required', 'numeric', 'min:0', 'max:99999999.999'],
            'items.*.image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'items.*.featured' => ['nullable', 'boolean'],
        ]);

        $menuNameAr = trim((string) ($data['menu_name_ar'] ?? ''));
        $menuNameEn = trim((string) ($data['menu_name_en'] ?? ''));
        if ($menuNameAr === '' && $menuNameEn === '') {
            throw ValidationException::withMessages([
                'menu_name_ar' => [app()->getLocale() === 'ar' ? 'اكتب اسم المنيو.' : 'Enter a menu name.'],
            ]);
        }

        $categories = (array) ($data['categories'] ?? []);
        foreach ($categories as $key => $category) {
            $nameAr = trim((string) ($category['name_ar'] ?? ''));
            $nameEn = trim((string) ($category['name_en'] ?? ''));
            if ($nameAr === '' && $nameEn === '') {
                throw ValidationException::withMessages([
                    "categories.$key.name_ar" => [app()->getLocale() === 'ar' ? 'اكتب اسم التصنيف.' : 'Enter a category name.'],
                ]);
            }
        }

        $items = (array) ($data['items'] ?? []);
        foreach ($items as $key => $item) {
            $categoryKey = (string) ($item['category_key'] ?? '');
            if ($categoryKey === '' || ! array_key_exists($categoryKey, $categories)) {
                throw ValidationException::withMessages([
                    "items.$key.category_key" => [app()->getLocale() === 'ar' ? 'اختر تصنيفاً صحيحاً للصنف.' : 'Choose a valid category for the item.'],
                ]);
            }

            $nameAr = trim((string) ($item['name_ar'] ?? ''));
            $nameEn = trim((string) ($item['name_en'] ?? ''));
            if ($nameAr === '' && $nameEn === '') {
                throw ValidationException::withMessages([
                    "items.$key.name_ar" => [app()->getLocale() === 'ar' ? 'اكتب اسم الصنف.' : 'Enter the item name.'],
                ]);
            }
        }

        $existingMenus = SmartOrderingMenu::query()
            ->where('site_id', $site->id)
            ->where('revision_id', $draft->id)
            ->count();

        DB::transaction(function () use (
            $actor,
            $site,
            $draft,
            $writer,
            $request,
            $menuNameAr,
            $menuNameEn,
            $categories,
            $items,
            $existingMenus,
        ): void {
            $menuImagePath = $request->hasFile('menu_image')
                ? $request->file('menu_image')->store('smart-ordering/'.$site->public_id.'/menus', 'public')
                : null;

            $menu = $writer->createMenu($actor, $site, $draft, [
                'name_ar' => $menuNameAr !== '' ? $menuNameAr : null,
                'name_en' => $menuNameEn !== '' ? $menuNameEn : $menuNameAr,
                'description_ar' => null,
                'description_en' => null,
                'image_path' => $menuImagePath,
                'is_default' => $existingMenus === 0,
                'is_visible' => true,
                'sort_order' => $existingMenus * 10,
                'branch_ids' => [],
            ], $request);

            $createdCategories = [];
            $categoryOrder = 0;
            foreach ($categories as $categoryKey => $category) {
                $nameAr = trim((string) ($category['name_ar'] ?? ''));
                $nameEn = trim((string) ($category['name_en'] ?? ''));
                $categoryImage = $request->file("categories.$categoryKey.image");
                $categoryImagePath = $categoryImage
                    ? $categoryImage->store('smart-ordering/'.$site->public_id.'/categories', 'public')
                    : null;

                $createdCategories[(string) $categoryKey] = $writer->createCategory($actor, $site, $draft, $menu, [
                    'name_ar' => $nameAr !== '' ? $nameAr : null,
                    'name_en' => $nameEn !== '' ? $nameEn : $nameAr,
                    'description_ar' => null,
                    'description_en' => null,
                    'icon' => null,
                    'image_path' => $categoryImagePath,
                    'is_visible' => true,
                    'sold_out' => false,
                    'sort_order' => $categoryOrder * 10,
                ], $request);
                $categoryOrder++;
            }

            $itemOrderByCategory = [];
            foreach ($items as $itemKey => $item) {
                $categoryKey = (string) $item['category_key'];
                $category = $createdCategories[$categoryKey];
                $nameAr = trim((string) ($item['name_ar'] ?? ''));
                $nameEn = trim((string) ($item['name_en'] ?? ''));
                $itemImage = $request->file("items.$itemKey.image");
                $itemImagePath = $itemImage
                    ? $itemImage->store('smart-ordering/'.$site->public_id.'/items', 'public')
                    : null;

                $itemOrderByCategory[$categoryKey] = ($itemOrderByCategory[$categoryKey] ?? 0);
                $sortOrder = $itemOrderByCategory[$categoryKey] * 10;
                $itemOrderByCategory[$categoryKey]++;

                $writer->createItem($actor, $site, $draft, $category, [
                    'source_product_id' => null,
                    'name_ar' => $nameAr !== '' ? $nameAr : null,
                    'name_en' => $nameEn !== '' ? $nameEn : $nameAr,
                    'description_ar' => null,
                    'description_en' => null,
                    'image_path' => $itemImagePath,
                    'gallery' => [],
                    'price_override' => (float) $item['price'],
                    'featured' => (bool) ($item['featured'] ?? false),
                    'best_seller' => false,
                    'is_new' => false,
                    'is_visible' => true,
                    'sold_out' => false,
                    'prep_time_minutes' => null,
                    'calories' => null,
                    'allergens' => [],
                    'spicy_level' => 0,
                    'vegetarian' => false,
                    'allow_customer_notes' => true,
                    'removable_ingredients' => [],
                    'sort_order' => $sortOrder,
                ], $request);
            }
        });

        if ($request->input('return_to') === 'menu_setup') {
            return redirect()->route('admin.smart-ordering.menu.index', [
                'store_id' => $site->store_id,
                'setup' => 'menu',
            ])->with('status', app()->getLocale() === 'ar' ? 'تم حفظ المنيو كمسودة.' : 'Menu saved as draft.');
        }

        return redirect()->to(
            route('admin.smart-ordering.appearance.index', [
                'site' => $site->public_id,
                'store_id' => $site->store_id,
                'revision' => 'draft',
            ]).'#menu-builder'
        )->with('status', app()->getLocale() === 'ar' ? 'تم حفظ المنيو كمسودة.' : 'Menu saved as draft.');
    }

    public function quickUpdate(
        Request $request,
        SmartOrderingMenu $menu,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingMenuWriteService $writer,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $draft = $revisions->draft($actor, $site, $request);

        abort_unless(
            (int) $menu->site_id === (int) $site->id
            && (int) $menu->revision_id === (int) $draft->id,
            404,
        );

        $data = $request->validate([
            'menu_name_ar' => ['nullable', 'string', 'max:255'],
            'menu_name_en' => ['nullable', 'string', 'max:255'],
            'menu_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],

            'categories' => ['required', 'array', 'min:1', 'max:20'],
            'categories.*.id' => ['nullable', 'integer', 'min:1'],
            'categories.*.name_ar' => ['nullable', 'string', 'max:255'],
            'categories.*.name_en' => ['nullable', 'string', 'max:255'],
            'categories.*.image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],

            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['nullable', 'integer', 'min:1'],
            'items.*.category_key' => ['required', 'string', 'max:64'],
            'items.*.name_ar' => ['nullable', 'string', 'max:255'],
            'items.*.name_en' => ['nullable', 'string', 'max:255'],
            'items.*.price' => ['required', 'numeric', 'min:0', 'max:99999999.999'],
            'items.*.image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'items.*.featured' => ['nullable', 'boolean'],
        ]);

        $menuNameAr = trim((string) ($data['menu_name_ar'] ?? ''));
        $menuNameEn = trim((string) ($data['menu_name_en'] ?? ''));
        if ($menuNameAr === '' && $menuNameEn === '') {
            throw ValidationException::withMessages([
                'menu_name_ar' => [app()->getLocale() === 'ar' ? 'اكتب اسم المنيو.' : 'Enter a menu name.'],
            ]);
        }

        $categories = (array) ($data['categories'] ?? []);
        foreach ($categories as $key => $category) {
            $nameAr = trim((string) ($category['name_ar'] ?? ''));
            $nameEn = trim((string) ($category['name_en'] ?? ''));
            if ($nameAr === '' && $nameEn === '') {
                throw ValidationException::withMessages([
                    "categories.$key.name_ar" => [app()->getLocale() === 'ar' ? 'اكتب اسم التصنيف.' : 'Enter a category name.'],
                ]);
            }
        }

        $items = (array) ($data['items'] ?? []);
        foreach ($items as $key => $item) {
            $categoryKey = (string) ($item['category_key'] ?? '');
            if ($categoryKey === '' || ! array_key_exists($categoryKey, $categories)) {
                throw ValidationException::withMessages([
                    "items.$key.category_key" => [app()->getLocale() === 'ar' ? 'اختر تصنيفاً صحيحاً للصنف.' : 'Choose a valid category for the item.'],
                ]);
            }

            $nameAr = trim((string) ($item['name_ar'] ?? ''));
            $nameEn = trim((string) ($item['name_en'] ?? ''));
            if ($nameAr === '' && $nameEn === '') {
                throw ValidationException::withMessages([
                    "items.$key.name_ar" => [app()->getLocale() === 'ar' ? 'اكتب اسم الصنف.' : 'Enter the item name.'],
                ]);
            }
        }

        $existingCategories = SmartOrderingMenuCategory::query()
            ->where('site_id', $site->id)
            ->where('revision_id', $draft->id)
            ->where('menu_id', $menu->id)
            ->get()
            ->keyBy('id');
        $existingItems = SmartOrderingMenuItem::query()
            ->where('site_id', $site->id)
            ->where('revision_id', $draft->id)
            ->whereIn('category_id', $existingCategories->keys())
            ->get()
            ->keyBy('id');

        foreach ($categories as $key => $category) {
            $categoryId = (int) ($category['id'] ?? 0);
            if ($categoryId > 0 && ! $existingCategories->has($categoryId)) {
                throw ValidationException::withMessages([
                    "categories.$key.id" => [app()->getLocale() === 'ar' ? 'التصنيف المحدد لا يتبع هذه المنيو.' : 'The selected category does not belong to this menu.'],
                ]);
            }
        }
        foreach ($items as $key => $item) {
            $itemId = (int) ($item['id'] ?? 0);
            if ($itemId > 0 && ! $existingItems->has($itemId)) {
                throw ValidationException::withMessages([
                    "items.$key.id" => [app()->getLocale() === 'ar' ? 'الصنف المحدد لا يتبع هذه المنيو.' : 'The selected item does not belong to this menu.'],
                ]);
            }
        }

        DB::transaction(function () use (
            $actor,
            $site,
            $draft,
            $writer,
            $request,
            $menu,
            $menuNameAr,
            $menuNameEn,
            $categories,
            $items,
            $existingCategories,
            $existingItems,
        ): void {
            $menuUpdate = [
                'name_ar' => $menuNameAr !== '' ? $menuNameAr : null,
                'name_en' => $menuNameEn !== '' ? $menuNameEn : $menuNameAr,
            ];
            if ($request->hasFile('menu_image')) {
                $menuUpdate['image_path'] = $request->file('menu_image')
                    ->store('smart-ordering/'.$site->public_id.'/menus', 'public');
            }
            $writer->updateMenu($actor, $site, $draft, $menu, $menuUpdate, $request);

            $categoryModels = [];
            $submittedCategoryIds = [];
            foreach ($categories as $categoryOrder => $category) {
                $categoryKey = (string) $categoryOrder;
                $categoryId = (int) ($category['id'] ?? 0);
                $nameAr = trim((string) ($category['name_ar'] ?? ''));
                $nameEn = trim((string) ($category['name_en'] ?? ''));
                $categoryData = [
                    'name_ar' => $nameAr !== '' ? $nameAr : null,
                    'name_en' => $nameEn !== '' ? $nameEn : $nameAr,
                    'sort_order' => ((int) $categoryOrder) * 10,
                ];

                $categoryImage = $request->file("categories.$categoryOrder.image");
                if ($categoryImage) {
                    $categoryData['image_path'] = $categoryImage
                        ->store('smart-ordering/'.$site->public_id.'/categories', 'public');
                }

                if ($categoryId > 0) {
                    /** @var SmartOrderingMenuCategory $categoryModel */
                    $categoryModel = $existingCategories->get($categoryId);
                    $categoryModels[$categoryKey] = $writer->updateCategory(
                        $actor,
                        $site,
                        $draft,
                        $categoryModel,
                        $categoryData,
                        $request,
                    );
                    $submittedCategoryIds[] = $categoryId;
                } else {
                    $categoryModels[$categoryKey] = $writer->createCategory(
                        $actor,
                        $site,
                        $draft,
                        $menu,
                        $categoryData + [
                            'description_ar' => null,
                            'description_en' => null,
                            'icon' => null,
                            'is_visible' => true,
                            'sold_out' => false,
                        ],
                        $request,
                    );
                }
            }

            $submittedItemIds = [];
            $itemOrderByCategory = [];
            foreach ($items as $itemKey => $item) {
                $categoryKey = (string) $item['category_key'];
                /** @var SmartOrderingMenuCategory $categoryModel */
                $categoryModel = $categoryModels[$categoryKey];
                $itemId = (int) ($item['id'] ?? 0);
                $nameAr = trim((string) ($item['name_ar'] ?? ''));
                $nameEn = trim((string) ($item['name_en'] ?? ''));
                $itemOrderByCategory[$categoryKey] = ($itemOrderByCategory[$categoryKey] ?? 0);
                $sortOrder = $itemOrderByCategory[$categoryKey] * 10;
                $itemOrderByCategory[$categoryKey]++;

                $itemData = [
                    'category_id' => (int) $categoryModel->id,
                    'name_ar' => $nameAr !== '' ? $nameAr : null,
                    'name_en' => $nameEn !== '' ? $nameEn : $nameAr,
                    'price_override' => (float) $item['price'],
                    'featured' => (bool) ($item['featured'] ?? false),
                    'sort_order' => $sortOrder,
                ];
                $itemImage = $request->file("items.$itemKey.image");
                if ($itemImage) {
                    $itemData['image_path'] = $itemImage
                        ->store('smart-ordering/'.$site->public_id.'/items', 'public');
                }

                if ($itemId > 0) {
                    /** @var SmartOrderingMenuItem $itemModel */
                    $itemModel = $existingItems->get($itemId);
                    $writer->updateItem($actor, $site, $draft, $itemModel, $itemData, $request);
                    $submittedItemIds[] = $itemId;
                } else {
                    $writer->createItem($actor, $site, $draft, $categoryModel, $itemData + [
                        'source_product_id' => null,
                        'description_ar' => null,
                        'description_en' => null,
                        'gallery' => [],
                        'best_seller' => false,
                        'is_new' => false,
                        'is_visible' => true,
                        'sold_out' => false,
                        'prep_time_minutes' => null,
                        'calories' => null,
                        'allergens' => [],
                        'spicy_level' => 0,
                        'vegetarian' => false,
                        'allow_customer_notes' => true,
                        'removable_ingredients' => [],
                    ], $request);
                }
            }

            foreach ($existingItems as $existingItem) {
                if (! in_array((int) $existingItem->id, $submittedItemIds, true)) {
                    $writer->deleteItem($actor, $site, $draft, $existingItem, $request);
                }
            }
            foreach ($existingCategories as $existingCategory) {
                if (! in_array((int) $existingCategory->id, $submittedCategoryIds, true)) {
                    $writer->deleteCategory($actor, $site, $draft, $existingCategory, $request);
                }
            }
        });

        $message = app()->getLocale() === 'ar'
            ? 'تم تحديث المنيو وحفظ التغييرات في المسودة.'
            : 'Menu updated and saved to the draft.';

        if ($request->input('return_to') === 'menu_setup') {
            return redirect()->route('admin.smart-ordering.menu.index', [
                'store_id' => $site->store_id,
                'setup' => 'menu',
            ])->with('status', $message);
        }

        return redirect()->to(
            route('admin.smart-ordering.appearance.index', [
                'site' => $site->public_id,
                'store_id' => $site->store_id,
                'revision' => 'draft',
            ]).'#menu-builder'
        )->with('status', $message);
    }

    public function setupIdentity(
        Request $request,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $menuRevisions,
        SmartOrderingRevisionService $revisions,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $draft = $menuRevisions->draft($actor, $site, $request);

        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:160'],
            'name_ar' => ['nullable', 'string', 'max:160'],
            'tagline_en' => ['nullable', 'string', 'max:240'],
            'tagline_ar' => ['nullable', 'string', 'max:240'],
            'description_en' => ['nullable', 'string', 'max:1000'],
            'description_ar' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:40'],
            'whatsapp_url' => ['nullable', 'url', 'max:500'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'slug' => ['required', 'string', 'max:64'],
        ]);

        $payload = (array) $draft->payload;
        $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];
        $identity = is_array($content['identity'] ?? null) ? $content['identity'] : [];

        foreach ([
            'name_en', 'name_ar', 'tagline_en', 'tagline_ar',
            'description_en', 'description_ar', 'phone', 'whatsapp_url',
        ] as $field) {
            $identity[$field] = trim((string) ($data[$field] ?? ''));
        }

        foreach (['logo', 'cover'] as $asset) {
            $file = $request->file($asset);
            if ($file === null) {
                continue;
            }

            $key = $asset.'_url';
            $previous = trim((string) ($identity[$key] ?? ''));
            if ($previous !== '' && ! str_starts_with($previous, '/') && ! str_contains($previous, '://')) {
                Storage::disk('public')->delete($previous);
            }

            $identity[$key] = $file->store('smart-ordering/branding/'.$site->public_id, 'public');
        }

        $content['identity'] = $identity;
        $payload['content'] = $content;

        DB::transaction(function () use ($actor, $site, $sites, $revisions, $draft, $payload, $data, $request): void {
            $sites->renameSlug($actor, $site, (string) $data['slug'], $request);
            // Guided setup never enables runtime here. Runtime is activated only
            // by the final restaurant launch gate after every prerequisite passes.
            $revisions->updateDraft($actor, $draft->fresh(), $payload, $request);
        });

        return redirect()->route('admin.smart-ordering.menu.index', [
            'store_id' => $site->store_id,
            'setup' => 'review',
        ])->with('status', app()->getLocale() === 'ar'
            ? 'تم حفظ بيانات المطعم. راجع الواجهة قبل النشر.'
            : 'Restaurant details saved. Review the storefront before publishing.');
    }

    public function updateMenu(
        Request $request,
        SmartOrderingMenu $menu,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingMenuWriteService $writer,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $draft = $revisions->draft($actor, $site, $request);
        $writer->updateMenu($actor, $site, $draft, $menu, $this->menuData($request, $site, $menu), $request);

        return back()->with('status', __('smart_ordering_menu.saved'));
    }

    public function deleteMenu(
        Request $request,
        SmartOrderingMenu $menu,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingMenuWriteService $writer,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $writer->deleteMenu($actor, $site, $revisions->draft($actor, $site, $request), $menu, $request);

        return back()->with('status', __('smart_ordering_menu.deleted'));
    }

    public function storeCategory(
        Request $request,
        SmartOrderingMenu $menu,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingMenuWriteService $writer,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $draft = $revisions->draft($actor, $site, $request);
        $data = $this->categoryData($request, $site);
        $writer->createCategory($actor, $site, $draft, $menu, $data, $request);

        return back()->with('status', __('smart_ordering_menu.saved'));
    }

    public function updateCategory(
        Request $request,
        SmartOrderingMenuCategory $category,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingMenuWriteService $writer,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $draft = $revisions->draft($actor, $site, $request);
        $data = $this->categoryData($request, $site, $category);
        $writer->updateCategory($actor, $site, $draft, $category, $data, $request);

        return back()->with('status', __('smart_ordering_menu.saved'));
    }

    public function deleteCategory(
        Request $request,
        SmartOrderingMenuCategory $category,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingMenuWriteService $writer,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $writer->deleteCategory($actor, $site, $revisions->draft($actor, $site, $request), $category, $request);

        return back()->with('status', __('smart_ordering_menu.deleted'));
    }

    public function storeItem(
        Request $request,
        SmartOrderingMenuCategory $category,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingMenuWriteService $writer,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $draft = $revisions->draft($actor, $site, $request);
        $writer->createItem($actor, $site, $draft, $category, $this->itemData($request, $site), $request);

        return back()->with('status', __('smart_ordering_menu.saved'));
    }

    public function updateItem(
        Request $request,
        SmartOrderingMenuItem $item,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingMenuWriteService $writer,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $draft = $revisions->draft($actor, $site, $request);
        $writer->updateItem($actor, $site, $draft, $item, $this->itemData($request, $site, $item), $request);

        return back()->with('status', __('smart_ordering_menu.saved'));
    }

    public function deleteItem(
        Request $request,
        SmartOrderingMenuItem $item,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingMenuWriteService $writer,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $writer->deleteItem($actor, $site, $revisions->draft($actor, $site, $request), $item, $request);

        return back()->with('status', __('smart_ordering_menu.deleted'));
    }

    public function storeGroup(
        Request $request,
        SmartOrderingMenuItem $item,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingMenuWriteService $writer,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $draft = $revisions->draft($actor, $site, $request);
        $writer->createModifierGroup($actor, $site, $draft, $item, $this->groupData($request), $request);

        return back()->with('status', __('smart_ordering_menu.saved'));
    }

    public function updateGroup(
        Request $request,
        SmartOrderingModifierGroup $group,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingMenuWriteService $writer,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $draft = $revisions->draft($actor, $site, $request);
        $writer->updateModifierGroup($actor, $site, $draft, $group, $this->groupData($request), $request);

        return back()->with('status', __('smart_ordering_menu.saved'));
    }

    public function deleteGroup(
        Request $request,
        SmartOrderingModifierGroup $group,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingMenuWriteService $writer,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $writer->deleteModifierGroup($actor, $site, $revisions->draft($actor, $site, $request), $group, $request);

        return back()->with('status', __('smart_ordering_menu.deleted'));
    }

    public function storeOption(
        Request $request,
        SmartOrderingModifierGroup $group,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingMenuWriteService $writer,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $draft = $revisions->draft($actor, $site, $request);
        $writer->createModifierOption($actor, $site, $draft, $group, $this->optionData($request), $request);

        return back()->with('status', __('smart_ordering_menu.saved'));
    }

    public function updateOption(
        Request $request,
        SmartOrderingModifierOption $option,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingMenuWriteService $writer,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $draft = $revisions->draft($actor, $site, $request);
        $writer->updateModifierOption($actor, $site, $draft, $option, $this->optionData($request), $request);

        return back()->with('status', __('smart_ordering_menu.saved'));
    }

    public function deleteOption(
        Request $request,
        SmartOrderingModifierOption $option,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingMenuWriteService $writer,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $writer->deleteModifierOption($actor, $site, $revisions->draft($actor, $site, $request), $option, $request);

        return back()->with('status', __('smart_ordering_menu.deleted'));
    }

    public function bulkAvailability(
        Request $request,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingMenuWriteService $writer,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $data = $request->validate([
            'entity' => ['required', Rule::in(['categories', 'items'])],
            'public_ids' => ['required', 'array', 'min:1'],
            'public_ids.*' => ['required', 'uuid'],
            'is_visible' => ['nullable', Rule::in(['', '0', '1'])],
            'sold_out' => ['nullable', Rule::in(['', '0', '1'])],
        ]);
        $visible = ($data['is_visible'] ?? '') === '' ? null : (bool) ((int) $data['is_visible']);
        $soldOut = ($data['sold_out'] ?? '') === '' ? null : (bool) ((int) $data['sold_out']);

        $writer->bulkAvailability(
            $actor,
            $site,
            $revisions->draft($actor, $site, $request),
            (string) $data['entity'],
            array_values((array) $data['public_ids']),
            $visible,
            $soldOut,
            $request,
        );

        return back()->with('status', __('smart_ordering_menu.saved'));
    }

    public function categoryBranch(
        Request $request,
        SmartOrderingMenuCategory $category,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingMenuWriteService $writer,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $branch = $this->branch($request, $site);
        $data = $request->validate([
            'override_visible' => ['required', Rule::in(['inherit', '0', '1'])],
            'override_sold_out' => ['required', Rule::in(['inherit', '0', '1'])],
            'override_sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
        $writer->setCategoryBranchOverride($actor, $site, $revisions->draft($actor, $site, $request), $category, $branch, [
            'is_visible' => $this->nullableBool((string) $data['override_visible']),
            'sold_out' => $this->nullableBool((string) $data['override_sold_out']),
            'sort_order' => $data['override_sort_order'] ?? null,
            'availability_schedule' => $this->schedule($request, 'override_'),
        ], $request);

        return back()->with('status', __('smart_ordering_menu.saved'));
    }

    public function itemBranch(
        Request $request,
        SmartOrderingMenuItem $item,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingMenuWriteService $writer,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $branch = $this->branch($request, $site);
        $data = $request->validate([
            'override_visible' => ['required', Rule::in(['inherit', '0', '1'])],
            'override_sold_out' => ['required', Rule::in(['inherit', '0', '1'])],
            'override_price' => ['nullable', 'numeric', 'min:0'],
            'override_prep_time' => ['nullable', 'integer', 'min:0', 'max:1440'],
        ]);
        $writer->setItemBranchOverride($actor, $site, $revisions->draft($actor, $site, $request), $item, $branch, [
            'is_visible' => $this->nullableBool((string) $data['override_visible']),
            'sold_out' => $this->nullableBool((string) $data['override_sold_out']),
            'price_override' => $data['override_price'] ?? null,
            'prep_time_minutes' => $data['override_prep_time'] ?? null,
            'availability_schedule' => $this->schedule($request, 'override_'),
        ], $request);

        return back()->with('status', __('smart_ordering_menu.saved'));
    }

    public function optionBranch(
        Request $request,
        SmartOrderingModifierOption $option,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingMenuWriteService $writer,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites);
        $branch = $this->branch($request, $site);
        $data = $request->validate([
            'override_visible' => ['required', Rule::in(['inherit', '0', '1'])],
            'override_sold_out' => ['required', Rule::in(['inherit', '0', '1'])],
            'override_price_delta' => ['nullable', 'numeric', 'min:0'],
        ]);
        $writer->setOptionBranchOverride($actor, $site, $revisions->draft($actor, $site, $request), $option, $branch, [
            'is_visible' => $this->nullableBool((string) $data['override_visible']),
            'sold_out' => $this->nullableBool((string) $data['override_sold_out']),
            'price_delta' => $data['override_price_delta'] ?? null,
        ], $request);

        return back()->with('status', __('smart_ordering_menu.saved'));
    }

    public function publish(
        Request $request,
        SmartOrderingRevision $revision,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingQrManager $qrManager,
        RestaurantOnboardingService $onboarding,
        AuditLogger $audit,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites, 'smart_ordering.publish');
        abort_unless((int) $revision->site_id === (int) $site->id, 404);

        $guided = $request->boolean('guided');
        if ($guided) {
            abort_unless($actor->hasPermission('smart_ordering.manage', (int) $site->store_id), 403);
        }

        $revisions->publish($actor, $site->fresh(), $revision, $request);

        if ($guided) {
            $site = $site->fresh();
            $publicUrl = route('public.smart-ordering.storefront', ['siteKey' => $site->slug], true);
            $targetPath = (string) parse_url($publicUrl, PHP_URL_PATH);
            $qr = SmartOrderingQrCode::query()
                ->where('store_id', $site->store_id)
                ->where('type', 'generic')
                ->where('source', 'restaurant-launch')
                ->first();

            $qrData = [
                'store_id' => (int) $site->store_id,
                'promotion_id' => null,
                'type' => 'generic',
                'label' => app()->getLocale() === 'ar' ? 'رابط المطعم الرئيسي' : 'Restaurant website',
                'notes' => app()->getLocale() === 'ar'
                    ? 'تم إنشاؤه تلقائياً عند نشر واجهة المطعم.'
                    : 'Generated automatically when the restaurant storefront is published.',
                'target_path' => $targetPath,
                'source' => 'restaurant-launch',
                'medium' => 'qr',
                'campaign' => null,
                'branch_reference' => null,
                'table_reference' => null,
                'valid_from' => null,
                'valid_until' => null,
                'is_enabled' => true,
            ];

            if ($qr instanceof SmartOrderingQrCode) {
                $before = $this->launchQrAuditState($qr);
                $qr = $qrManager->update($qr, $qrData, (int) $actor->getKey());
                if (! $qr->is_enabled) {
                    $qr = $qrManager->setEnabled($qr, true, (int) $actor->getKey());
                }
                $qr->loadMissing('activeToken');
                if ($qr->activeToken === null) {
                    $qrManager->rotate($qr, (int) $actor->getKey(), false);
                    $qr->refresh()->loadMissing('activeToken');
                }
                $audit->record(
                    'smart_ordering.qr.launch_synced',
                    $actor,
                    $qr,
                    $before,
                    $this->launchQrAuditState($qr),
                    $request,
                );
            } else {
                $created = $qrManager->create($qrData, (int) $actor->getKey());
                $qr = $created['qr'];
                $audit->record(
                    'smart_ordering.qr.launch_created',
                    $actor,
                    $qr,
                    null,
                    $this->launchQrAuditState($qr),
                    $request,
                );
            }

            $store = Store::query()->findOrFail((int) $site->store_id);
            $status = $onboarding->status($store, $actor);
            $launched = false;
            if ((bool) ($status['can_launch'] ?? false)) {
                $onboarding->launch($store, $actor, $request);
                $launched = true;
            }

            $message = $launched
                ? (app()->getLocale() === 'ar'
                    ? 'تم النشر وتفعيل الموقع وإنشاء QR للمطعم.'
                    : 'Published, activated the live site, and created the restaurant QR.')
                : (app()->getLocale() === 'ar'
                    ? 'تم نشر المنيو وإنشاء QR، لكن تشغيل الموقع ما زال ينتظر إكمال متطلبات الإطلاق الظاهرة.'
                    : 'The menu was published and its QR created, but the live site is still waiting for the remaining launch requirements.');

            return redirect()->route('admin.smart-ordering.menu.index', [
                'store_id' => $site->store_id,
                'setup' => 'publish',
            ])->with('status', $message)
                ->with('open_live_storefront', $launched);
        }

        return back()->with('status', __('smart_ordering_menu.published'));
    }

    public function siteQrSvg(
        Request $request,
        SmartOrderingSite $site,
        SmartOrderingQrAssetService $assets,
    ): Response {
        $this->authorizeSiteQr($request, $site);
        $url = route('public.smart-ordering.storefront', ['siteKey' => $site->slug], true);
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($assets->svg($url), 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Content-Disposition' => $disposition.'; filename="foodex-restaurant-'.$site->public_id.'.svg"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function siteQrPdf(
        Request $request,
        SmartOrderingSite $site,
        SmartOrderingQrAssetService $assets,
    ): Response {
        $this->authorizeSiteQr($request, $site);
        $url = route('public.smart-ordering.storefront', ['siteKey' => $site->slug], true);
        $label = (string) ($site->store()->value('name') ?: 'FOODEX Restaurant');

        return response($assets->pdf($url, $label), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="foodex-restaurant-'.$site->public_id.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function unpublish(
        Request $request,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites, 'smart_ordering.publish');
        $revisions->unpublish($actor, $site, $request);

        return back()->with('status', __('smart_ordering_menu.unpublish.done'));
    }

    public function schedulePublish(
        Request $request,
        SmartOrderingRevision $revision,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites, 'smart_ordering.publish');
        abort_unless((int) $revision->site_id === (int) $site->id, 404);

        $data = $request->validate([
            'scheduled_at' => ['required', 'date_format:Y-m-d\\TH:i'],
        ]);
        $timezone = $this->publishTimezone($site);
        $revisions->schedule(
            $actor,
            $site,
            $revision,
            CarbonImmutable::parse((string) $data['scheduled_at'], $timezone),
            $request,
        );

        return back()->with('status', __('smart_ordering_menu.scheduled'));
    }

    /**
     * @return list<array{type:string,type_label:string,id:int,menu_id:int,label:string,anchor:string}>
     */
    private function focusResults(SmartOrderingSite $site, SmartOrderingRevision $draft, string $query): array
    {
        if ($query === '') {
            return [];
        }

        $needle = '%'.$query.'%';
        $isAr = app()->getLocale() === 'ar';
        $results = collect();

        $append = static function ($rows, string $type, callable $menuId, callable $anchor) use ($results, $isAr): void {
            foreach ($rows as $row) {
                $name = trim((string) (($isAr ? ($row->name_ar ?? null) : ($row->name_en ?? null))
                    ?: ($isAr ? ($row->name_en ?? null) : ($row->name_ar ?? null))
                    ?: '#'.$row->id));
                $typeLabel = match ($type) {
                    'menu' => $isAr ? 'منيو' : 'Menu',
                    'category' => $isAr ? 'تصنيف' : 'Category',
                    'item' => $isAr ? 'صنف' : 'Item',
                    'group' => $isAr ? 'مجموعة' : 'Group',
                    'option' => $isAr ? 'خيار' : 'Option',
                    default => $isAr ? 'سجل' : 'Record',
                };
                $results->push([
                    'type' => $type,
                    'type_label' => $typeLabel,
                    'id' => (int) $row->id,
                    'menu_id' => (int) $menuId($row),
                    'label' => $name,
                    'anchor' => (string) $anchor($row),
                ]);
            }
        };

        $append(
            DB::table('smart_ordering_menus')
                ->where('site_id', $site->id)
                ->where('revision_id', $draft->id)
                ->where(function ($builder) use ($needle): void {
                    $builder->where('name_ar', 'like', $needle)
                        ->orWhere('name_en', 'like', $needle)
                        ->orWhere('public_id', 'like', $needle);
                })
                ->orderBy('sort_order')
                ->limit(6)
                ->get(['id', 'name_ar', 'name_en']),
            'menu',
            static fn ($row): int => (int) $row->id,
            static fn ($row): string => 'menu-'.$row->id,
        );

        $append(
            DB::table('smart_ordering_menu_categories as categories')
                ->join('smart_ordering_menus as menus', 'menus.id', '=', 'categories.menu_id')
                ->where('menus.site_id', $site->id)
                ->where('menus.revision_id', $draft->id)
                ->where(function ($builder) use ($needle): void {
                    $builder->where('categories.name_ar', 'like', $needle)
                        ->orWhere('categories.name_en', 'like', $needle)
                        ->orWhere('categories.public_id', 'like', $needle);
                })
                ->orderBy('categories.sort_order')
                ->limit(6)
                ->get(['categories.id', 'categories.menu_id', 'categories.name_ar', 'categories.name_en']),
            'category',
            static fn ($row): int => (int) $row->menu_id,
            static fn ($row): string => 'menu-category-'.$row->id,
        );

        $append(
            DB::table('smart_ordering_menu_items as items')
                ->join('smart_ordering_menu_categories as categories', 'categories.id', '=', 'items.category_id')
                ->join('smart_ordering_menus as menus', 'menus.id', '=', 'categories.menu_id')
                ->where('menus.site_id', $site->id)
                ->where('menus.revision_id', $draft->id)
                ->where(function ($builder) use ($needle): void {
                    $builder->where('items.name_ar', 'like', $needle)
                        ->orWhere('items.name_en', 'like', $needle)
                        ->orWhere('items.public_id', 'like', $needle);
                })
                ->orderBy('items.sort_order')
                ->limit(6)
                ->get(['items.id', 'items.public_id', 'items.name_ar', 'items.name_en', 'categories.menu_id']),
            'item',
            static fn ($row): int => (int) $row->menu_id,
            static fn ($row): string => 'menu-item-'.$row->public_id,
        );

        $append(
            DB::table('smart_ordering_modifier_groups as groups')
                ->join('smart_ordering_menu_items as items', 'items.id', '=', 'groups.item_id')
                ->join('smart_ordering_menu_categories as categories', 'categories.id', '=', 'items.category_id')
                ->join('smart_ordering_menus as menus', 'menus.id', '=', 'categories.menu_id')
                ->where('menus.site_id', $site->id)
                ->where('menus.revision_id', $draft->id)
                ->where(function ($builder) use ($needle): void {
                    $builder->where('groups.name_ar', 'like', $needle)
                        ->orWhere('groups.name_en', 'like', $needle);
                })
                ->orderBy('groups.sort_order')
                ->limit(4)
                ->get(['groups.id', 'groups.name_ar', 'groups.name_en', 'categories.menu_id']),
            'group',
            static fn ($row): int => (int) $row->menu_id,
            static fn ($row): string => 'menu-group-'.$row->id,
        );

        $append(
            DB::table('smart_ordering_modifier_options as options')
                ->join('smart_ordering_modifier_groups as groups', 'groups.id', '=', 'options.group_id')
                ->join('smart_ordering_menu_items as items', 'items.id', '=', 'groups.item_id')
                ->join('smart_ordering_menu_categories as categories', 'categories.id', '=', 'items.category_id')
                ->join('smart_ordering_menus as menus', 'menus.id', '=', 'categories.menu_id')
                ->where('menus.site_id', $site->id)
                ->where('menus.revision_id', $draft->id)
                ->where(function ($builder) use ($needle): void {
                    $builder->where('options.name_ar', 'like', $needle)
                        ->orWhere('options.name_en', 'like', $needle);
                })
                ->orderBy('options.sort_order')
                ->limit(4)
                ->get(['options.id', 'options.name_ar', 'options.name_en', 'categories.menu_id']),
            'option',
            static fn ($row): int => (int) $row->menu_id,
            static fn ($row): string => 'menu-option-'.$row->id,
        );

        return $results->take(25)->values()->all();
    }

    /** @return array<string,mixed> */
    private function menuData(Request $request, SmartOrderingSite $site, ?SmartOrderingMenu $current = null): array
    {
        $data = $request->validate([
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string', 'max:5000'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'is_default' => ['nullable', 'boolean'],
            'is_visible' => ['nullable', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'min:1'],
        ]);
        unset($data['image']);
        $data['is_default'] = $request->boolean('is_default');
        $data['is_visible'] = $request->boolean('is_visible');

        if ($request->boolean('remove_image')) {
            $data['image_path'] = null;
        } elseif ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('smart-ordering/'.$site->public_id.'/menus', 'public');
        } elseif ($current !== null) {
            $data['image_path'] = $current->image_path;
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function categoryData(Request $request, SmartOrderingSite $site, ?SmartOrderingMenuCategory $current = null): array
    {
        $data = $request->validate([
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string', 'max:5000'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'icon' => ['nullable', 'string', 'max:64'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'is_visible' => ['nullable', 'boolean'],
            'sold_out' => ['nullable', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);
        unset($data['image']);
        $data['is_visible'] = $request->boolean('is_visible');
        $data['sold_out'] = $request->boolean('sold_out');
        $data['availability_schedule'] = $this->schedule($request);

        if ($request->boolean('remove_image')) {
            $data['image_path'] = null;
        } elseif ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('smart-ordering/'.$site->public_id.'/categories', 'public');
        } elseif ($current !== null) {
            $data['image_path'] = $current->image_path;
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function itemData(Request $request, SmartOrderingSite $site, ?SmartOrderingMenuItem $current = null): array
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer', 'min:1'],
            'source_product_id' => ['nullable', 'integer', 'min:1'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string', 'max:5000'],
            'description_en' => ['nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'gallery_images' => ['nullable', 'array', 'max:8'],
            'gallery_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
            'featured' => ['nullable', 'boolean'],
            'best_seller' => ['nullable', 'boolean'],
            'is_new' => ['nullable', 'boolean'],
            'is_visible' => ['nullable', 'boolean'],
            'sold_out' => ['nullable', 'boolean'],
            'prep_time_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'calories' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'spicy_level' => ['required', 'integer', 'min:0', 'max:5'],
            'vegetarian' => ['nullable', 'boolean'],
            'allow_customer_notes' => ['nullable', 'boolean'],
            'allergens_text' => ['nullable', 'string', 'max:2000'],
            'removable_ingredients_text' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);
        unset($data['image'], $data['gallery_images'], $data['allergens_text'], $data['removable_ingredients_text']);
        foreach (['featured', 'best_seller', 'is_new', 'is_visible', 'sold_out', 'vegetarian', 'allow_customer_notes'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }
        $data['availability_schedule'] = $this->schedule($request);
        $data['allergens'] = $this->csv((string) $request->input('allergens_text', ''));
        $data['removable_ingredients'] = $this->csv((string) $request->input('removable_ingredients_text', ''));

        if ($request->boolean('remove_image')) {
            $data['image_path'] = null;
        } elseif ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('smart-ordering/'.$site->public_id.'/items', 'public');
        } elseif ($current !== null) {
            $data['image_path'] = $current->image_path;
        }

        $gallery = $request->boolean('clear_gallery') ? [] : (array) ($current->gallery ?? []);
        foreach ((array) $request->file('gallery_images', []) as $image) {
            $gallery[] = $image->store('smart-ordering/'.$site->public_id.'/items/gallery', 'public');
        }
        $data['gallery'] = array_values(array_unique(array_filter($gallery, 'is_string')));

        return $data;
    }

    /** @return array<string,mixed> */
    private function groupData(Request $request): array
    {
        $data = $request->validate([
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in(['modifier', 'size', 'variant', 'extra', 'ingredient'])],
            'selection_type' => ['required', Rule::in(['single', 'multiple'])],
            'is_required' => ['nullable', 'boolean'],
            'min_selections' => ['required', 'integer', 'min:0', 'max:100'],
            'max_selections' => ['required', 'integer', 'min:1', 'max:100'],
            'is_visible' => ['nullable', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);
        $data['is_required'] = $request->boolean('is_required');
        $data['is_visible'] = $request->boolean('is_visible');

        return $data;
    }

    /** @return array<string,mixed> */
    private function optionData(Request $request): array
    {
        $data = $request->validate([
            'source_product_id' => ['nullable', 'integer', 'min:1'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'price_delta' => ['required', 'numeric', 'min:0'],
            'is_default' => ['nullable', 'boolean'],
            'is_visible' => ['nullable', 'boolean'],
            'sold_out' => ['nullable', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ]);
        foreach (['is_default', 'is_visible', 'sold_out'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }

        return $data;
    }

    /** @return array{0:User,1:SmartOrderingSite,2:Collection<int,Store>} */
    /** @return array<string, mixed> */
    private function launchQrAuditState(SmartOrderingQrCode $qr): array
    {
        return [
            'public_id' => (string) $qr->public_id,
            'store_id' => (int) $qr->store_id,
            'type' => (string) $qr->type,
            'label' => (string) $qr->label,
            'target_path' => (string) $qr->target_path,
            'source' => $qr->source,
            'medium' => $qr->medium,
            'is_enabled' => (bool) $qr->is_enabled,
        ];
    }

    private function authorizeSiteQr(Request $request, SmartOrderingSite $site): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        app(RestaurantSaasStoreAccess::class)->assertStore(
            $actor,
            (int) $site->store_id,
            'smart_ordering.view',
            'b2c',
        );
        abort_unless((bool) $site->master_enabled, 409);
        abort_unless(
            SmartOrderingRevision::query()
                ->where('site_id', $site->id)
                ->where('status', 'published')
                ->exists(),
            409,
        );

        return $actor;
    }

    private function context(
        Request $request,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        string $permission = 'smart_ordering.manage',
    ): array {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $storeIds = app(RestaurantSaasStoreAccess::class)
            ->allowedStoreIds($actor, $permission, $request);

        $requested = $request->integer('store_id');
        if ($requested > 0 && ! in_array($requested, $storeIds, true)) {
            abort(404);
        }
        $storeId = $requested > 0 ? $requested : (int) $storeIds[0];

        $site = $sites->ensureForStore($storeId, $actor, $request);
        $stores = Store::query()->whereIn('id', $storeIds)->orderBy('name')->get();

        return [$actor, $site, $stores];
    }

    private function publishTimezone(SmartOrderingSite $site): string
    {
        $timezone = SmartOrderingBranch::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->value('timezone');

        return is_string($timezone) && trim($timezone) !== ''
            ? $timezone
            : (string) config('app.timezone', 'UTC');
    }

    private function branch(Request $request, SmartOrderingSite $site): SmartOrderingBranch
    {
        $data = $request->validate(['branch_id' => ['required', 'integer', 'min:1']]);

        return SmartOrderingBranch::query()
            ->whereKey((int) $data['branch_id'])
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->firstOrFail();
    }

    /** @return array{windows:list<array{days:list<int>,start:string,end:string}>}|null */
    private function schedule(Request $request, string $prefix = ''): ?array
    {
        if ($request->boolean($prefix.'schedule_enabled') === false) {
            return null;
        }

        $data = $request->validate([
            $prefix.'schedule_days' => ['required', 'array', 'min:1'],
            $prefix.'schedule_days.*' => ['integer', 'min:1', 'max:7'],
            $prefix.'schedule_start' => ['required', 'date_format:H:i'],
            $prefix.'schedule_end' => ['required', 'date_format:H:i'],
        ]);

        return ['windows' => [[
            'days' => array_values(array_unique(array_map('intval', (array) $data[$prefix.'schedule_days']))),
            'start' => (string) $data[$prefix.'schedule_start'],
            'end' => (string) $data[$prefix.'schedule_end'],
        ]]];
    }

    private function nullableBool(string $value): ?bool
    {
        return $value === 'inherit' ? null : $value === '1';
    }

    /** @return list<string> */
    private function csv(string $value): array
    {
        return collect(preg_split('/[,،\\n]+/u', $value) ?: [])
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
