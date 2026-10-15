<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Models\SmartOrderingMenu;
use App\Domain\SmartOrdering\Models\SmartOrderingMenuCategory;
use App\Domain\SmartOrdering\Models\SmartOrderingMenuItem;
use App\Domain\SmartOrdering\Models\SmartOrderingModifierGroup;
use App\Domain\SmartOrdering\Models\SmartOrderingModifierOption;
use App\Domain\SmartOrdering\Models\SmartOrderingRevision;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class SmartOrderingMenuReadService
{
    public function __construct(
        private readonly SmartOrderingRevisionService $revisions,
        private readonly SmartOrderingPriceResolver $prices,
        private readonly SmartOrderingAvailabilityService $availability,
    ) {}

    public function publishedRevision(SmartOrderingSite $site): SmartOrderingRevision
    {
        $revision = $this->revisions->published($site);
        abort_unless($revision instanceof SmartOrderingRevision, 404);

        return $revision;
    }

    public function branch(SmartOrderingSite $site, ?string $publicId): ?SmartOrderingBranch
    {
        if ($publicId !== null && trim($publicId) !== '') {
            return SmartOrderingBranch::query()
                ->where('site_id', $site->id)
                ->where('public_id', trim($publicId))
                ->where('is_active', true)
                ->firstOrFail();
        }

        return SmartOrderingBranch::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array{revision_id:string,branch:?array{id:string,name:string,timezone:string},menus:list<array<string,mixed>>}
     */
    public function menu(
        SmartOrderingSite $site,
        string $locale = 'en',
        ?SmartOrderingBranch $branch = null,
    ): array {
        $revision = $this->publishedRevision($site);
        $menus = SmartOrderingMenu::query()
            ->where('revision_id', $revision->id)
            ->where('site_id', $site->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (SmartOrderingMenu $menu): bool => $this->menuAppliesToBranch($menu, $branch))
            ->map(fn (SmartOrderingMenu $menu): array => $this->menuResource($site, $menu, $locale, $branch))
            ->values()
            ->all();

        return [
            'revision_id' => (string) $revision->public_id,
            'branch' => $branch === null ? null : [
                'id' => (string) $branch->public_id,
                'name' => $this->localized($branch->name_ar, $branch->name_en, $locale),
                'timezone' => (string) $branch->timezone,
            ],
            'menus' => $menus,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function categories(
        SmartOrderingSite $site,
        string $locale = 'en',
        ?SmartOrderingBranch $branch = null,
    ): array {
        $revision = $this->publishedRevision($site);
        $menuIds = SmartOrderingMenu::query()
            ->where('revision_id', $revision->id)
            ->where('site_id', $site->id)
            ->where('is_visible', true)
            ->get()
            ->filter(fn (SmartOrderingMenu $menu): bool => $this->menuAppliesToBranch($menu, $branch))
            ->pluck('id');

        return SmartOrderingMenuCategory::query()
            ->where('revision_id', $revision->id)
            ->where('site_id', $site->id)
            ->whereIn('menu_id', $menuIds)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (SmartOrderingMenuCategory $category): ?array => $this->categoryResource($category, $locale, $branch))
            ->filter()
            ->sortBy('sort_order')
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   data:list<array<string,mixed>>,
     *   meta:array{current_page:int,per_page:int,total:int,last_page:int}
     * }
     */
    public function searchItems(
        SmartOrderingSite $site,
        string $locale = 'en',
        ?SmartOrderingBranch $branch = null,
        ?string $query = null,
        ?string $categoryPublicId = null,
        ?bool $featured = null,
        int $page = 1,
        int $perPage = 20,
    ): array {
        $revision = $this->publishedRevision($site);
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));

        $menuIds = SmartOrderingMenu::query()
            ->where('revision_id', $revision->id)
            ->where('site_id', $site->id)
            ->where('is_visible', true)
            ->get()
            ->filter(fn (SmartOrderingMenu $menu): bool => $this->menuAppliesToBranch($menu, $branch))
            ->pluck('id');

        $categoryIds = SmartOrderingMenuCategory::query()
            ->where('revision_id', $revision->id)
            ->where('site_id', $site->id)
            ->whereIn('menu_id', $menuIds)
            ->pluck('id');

        $items = SmartOrderingMenuItem::query()
            ->where('smart_ordering_menu_items.revision_id', $revision->id)
            ->where('smart_ordering_menu_items.site_id', $site->id)
            ->whereIn('smart_ordering_menu_items.category_id', $categoryIds)
            ->when($categoryPublicId !== null && $categoryPublicId !== '', function ($builder) use ($revision, $categoryPublicId): void {
                $categoryId = SmartOrderingMenuCategory::query()
                    ->where('revision_id', $revision->id)
                    ->where('public_id', $categoryPublicId)
                    ->value('id');
                abort_if($categoryId === null, 404);
                $builder->where('category_id', $categoryId);
            })
            ->when($featured !== null, fn ($builder) => $builder->where('featured', $featured))
            ->when($query !== null && trim($query) !== '', function ($builder) use ($query): void {
                $needle = '%'.trim($query).'%';
                $builder->where(function ($nested) use ($needle): void {
                    $nested->where('name_ar', 'like', $needle)
                        ->orWhere('name_en', 'like', $needle)
                        ->orWhere('description_ar', 'like', $needle)
                        ->orWhere('description_en', 'like', $needle);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $data = collect($items->items())
            ->map(fn (SmartOrderingMenuItem $item): ?array => $this->itemResource($site, $item, $locale, $branch, false))
            ->filter()
            ->values()
            ->all();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'last_page' => $items->lastPage(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function itemDetail(
        SmartOrderingSite $site,
        string $itemPublicId,
        string $locale = 'en',
        ?SmartOrderingBranch $branch = null,
    ): array {
        $revision = $this->publishedRevision($site);
        $item = SmartOrderingMenuItem::query()
            ->where('revision_id', $revision->id)
            ->where('site_id', $site->id)
            ->where('public_id', $itemPublicId)
            ->firstOrFail();

        $resource = $this->itemResource($site, $item, $locale, $branch, true);
        abort_if($resource === null, 404);

        return $resource;
    }

    /** @return array<string,mixed> */
    private function menuResource(
        SmartOrderingSite $site,
        SmartOrderingMenu $menu,
        string $locale,
        ?SmartOrderingBranch $branch,
    ): array {
        $categories = SmartOrderingMenuCategory::query()
            ->where('menu_id', $menu->id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SmartOrderingMenuCategory $category): ?array => $this->categoryResource($category, $locale, $branch, $site))
            ->filter()
            ->sortBy('sort_order')
            ->values()
            ->all();

        return [
            'id' => (string) $menu->public_id,
            'name' => $this->localized($menu->name_ar, $menu->name_en, $locale),
            'description' => $this->localized($menu->description_ar, $menu->description_en, $locale),
            'is_default' => (bool) $menu->is_default,
            'categories' => $categories,
        ];
    }

    /** @return array<string,mixed>|null */
    private function categoryResource(
        SmartOrderingMenuCategory $category,
        string $locale,
        ?SmartOrderingBranch $branch,
        ?SmartOrderingSite $site = null,
    ): ?array {
        $state = $this->availability->category($category, $branch);
        if ($state['reason'] === 'HIDDEN') {
            return null;
        }

        $sortOrder = (int) $category->sort_order;
        if ($branch !== null) {
            $branchSort = DB::table('smart_ordering_category_branch_overrides')
                ->where('category_id', $category->id)
                ->where('branch_id', $branch->id)
                ->value('sort_order');
            if ($branchSort !== null) {
                $sortOrder = (int) $branchSort;
            }
        }

        $resource = [
            'id' => (string) $category->public_id,
            'name' => $this->localized($category->name_ar, $category->name_en, $locale),
            'description' => $this->localized($category->description_ar, $category->description_en, $locale),
            'image' => $this->publicMediaUrl($category->image_path),
            'icon' => $category->icon,
            'available' => $state['available'],
            'availability_reason' => $state['reason'],
            'sold_out' => $state['sold_out'],
            'sort_order' => $sortOrder,
        ];

        if ($site !== null) {
            $resource['items'] = SmartOrderingMenuItem::query()
                ->where('category_id', $category->id)
                ->orderBy('sort_order')
                ->get()
                ->map(fn (SmartOrderingMenuItem $item): ?array => $this->itemResource($site, $item, $locale, $branch, false))
                ->filter()
                ->values()
                ->all();
        }

        return $resource;
    }

    /** @return array<string,mixed>|null */
    private function itemResource(
        SmartOrderingSite $site,
        SmartOrderingMenuItem $item,
        string $locale,
        ?SmartOrderingBranch $branch,
        bool $detail,
    ): ?array {
        $category = SmartOrderingMenuCategory::query()->findOrFail($item->category_id);
        $menu = SmartOrderingMenu::query()->findOrFail($category->menu_id);
        if (! $this->menuAppliesToBranch($menu, $branch)) {
            return null;
        }

        $state = $this->availability->item($site, $item, $branch);
        if (in_array($state['reason'], ['HIDDEN', 'CATEGORY_HIDDEN'], true)) {
            return null;
        }

        $price = $this->prices->displayPrice($site, $item, $branch);
        $image = $item->image_path;
        if (($image === null || $image === '') && $item->source_product_id !== null) {
            $image = DB::table('product_images')
                ->where('product_id', $item->source_product_id)
                ->orderByDesc('is_primary')
                ->orderBy('sort_order')
                ->value('path');
        }

        $resource = [
            'id' => (string) $item->public_id,
            'category_id' => (string) $item->category()->value('public_id'),
            'name' => $this->localized($item->name_ar, $item->name_en, $locale),
            'description' => $this->localized($item->description_ar, $item->description_en, $locale),
            'image' => $this->publicMediaUrl(is_string($image) ? $image : null),
            'gallery' => collect((array) $item->gallery)
                ->filter('is_string')
                ->map(fn (string $path): string => $this->publicMediaUrl($path) ?? '')
                ->filter()
                ->values()
                ->all(),
            'price' => $price['amount'],
            'price_source' => $price['source'],
            'available' => $state['available'],
            'availability_reason' => $state['reason'],
            'sold_out' => $state['sold_out'],
            'featured' => (bool) $item->featured,
            'best_seller' => (bool) $item->best_seller,
            'is_new' => (bool) $item->is_new,
            'prep_time_minutes' => $this->effectivePrepTime($item, $branch),
            'calories' => $item->calories === null ? null : (int) $item->calories,
            'allergens' => (array) $item->allergens,
            'spicy_level' => (int) $item->spicy_level,
            'vegetarian' => (bool) $item->vegetarian,
            'allow_customer_notes' => (bool) $item->allow_customer_notes,
            'removable_ingredients' => (array) $item->removable_ingredients,
        ];

        if ($detail) {
            $resource['modifier_groups'] = SmartOrderingModifierGroup::query()
                ->where('item_id', $item->id)
                ->where('is_visible', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (SmartOrderingModifierGroup $group): array => $this->modifierGroupResource($site, $group, $locale, $branch))
                ->values()
                ->all();
        }

        return $resource;
    }

    /** @return array<string,mixed> */
    private function modifierGroupResource(
        SmartOrderingSite $site,
        SmartOrderingModifierGroup $group,
        string $locale,
        ?SmartOrderingBranch $branch,
    ): array {
        $options = SmartOrderingModifierOption::query()
            ->where('group_id', $group->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (SmartOrderingModifierOption $option) use ($site, $locale, $branch): ?array {
                $state = $this->availability->option($site, $option, $branch);
                if ($state['reason'] === 'HIDDEN') {
                    return null;
                }

                return [
                    'id' => (string) $option->public_id,
                    'name' => $this->localized($option->name_ar, $option->name_en, $locale),
                    'price_delta' => $this->prices->modifierDelta($site, $option, $branch),
                    'is_default' => (bool) $option->is_default,
                    'available' => $state['available'],
                    'sold_out' => $state['sold_out'],
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'id' => (string) $group->public_id,
            'name' => $this->localized($group->name_ar, $group->name_en, $locale),
            'kind' => (string) $group->kind,
            'selection_type' => (string) $group->selection_type,
            'required' => (bool) $group->is_required,
            'min_selections' => (int) $group->min_selections,
            'max_selections' => $group->selection_type === 'single' ? 1 : (int) $group->max_selections,
            'options' => $options,
        ];
    }

    private function menuAppliesToBranch(SmartOrderingMenu $menu, ?SmartOrderingBranch $branch): bool
    {
        $scopedBranchIds = DB::table('smart_ordering_menu_branches')
            ->where('menu_id', $menu->id)
            ->pluck('branch_id');

        if ($scopedBranchIds->isEmpty()) {
            return true;
        }

        return $branch !== null && $scopedBranchIds->contains((int) $branch->id);
    }

    private function effectivePrepTime(SmartOrderingMenuItem $item, ?SmartOrderingBranch $branch): ?int
    {
        if ($branch !== null) {
            $value = DB::table('smart_ordering_item_branch_overrides')
                ->where('item_id', $item->id)
                ->where('branch_id', $branch->id)
                ->value('prep_time_minutes');

            if ($value !== null) {
                return (int) $value;
            }
        }

        return $item->prep_time_minutes === null ? null : (int) $item->prep_time_minutes;
    }

    private function localized(?string $ar, ?string $en, string $locale): string
    {
        $ar = trim((string) $ar);
        $en = trim((string) $en);

        return strtolower($locale) === 'ar'
            ? ($ar !== '' ? $ar : $en)
            : ($en !== '' ? $en : $ar);
    }

    private function publicMediaUrl(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'https://') || str_starts_with($path, 'http://')) {
            return $path;
        }

        return Storage::disk('public')->url(ltrim($path, '/'));
    }
}
