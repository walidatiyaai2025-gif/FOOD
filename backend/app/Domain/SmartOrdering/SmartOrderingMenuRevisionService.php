<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingMenuItem;
use App\Domain\SmartOrdering\Models\SmartOrderingModifierGroup;
use App\Domain\SmartOrdering\Models\SmartOrderingRevision;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SmartOrderingMenuRevisionService
{
    public function __construct(
        private readonly SmartOrderingRevisionService $revisions,
        private readonly SmartOrderingPriceResolver $prices,
    ) {}

    public function draft(User $actor, SmartOrderingSite $site, ?Request $request = null): SmartOrderingRevision
    {
        $draft = $this->revisions->createOrReuseDraft($actor, $site, $request);
        $this->cloneSourceMenuIfNeeded($site, $draft);

        return $draft->fresh();
    }

    public function scheduled(SmartOrderingSite $site): ?SmartOrderingRevision
    {
        return $this->revisions->scheduled($site);
    }

    public function published(SmartOrderingSite $site): ?SmartOrderingRevision
    {
        return $this->revisions->published($site);
    }

    public function publish(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        ?Request $request = null,
    ): SmartOrderingRevision {
        abort_unless((int) $draft->site_id === (int) $site->id && $draft->status === 'draft', 404);
        abort_if($this->revisions->scheduled($site) instanceof SmartOrderingRevision, 409);

        $this->validateDraft($site, $draft);
        $this->refreshChecksum($actor, $draft, $request);

        return $this->revisions->publish($actor, $draft->fresh(), $request);
    }

    public function unpublish(
        User $actor,
        SmartOrderingSite $site,
        ?Request $request = null,
    ): ?SmartOrderingRevision {
        return $this->revisions->unpublish($actor, $site, $request);
    }

    public function schedule(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $draft,
        CarbonInterface $scheduledAt,
        ?Request $request = null,
    ): SmartOrderingRevision {
        abort_unless((int) $draft->site_id === (int) $site->id && $draft->status === 'draft', 404);
        if (! $scheduledAt->isFuture()) {
            throw ValidationException::withMessages([
                'scheduled_at' => [__('smart_ordering_menu.validation.scheduled_future')],
            ]);
        }

        $this->validateDraft($site, $draft);
        $this->refreshChecksum($actor, $draft, $request);

        return $this->revisions->schedule($actor, $draft->fresh(), $scheduledAt, $request);
    }

    public function rollback(
        User $actor,
        SmartOrderingSite $site,
        SmartOrderingRevision $source,
        ?Request $request = null,
    ): SmartOrderingRevision {
        abort_unless($actor->hasPermission('smart_ordering.publish', (int) $site->store_id), 403);

        return DB::transaction(function () use ($actor, $site, $source, $request): SmartOrderingRevision {
            $draft = $this->revisions->createRollbackDraft($actor, $site, $source, $request);
            $this->cloneMenuRevision($site, $source->fresh(), $draft);
            $this->validateDraft($site, $draft->fresh());
            $this->refreshChecksum($actor, $draft->fresh(), $request);

            return $this->revisions->publish($actor, $draft->fresh(), $request);
        });
    }

    public function refreshChecksum(
        User $actor,
        SmartOrderingRevision $draft,
        ?Request $request = null,
    ): SmartOrderingRevision {
        abort_unless($draft->status === 'draft', 409);

        $snapshot = $this->snapshot($draft);
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payload = (array) $draft->payload;
        $payload['menu_schema_version'] = 1;
        $payload['menu_checksum'] = hash('sha256', $encoded);

        return $this->revisions->updateDraft($actor, $draft, $payload, $request);
    }

    public function validateDraft(SmartOrderingSite $site, SmartOrderingRevision $draft): void
    {
        abort_unless((int) $draft->site_id === (int) $site->id && $draft->status === 'draft', 404);

        $menus = DB::table('smart_ordering_menus')
            ->where('revision_id', $draft->id)
            ->where('site_id', $site->id)
            ->get();

        if ($menus->where('is_default', true)->count() > 1) {
            throw ValidationException::withMessages([
                'menu' => [__('smart_ordering_menu.validation.single_default_menu')],
            ]);
        }

        foreach (SmartOrderingMenuItem::query()
            ->where('revision_id', $draft->id)
            ->where('site_id', $site->id)
            ->get() as $item) {
            $this->prices->displayPrice($site, $item);

            foreach (SmartOrderingModifierGroup::query()->where('item_id', $item->id)->get() as $group) {
                $max = (int) $group->max_selections;
                $min = (int) $group->min_selections;
                if ($group->selection_type === 'single') {
                    $max = 1;
                }

                if ($max < 1 || $min > $max || ($group->is_required && $min < 1)) {
                    throw ValidationException::withMessages([
                        'modifier_group' => [__('smart_ordering_menu.validation.modifier_bounds')],
                    ]);
                }

                $visibleOptions = DB::table('smart_ordering_modifier_options')
                    ->where('group_id', $group->id)
                    ->where('is_visible', true)
                    ->count();

                if ($min > $visibleOptions) {
                    throw ValidationException::withMessages([
                        'modifier_group' => [__('smart_ordering_menu.validation.modifier_options')],
                    ]);
                }
            }
        }
    }

    private function cloneSourceMenuIfNeeded(SmartOrderingSite $site, SmartOrderingRevision $draft): void
    {
        if (DB::table('smart_ordering_menus')->where('revision_id', $draft->id)->exists()) {
            return;
        }

        $source = $this->revisions->scheduled($site) ?? $this->revisions->published($site);
        if (! $source instanceof SmartOrderingRevision || $source->id === $draft->id) {
            return;
        }

        $this->cloneMenuRevision($site, $source, $draft);
    }

    private function cloneMenuRevision(
        SmartOrderingSite $site,
        SmartOrderingRevision $source,
        SmartOrderingRevision $draft,
    ): void {
        abort_unless(
            (int) $source->site_id === (int) $site->id
                && (int) $draft->site_id === (int) $site->id
                && $draft->status === 'draft',
            404,
        );

        abort_if(
            DB::table('smart_ordering_menus')->where('revision_id', $draft->id)->exists(),
            409,
        );

        DB::transaction(function () use ($site, $draft, $source): void {
            $menuMap = [];
            foreach (DB::table('smart_ordering_menus')->where('revision_id', $source->id)->orderBy('id')->get() as $row) {
                $newId = DB::table('smart_ordering_menus')->insertGetId([
                    'revision_id' => $draft->id,
                    'site_id' => $site->id,
                    'public_id' => $row->public_id,
                    'name_ar' => $row->name_ar,
                    'name_en' => $row->name_en,
                    'description_ar' => $row->description_ar,
                    'description_en' => $row->description_en,
                    'image_path' => $row->image_path,
                    'is_default' => $row->is_default,
                    'is_visible' => $row->is_visible,
                    'sort_order' => $row->sort_order,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $menuMap[(int) $row->id] = (int) $newId;

                foreach (DB::table('smart_ordering_menu_branches')->where('menu_id', $row->id)->pluck('branch_id') as $branchId) {
                    DB::table('smart_ordering_menu_branches')->insert([
                        'menu_id' => $newId,
                        'branch_id' => $branchId,
                    ]);
                }
            }

            $categoryMap = [];
            foreach (DB::table('smart_ordering_menu_categories')->where('revision_id', $source->id)->orderBy('id')->get() as $row) {
                $newId = DB::table('smart_ordering_menu_categories')->insertGetId([
                    'revision_id' => $draft->id,
                    'site_id' => $site->id,
                    'menu_id' => $menuMap[(int) $row->menu_id],
                    'public_id' => $row->public_id,
                    'name_ar' => $row->name_ar,
                    'name_en' => $row->name_en,
                    'description_ar' => $row->description_ar,
                    'description_en' => $row->description_en,
                    'image_path' => $row->image_path,
                    'icon' => $row->icon,
                    'is_visible' => $row->is_visible,
                    'sold_out' => $row->sold_out,
                    'availability_schedule' => $row->availability_schedule,
                    'sort_order' => $row->sort_order,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $categoryMap[(int) $row->id] = (int) $newId;
            }

            foreach (DB::table('smart_ordering_category_branch_overrides')
                ->whereIn('category_id', array_keys($categoryMap))->get() as $row) {
                DB::table('smart_ordering_category_branch_overrides')->insert([
                    'category_id' => $categoryMap[(int) $row->category_id],
                    'branch_id' => $row->branch_id,
                    'is_visible' => $row->is_visible,
                    'sold_out' => $row->sold_out,
                    'availability_schedule' => $row->availability_schedule,
                    'sort_order' => $row->sort_order,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $itemMap = [];
            foreach (DB::table('smart_ordering_menu_items')->where('revision_id', $source->id)->orderBy('id')->get() as $row) {
                $newId = DB::table('smart_ordering_menu_items')->insertGetId([
                    'revision_id' => $draft->id,
                    'site_id' => $site->id,
                    'category_id' => $categoryMap[(int) $row->category_id],
                    'public_id' => $row->public_id,
                    'source_product_id' => $row->source_product_id,
                    'name_ar' => $row->name_ar,
                    'name_en' => $row->name_en,
                    'description_ar' => $row->description_ar,
                    'description_en' => $row->description_en,
                    'image_path' => $row->image_path,
                    'gallery' => $row->gallery,
                    'price_override' => $row->price_override,
                    'featured' => $row->featured,
                    'best_seller' => $row->best_seller,
                    'is_new' => $row->is_new,
                    'is_visible' => $row->is_visible,
                    'sold_out' => $row->sold_out,
                    'availability_schedule' => $row->availability_schedule,
                    'prep_time_minutes' => $row->prep_time_minutes,
                    'calories' => $row->calories,
                    'allergens' => $row->allergens,
                    'spicy_level' => $row->spicy_level,
                    'vegetarian' => $row->vegetarian,
                    'allow_customer_notes' => $row->allow_customer_notes,
                    'removable_ingredients' => $row->removable_ingredients,
                    'sort_order' => $row->sort_order,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $itemMap[(int) $row->id] = (int) $newId;
            }

            foreach (DB::table('smart_ordering_item_branch_overrides')
                ->whereIn('item_id', array_keys($itemMap))->get() as $row) {
                DB::table('smart_ordering_item_branch_overrides')->insert([
                    'item_id' => $itemMap[(int) $row->item_id],
                    'branch_id' => $row->branch_id,
                    'price_override' => $row->price_override,
                    'is_visible' => $row->is_visible,
                    'sold_out' => $row->sold_out,
                    'availability_schedule' => $row->availability_schedule,
                    'prep_time_minutes' => $row->prep_time_minutes,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $groupMap = [];
            foreach (DB::table('smart_ordering_modifier_groups')->where('revision_id', $source->id)->orderBy('id')->get() as $row) {
                $newId = DB::table('smart_ordering_modifier_groups')->insertGetId([
                    'revision_id' => $draft->id,
                    'site_id' => $site->id,
                    'item_id' => $itemMap[(int) $row->item_id],
                    'public_id' => $row->public_id,
                    'name_ar' => $row->name_ar,
                    'name_en' => $row->name_en,
                    'kind' => $row->kind,
                    'selection_type' => $row->selection_type,
                    'is_required' => $row->is_required,
                    'min_selections' => $row->min_selections,
                    'max_selections' => $row->max_selections,
                    'is_visible' => $row->is_visible,
                    'sort_order' => $row->sort_order,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $groupMap[(int) $row->id] = (int) $newId;
            }

            $optionMap = [];
            foreach (DB::table('smart_ordering_modifier_options')->whereIn('group_id', array_keys($groupMap))->orderBy('id')->get() as $row) {
                $newId = DB::table('smart_ordering_modifier_options')->insertGetId([
                    'group_id' => $groupMap[(int) $row->group_id],
                    'public_id' => $row->public_id,
                    'source_product_id' => $row->source_product_id,
                    'name_ar' => $row->name_ar,
                    'name_en' => $row->name_en,
                    'price_delta' => $row->price_delta,
                    'is_default' => $row->is_default,
                    'is_visible' => $row->is_visible,
                    'sold_out' => $row->sold_out,
                    'sort_order' => $row->sort_order,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $optionMap[(int) $row->id] = (int) $newId;
            }

            foreach (DB::table('smart_ordering_modifier_option_branch_overrides')
                ->whereIn('option_id', array_keys($optionMap))->get() as $row) {
                DB::table('smart_ordering_modifier_option_branch_overrides')->insert([
                    'option_id' => $optionMap[(int) $row->option_id],
                    'branch_id' => $row->branch_id,
                    'price_delta' => $row->price_delta,
                    'is_visible' => $row->is_visible,
                    'sold_out' => $row->sold_out,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /** @return array<string,mixed> */
    private function snapshot(SmartOrderingRevision $revision): array
    {
        $menus = DB::table('smart_ordering_menus')
            ->where('revision_id', $revision->id)
            ->orderBy('sort_order')->orderBy('public_id')
            ->get()->map(function (object $menu): array {
                $categories = DB::table('smart_ordering_menu_categories')
                    ->where('menu_id', $menu->id)
                    ->orderBy('sort_order')->orderBy('public_id')
                    ->get()->map(function (object $category): array {
                        $items = DB::table('smart_ordering_menu_items')
                            ->where('category_id', $category->id)
                            ->orderBy('sort_order')->orderBy('public_id')
                            ->get()->map(function (object $item): array {
                                $groups = DB::table('smart_ordering_modifier_groups')
                                    ->where('item_id', $item->id)
                                    ->orderBy('sort_order')->orderBy('public_id')
                                    ->get()->map(function (object $group): array {
                                        $options = DB::table('smart_ordering_modifier_options')
                                            ->where('group_id', $group->id)
                                            ->orderBy('sort_order')->orderBy('public_id')
                                            ->get()->map(fn (object $option): array => $this->snapshotRow($option, ['group_id']))->all();

                                        return [
                                            ...$this->snapshotRow($group, ['item_id']),
                                            'options' => $options,
                                        ];
                                    })->all();

                                return [
                                    ...$this->snapshotRow($item, ['category_id']),
                                    'groups' => $groups,
                                ];
                            })->all();

                        return [
                            ...$this->snapshotRow($category, ['menu_id']),
                            'items' => $items,
                        ];
                    })->all();

                return [
                    ...$this->snapshotRow($menu),
                    'categories' => $categories,
                ];
            })->all();

        return ['menus' => $menus];
    }

    /**
     * Strip revision-local database identity from the menu content checksum.
     *
     * A rollback creates new rows for the same historical menu tree. Row IDs,
     * foreign keys and timestamps therefore change even when the public menu
     * content is byte-for-byte equivalent. They must not invalidate the
     * deterministic menu checksum used by revision payloads.
     *
     * @param  list<string>  $relationKeys
     * @return array<string,mixed>
     */
    private function snapshotRow(object $row, array $relationKeys = []): array
    {
        $data = (array) $row;

        foreach ([
            'id',
            'revision_id',
            'site_id',
            'created_at',
            'updated_at',
            ...$relationKeys,
        ] as $key) {
            unset($data[$key]);
        }

        ksort($data);

        return $data;
    }
}
