<?php

namespace App\Domain\SmartOrdering;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Models\SmartOrderingMenuItem;
use App\Domain\SmartOrdering\Models\SmartOrderingModifierOption;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SmartOrderingPriceResolver
{
    /**
     * @return array{amount:float,source:string}
     */
    public function displayPrice(
        SmartOrderingSite $site,
        SmartOrderingMenuItem $item,
        ?SmartOrderingBranch $branch = null,
    ): array {
        abort_unless((int) $item->site_id === (int) $site->id, 404);
        $this->assertBranch($site, $branch);

        if ($branch !== null) {
            $override = DB::table('smart_ordering_item_branch_overrides')
                ->where('item_id', $item->id)
                ->where('branch_id', $branch->id)
                ->first();

            if ($override !== null && $override->price_override !== null) {
                return ['amount' => round((float) $override->price_override, 3), 'source' => 'branch_override'];
            }
        }

        if ($item->price_override !== null) {
            return ['amount' => round((float) $item->price_override, 3), 'source' => 'item_override'];
        }

        if ($item->source_product_id === null) {
            throw ValidationException::withMessages([
                'price' => [__('smart_ordering_menu.validation.price_required')],
            ]);
        }

        $price = DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join) use ($site): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->where('store_products.store_id', '=', $site->store_id);
            })
            ->where('products.id', $item->source_product_id)
            ->where('products.is_active', true)
            ->where('catalogs.store_id', $site->store_id)
            ->where('catalogs.channel', 'b2c')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('store_products.is_active', true)
            ->whereNotNull('store_products.price')
            ->value('store_products.price');

        if ($price === null) {
            throw ValidationException::withMessages([
                'source_product_id' => [__('smart_ordering_menu.validation.product_unavailable')],
            ]);
        }

        return ['amount' => round((float) $price, 3), 'source' => 'store_product'];
    }

    /**
     * Resolve prices for a bounded collection without issuing one product query per item.
     *
     * @param  iterable<SmartOrderingMenuItem>  $items
     * @return array{prices:array<int,array{amount:float,source:string}>,errors:array<int,string>}
     */
    public function displayPrices(
        SmartOrderingSite $site,
        iterable $items,
        ?SmartOrderingBranch $branch = null,
    ): array {
        $items = collect($items)->values();
        $this->assertBranch($site, $branch);

        foreach ($items as $item) {
            abort_unless((int) $item->site_id === (int) $site->id, 404);
        }

        $itemIds = $items
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values();

        $branchOverrides = $branch === null || $itemIds->isEmpty()
            ? collect()
            : DB::table('smart_ordering_item_branch_overrides')
                ->where('branch_id', $branch->id)
                ->whereIn('item_id', $itemIds)
                ->get(['item_id', 'price_override'])
                ->keyBy('item_id');

        $sourceProductIds = $items
            ->filter(function (SmartOrderingMenuItem $item) use ($branchOverrides): bool {
                $override = $branchOverrides->get((int) $item->id);

                return ($override === null || $override->price_override === null)
                    && $item->price_override === null
                    && $item->source_product_id !== null;
            })
            ->pluck('source_product_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $sourcePrices = $sourceProductIds->isEmpty()
            ? collect()
            : DB::table('products')
                ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                ->join('store_products', function ($join) use ($site): void {
                    $join->on('store_products.product_id', '=', 'products.id')
                        ->where('store_products.store_id', '=', $site->store_id);
                })
                ->whereIn('products.id', $sourceProductIds)
                ->where('products.is_active', true)
                ->where('catalogs.store_id', $site->store_id)
                ->where('catalogs.channel', 'b2c')
                ->where('catalogs.is_active', true)
                ->where('catalogs.is_migration_quarantine', false)
                ->where('store_products.is_active', true)
                ->whereNotNull('store_products.price')
                ->pluck('store_products.price', 'products.id');

        $prices = [];
        $errors = [];

        foreach ($items as $item) {
            $itemId = (int) $item->id;
            $branchOverride = $branchOverrides->get($itemId);

            if ($branchOverride !== null && $branchOverride->price_override !== null) {
                $prices[$itemId] = [
                    'amount' => round((float) $branchOverride->price_override, 3),
                    'source' => 'branch_override',
                ];

                continue;
            }

            if ($item->price_override !== null) {
                $prices[$itemId] = [
                    'amount' => round((float) $item->price_override, 3),
                    'source' => 'item_override',
                ];

                continue;
            }

            if ($item->source_product_id === null) {
                $errors[$itemId] = __('smart_ordering_menu.validation.price_required');

                continue;
            }

            $productId = (int) $item->source_product_id;
            if (! $sourcePrices->has($productId)) {
                $errors[$itemId] = __('smart_ordering_menu.validation.product_unavailable');

                continue;
            }

            $prices[$itemId] = [
                'amount' => round((float) $sourcePrices->get($productId), 3),
                'source' => 'store_product',
            ];
        }

        return ['prices' => $prices, 'errors' => $errors];
    }

    public function modifierDelta(
        SmartOrderingSite $site,
        SmartOrderingModifierOption $option,
        ?SmartOrderingBranch $branch = null,
    ): float {
        $this->assertBranch($site, $branch);

        $belongs = DB::table('smart_ordering_modifier_groups')
            ->join('smart_ordering_menu_items', 'smart_ordering_menu_items.id', '=', 'smart_ordering_modifier_groups.item_id')
            ->where('smart_ordering_modifier_groups.id', $option->group_id)
            ->where('smart_ordering_menu_items.site_id', $site->id)
            ->exists();
        abort_unless($belongs, 404);

        if ($branch !== null) {
            $value = DB::table('smart_ordering_modifier_option_branch_overrides')
                ->where('option_id', $option->id)
                ->where('branch_id', $branch->id)
                ->value('price_delta');

            if ($value !== null) {
                return round((float) $value, 3);
            }
        }

        return round((float) $option->price_delta, 3);
    }

    public function assertProductBelongsToStore(SmartOrderingSite $site, int $productId): void
    {
        $owned = DB::table('products')
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

        if (! $owned) {
            throw ValidationException::withMessages([
                'source_product_id' => [__('smart_ordering_menu.validation.product_unavailable')],
            ]);
        }
    }

    private function assertBranch(SmartOrderingSite $site, ?SmartOrderingBranch $branch): void
    {
        if ($branch !== null) {
            abort_unless((int) $branch->site_id === (int) $site->id && $branch->is_active, 404);
        }
    }
}
