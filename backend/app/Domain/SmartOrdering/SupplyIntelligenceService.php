<?php

namespace App\Domain\SmartOrdering;

use App\Domain\Pricing\B2bPriceResolver;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Models\B2bCustomer;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\User;
use App\Services\B2bAccountLedgerService;
use App\Services\WholesalePrincipal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SupplyIntelligenceService
{
    private const DEFAULT_LOOKBACK_DAYS = 28;

    private const TREND_REPLENISHMENT_DAYS = 7.0;

    private const SAFETY_DAYS = 3.0;

    public function __construct(
        private readonly WholesalePrincipal $principal,
        private readonly B2bPriceResolver $pricing,
        private readonly B2bAccountLedgerService $ledger,
    ) {}

    /** @return list<array<string,mixed>> */
    public function menuItems(SmartOrderingSite $site): array
    {
        $revisionId = DB::table('smart_ordering_revisions')
            ->where('site_id', $site->getKey())
            ->orderByRaw("CASE WHEN status = 'published' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->value('id');

        if ($revisionId === null) {
            return [];
        }

        return DB::table('smart_ordering_menu_items')
            ->where('revision_id', $revisionId)
            ->where('site_id', $site->getKey())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['public_id', 'source_product_id', 'name_ar', 'name_en'])
            ->map(static fn (object $row): array => [
                'public_id' => (string) $row->public_id,
                'source_product_id' => $row->source_product_id === null ? null : (int) $row->source_product_id,
                'name_ar' => $row->name_ar,
                'name_en' => $row->name_en,
            ])
            ->all();
    }

    /** @return list<array<string,mixed>> */
    public function foodexProducts(?string $query = null, int $limit = 250): array
    {
        $storeId = $this->principal->storeId();
        $query = trim((string) $query);

        return DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->on('store_products.store_id', '=', 'catalogs.store_id');
            })
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('products.is_active', true)
            ->where('store_products.is_active', true)
            ->when($query !== '', function ($builder) use ($query): void {
                $builder->where(function ($scope) use ($query): void {
                    $scope->where('products.name', 'like', '%'.$query.'%')
                        ->orWhere('products.sku', 'like', '%'.$query.'%')
                        ->orWhere('products.barcode', 'like', '%'.$query.'%');
                });
            })
            ->orderBy('products.name')
            ->limit(max(1, min(500, $limit)))
            ->get(['products.id', 'products.sku', 'products.name', 'products.category_id', 'store_products.price'])
            ->map(fn (object $product): array => [
                'id' => (int) $product->id,
                'sku' => (string) $product->sku,
                'name' => (string) $product->name,
                'category_id' => $product->category_id === null ? null : (int) $product->category_id,
                'catalog_price' => $product->price === null ? null : (float) $product->price,
                'available_quantity' => $this->liveAvailable($storeId, (int) $product->id),
            ])
            ->all();
    }

    /** @return list<array<string,mixed>> */
    public function restaurantProducts(SmartOrderingSite $site): array
    {
        return DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->where('catalogs.store_id', $site->store_id)
            ->where('catalogs.channel', 'b2c')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('products.is_active', true)
            ->orderBy('products.name')
            ->limit(500)
            ->get(['products.id', 'products.sku', 'products.name'])
            ->map(static fn (object $product): array => [
                'id' => (int) $product->id,
                'sku' => (string) $product->sku,
                'name' => (string) $product->name,
            ])
            ->all();
    }

    /** @return list<array<string,mixed>> */
    public function mappings(SmartOrderingSite $site): array
    {
        return DB::table('smart_ordering_supply_mappings as m')
            ->join('products as p', 'p.id', '=', 'm.foodex_product_id')
            ->leftJoin('smart_ordering_branches as b', 'b.id', '=', 'm.branch_id')
            ->where('m.site_id', $site->getKey())
            ->orderByDesc('m.effective_from')
            ->orderByDesc('m.id')
            ->limit(500)
            ->get([
                'm.id', 'm.public_id', 'm.branch_id', 'm.menu_item_public_id',
                'm.variant_product_id', 'm.foodex_product_id', 'm.quantity_per_item',
                'm.waste_factor', 'm.effective_from', 'm.effective_to', 'm.is_active',
                'p.sku', 'p.name as product_name', 'p.is_active as product_active',
                'b.name_ar as branch_name_ar', 'b.name_en as branch_name_en',
            ])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'public_id' => (string) $row->public_id,
                'branch_id' => $row->branch_id === null ? null : (int) $row->branch_id,
                'branch_name_ar' => $row->branch_name_ar,
                'branch_name_en' => $row->branch_name_en,
                'menu_item_public_id' => (string) $row->menu_item_public_id,
                'variant_product_id' => $row->variant_product_id === null ? null : (int) $row->variant_product_id,
                'foodex_product_id' => (int) $row->foodex_product_id,
                'foodex_sku' => (string) $row->sku,
                'foodex_name' => (string) $row->product_name,
                'quantity_per_item' => (float) $row->quantity_per_item,
                'waste_factor' => (float) $row->waste_factor,
                'effective_from' => (string) $row->effective_from,
                'effective_to' => $row->effective_to === null ? null : (string) $row->effective_to,
                'is_active' => (bool) $row->is_active,
                'catalog_active' => (bool) $row->product_active,
            ])
            ->all();
    }

    /** @param array<string,mixed> $data */
    public function createMapping(User $actor, SmartOrderingSite $site, array $data): array
    {
        $menuItemPublicId = trim((string) ($data['menu_item_public_id'] ?? ''));
        abort_unless(Str::isUuid($menuItemPublicId), 422, 'A valid menu item is required.');

        $item = DB::table('smart_ordering_menu_items')
            ->where('site_id', $site->getKey())
            ->where('public_id', $menuItemPublicId)
            ->orderByDesc('revision_id')
            ->first(['source_product_id']);
        abort_unless($item !== null, 404, 'Menu item does not belong to this Smart Ordering site.');

        $branchId = isset($data['branch_id']) && (int) $data['branch_id'] > 0 ? (int) $data['branch_id'] : null;
        if ($branchId !== null) {
            $this->assertBranch($site, $branchId);
        }

        $variantProductId = isset($data['variant_product_id']) && (int) $data['variant_product_id'] > 0
            ? (int) $data['variant_product_id']
            : null;
        if ($variantProductId !== null) {
            $this->assertRestaurantProduct((int) $site->store_id, $variantProductId);
        }

        $foodexProductId = (int) ($data['foodex_product_id'] ?? 0);
        $this->assertFoodexProduct($foodexProductId);

        $quantity = round((float) ($data['quantity_per_item'] ?? 0), 6);
        $waste = round((float) ($data['waste_factor'] ?? 0), 6);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity_per_item' => ['Quantity per sold item must be greater than zero.']]);
        }
        if ($waste < 0 || $waste > 1) {
            throw ValidationException::withMessages(['waste_factor' => ['Waste factor must be between 0 and 1.']]);
        }

        $effectiveFrom = CarbonImmutable::parse((string) ($data['effective_from'] ?? 'now'));
        $effectiveTo = isset($data['effective_to']) && trim((string) $data['effective_to']) !== ''
            ? CarbonImmutable::parse((string) $data['effective_to'])
            : null;
        if ($effectiveTo !== null && $effectiveTo->lte($effectiveFrom)) {
            throw ValidationException::withMessages(['effective_to' => ['Effective end must be after effective start.']]);
        }

        $publicId = (string) Str::uuid();
        DB::table('smart_ordering_supply_mappings')->insert([
            'public_id' => $publicId,
            'site_id' => (int) $site->getKey(),
            'branch_id' => $branchId,
            'menu_item_public_id' => $menuItemPublicId,
            'variant_product_id' => $variantProductId,
            'foodex_product_id' => $foodexProductId,
            'quantity_per_item' => $quantity,
            'waste_factor' => $waste,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'is_active' => true,
            'created_by' => (int) $actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return collect($this->mappings($site))->firstWhere('public_id', $publicId) ?? [];
    }

    public function deactivateMapping(User $actor, SmartOrderingSite $site, string $publicId): void
    {
        $mapping = DB::table('smart_ordering_supply_mappings')
            ->where('site_id', $site->getKey())
            ->where('public_id', $publicId)
            ->first();
        abort_unless($mapping !== null, 404);

        DB::table('smart_ordering_supply_mappings')
            ->where('id', $mapping->id)
            ->update([
                'is_active' => false,
                'effective_to' => $mapping->effective_to ?? now(),
                'updated_at' => now(),
            ]);
    }

    public function setAuthoritativeStock(
        User $actor,
        SmartOrderingSite $site,
        int $foodexProductId,
        ?int $branchId,
        float $onHand,
        string $source,
        CarbonImmutable $countedAt,
    ): void {
        if ($branchId !== null) {
            $this->assertBranch($site, $branchId);
        }
        $this->assertFoodexProduct($foodexProductId);
        if ($onHand < 0) {
            throw ValidationException::withMessages(['on_hand_quantity' => ['On-hand quantity cannot be negative.']]);
        }
        $source = trim($source);
        if ($source === '') {
            throw ValidationException::withMessages(['source' => ['An authoritative stock source is required.']]);
        }

        DB::transaction(function () use ($actor, $site, $foodexProductId, $branchId, $onHand, $source, $countedAt): void {
            $query = DB::table('smart_ordering_supply_stock')
                ->where('site_id', $site->getKey())
                ->where('foodex_product_id', $foodexProductId)
                ->when($branchId === null, fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where('branch_id', $branchId));
            $existing = $query->lockForUpdate()->first(['id']);

            $values = [
                'branch_id' => $branchId,
                'on_hand_quantity' => round($onHand, 6),
                'source' => mb_substr($source, 0, 120),
                'counted_at' => $countedAt->utc(),
                'updated_by' => (int) $actor->getKey(),
                'updated_at' => now(),
            ];

            if ($existing === null) {
                DB::table('smart_ordering_supply_stock')->insert([
                    'site_id' => (int) $site->getKey(),
                    'foodex_product_id' => $foodexProductId,
                    ...$values,
                    'created_at' => now(),
                ]);
            } else {
                DB::table('smart_ordering_supply_stock')->where('id', $existing->id)->update($values);
            }

            DB::table('smart_ordering_supply_stock_snapshots')->insert([
                'site_id' => (int) $site->getKey(),
                'branch_id' => $branchId,
                'foodex_product_id' => $foodexProductId,
                'on_hand_quantity' => round($onHand, 6),
                'source' => mb_substr($source, 0, 120),
                'counted_at' => $countedAt->utc(),
                'recorded_by' => (int) $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
    }

    public function recordWaste(
        User $actor,
        SmartOrderingSite $site,
        int $foodexProductId,
        ?int $branchId,
        float $quantity,
        string $reason,
        CarbonImmutable $occurredAt,
    ): string {
        if ($branchId !== null) {
            $this->assertBranch($site, $branchId);
        }
        $this->assertFoodexProduct($foodexProductId);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => ['Waste quantity must be greater than zero.']]);
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['A waste reason is required.']]);
        }

        $publicId = (string) Str::uuid();
        DB::table('smart_ordering_supply_waste_events')->insert([
            'public_id' => $publicId,
            'site_id' => (int) $site->getKey(),
            'branch_id' => $branchId,
            'foodex_product_id' => $foodexProductId,
            'quantity' => round($quantity, 6),
            'reason' => mb_substr($reason, 0, 160),
            'occurred_at' => $occurredAt->utc(),
            'recorded_by' => (int) $actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    /** @return list<array<string,mixed>> */
    public function wasteEvents(SmartOrderingSite $site, ?int $branchId = null, int $limit = 100): array
    {
        if ($branchId !== null) {
            $this->assertBranch($site, $branchId);
        }

        return DB::table('smart_ordering_supply_waste_events as w')
            ->join('products as p', 'p.id', '=', 'w.foodex_product_id')
            ->leftJoin('smart_ordering_branches as b', 'b.id', '=', 'w.branch_id')
            ->where('w.site_id', $site->getKey())
            ->when($branchId !== null, fn ($q) => $q->where('w.branch_id', $branchId))
            ->orderByDesc('w.occurred_at')
            ->orderByDesc('w.id')
            ->limit(max(1, min(500, $limit)))
            ->get([
                'w.public_id', 'w.quantity', 'w.reason', 'w.occurred_at',
                'p.sku', 'p.name as product_name',
                'b.name_ar as branch_name_ar', 'b.name_en as branch_name_en',
            ])
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    /** @return list<array<string,mixed>> */
    public function stockHistory(SmartOrderingSite $site, ?int $branchId = null, int $limit = 100): array
    {
        if ($branchId !== null) {
            $this->assertBranch($site, $branchId);
        }

        return DB::table('smart_ordering_supply_stock_snapshots as s')
            ->join('products as p', 'p.id', '=', 's.foodex_product_id')
            ->leftJoin('smart_ordering_branches as b', 'b.id', '=', 's.branch_id')
            ->where('s.site_id', $site->getKey())
            ->when($branchId !== null, fn ($q) => $q->where('s.branch_id', $branchId))
            ->orderByDesc('s.counted_at')
            ->orderByDesc('s.id')
            ->limit(max(1, min(500, $limit)))
            ->get([
                's.foodex_product_id', 's.on_hand_quantity', 's.source', 's.counted_at',
                'p.sku', 'p.name as product_name',
                'b.name_ar as branch_name_ar', 'b.name_en as branch_name_en',
            ])
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    /** @return list<array<string,mixed>> */
    public function recipeCosts(SmartOrderingSite $site, ?int $branchId = null): array
    {
        if ($branchId !== null) {
            $this->assertBranch($site, $branchId);
        }

        $customer = $this->linkedCustomer($site);
        $principalStoreId = $this->principal->storeId();
        $items = $this->menuItems($site);
        $recipes = [];
        $productIds = [];

        foreach ($items as $item) {
            $mappings = $this->effectiveMappings(
                (int) $site->getKey(),
                $branchId ?? 0,
                (string) $item['public_id'],
                0,
                CarbonImmutable::now('UTC'),
            );
            if ($mappings->isEmpty()) {
                continue;
            }

            $lines = $mappings->map(static function (object $mapping) use (&$productIds): array {
                $productId = (int) $mapping->foodex_product_id;
                $productIds[] = $productId;

                return [
                    'foodex_product_id' => $productId,
                    'quantity_per_item' => (float) $mapping->quantity_per_item,
                    'waste_factor' => (float) $mapping->waste_factor,
                ];
            })->all();

            $recipes[] = [
                'menu_item_public_id' => (string) $item['public_id'],
                'name_ar' => $item['name_ar'],
                'name_en' => $item['name_en'],
                'lines' => $lines,
            ];
        }

        $prices = [];
        if ($customer instanceof B2bCustomer && $productIds !== []) {
            try {
                $prices = $this->pricing->resolveMany(
                    $customer,
                    collect($productIds)->unique()->map(static fn (int $id): array => [
                        'store_id' => $principalStoreId,
                        'product_id' => $id,
                    ])->values()->all(),
                );
            } catch (Throwable) {
                $prices = [];
            }
        }
        $products = $this->productRows(array_values(array_unique($productIds)));

        return collect($recipes)->map(function (array $recipe) use ($prices, $products, $principalStoreId): array {
            $cost = 0.0;
            $complete = true;
            $recipe['lines'] = collect($recipe['lines'])->map(function (array $line) use ($prices, $products, $principalStoreId, &$cost, &$complete): array {
                $productId = (int) $line['foodex_product_id'];
                $price = $prices[$principalStoreId.':'.$productId] ?? null;
                $unitCost = is_array($price) ? (float) $price['price'] : null;
                $lineCost = $unitCost === null
                    ? null
                    : round((float) $line['quantity_per_item'] * (1 + (float) $line['waste_factor']) * $unitCost, 3);
                if ($lineCost === null) {
                    $complete = false;
                } else {
                    $cost += $lineCost;
                }

                return [
                    ...$line,
                    'sku' => $products[$productId]['sku'] ?? null,
                    'product_name' => $products[$productId]['name'] ?? null,
                    'unit_cost' => $unitCost,
                    'line_cost' => $lineCost,
                ];
            })->all();
            $recipe['estimated_cost'] = $complete ? round($cost, 3) : null;
            $recipe['cost_complete'] = $complete;

            return $recipe;
        })->values()->all();
    }

    /** @return list<array<string,mixed>> */
    public function stockVariance(
        SmartOrderingSite $site,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $branchId = null,
    ): array {
        $consumption = collect($this->consumption($site, $from, $to, $branchId)['products'])
            ->keyBy('foodex_product_id');

        $snapshots = DB::table('smart_ordering_supply_stock_snapshots')
            ->where('site_id', $site->getKey())
            ->when($branchId === null, fn ($q) => $q, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('counted_at', [$from->utc(), $to->utc()])
            ->orderBy('counted_at')
            ->orderBy('id')
            ->get()
            ->groupBy('foodex_product_id');

        $waste = DB::table('smart_ordering_supply_waste_events')
            ->where('site_id', $site->getKey())
            ->when($branchId === null, fn ($q) => $q, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('occurred_at', [$from->utc(), $to->utc()])
            ->groupBy('foodex_product_id')
            ->selectRaw('foodex_product_id, SUM(quantity) as quantity')
            ->pluck('quantity', 'foodex_product_id');

        $ids = $snapshots->keys()->merge($consumption->keys())->unique()->map(fn ($id) => (int) $id)->values()->all();
        $products = $this->productRows($ids);

        return collect($ids)->map(function (int $productId) use ($snapshots, $consumption, $waste, $products): array {
            $group = $snapshots->get($productId, collect());
            $opening = $group->first();
            $closing = $group->last();
            $observed = $group->count() >= 2
                ? round((float) $opening->on_hand_quantity - (float) $closing->on_hand_quantity, 6)
                : null;
            $theoretical = (float) ($consumption->get($productId)['estimated_usage'] ?? 0.0);
            $recordedWaste = round((float) ($waste[$productId] ?? 0.0), 6);

            return [
                'foodex_product_id' => $productId,
                'sku' => $products[$productId]['sku'] ?? null,
                'name' => $products[$productId]['name'] ?? null,
                'opening_on_hand' => $opening?->on_hand_quantity === null ? null : (float) $opening->on_hand_quantity,
                'closing_on_hand' => $closing?->on_hand_quantity === null ? null : (float) $closing->on_hand_quantity,
                'observed_stock_delta' => $observed,
                'theoretical_usage' => round($theoretical, 6),
                'recorded_waste' => $recordedWaste,
                'variance' => $observed === null ? null : round($observed - $theoretical, 6),
                'evidence' => $observed === null ? 'insufficient_stock_snapshots' : 'snapshot_delta_only',
            ];
        })->values()->all();
    }

    /**
     * @return array{
     *   disclaimer:string,from:string,to:string,total_completed_orders:int,total_lines:int,
     *   mapped_lines:int,coverage:float,confidence:string,products:list<array<string,mixed>>
     * }
     */
    public function consumption(
        SmartOrderingSite $site,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?int $branchId = null,
    ): array {
        if ($branchId !== null) {
            $this->assertBranch($site, $branchId);
        }

        $to = ($to ?? CarbonImmutable::now('Asia/Kuwait'))->endOfDay();
        $from = ($from ?? $to->subDays(self::DEFAULT_LOOKBACK_DAYS - 1))->startOfDay();
        abort_if($from->gt($to), 422, 'Consumption start must be before end.');

        $contexts = DB::table('smart_ordering_order_contexts as c')
            ->join('smart_ordering_branches as b', 'b.id', '=', 'c.branch_id')
            ->join('orders as o', 'o.id', '=', 'c.order_id')
            ->where('b.site_id', $site->getKey())
            ->where('c.lifecycle_status', 'COMPLETED')
            ->when($branchId !== null, fn ($q) => $q->where('c.branch_id', $branchId))
            ->whereNotIn(DB::raw('LOWER(o.status)'), ['cancelled', 'canceled', 'void', 'voided', 'refunded', 'refund'])
            ->whereExists(function ($query) use ($from, $to): void {
                $query->selectRaw('1')
                    ->from('smart_ordering_order_events as e')
                    ->whereColumn('e.order_context_id', 'c.id')
                    ->where('e.to_status', 'COMPLETED')
                    ->whereBetween('e.created_at', [$from->utc(), $to->utc()]);
            })
            ->get([
                'c.id', 'c.branch_id', 'c.revision_public_id', 'c.cart_snapshot',
                'b.name_ar as branch_name_ar', 'b.name_en as branch_name_en',
            ]);

        if ($contexts->isEmpty()) {
            return $this->emptyConsumption($from, $to);
        }

        $completedAt = DB::table('smart_ordering_order_events')
            ->whereIn('order_context_id', $contexts->pluck('id'))
            ->where('to_status', 'COMPLETED')
            ->groupBy('order_context_id')
            ->selectRaw('order_context_id, MAX(created_at) as completed_at')
            ->pluck('completed_at', 'order_context_id');

        $totals = [];
        $totalLines = 0;
        $mappedLines = 0;

        foreach ($contexts as $context) {
            $snapshot = $this->decodeJson($context->cart_snapshot);
            $at = CarbonImmutable::parse((string) ($completedAt[$context->id] ?? now()), 'UTC')->setTimezone('Asia/Kuwait');

            foreach ((array) ($snapshot['items'] ?? []) as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $totalLines++;
                $quantity = max(0.0, (float) ($line['quantity'] ?? 0));
                if ($quantity <= 0) {
                    continue;
                }

                $selection = is_array($line['selection'] ?? null) ? $line['selection'] : [];
                $primaryProductId = isset($selection['variant_product_id'])
                    ? (int) $selection['variant_product_id']
                    : (int) ($line['product_id'] ?? 0);
                $menuItemPublicId = isset($line['menu_item_public_id']) && Str::isUuid((string) $line['menu_item_public_id'])
                    ? (string) $line['menu_item_public_id']
                    : $this->inferMenuItemPublicId(
                        (int) $site->getKey(),
                        (string) ($context->revision_public_id ?? ''),
                        (int) ($line['product_id'] ?? 0),
                    );

                if ($menuItemPublicId === null) {
                    continue;
                }

                $mappings = $this->effectiveMappings(
                    (int) $site->getKey(),
                    (int) $context->branch_id,
                    $menuItemPublicId,
                    $primaryProductId,
                    $at,
                );

                if ($mappings->isEmpty()) {
                    continue;
                }
                $mappedLines++;

                foreach ($mappings as $mapping) {
                    $productId = (int) $mapping->foodex_product_id;
                    $estimated = round(
                        $quantity * (float) $mapping->quantity_per_item * (1 + (float) $mapping->waste_factor),
                        6,
                    );
                    if ($estimated <= 0) {
                        continue;
                    }

                    if (! isset($totals[$productId])) {
                        $totals[$productId] = [
                            'foodex_product_id' => $productId,
                            'estimated_usage' => 0.0,
                            'daily' => [],
                            'weekly' => [],
                            'branches' => [],
                        ];
                    }

                    $day = $at->toDateString();
                    $week = $at->startOfWeek()->toDateString();
                    $totals[$productId]['estimated_usage'] += $estimated;
                    $totals[$productId]['daily'][$day] = ($totals[$productId]['daily'][$day] ?? 0) + $estimated;
                    $totals[$productId]['weekly'][$week] = ($totals[$productId]['weekly'][$week] ?? 0) + $estimated;
                    $totals[$productId]['branches'][(int) $context->branch_id] =
                        ($totals[$productId]['branches'][(int) $context->branch_id] ?? 0) + $estimated;
                }
            }
        }

        $productRows = $this->productRows(array_keys($totals));
        $products = collect($totals)->map(function (array $row) use ($productRows): array {
            $product = $productRows[(int) $row['foodex_product_id']] ?? null;
            $row['sku'] = $product['sku'] ?? null;
            $row['name'] = $product['name'] ?? null;
            $row['catalog_active'] = (bool) ($product['active'] ?? false);
            $row['estimated_usage'] = round((float) $row['estimated_usage'], 6);
            $row['daily'] = collect($row['daily'])->map(fn ($v) => round((float) $v, 6))->all();
            $row['weekly'] = collect($row['weekly'])->map(fn ($v) => round((float) $v, 6))->all();
            $row['branches'] = collect($row['branches'])->map(fn ($v) => round((float) $v, 6))->all();

            return $row;
        })->sortByDesc('estimated_usage')->values()->all();

        $coverage = $totalLines === 0 ? 0.0 : round($mappedLines / $totalLines, 4);

        return [
            'disclaimer' => 'Estimated usage derived from completed Smart Ordering sales. It is not counted or physical inventory.',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_completed_orders' => $contexts->count(),
            'total_lines' => $totalLines,
            'mapped_lines' => $mappedLines,
            'coverage' => $coverage,
            'confidence' => $coverage >= 0.90 ? 'high' : ($coverage >= 0.60 ? 'medium' : 'low'),
            'products' => $products,
        ];
    }

    /** @return array<string,mixed> */
    public function recommendations(
        SmartOrderingSite $site,
        ?int $branchId = null,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): array {
        $consumption = $this->consumption($site, $from, $to, $branchId);
        $days = max(
            1,
            CarbonImmutable::parse($consumption['from'])->diffInDays(CarbonImmutable::parse($consumption['to'])) + 1,
        );
        $principalStoreId = $this->principal->storeId();
        $customer = $this->linkedCustomer($site);
        $prices = [];
        if ($customer instanceof B2bCustomer && $consumption['products'] !== []) {
            try {
                $prices = $this->pricing->resolveMany(
                    $customer,
                    array_map(
                        static fn (array $row): array => [
                            'store_id' => $principalStoreId,
                            'product_id' => (int) $row['foodex_product_id'],
                        ],
                        $consumption['products'],
                    ),
                );
            } catch (Throwable) {
                $prices = [];
            }
        }

        $rows = [];
        foreach ($consumption['products'] as $usage) {
            $productId = (int) $usage['foodex_product_id'];
            $velocity = round((float) $usage['estimated_usage'] / $days, 6);
            $stock = $this->authoritativeStock($site, $productId, $branchId);
            $targetDays = $stock === null
                ? self::TREND_REPLENISHMENT_DAYS
                : self::TREND_REPLENISHMENT_DAYS + self::SAFETY_DAYS;
            $grossNeed = $stock === null
                ? $velocity * $targetDays
                : max(0.0, ($velocity * $targetDays) - (float) $stock['on_hand_quantity']);
            $daysOfSupply = $stock !== null && $velocity > 0
                ? round((float) $stock['on_hand_quantity'] / $velocity, 2)
                : null;

            $price = $prices[$principalStoreId.':'.$productId] ?? null;
            $available = $this->liveAvailable($principalStoreId, $productId);
            $active = (bool) ($usage['catalog_active'] ?? false);
            $suggested = 0.0;
            $unconstrained = 0.0;

            if ($active && is_array($price) && $grossNeed > 0) {
                $unconstrained = $this->roundUp(
                    $grossNeed,
                    (float) $price['minimum_quantity'],
                    (float) $price['ordering_increment'],
                );
                $suggested = min(
                    $unconstrained,
                    $this->roundDown(
                        $available,
                        (float) $price['minimum_quantity'],
                        (float) $price['ordering_increment'],
                    ),
                );
            }

            $dismissed = DB::table('smart_ordering_supply_dismissals')
                ->where('site_id', $site->getKey())
                ->where('foodex_product_id', $productId)
                ->where('dismissed_until', '>', now())
                ->when($branchId === null, fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where(function ($scope) use ($branchId): void {
                    $scope->whereNull('branch_id')->orWhere('branch_id', $branchId);
                }))
                ->exists();

            $rows[] = [
                ...$usage,
                'daily_estimated_usage' => $velocity,
                'authoritative_stock' => $stock,
                'days_of_supply' => $daysOfSupply,
                'reorder_basis' => $stock === null ? 'consumption_trend_only' : 'authoritative_stock_plus_consumption',
                'target_days' => $targetDays,
                'gross_need' => round($grossNeed, 6),
                'current_price' => is_array($price) ? round((float) $price['price'], 3) : null,
                'minimum_quantity' => is_array($price) ? (float) $price['minimum_quantity'] : null,
                'ordering_increment' => is_array($price) ? (float) $price['ordering_increment'] : null,
                'foodex_available_quantity' => round($available, 3),
                'unconstrained_quantity' => round($unconstrained, 3),
                'recommended_quantity' => round($suggested, 3),
                'estimated_cost' => is_array($price) ? round($suggested * (float) $price['price'], 3) : null,
                'is_executable' => $active && $customer instanceof B2bCustomer && is_array($price) && $suggested > 0 && ! $dismissed,
                'dismissed' => $dismissed,
                'state' => match (true) {
                    ! $active => 'stale_or_inactive_sku',
                    ! $customer instanceof B2bCustomer => 'wholesale_account_unavailable',
                    ! is_array($price) => 'current_pricing_unavailable',
                    $grossNeed <= 0 => 'no_reorder_needed',
                    $available <= 0 => 'foodex_unavailable',
                    $suggested + 0.0001 < $unconstrained => 'availability_limited',
                    $dismissed => 'dismissed',
                    default => 'ready_for_draft',
                },
                'explanation' => [
                    'formula' => $stock === null
                        ? 'daily_estimated_usage * 7 days'
                        : '(daily_estimated_usage * 10 days) - authoritative_on_hand',
                    'facts' => [
                        'completed_order_coverage' => $consumption['coverage'],
                        'estimated_usage' => (float) $usage['estimated_usage'],
                        'period_days' => $days,
                        'daily_estimated_usage' => $velocity,
                        'authoritative_on_hand' => $stock['on_hand_quantity'] ?? null,
                        'foodex_availability' => round($available, 3),
                    ],
                ],
            ];
        }

        return [
            'disclaimer' => $consumption['disclaimer'],
            'coverage' => $consumption['coverage'],
            'confidence' => $consumption['confidence'],
            'rows' => collect($rows)
                ->sortByDesc(fn (array $row): float => (float) $row['estimated_cost'])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<int|string,mixed>  $quantities
     * @return array<string,mixed>
     */
    public function createDraft(
        User $actor,
        SmartOrderingSite $site,
        array $quantities,
        ?int $branchId = null,
    ): array {
        if ($branchId !== null) {
            $this->assertBranch($site, $branchId);
        }

        $customer = $this->linkedCustomer($site);
        abort_unless($customer instanceof B2bCustomer, 409, 'A linked FOODEX wholesale account is required.');

        $principalStoreId = $this->principal->storeId();
        $fresh = collect($this->recommendations($site, $branchId)['rows'])
            ->keyBy('foodex_product_id');

        $selected = [];
        foreach ($quantities as $productId => $raw) {
            $productId = (int) $productId;
            $quantity = round((float) $raw, 3);
            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $row = $fresh->get($productId);
            if (! is_array($row) || ! (bool) ($row['is_executable'] ?? false)) {
                throw ValidationException::withMessages(['quantities' => ['A selected recommendation is stale or no longer executable.']]);
            }
            if ($quantity > (float) $row['recommended_quantity'] + 0.0001) {
                throw ValidationException::withMessages(['quantities' => ['Requested quantity exceeds the current deterministic recommendation.']]);
            }

            $price = $this->pricing->resolve($customer, $principalStoreId, $productId);
            $this->assertOrderQuantity($quantity, (float) $price['minimum_quantity'], (float) $price['ordering_increment']);
            abort_if($quantity > $this->liveAvailable($principalStoreId, $productId) + 0.0001, 409, 'FOODEX availability changed. Review the draft again.');

            $selected[] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price' => (float) $price['price'],
                'snapshot' => $row,
            ];
        }

        if ($selected === []) {
            throw ValidationException::withMessages(['quantities' => ['Select at least one current recommendation.']]);
        }

        return DB::transaction(function () use ($actor, $site, $customer, $principalStoreId, $selected, $branchId): array {
            $cart = Cart::query()
                ->where('store_id', $principalStoreId)
                ->where('b2b_customer_id', $customer->getKey())
                ->where('channel', 'b2b')
                ->lockForUpdate()
                ->first();

            if (! $cart instanceof Cart) {
                $cart = Cart::query()->create([
                    'store_id' => $principalStoreId,
                    'customer_id' => $customer->legacy_customer_id,
                    'b2b_customer_id' => $customer->getKey(),
                    'channel' => 'b2b',
                    'guest_token' => null,
                ]);
            }

            $existing = CartItem::query()
                ->where('cart_id', $cart->getKey())
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            $existingCost = 0.0;
            foreach ($existing as $item) {
                $price = $this->pricing->resolve($customer, $principalStoreId, (int) $item->product_id);
                $this->assertOrderQuantity((float) $item->quantity, (float) $price['minimum_quantity'], (float) $price['ordering_increment']);
                abort_if(
                    (float) $item->quantity > $this->liveAvailable($principalStoreId, (int) $item->product_id) + 0.0001,
                    409,
                    'Existing wholesale cart requires review because availability changed.',
                );
                $existingCost += (float) $item->quantity * (float) $price['price'];
            }

            $mutations = [];
            $incrementalCost = 0.0;
            foreach ($selected as $line) {
                $price = $this->pricing->resolve($customer, $principalStoreId, $line['product_id']);
                $current = $existing->get($line['product_id']);
                $currentQuantity = $current instanceof CartItem ? (float) $current->quantity : 0.0;
                $target = max($currentQuantity, (float) $line['quantity']);
                $this->assertOrderQuantity($target, (float) $price['minimum_quantity'], (float) $price['ordering_increment']);
                abort_if($target > $this->liveAvailable($principalStoreId, $line['product_id']) + 0.0001, 409, 'FOODEX availability changed.');

                $increment = max(0.0, $target - $currentQuantity);
                $incrementalCost += $increment * (float) $price['price'];
                $mutations[] = [
                    'item' => $current,
                    'product_id' => $line['product_id'],
                    'target' => $target,
                    'unit_price' => (float) $price['price'],
                    'increment' => $increment,
                ];
            }

            $finance = $this->ledger->summary($customer, $principalStoreId);
            abort_if(
                $existingCost + $incrementalCost > (float) $finance['purchasing_power'] + 0.0001,
                409,
                'The reviewed draft exceeds current FOODEX purchasing power.',
            );

            foreach ($mutations as $mutation) {
                $item = $mutation['item'];
                if (! $item instanceof CartItem) {
                    $item = new CartItem([
                        'cart_id' => $cart->getKey(),
                        'product_id' => $mutation['product_id'],
                    ]);
                }
                $item->quantity = round((float) $mutation['target'], 3);
                $item->unit_price_snapshot = round((float) $mutation['unit_price'], 3);
                $item->save();
            }

            $publicId = (string) Str::uuid();
            DB::table('smart_ordering_supply_reorder_drafts')->insert([
                'public_id' => $publicId,
                'site_id' => (int) $site->getKey(),
                'branch_id' => $branchId,
                'b2b_customer_id' => (int) $customer->getKey(),
                'cart_id' => (int) $cart->getKey(),
                'converted_order_id' => null,
                'currency' => (string) $finance['currency'],
                'assisted_value' => round($incrementalCost, 3),
                'recommendation_snapshot' => json_encode([
                    'source' => 'smart_ordering_supply_intelligence_v1',
                    'lines' => array_map(static fn (array $line): array => [
                        'product_id' => $line['product_id'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'reorder_basis' => $line['snapshot']['reorder_basis'] ?? null,
                        'explanation' => $line['snapshot']['explanation'] ?? null,
                    ], $selected),
                ], JSON_THROW_ON_ERROR),
                'created_by' => (int) $actor->getKey(),
                'converted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'draft_public_id' => $publicId,
                'cart_id' => (int) $cart->getKey(),
                'selected_lines' => count($selected),
                'assisted_value' => round($incrementalCost, 3),
                'currency' => (string) $finance['currency'],
                'order_created' => false,
                'message' => 'Draft FOODEX B2B basket updated. No purchase was submitted.',
            ];
        }, 3);
    }

    public function dismiss(
        User $actor,
        SmartOrderingSite $site,
        int $foodexProductId,
        ?int $branchId,
        int $days,
        ?string $reason,
    ): void {
        if ($branchId !== null) {
            $this->assertBranch($site, $branchId);
        }
        $this->assertFoodexProduct($foodexProductId);

        DB::table('smart_ordering_supply_dismissals')->insert([
            'site_id' => (int) $site->getKey(),
            'branch_id' => $branchId,
            'foodex_product_id' => $foodexProductId,
            'dismissed_until' => now()->addDays(max(1, min(90, $days))),
            'reason' => $reason === null ? null : mb_substr(trim($reason), 0, 500),
            'dismissed_by' => (int) $actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    public function commercialInsight(SmartOrderingSite $site): array
    {
        $base = DB::table('smart_ordering_supply_reorder_drafts')
            ->where('site_id', $site->getKey());

        $draftCount = (clone $base)->count();
        $convertedCount = (clone $base)->whereNotNull('converted_order_id')->count();

        return [
            'mapped_menu_items' => DB::table('smart_ordering_supply_mappings')
                ->where('site_id', $site->getKey())
                ->where('is_active', true)
                ->distinct()
                ->count('menu_item_public_id'),
            'assisted_drafts' => $draftCount,
            'assisted_draft_value' => round((float) (clone $base)->sum('assisted_value'), 3),
            'converted_assisted_orders' => $convertedCount,
            'attributed_foodex_sales_value' => round((float) (clone $base)
                ->whereNotNull('converted_order_id')
                ->sum('assisted_value'), 3),
            'attribution_rule' => 'Exact FOODEX B2B cart created/updated by Smart Ordering supply assistance and later checked out.',
        ];
    }

    /** @return list<array<string,mixed>> */
    public function relatedProducts(SmartOrderingSite $site, int $limit = 8): array
    {
        $mappedIds = DB::table('smart_ordering_supply_mappings')
            ->where('site_id', $site->getKey())
            ->where('is_active', true)
            ->pluck('foodex_product_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($mappedIds->isEmpty()) {
            return [];
        }

        $categories = DB::table('products')->whereIn('id', $mappedIds)->pluck('category_id')->filter()->unique();
        if ($categories->isEmpty()) {
            return [];
        }

        $storeId = $this->principal->storeId();

        return DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('store_products', function ($join): void {
                $join->on('store_products.product_id', '=', 'products.id')
                    ->on('store_products.store_id', '=', 'catalogs.store_id');
            })
            ->where('catalogs.store_id', $storeId)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_active', true)
            ->where('store_products.is_active', true)
            ->where('products.is_active', true)
            ->whereIn('products.category_id', $categories)
            ->whereNotIn('products.id', $mappedIds)
            ->orderBy('products.name')
            ->limit(max(1, min(20, $limit)))
            ->get(['products.id', 'products.sku', 'products.name', 'store_products.price'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'sku' => (string) $row->sku,
                'name' => (string) $row->name,
                'price' => $row->price === null ? null : (float) $row->price,
                'available_quantity' => $this->liveAvailable($storeId, (int) $row->id),
            ])
            ->filter(static fn (array $row): bool => $row['available_quantity'] > 0)
            ->values()
            ->all();
    }

    private function linkedCustomer(SmartOrderingSite $site): ?B2bCustomer
    {
        $id = DB::table('retail_wholesale_accounts')
            ->where('retail_store_id', $site->store_id)
            ->value('b2b_customer_id');

        return $id === null ? null : B2bCustomer::query()->find((int) $id);
    }

    private function assertBranch(SmartOrderingSite $site, int $branchId): void
    {
        abort_unless(
            DB::table('smart_ordering_branches')
                ->where('id', $branchId)
                ->where('site_id', $site->getKey())
                ->exists(),
            404,
        );
    }

    private function assertRestaurantProduct(int $storeId, int $productId): void
    {
        abort_unless(
            DB::table('products')
                ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                ->where('products.id', $productId)
                ->where('catalogs.store_id', $storeId)
                ->where('catalogs.channel', 'b2c')
                ->where('products.is_active', true)
                ->exists(),
            422,
            'Variant product does not belong to this restaurant.',
        );
    }

    private function assertFoodexProduct(int $productId): void
    {
        $storeId = $this->principal->storeId();
        abort_unless(
            DB::table('products')
                ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
                ->join('store_products', function ($join): void {
                    $join->on('store_products.product_id', '=', 'products.id')
                        ->on('store_products.store_id', '=', 'catalogs.store_id');
                })
                ->where('products.id', $productId)
                ->where('catalogs.store_id', $storeId)
                ->where('catalogs.channel', 'b2b')
                ->where('catalogs.is_active', true)
                ->where('products.is_active', true)
                ->where('store_products.is_active', true)
                ->exists(),
            422,
            'FOODEX SKU is stale, inactive, or outside the authoritative wholesale catalog.',
        );
    }

    private function inferMenuItemPublicId(int $siteId, string $revisionPublicId, int $productId): ?string
    {
        if ($productId <= 0 || ! Str::isUuid($revisionPublicId)) {
            return null;
        }

        $revisionId = DB::table('smart_ordering_revisions')
            ->where('site_id', $siteId)
            ->where('public_id', $revisionPublicId)
            ->value('id');
        if ($revisionId === null) {
            return null;
        }

        $ids = DB::table('smart_ordering_menu_items')
            ->where('revision_id', $revisionId)
            ->where('site_id', $siteId)
            ->where('source_product_id', $productId)
            ->pluck('public_id')
            ->unique()
            ->values();

        return $ids->count() === 1 ? (string) $ids->first() : null;
    }

    /** @return Collection<int,\stdClass> */
    private function effectiveMappings(
        int $siteId,
        int $branchId,
        string $menuItemPublicId,
        int $variantProductId,
        CarbonImmutable $at,
    ): Collection {
        $rows = DB::table('smart_ordering_supply_mappings')
            ->where('site_id', $siteId)
            ->where('menu_item_public_id', $menuItemPublicId)
            ->where('effective_from', '<=', $at->utc())
            ->where(function ($query) use ($at): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $at->utc());
            })
            ->where(function ($query) use ($branchId): void {
                $query->whereNull('branch_id')->orWhere('branch_id', $branchId);
            })
            ->where(function ($query) use ($variantProductId): void {
                $query->whereNull('variant_product_id');
                if ($variantProductId > 0) {
                    $query->orWhere('variant_product_id', $variantProductId);
                }
            })
            ->orderByDesc(DB::raw('branch_id IS NOT NULL'))
            ->orderByDesc(DB::raw('variant_product_id IS NOT NULL'))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        return $rows
            ->groupBy('foodex_product_id')
            ->map(static fn (Collection $group): object => $group->first())
            ->values();
    }

    /** @return array<int,array{sku:string,name:string,active:bool}> */
    private function productRows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('products')
            ->whereIn('id', $ids)
            ->get(['id', 'sku', 'name', 'is_active'])
            ->mapWithKeys(static fn (object $row): array => [
                (int) $row->id => [
                    'sku' => (string) $row->sku,
                    'name' => (string) $row->name,
                    'active' => (bool) $row->is_active,
                ],
            ])
            ->all();
    }

    /** @return array<string,mixed>|null */
    private function authoritativeStock(SmartOrderingSite $site, int $productId, ?int $branchId): ?array
    {
        $row = DB::table('smart_ordering_supply_stock')
            ->where('site_id', $site->getKey())
            ->where('foodex_product_id', $productId)
            ->when($branchId === null, fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where(function ($scope) use ($branchId): void {
                $scope->where('branch_id', $branchId)->orWhereNull('branch_id');
            }))
            ->orderByDesc(DB::raw('branch_id IS NOT NULL'))
            ->orderByDesc('counted_at')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'on_hand_quantity' => (float) $row->on_hand_quantity,
            'source' => (string) $row->source,
            'counted_at' => (string) $row->counted_at,
            'branch_id' => $row->branch_id === null ? null : (int) $row->branch_id,
        ];
    }

    private function liveAvailable(int $storeId, int $productId): float
    {
        $rows = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('warehouses.store_id', $storeId)
            ->where('warehouses.is_active', true)
            ->where('inventories.product_id', $productId)
            ->get(['inventories.quantity', 'inventories.reserved_quantity']);

        return round((float) $rows->sum(
            static fn (object $row): float => max(
                0.0,
                (float) $row->quantity - (float) $row->reserved_quantity,
            ),
        ), 3);
    }

    private function roundUp(float $quantity, float $minimum, float $increment): float
    {
        $minimum = max(0.0, $minimum);
        $increment = max(0.001, $increment);
        if ($quantity <= 0) {
            return 0.0;
        }
        if ($quantity <= $minimum) {
            return round($minimum, 3);
        }

        return round($minimum + (ceil((($quantity - $minimum) / $increment) - 0.0000001) * $increment), 3);
    }

    private function roundDown(float $quantity, float $minimum, float $increment): float
    {
        $minimum = max(0.0, $minimum);
        $increment = max(0.001, $increment);
        if ($quantity + 0.0001 < $minimum) {
            return 0.0;
        }

        return round($minimum + (floor((($quantity - $minimum) / $increment) + 0.0000001) * $increment), 3);
    }

    private function assertOrderQuantity(float $quantity, float $minimum, float $increment): void
    {
        $minimum = max(0.0, $minimum);
        $increment = max(0.001, $increment);
        if ($quantity + 0.0001 < $minimum) {
            throw ValidationException::withMessages(['quantities' => ['Quantity is below the current FOODEX minimum.']]);
        }

        $steps = ($quantity - $minimum) / $increment;
        if (abs($steps - round($steps)) > 0.0001) {
            throw ValidationException::withMessages(['quantities' => ['Quantity does not match the current FOODEX ordering increment.']]);
        }
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,mixed> */
    private function emptyConsumption(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'disclaimer' => 'Estimated usage derived from completed Smart Ordering sales. It is not counted or physical inventory.',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_completed_orders' => 0,
            'total_lines' => 0,
            'mapped_lines' => 0,
            'coverage' => 0.0,
            'confidence' => 'low',
            'products' => [],
        ];
    }
}
