<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Models\SmartOrderingMenuItem;
use App\Domain\SmartOrdering\Models\SmartOrderingModifierGroup;
use App\Domain\SmartOrdering\Models\SmartOrderingModifierOption;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use Illuminate\Validation\ValidationException;

final class SmartOrderingModifierService
{
    public function __construct(
        private readonly SmartOrderingPriceResolver $prices,
        private readonly SmartOrderingAvailabilityService $availability,
    ) {}

    /**
     * @param  array<string,list<string>>  $selections
     * @return array{
     *   base_price:float,
     *   modifier_delta:float,
     *   total_price:float,
     *   selections:list<array{group_id:string,option_ids:list<string>}>
     * }
     */
    public function quote(
        SmartOrderingSite $site,
        SmartOrderingMenuItem $item,
        array $selections,
        ?SmartOrderingBranch $branch = null,
    ): array {
        $state = $this->availability->item($site, $item, $branch);
        if (! $state['available']) {
            throw ValidationException::withMessages([
                'item' => [__('smart_ordering_menu.validation.item_unavailable')],
            ]);
        }

        $base = $this->prices->displayPrice($site, $item, $branch)['amount'];
        $groups = SmartOrderingModifierGroup::query()
            ->where('item_id', $item->id)
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $knownGroups = $groups->pluck('public_id')->map(static fn ($id): string => (string) $id)->all();
        foreach (array_keys($selections) as $groupPublicId) {
            if (! in_array((string) $groupPublicId, $knownGroups, true)) {
                throw ValidationException::withMessages([
                    'selections' => [__('smart_ordering_menu.validation.unknown_modifier_group')],
                ]);
            }
        }

        $delta = 0.0;
        $normalized = [];

        foreach ($groups as $group) {
            $groupId = (string) $group->public_id;
            $selected = array_values(array_unique(array_filter(
                array_map('strval', (array) ($selections[$groupId] ?? [])),
                static fn (string $id): bool => $id !== '',
            )));

            $minimum = max(0, (int) $group->min_selections);
            if ($group->is_required) {
                $minimum = max(1, $minimum);
            }
            $maximum = $group->selection_type === 'single'
                ? 1
                : max(1, (int) $group->max_selections);

            if (count($selected) < $minimum || count($selected) > $maximum) {
                throw ValidationException::withMessages([
                    'selections.'.$groupId => [__('smart_ordering_menu.validation.modifier_bounds')],
                ]);
            }

            $options = SmartOrderingModifierOption::query()
                ->where('group_id', $group->id)
                ->whereIn('public_id', $selected)
                ->get()
                ->keyBy(fn (SmartOrderingModifierOption $option): string => (string) $option->public_id);

            if ($options->count() !== count($selected)) {
                throw ValidationException::withMessages([
                    'selections.'.$groupId => [__('smart_ordering_menu.validation.unknown_modifier_option')],
                ]);
            }

            foreach ($selected as $optionId) {
                /** @var SmartOrderingModifierOption $option */
                $option = $options->get($optionId);
                $optionState = $this->availability->option($site, $option, $branch);
                if (! $optionState['available']) {
                    throw ValidationException::withMessages([
                        'selections.'.$groupId => [__('smart_ordering_menu.validation.option_unavailable')],
                    ]);
                }
                $delta += $this->prices->modifierDelta($site, $option, $branch);
            }

            $normalized[] = ['group_id' => $groupId, 'option_ids' => $selected];
        }

        $delta = round($delta, 3);

        return [
            'base_price' => round($base, 3),
            'modifier_delta' => $delta,
            'total_price' => round($base + $delta, 3),
            'selections' => $normalized,
        ];
    }
}
