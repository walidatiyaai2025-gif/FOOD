<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingRevision;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use Illuminate\Support\Facades\DB;

final class SmartOrderingMenuRevisionCompareService
{
    /**
     * @return array{
     *   from:array{public_id:string,status:string},
     *   to:array{public_id:string,status:string},
     *   summary:array{added:int,removed:int,modified:int,total:int},
     *   changes:list<array{entity:string,public_id:string,branch_public_id:?string,change:string,fields:list<string>}>
     * }
     */
    public function compare(
        SmartOrderingSite $site,
        SmartOrderingRevision $from,
        SmartOrderingRevision $to,
    ): array {
        abort_unless(
            (int) $from->site_id === (int) $site->id
                && (int) $to->site_id === (int) $site->id,
            404,
        );

        $left = $this->snapshot($site, $from);
        $right = $this->snapshot($site, $to);
        $keys = array_values(array_unique([...array_keys($left), ...array_keys($right)]));
        sort($keys, SORT_STRING);

        $changes = [];
        $summary = ['added' => 0, 'removed' => 0, 'modified' => 0, 'total' => 0];

        foreach ($keys as $key) {
            $before = $left[$key] ?? null;
            $after = $right[$key] ?? null;

            if ($before === null && $after !== null) {
                $change = 'added';
                $fields = array_keys($after['attributes']);
                sort($fields, SORT_STRING);
                $identity = $after;
            } elseif ($before !== null && $after === null) {
                $change = 'removed';
                $fields = array_keys($before['attributes']);
                sort($fields, SORT_STRING);
                $identity = $before;
            } elseif ($before !== null && $after !== null && $before['attributes'] !== $after['attributes']) {
                $change = 'modified';
                $fields = $this->changedFields($before['attributes'], $after['attributes']);
                $identity = $after;
            } else {
                continue;
            }

            $summary[$change]++;
            $summary['total']++;
            $changes[] = [
                'entity' => $identity['entity'],
                'public_id' => $identity['public_id'],
                'branch_public_id' => $identity['branch_public_id'],
                'change' => $change,
                'fields' => $fields,
            ];
        }

        usort($changes, static fn (array $a, array $b): int => [
            $a['entity'],
            $a['public_id'],
            $a['branch_public_id'] ?? '',
            $a['change'],
        ] <=> [
            $b['entity'],
            $b['public_id'],
            $b['branch_public_id'] ?? '',
            $b['change'],
        ]);

        return [
            'from' => [
                'public_id' => (string) $from->public_id,
                'status' => (string) $from->status,
            ],
            'to' => [
                'public_id' => (string) $to->public_id,
                'status' => (string) $to->status,
            ],
            'summary' => $summary,
            'changes' => $changes,
        ];
    }

