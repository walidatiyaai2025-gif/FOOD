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
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SmartOrderingMenuWriteService
{
    public function __construct(
        private readonly SmartOrderingPriceResolver $prices,
        private readonly SmartOrderingMenuRevisionService $menuRevisions,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string,mixed> $data */
    public function createMenu(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        array $data,
        ?Request $request = null,
    ): SmartOrderingMenu {
        $this->assertDraft($actor, $site, $draft);

        return DB::transaction(function () use ($actor, $site, $draft, $data, $request): SmartOrderingMenu {
            if ((bool) ($data['is_default'] ?? false)) {
                SmartOrderingMenu::query()
                    ->where('revision_id', $draft->id)
                    ->update(['is_default' => false, 'updated_at' => now()]);
            }

            $menu = SmartOrderingMenu::query()->create([
                'revision_id' => $draft->id,
                'site_id' => $site->id,
                'public_id' => (string) Str::uuid(),
                ...$this->pick($data, [
                    'name_ar', 'name_en', 'description_ar', 'description_en', 'image_path',
                    'is_default', 'is_visible', 'sort_order',
                ]),
            ]);

            $this->syncMenuBranches($site, $menu, (array) ($data['branch_ids'] ?? []));
            $this->record('smart_ordering.menu.created', $actor, $site, null, $this->auditRow($menu), $request);
            $this->touch($actor, $draft, $request);

            return $menu->fresh();
        });
    }

    /** @param array<string,mixed> $data */
    public function updateMenu(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        SmartOrderingMenu $menu,
        array $data,
        ?Request $request = null,
    ): SmartOrderingMenu {
        $this->assertEntity($actor, $site, $draft, $menu);
        $before = $this->auditRow($menu);

        return DB::transaction(function () use ($actor, $site, $draft, $menu, $data, $request, $before): SmartOrderingMenu {
            if (array_key_exists('is_default', $data) && (bool) $data['is_default']) {
                SmartOrderingMenu::query()
                    ->where('revision_id', $draft->id)
                    ->whereKeyNot($menu->id)
                    ->update(['is_default' => false, 'updated_at' => now()]);
            }

            $menu->fill($this->pick($data, [
                'name_ar', 'name_en', 'description_ar', 'description_en', 'image_path',
                'is_default', 'is_visible', 'sort_order',
            ]))->save();

            if (array_key_exists('branch_ids', $data)) {
                $this->syncMenuBranches($site, $menu, (array) $data['branch_ids']);
            }

            $this->record('smart_ordering.menu.updated', $actor, $site, $before, $this->auditRow($menu), $request);
            $this->touch($actor, $draft, $request);

            return $menu->fresh();
        });
    }

    public function deleteMenu(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        SmartOrderingMenu $menu,
        ?Request $request = null,
    ): void {
        $this->assertEntity($actor, $site, $draft, $menu);
        $before = $this->auditRow($menu);

        DB::transaction(function () use ($actor, $site, $draft, $menu, $request, $before): void {
            $menu->delete();
            $this->record('smart_ordering.menu.deleted', $actor, $site, $before, null, $request);
            $this->touch($actor, $draft, $request);
        });
    }

    /** @param array<string,mixed> $data */
    public function createCategory(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        SmartOrderingMenu $menu,
        array $data,
        ?Request $request = null,
    ): SmartOrderingMenuCategory {
        $this->assertEntity($actor, $site, $draft, $menu);

        $category = SmartOrderingMenuCategory::query()->create([
            'revision_id' => $draft->id,
            'site_id' => $site->id,
            'menu_id' => $menu->id,
            'public_id' => (string) Str::uuid(),
            ...$this->pick($data, [
                'name_ar', 'name_en', 'description_ar', 'description_en',
                'image_path', 'icon', 'is_visible', 'sold_out',
                'availability_schedule', 'sort_order',
            ]),
        ]);

        $this->record('smart_ordering.menu_category.created', $actor, $site, null, $this->auditRow($category), $request);
        $this->touch($actor, $draft, $request);

        return $category->fresh();
    }

    /** @param array<string,mixed> $data */
    public function updateCategory(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        SmartOrderingMenuCategory $category,
        array $data,
        ?Request $request = null,
    ): SmartOrderingMenuCategory {
        $this->assertEntity($actor, $site, $draft, $category);
        $before = $this->auditRow($category);

        $category->fill($this->pick($data, [
            'name_ar', 'name_en', 'description_ar', 'description_en',
            'image_path', 'icon', 'is_visible', 'sold_out',
            'availability_schedule', 'sort_order',
        ]))->save();

        $this->record('smart_ordering.menu_category.updated', $actor, $site, $before, $this->auditRow($category), $request);
        $this->touch($actor, $draft, $request);

        return $category->fresh();
    }

    public function deleteCategory(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        SmartOrderingMenuCategory $category,
        ?Request $request = null,
    ): void {
        $this->assertEntity($actor, $site, $draft, $category);
        $before = $this->auditRow($category);
        $category->delete();
        $this->record('smart_ordering.menu_category.deleted', $actor, $site, $before, null, $request);
        $this->touch($actor, $draft, $request);
    }

    /** @param array<string,mixed> $data */
    public function createItem(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        SmartOrderingMenuCategory $category,
        array $data,
        ?Request $request = null,
    ): SmartOrderingMenuItem {
        $this->assertEntity($actor, $site, $draft, $category);
        $this->assertSourceProduct($site, $data['source_product_id'] ?? null);

        $item = SmartOrderingMenuItem::query()->create([
            'revision_id' => $draft->id,
            'site_id' => $site->id,
            'category_id' => $category->id,
            'public_id' => (string) Str::uuid(),
            ...$this->pick($data, [
                'source_product_id', 'name_ar', 'name_en', 'description_ar', 'description_en',
                'image_path', 'gallery', 'price_override', 'featured', 'best_seller', 'is_new',
                'is_visible', 'sold_out', 'availability_schedule', 'prep_time_minutes',
                'calories', 'allergens', 'spicy_level', 'vegetarian',
                'allow_customer_notes', 'removable_ingredients', 'sort_order',
            ]),
        ]);

        $this->prices->displayPrice($site, $item);
        $this->record('smart_ordering.menu_item.created', $actor, $site, null, $this->auditRow($item), $request);
        $this->touch($actor, $draft, $request);

        return $item->fresh();
    }

    /** @param array<string,mixed> $data */
    public function updateItem(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        SmartOrderingMenuItem $item,
        array $data,
        ?Request $request = null,
    ): SmartOrderingMenuItem {
        $this->assertEntity($actor, $site, $draft, $item);
        if (array_key_exists('source_product_id', $data)) {
            $this->assertSourceProduct($site, $data['source_product_id']);
        }
        if (array_key_exists('category_id', $data)) {
            $this->assertCategoryId($draft, (int) $data['category_id']);
        }
        $before = $this->auditRow($item);

        $item->fill($this->pick($data, [
            'source_product_id', 'category_id', 'name_ar', 'name_en', 'description_ar', 'description_en',
            'image_path', 'gallery', 'price_override', 'featured', 'best_seller', 'is_new',
            'is_visible', 'sold_out', 'availability_schedule', 'prep_time_minutes',
            'calories', 'allergens', 'spicy_level', 'vegetarian',
            'allow_customer_notes', 'removable_ingredients', 'sort_order',
        ]))->save();

        $this->prices->displayPrice($site, $item);
        $this->record('smart_ordering.menu_item.updated', $actor, $site, $before, $this->auditRow($item), $request);
        $this->touch($actor, $draft, $request);

        return $item->fresh();
    }

    public function deleteItem(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        SmartOrderingMenuItem $item,
        ?Request $request = null,
    ): void {
        $this->assertEntity($actor, $site, $draft, $item);
        $before = $this->auditRow($item);
        $item->delete();
        $this->record('smart_ordering.menu_item.deleted', $actor, $site, $before, null, $request);
        $this->touch($actor, $draft, $request);
    }

    /** @param array<string,mixed> $data */
    public function createModifierGroup(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        SmartOrderingMenuItem $item,
        array $data,
        ?Request $request = null,
    ): SmartOrderingModifierGroup {
        $this->assertEntity($actor, $site, $draft, $item);
        $this->validateModifierBounds($data);

        $group = SmartOrderingModifierGroup::query()->create([
            'revision_id' => $draft->id,
            'site_id' => $site->id,
            'item_id' => $item->id,
            'public_id' => (string) Str::uuid(),
            ...$this->pick($data, [
                'name_ar', 'name_en', 'kind', 'selection_type', 'is_required',
                'min_selections', 'max_selections', 'is_visible', 'sort_order',
            ]),
        ]);

        $this->record('smart_ordering.modifier_group.created', $actor, $site, null, $this->auditRow($group), $request);
        $this->touch($actor, $draft, $request);

        return $group->fresh();
    }

    /** @param array<string,mixed> $data */
    public function updateModifierGroup(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        SmartOrderingModifierGroup $group,
        array $data,
        ?Request $request = null,
    ): SmartOrderingModifierGroup {
        $this->assertEntity($actor, $site, $draft, $group);
        $merged = [...$group->toArray(), ...$data];
        $this->validateModifierBounds($merged);
        $before = $this->auditRow($group);

        $group->fill($this->pick($data, [
            'name_ar', 'name_en', 'kind', 'selection_type', 'is_required',
            'min_selections', 'max_selections', 'is_visible', 'sort_order',
        ]))->save();

        $this->record('smart_ordering.modifier_group.updated', $actor, $site, $before, $this->auditRow($group), $request);
        $this->touch($actor, $draft, $request);

        return $group->fresh();
    }

    public function deleteModifierGroup(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        SmartOrderingModifierGroup $group,
        ?Request $request = null,
    ): void {
        $this->assertEntity($actor, $site, $draft, $group);
        $before = $this->auditRow($group);
        $group->delete();
        $this->record('smart_ordering.modifier_group.deleted', $actor, $site, $before, null, $request);
        $this->touch($actor, $draft, $request);
    }

    /** @param array<string,mixed> $data */
    public function createModifierOption(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        SmartOrderingModifierGroup $group,
        array $data,
        ?Request $request = null,
    ): SmartOrderingModifierOption {
        $this->assertEntity($actor, $site, $draft, $group);
        $this->assertSourceProduct($site, $data['source_product_id'] ?? null);

        $option = SmartOrderingModifierOption::query()->create([
            'group_id' => $group->id,
            'public_id' => (string) Str::uuid(),
            ...$this->pick($data, [
                'source_product_id', 'name_ar', 'name_en', 'price_delta',
                'is_default', 'is_visible', 'sold_out', 'sort_order',
            ]),
        ]);

        $this->record('smart_ordering.modifier_option.created', $actor, $site, null, $this->auditRow($option), $request);
        $this->touch($actor, $draft, $request);

        return $option->fresh();
    }

    /** @param array<string,mixed> $data */
    public function updateModifierOption(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        SmartOrderingModifierOption $option,
        array $data,
        ?Request $request = null,
    ): SmartOrderingModifierOption {
        $this->assertOption($actor, $site, $draft, $option);
        if (array_key_exists('source_product_id', $data)) {
            $this->assertSourceProduct($site, $data['source_product_id']);
        }
        $before = $this->auditRow($option);

        $option->fill($this->pick($data, [
            'source_product_id', 'name_ar', 'name_en', 'price_delta',
            'is_default', 'is_visible', 'sold_out', 'sort_order',
        ]))->save();

        $this->record('smart_ordering.modifier_option.updated', $actor, $site, $before, $this->auditRow($option), $request);
        $this->touch($actor, $draft, $request);

        return $option->fresh();
    }

    public function deleteModifierOption(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        SmartOrderingModifierOption $option,
        ?Request $request = null,
    ): void {
        $this->assertOption($actor, $site, $draft, $option);
        $before = $this->auditRow($option);
        $option->delete();
        $this->record('smart_ordering.modifier_option.deleted', $actor, $site, $before, null, $request);
        $this->touch($actor, $draft, $request);
    }

    /**
     * @param  list<array{public_id:string,sort_order:int}>  $positions
     */
    public function reorder(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        string $entity,
        array $positions,
        ?Request $request = null,
    ): void {
        $this->assertDraft($actor, $site, $draft);

        [$table, $revisionColumn] = match ($entity) {
            'menus' => ['smart_ordering_menus', 'revision_id'],
            'categories' => ['smart_ordering_menu_categories', 'revision_id'],
            'items' => ['smart_ordering_menu_items', 'revision_id'],
            'groups' => ['smart_ordering_modifier_groups', 'revision_id'],
            default => throw ValidationException::withMessages(['entity' => [__('smart_ordering_menu.validation.reorder_entity')]]),
        };

        foreach ($positions as $position) {
            DB::table($table)
                ->where($revisionColumn, $draft->id)
                ->where('public_id', (string) $position['public_id'])
                ->update(['sort_order' => max(0, (int) $position['sort_order']), 'updated_at' => now()]);
        }

        $this->record('smart_ordering.menu.reordered', $actor, $site, null, [
            'store_id' => (int) $site->store_id,
            'entity' => $entity,
            'positions' => $positions,
        ], $request);
        $this->touch($actor, $draft, $request);
    }

    /**
     * @param  list<string>  $publicIds
     */
    public function bulkAvailability(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        string $entity,
        array $publicIds,
        ?bool $visible,
        ?bool $soldOut,
        ?Request $request = null,
    ): void {
        $this->assertDraft($actor, $site, $draft);
        $table = match ($entity) {
            'categories' => 'smart_ordering_menu_categories',
            'items' => 'smart_ordering_menu_items',
            default => throw ValidationException::withMessages(['entity' => [__('smart_ordering_menu.validation.bulk_entity')]]),
        };

        $values = ['updated_at' => now()];
        if ($visible !== null) {
            $values['is_visible'] = $visible;
        }
        if ($soldOut !== null) {
            $values['sold_out'] = $soldOut;
        }

        DB::table($table)
            ->where('revision_id', $draft->id)
            ->where('site_id', $site->id)
            ->whereIn('public_id', $publicIds)
            ->update($values);

        $this->record('smart_ordering.menu.bulk_availability', $actor, $site, null, [
            'store_id' => (int) $site->store_id,
            'entity' => $entity,
            'count' => count($publicIds),
            'is_visible' => $visible,
            'sold_out' => $soldOut,
        ], $request);
        $this->touch($actor, $draft, $request);
    }

    /** @param array<string,mixed> $data */
    public function setCategoryBranchOverride(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        SmartOrderingMenuCategory $category,
        SmartOrderingBranch $branch,
        array $data,
        ?Request $request = null,
    ): void {
        $this->assertEntity($actor, $site, $draft, $category);
        $this->assertBranch($site, $branch);
        $this->upsertOverride(
            'smart_ordering_category_branch_overrides',
            'category_id',
            (int) $category->id,
            (int) $branch->id,
            $this->pick($data, ['is_visible', 'sold_out', 'availability_schedule', 'sort_order']),
        );
        $this->record('smart_ordering.category_branch_override.updated', $actor, $site, null, [
            'store_id' => (int) $site->store_id,
            'category_public_id' => (string) $category->public_id,
            'branch_public_id' => (string) $branch->public_id,
        ], $request);
        $this->touch($actor, $draft, $request);
    }

    /** @param array<string,mixed> $data */
    public function setItemBranchOverride(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        SmartOrderingMenuItem $item,
        SmartOrderingBranch $branch,
        array $data,
        ?Request $request = null,
    ): void {
        $this->assertEntity($actor, $site, $draft, $item);
        $this->assertBranch($site, $branch);
        $this->upsertOverride(
            'smart_ordering_item_branch_overrides',
            'item_id',
            (int) $item->id,
            (int) $branch->id,
            $this->pick($data, ['price_override', 'is_visible', 'sold_out', 'availability_schedule', 'prep_time_minutes']),
        );
        $this->record('smart_ordering.item_branch_override.updated', $actor, $site, null, [
            'store_id' => (int) $site->store_id,
            'item_public_id' => (string) $item->public_id,
            'branch_public_id' => (string) $branch->public_id,
        ], $request);
        $this->touch($actor, $draft, $request);
    }

    /** @param array<string,mixed> $data */
    public function setOptionBranchOverride(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        SmartOrderingModifierOption $option,
        SmartOrderingBranch $branch,
        array $data,
        ?Request $request = null,
    ): void {
        $this->assertOption($actor, $site, $draft, $option);
        $this->assertBranch($site, $branch);
        $this->upsertOverride(
            'smart_ordering_modifier_option_branch_overrides',
            'option_id',
            (int) $option->id,
            (int) $branch->id,
            $this->pick($data, ['price_delta', 'is_visible', 'sold_out']),
        );
        $this->record('smart_ordering.option_branch_override.updated', $actor, $site, null, [
            'store_id' => (int) $site->store_id,
            'option_public_id' => (string) $option->public_id,
            'branch_public_id' => (string) $branch->public_id,
        ], $request);
        $this->touch($actor, $draft, $request);
    }

    private function assertDraft(User $actor, SmartOrderingSite $site, SmartOrderingRevision $draft): void
    {
        abort_unless($actor->hasPermission('smart_ordering.manage', (int) $site->store_id), 403);
        abort_unless((int) $draft->site_id === (int) $site->id && $draft->status === 'draft', 404);
    }

    private function assertEntity(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        Model $entity,
    ): void {
        $this->assertDraft($actor, $site, $draft);
        $revisionId = $entity->getAttribute('revision_id');
        $siteId = $entity->getAttribute('site_id');

        abort_unless(
            is_numeric($revisionId)
            && (int) $revisionId === (int) $draft->id
            && is_numeric($siteId)
            && (int) $siteId === (int) $site->id,
            404,
        );
    }

    private function assertOption(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        SmartOrderingModifierOption $option,
    ): void {
        $this->assertDraft($actor, $site, $draft);
        $belongs = DB::table('smart_ordering_modifier_groups')
            ->where('smart_ordering_modifier_groups.id', $option->group_id)
            ->where('revision_id', $draft->id)
            ->where('site_id', $site->id)
            ->exists();
        abort_unless($belongs, 404);
    }

    private function assertCategoryId(SmartOrderingRevision $draft, int $categoryId): void
    {
        abort_unless(SmartOrderingMenuCategory::query()
            ->whereKey($categoryId)
            ->where('revision_id', $draft->id)
            ->exists(), 404);
    }

    private function assertSourceProduct(SmartOrderingSite $site, mixed $productId): void
    {
        if ($productId === null || $productId === '') {
            return;
        }

        $this->prices->assertProductBelongsToStore($site, (int) $productId);
    }

    private function assertBranch(SmartOrderingSite $site, SmartOrderingBranch $branch): void
    {
        abort_unless((int) $branch->site_id === (int) $site->id && $branch->is_active, 404);
    }

    /** @param list<int|string> $branchIds */
    private function syncMenuBranches(SmartOrderingSite $site, SmartOrderingMenu $menu, array $branchIds): void
    {
        $branchIds = array_values(array_unique(array_map('intval', $branchIds)));
        if ($branchIds !== []) {
            $valid = SmartOrderingBranch::query()
                ->where('site_id', $site->id)
                ->where('is_active', true)
                ->whereIn('id', $branchIds)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if (count($valid) !== count($branchIds)) {
                throw ValidationException::withMessages([
                    'branch_ids' => [__('smart_ordering_menu.validation.branch_scope')],
                ]);
            }
        }

        DB::table('smart_ordering_menu_branches')->where('menu_id', $menu->id)->delete();
        foreach ($branchIds as $branchId) {
            DB::table('smart_ordering_menu_branches')->insert([
                'menu_id' => $menu->id,
                'branch_id' => $branchId,
            ]);
        }
    }

    /** @param array<string,mixed> $data */
    private function validateModifierBounds(array $data): void
    {
        $type = (string) ($data['selection_type'] ?? 'single');
        $required = (bool) ($data['is_required'] ?? false);
        $min = max(0, (int) ($data['min_selections'] ?? 0));
        $max = max(1, (int) ($data['max_selections'] ?? 1));

        if (! in_array($type, ['single', 'multiple'], true)
            || ($type === 'single' && $max !== 1)
            || $min > $max
            || ($required && $min < 1)) {
            throw ValidationException::withMessages([
                'modifier_group' => [__('smart_ordering_menu.validation.modifier_bounds')],
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $values
     */
    private function upsertOverride(
        string $table,
        string $foreignKey,
        int $entityId,
        int $branchId,
        array $values,
    ): void {
        DB::table($table)->updateOrInsert(
            [$foreignKey => $entityId, 'branch_id' => $branchId],
            [...$values, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    /** @param array<string,mixed> $data
     * @param  list<string>  $keys
     * @return array<string,mixed>
     */
    private function pick(array $data, array $keys): array
    {
        return array_intersect_key($data, array_flip($keys));
    }

    /** @return array<string,mixed> */
    private function auditRow(Model $model): array
    {
        $values = $model->toArray();
        unset($values['id'], $values['revision_id'], $values['site_id'], $values['created_at'], $values['updated_at']);

        return $values;
    }

    private function record(
        string $event,
        User $actor,
        SmartOrderingSite $site,
        ?array $before,
        ?array $after,
        ?Request $request,
    ): void {
        $this->audit->record(
            $event,
            $actor,
            $site,
            $before === null ? null : ['store_id' => (int) $site->store_id, ...$before],
            $after === null ? null : ['store_id' => (int) $site->store_id, ...$after],
            $request,
        );
    }

    private function touch(User $actor, SmartOrderingRevision $draft, ?Request $request): void
    {
        $this->menuRevisions->refreshChecksum($actor, $draft->fresh(), $request);
    }
}
