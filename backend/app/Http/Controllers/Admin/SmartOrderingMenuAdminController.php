<?php

namespace App\Http\Controllers\Admin;

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
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Services\CommerceQuoteService;
use App\Support\DisplayNumber;
use App\Support\TenantContextResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class SmartOrderingMenuAdminController extends Controller
{
    public function index(
        Request $request,
        TenantContextResolver $tenants,
        SmartOrderingSiteService $sites,
        SmartOrderingMenuRevisionService $revisions,
        SmartOrderingPriceResolver $prices,
        CommerceQuoteService $quotes,
    ): View {
        [$actor, $site, $stores] = $this->context($request, $tenants, $sites);
        $draft = $revisions->draft($actor, $site, $request);
        $publishedRevision = $revisions->published($site);
        $scheduledRevision = $revisions->scheduled($site);

        $menuIndex = SmartOrderingMenu::query()
            ->where('site_id', $site->id)
            ->where('revision_id', $draft->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'public_id', 'name_ar', 'name_en', 'sort_order', 'is_default', 'is_visible']);

        $reviewMode = $request->boolean('review');
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

        if ($reviewMode === false && $focusedMenuId !== null) {
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
            'publicMenuUrl' => $publishedRevision instanceof SmartOrderingRevision
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
    ): RedirectResponse {
        [$actor, $site] = $this->context($request, $tenants, $sites, 'smart_ordering.publish');
        abort_unless((int) $revision->site_id === (int) $site->id, 404);
        $revisions->publish($actor, $site, $revision, $request);

        return back()->with('status', __('smart_ordering_menu.published'));
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