    /**
     * @return array<string,array{
     *   entity:string,
     *   public_id:string,
     *   branch_public_id:?string,
     *   attributes:array<string,mixed>
     * }>
     */
    private function snapshot(SmartOrderingSite $site, SmartOrderingRevision $revision): array
    {
        $records = [];
        $menus = DB::table('smart_ordering_menus')
            ->where('site_id', $site->id)
            ->where('revision_id', $revision->id)
            ->orderBy('public_id')
            ->get();

        $menuPublicIds = $menus->mapWithKeys(
            static fn (object $row): array => [(int) $row->id => (string) $row->public_id],
        )->all();

        foreach ($menus as $row) {
            $attributes = $this->publicAttributes($row, [
                'id', 'revision_id', 'site_id', 'public_id', 'created_at', 'updated_at',
            ]);
            $this->record($records, 'menu', (string) $row->public_id, null, $attributes);
        }

        if ($menuPublicIds !== []) {
            foreach (DB::table('smart_ordering_menu_branches as scope')
                ->join('smart_ordering_branches as branch', 'branch.id', '=', 'scope.branch_id')
                ->where('branch.site_id', $site->id)
                ->whereIn('scope.menu_id', array_keys($menuPublicIds))
                ->orderBy('scope.menu_id')
                ->orderBy('branch.public_id')
                ->get(['scope.menu_id', 'branch.public_id as branch_public_id']) as $row) {
                $this->record(
                    $records,
                    'menu_branch_scope',
                    $menuPublicIds[(int) $row->menu_id],
                    (string) $row->branch_public_id,
                    ['membership' => true],
                );
            }
        }

        $categories = DB::table('smart_ordering_menu_categories')
            ->where('site_id', $site->id)
            ->where('revision_id', $revision->id)
            ->orderBy('public_id')
            ->get();

        $categoryPublicIds = $categories->mapWithKeys(
            static fn (object $row): array => [(int) $row->id => (string) $row->public_id],
        )->all();

        foreach ($categories as $row) {
            $attributes = $this->publicAttributes($row, [
                'id', 'revision_id', 'site_id', 'menu_id', 'public_id', 'created_at', 'updated_at',
            ]);
            $attributes['menu_public_id'] = $menuPublicIds[(int) $row->menu_id] ?? null;
            ksort($attributes);
            $this->record($records, 'menu_category', (string) $row->public_id, null, $attributes);
        }

        if ($categoryPublicIds !== []) {
            foreach (DB::table('smart_ordering_category_branch_overrides as override')
                ->join('smart_ordering_branches as branch', 'branch.id', '=', 'override.branch_id')
                ->where('branch.site_id', $site->id)
                ->whereIn('override.category_id', array_keys($categoryPublicIds))
                ->orderBy('override.category_id')
                ->orderBy('branch.public_id')
                ->get(['override.*', 'branch.public_id as branch_public_id']) as $row) {
                $attributes = $this->publicAttributes($row, [
                    'id', 'category_id', 'branch_id', 'branch_public_id', 'created_at', 'updated_at',
                ]);
                $this->record(
                    $records,
                    'category_branch_override',
                    $categoryPublicIds[(int) $row->category_id],
                    (string) $row->branch_public_id,
                    $attributes,
                );
            }
        }

        $items = DB::table('smart_ordering_menu_items')
            ->where('site_id', $site->id)
            ->where('revision_id', $revision->id)
            ->orderBy('public_id')
            ->get();

        $itemPublicIds = $items->mapWithKeys(
            static fn (object $row): array => [(int) $row->id => (string) $row->public_id],
        )->all();
        $productSkus = $this->productSkus($items->pluck('source_product_id')->all());

        foreach ($items as $row) {
            $attributes = $this->publicAttributes($row, [
                'id', 'revision_id', 'site_id', 'category_id', 'public_id',
                'source_product_id', 'created_at', 'updated_at',
            ]);
            $attributes['category_public_id'] = $categoryPublicIds[(int) $row->category_id] ?? null;
            $attributes['source_product_sku'] = $row->source_product_id === null
                ? null
                : ($productSkus[(int) $row->source_product_id] ?? null);
            ksort($attributes);
            $this->record($records, 'menu_item', (string) $row->public_id, null, $attributes);
        }

        if ($itemPublicIds !== []) {
            foreach (DB::table('smart_ordering_item_branch_overrides as override')
                ->join('smart_ordering_branches as branch', 'branch.id', '=', 'override.branch_id')
                ->where('branch.site_id', $site->id)
                ->whereIn('override.item_id', array_keys($itemPublicIds))
                ->orderBy('override.item_id')
                ->orderBy('branch.public_id')
                ->get(['override.*', 'branch.public_id as branch_public_id']) as $row) {
                $attributes = $this->publicAttributes($row, [
                    'id', 'item_id', 'branch_id', 'branch_public_id', 'created_at', 'updated_at',
                ]);
                $this->record(
                    $records,
                    'item_branch_override',
                    $itemPublicIds[(int) $row->item_id],
                    (string) $row->branch_public_id,
                    $attributes,
                );
            }
        }

        $groups = DB::table('smart_ordering_modifier_groups')
            ->where('site_id', $site->id)
            ->where('revision_id', $revision->id)
            ->orderBy('public_id')
            ->get();

        $groupPublicIds = $groups->mapWithKeys(
            static fn (object $row): array => [(int) $row->id => (string) $row->public_id],
        )->all();

        foreach ($groups as $row) {
            $attributes = $this->publicAttributes($row, [
                'id', 'revision_id', 'site_id', 'item_id', 'public_id', 'created_at', 'updated_at',
            ]);
            $attributes['item_public_id'] = $itemPublicIds[(int) $row->item_id] ?? null;
            ksort($attributes);
            $this->record($records, 'modifier_group', (string) $row->public_id, null, $attributes);
        }

        $options = $groupPublicIds === []
            ? collect()
            : DB::table('smart_ordering_modifier_options')
                ->whereIn('group_id', array_keys($groupPublicIds))
                ->orderBy('public_id')
                ->get();

        $optionPublicIds = $options->mapWithKeys(
            static fn (object $row): array => [(int) $row->id => (string) $row->public_id],
        )->all();
        $optionProductSkus = $this->productSkus($options->pluck('source_product_id')->all());

        foreach ($options as $row) {
            $attributes = $this->publicAttributes($row, [
                'id', 'group_id', 'public_id', 'source_product_id', 'created_at', 'updated_at',
            ]);
            $attributes['group_public_id'] = $groupPublicIds[(int) $row->group_id] ?? null;
            $attributes['source_product_sku'] = $row->source_product_id === null
                ? null
                : ($optionProductSkus[(int) $row->source_product_id] ?? null);
            ksort($attributes);
            $this->record($records, 'modifier_option', (string) $row->public_id, null, $attributes);
        }

        if ($optionPublicIds !== []) {
            foreach (DB::table('smart_ordering_modifier_option_branch_overrides as override')
                ->join('smart_ordering_branches as branch', 'branch.id', '=', 'override.branch_id')
                ->where('branch.site_id', $site->id)
                ->whereIn('override.option_id', array_keys($optionPublicIds))
                ->orderBy('override.option_id')
                ->orderBy('branch.public_id')
                ->get(['override.*', 'branch.public_id as branch_public_id']) as $row) {
                $attributes = $this->publicAttributes($row, [
                    'id', 'option_id', 'branch_id', 'branch_public_id', 'created_at', 'updated_at',
                ]);
                $this->record(
                    $records,
                    'modifier_option_branch_override',
                    $optionPublicIds[(int) $row->option_id],
                    (string) $row->branch_public_id,
                    $attributes,
                );
            }
        }

        ksort($records);

        return $records;
    }

