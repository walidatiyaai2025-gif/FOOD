<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Models\SmartOrderingMenuCategory;
use App\Domain\SmartOrdering\Models\SmartOrderingMenuItem;
use App\Domain\SmartOrdering\Models\SmartOrderingModifierOption;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class SmartOrderingAvailabilityService
{
    /** @return array{available:bool,reason:?string,sold_out:bool} */
    public function category(
        SmartOrderingMenuCategory $category,
        ?SmartOrderingBranch $branch = null,
        ?CarbonInterface $at = null,
    ): array {
        $this->assertBranch((int) $category->site_id, $branch);
        $visible = (bool) $category->is_visible;
        $soldOut = (bool) $category->sold_out;
        $schedule = $category->availability_schedule;

        if ($branch !== null) {
            $override = DB::table('smart_ordering_category_branch_overrides')
                ->where('category_id', $category->id)
                ->where('branch_id', $branch->id)
                ->first();

            if ($override !== null) {
                $visible = $override->is_visible === null ? $visible : (bool) $override->is_visible;
                $soldOut = $override->sold_out === null ? $soldOut : (bool) $override->sold_out;
                $schedule = $override->availability_schedule === null
                    ? $schedule
                    : $this->decodeSchedule($override->availability_schedule);
            }
        }

        if (! $visible) {
            return ['available' => false, 'reason' => 'HIDDEN', 'sold_out' => $soldOut];
        }
        if ($soldOut) {
            return ['available' => false, 'reason' => 'SOLD_OUT', 'sold_out' => true];
        }
        if (! $this->scheduleAllows($schedule, $this->clock($branch, $at))) {
            return ['available' => false, 'reason' => 'OUTSIDE_SCHEDULE', 'sold_out' => false];
        }

        return ['available' => true, 'reason' => null, 'sold_out' => false];
    }

    /** @return array{available:bool,reason:?string,sold_out:bool} */
    public function item(
        SmartOrderingSite $site,
        SmartOrderingMenuItem $item,
        ?SmartOrderingBranch $branch = null,
        ?CarbonInterface $at = null,
    ): array {
        abort_unless((int) $item->site_id === (int) $site->id, 404);
        $this->assertBranch((int) $site->id, $branch);

        $category = $item->relationLoaded('category')
            ? $item->getRelation('category')
            : $item->category()->firstOrFail();
        abort_unless($category instanceof SmartOrderingMenuCategory, 404);

        $categoryState = $this->category($category, $branch, $at);
        if (! $categoryState['available']) {
            return [
                'available' => false,
                'reason' => 'CATEGORY_'.$categoryState['reason'],
                'sold_out' => $categoryState['sold_out'],
            ];
        }

        $visible = (bool) $item->is_visible;
        $soldOut = (bool) $item->sold_out;
        $schedule = $item->availability_schedule;

        if ($branch !== null) {
            $override = DB::table('smart_ordering_item_branch_overrides')
                ->where('item_id', $item->id)
                ->where('branch_id', $branch->id)
                ->first();

            if ($override !== null) {
                $visible = $override->is_visible === null ? $visible : (bool) $override->is_visible;
                $soldOut = $override->sold_out === null ? $soldOut : (bool) $override->sold_out;
                $schedule = $override->availability_schedule === null
                    ? $schedule
                    : $this->decodeSchedule($override->availability_schedule);
            }
        }

        if (! $visible) {
            return ['available' => false, 'reason' => 'HIDDEN', 'sold_out' => $soldOut];
        }
        if ($soldOut) {
            return ['available' => false, 'reason' => 'SOLD_OUT', 'sold_out' => true];
        }
        if (! $this->scheduleAllows($schedule, $this->clock($branch, $at))) {
            return ['available' => false, 'reason' => 'OUTSIDE_SCHEDULE', 'sold_out' => false];
        }

        if ($item->source_product_id !== null && ! $this->sourceProductAvailable($site, (int) $item->source_product_id)) {
            return ['available' => false, 'reason' => 'SOURCE_PRODUCT_UNAVAILABLE', 'sold_out' => true];
        }

        return ['available' => true, 'reason' => null, 'sold_out' => false];
    }

    /** @return array{available:bool,reason:?string,sold_out:bool} */
    public function option(
        SmartOrderingSite $site,
        SmartOrderingModifierOption $option,
        ?SmartOrderingBranch $branch = null,
    ): array {
        $this->assertBranch((int) $site->id, $branch);
        $belongs = DB::table('smart_ordering_modifier_groups')
            ->join('smart_ordering_menu_items', 'smart_ordering_menu_items.id', '=', 'smart_ordering_modifier_groups.item_id')
            ->where('smart_ordering_modifier_groups.id', $option->group_id)
            ->where('smart_ordering_menu_items.site_id', $site->id)
            ->exists();
        abort_unless($belongs, 404);

        $visible = (bool) $option->is_visible;
        $soldOut = (bool) $option->sold_out;

        if ($branch !== null) {
            $override = DB::table('smart_ordering_modifier_option_branch_overrides')
                ->where('option_id', $option->id)
                ->where('branch_id', $branch->id)
                ->first();

            if ($override !== null) {
                $visible = $override->is_visible === null ? $visible : (bool) $override->is_visible;
                $soldOut = $override->sold_out === null ? $soldOut : (bool) $override->sold_out;
            }
        }

        if (! $visible) {
            return ['available' => false, 'reason' => 'HIDDEN', 'sold_out' => $soldOut];
        }
        if ($soldOut) {
            return ['available' => false, 'reason' => 'SOLD_OUT', 'sold_out' => true];
        }
        if ($option->source_product_id !== null
            && ! $this->sourceProductAvailable($site, (int) $option->source_product_id)) {
            return ['available' => false, 'reason' => 'SOURCE_PRODUCT_UNAVAILABLE', 'sold_out' => true];
        }

        return ['available' => true, 'reason' => null, 'sold_out' => false];
    }

    private function sourceProductAvailable(SmartOrderingSite $site, int $productId): bool
    {
        return DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join) use ($site): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->where('store_products.store_id', '=', $site->store_id);
            })
            ->where('products.id', $productId)
            ->where('products.is_active', true)
            ->where('catalogs.store_id', $site->store_id)
            ->where('catalogs.channel', 'b2c')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('store_products.is_active', true)
            ->exists();
    }

    private function assertBranch(int $siteId, ?SmartOrderingBranch $branch): void
    {
        if ($branch !== null) {
            abort_unless((int) $branch->site_id === $siteId && $branch->is_active, 404);
        }
    }

    private function clock(?SmartOrderingBranch $branch, ?CarbonInterface $at): CarbonImmutable
    {
        $timezone = $branch?->timezone ?: 'Asia/Kuwait';

        return $at === null
            ? CarbonImmutable::now($timezone)
            : CarbonImmutable::instance($at)->setTimezone($timezone);
    }

    private function decodeSchedule(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function scheduleAllows(mixed $schedule, CarbonImmutable $now): bool
    {
        $schedule = $this->decodeSchedule($schedule);
        if (! is_array($schedule) || $schedule === []) {
            return true;
        }

        $windows = array_is_list($schedule) ? $schedule : ($schedule['windows'] ?? []);
        if (! is_array($windows) || $windows === []) {
            return true;
        }

        $today = $now->dayOfWeekIso;
        $previous = $today === 1 ? 7 : $today - 1;
        $minutes = ($now->hour * 60) + $now->minute;

        foreach ($windows as $window) {
            if (! is_array($window)) {
                continue;
            }

            $days = array_values(array_filter(
                array_map('intval', (array) ($window['days'] ?? [])),
                static fn (int $day): bool => $day >= 1 && $day <= 7,
            ));
            $start = $this->timeMinutes($window['start'] ?? null);
            $end = $this->timeMinutes($window['end'] ?? null);

            if ($days === [] || $start === null || $end === null) {
                continue;
            }

            if ($start < $end
                && in_array($today, $days, true)
                && $minutes >= $start
                && $minutes < $end) {
                return true;
            }

            if ($start > $end
                && ((in_array($today, $days, true) && $minutes >= $start)
                    || (in_array($previous, $days, true) && $minutes < $end))) {
                return true;
            }

            if ($start === $end && in_array($today, $days, true)) {
                return true;
            }
        }

        return false;
    }

    private function timeMinutes(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^(\d{2}):(\d{2})$/', $value, $matches) !== 1) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        return $hour <= 23 && $minute <= 59 ? ($hour * 60) + $minute : null;
    }
}