    /**
     * @param array<string,array{
     *   entity:string,
     *   public_id:string,
     *   branch_public_id:?string,
     *   attributes:array<string,mixed>
     * }> $records
     * @param  array<string,mixed>  $attributes
     */
    private function record(
        array &$records,
        string $entity,
        string $publicId,
        ?string $branchPublicId,
        array $attributes,
    ): void {
        ksort($attributes);
        $key = implode('|', [$entity, $publicId, $branchPublicId ?? '']);
        $records[$key] = [
            'entity' => $entity,
            'public_id' => $publicId,
            'branch_public_id' => $branchPublicId,
            'attributes' => $attributes,
        ];
    }

    /** @param list<string> $except
     * @return array<string,mixed>
     */
    private function publicAttributes(object $row, array $except): array
    {
        $attributes = (array) $row;

        foreach ($except as $key) {
            unset($attributes[$key]);
        }

        foreach ($attributes as $key => $value) {
            $attributes[$key] = $this->normalizeValue($value);
        }

        ksort($attributes);

        return $attributes;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || ! in_array($trimmed[0], ['[', '{'], true)) {
            return $value;
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            return $value;
        }

        return $this->normalizeArray($decoded);
    }

    /** @param array<mixed> $value
     * @return array<mixed>
     */
    private function normalizeArray(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalizeArray($item);
            }
        }

        if (array_is_list($value)) {
            return $value;
        }

        ksort($value);

        return $value;
    }

    /** @param list<mixed> $ids
     * @return array<int,string>
     */
    private function productSkus(array $ids): array
    {
        $ids = array_values(array_unique(array_map(
            'intval',
            array_filter($ids, static fn (mixed $id): bool => $id !== null),
        )));

        if ($ids === []) {
            return [];
        }

        return DB::table('products')
            ->whereIn('id', $ids)
            ->pluck('sku', 'id')
            ->mapWithKeys(static fn (string $sku, int|string $id): array => [(int) $id => $sku])
            ->all();
    }

    /**
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     * @return list<string>
     */
    private function changedFields(array $before, array $after): array
    {
        $fields = array_values(array_unique([...array_keys($before), ...array_keys($after)]));
        $fields = array_values(array_filter(
            $fields,
            static fn (string $field): bool => ($before[$field] ?? null) !== ($after[$field] ?? null),
        ));
        sort($fields, SORT_STRING);

        return $fields;
    }
}
