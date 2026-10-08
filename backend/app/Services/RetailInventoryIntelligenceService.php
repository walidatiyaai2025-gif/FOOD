<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RetailInventoryIntelligenceService
{
    private const TIMEZONE = 'Asia/Kuwait';

    /** Recent, middle and older window weights. */
    private const VELOCITY_WEIGHTS = [
        'days_1_7' => 0.50,
        'days_8_14' => 0.30,
        'days_15_30' => 0.20,
    ];

    private const FAST_MOVER_UNITS_PER_DAY = 1.0;

    private const SLOW_MOVER_UNITS_PER_DAY = 0.2;

    private const CRITICAL_COVER_DAYS = 3.0;

    private const UNDERSTOCK_COVER_DAYS = 7.0;

    private const OVERSTOCK_COVER_DAYS = 45.0;

    /**
     * Deterministic store-scoped Retail inventory intelligence.
     *
     * @return array{
     *   retail_store_id:int,
     *   timezone:string,
     *   as_of:string,
     *   model:array<string,mixed>,
     *   products:list<array<string,mixed>>
     * }
     */
    public function forStore(int $retailStoreId, ?CarbonImmutable $asOf = null): array
    {
        $this->assertRetailStore($retailStoreId);

        $asOf = ($asOf ?? CarbonImmutable::now(self::TIMEZONE))->setTimezone(self::TIMEZONE);
        $day = $asOf->startOfDay();

        $recentStart = $day->subDays(6)->utc()->toDateTimeString();
        $middleStart = $day->subDays(13)->utc()->toDateTimeString();
        $olderStart = $day->subDays(29)->utc()->toDateTimeString();
        $end = $day->endOfDay()->utc()->toDateTimeString();

        $stockTotals = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('warehouses.store_id', $retailStoreId)
            ->where('warehouses.is_active', true)
            ->groupBy('inventories.product_id')
            ->select('inventories.product_id')
            ->selectRaw('SUM(inventories.quantity) as on_hand')
            ->selectRaw('SUM(inventories.reserved_quantity) as reserved');

        $sales = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $retailStoreId)
            ->where('orders.channel', 'b2c')
            ->whereNotIn('orders.status', ['cancelled', 'refunded'])
            ->whereBetween('orders.created_at', [$olderStart, $end])
            ->groupBy('order_items.product_id')
            ->select('order_items.product_id')
            ->selectRaw(
                'SUM(CASE WHEN orders.created_at >= ? THEN order_items.quantity ELSE 0 END) as units_1_7',
                [$recentStart],
            )
            ->selectRaw(
                'SUM(CASE WHEN orders.created_at >= ? AND orders.created_at < ? THEN order_items.quantity ELSE 0 END) as units_8_14',
                [$middleStart, $recentStart],
            )
            ->selectRaw(
                'SUM(CASE WHEN orders.created_at >= ? AND orders.created_at < ? THEN order_items.quantity ELSE 0 END) as units_15_30',
                [$olderStart, $middleStart],
            )
            ->selectRaw('SUM(order_items.quantity) as units_30')
            ->selectRaw('MAX(orders.created_at) as last_sale_at');

        $products = DB::table('store_products')
            ->join('products', 'products.id', '=', 'store_products.product_id')
            ->leftJoinSub($stockTotals, 'stock_totals', function ($join): void {
                $join->on('stock_totals.product_id', '=', 'products.id');
            })
            ->leftJoinSub($sales, 'sales_totals', function ($join): void {
                $join->on('sales_totals.product_id', '=', 'products.id');
            })
            ->where('store_products.store_id', $retailStoreId)
            ->where('store_products.is_active', true)
            ->where('products.is_active', true)
            ->orderBy('products.name')
            ->get([
                'products.id',
                'products.sku',
                'products.name',
                'store_products.cost_price',
                'store_products.created_at as listed_at',
                'stock_totals.on_hand',
                'stock_totals.reserved',
                'sales_totals.units_1_7',
                'sales_totals.units_8_14',
                'sales_totals.units_15_30',
                'sales_totals.units_30',
                'sales_totals.last_sale_at',
            ]);

        $agingByProduct = $this->aging($retailStoreId, $asOf, $products->keyBy('id')->all());

        return [
            'retail_store_id' => $retailStoreId,
            'timezone' => self::TIMEZONE,
            'as_of' => $asOf->toIso8601String(),
            'model' => [
                'velocity_windows' => [
                    'days_1_7' => ['days' => 7, 'weight' => self::VELOCITY_WEIGHTS['days_1_7']],
                    'days_8_14' => ['days' => 7, 'weight' => self::VELOCITY_WEIGHTS['days_8_14']],
                    'days_15_30' => ['days' => 16, 'weight' => self::VELOCITY_WEIGHTS['days_15_30']],
                ],
                'movement_thresholds' => [
                    'fast_units_per_day' => self::FAST_MOVER_UNITS_PER_DAY,
                    'slow_units_per_day' => self::SLOW_MOVER_UNITS_PER_DAY,
                ],
                'cover_thresholds_days' => [
                    'critical' => self::CRITICAL_COVER_DAYS,
                    'understock' => self::UNDERSTOCK_COVER_DAYS,
                    'overstock' => self::OVERSTOCK_COVER_DAYS,
                ],
                'sales_excluded_statuses' => ['cancelled', 'refunded'],
                'available_stock_formula' => 'on_hand - reserved',
            ],
            'products' => $products
                ->map(function (object $row) use ($asOf, $agingByProduct): array {
                    $onHand = round((float) ($row->on_hand ?? 0), 3);
                    $reserved = round((float) ($row->reserved ?? 0), 3);
                    $available = round($onHand - $reserved, 3);
                    $listedAt = CarbonImmutable::parse((string) $row->listed_at, 'UTC')
                        ->setTimezone(self::TIMEZONE);
                    $historyDays = min(
                        30,
                        max(
                            1,
                            (int) $listedAt->startOfDay()->diffInDays(
                                $asOf->startOfDay(),
                                false,
                            ) + 1,
                        ),
                    );

                    $units = [
                        'days_1_7' => round((float) ($row->units_1_7 ?? 0), 3),
                        'days_8_14' => round((float) ($row->units_8_14 ?? 0), 3),
                        'days_15_30' => round((float) ($row->units_15_30 ?? 0), 3),
                        'days_1_30' => round((float) ($row->units_30 ?? 0), 3),
                    ];

                    [$velocity, $windowDays, $effectiveWeights] = $this->velocity(
                        $units,
                        $historyDays,
                    );
                    $confidence = $this->confidence($historyDays);
                    $daysCover = $velocity > 0
                        ? round(max(0.0, $available) / $velocity, 2)
                        : null;
                    $stockoutAt = $daysCover === null
                        ? null
                        : $asOf->addSeconds((int) round($daysCover * 86400))->toIso8601String();

                    return [
                        'product_id' => (int) $row->id,
                        'sku' => (string) $row->sku,
                        'name' => (string) $row->name,
                        'stock' => [
                            'on_hand' => $onHand,
                            'reserved' => $reserved,
                            'available' => $available,
                        ],
                        'sales' => [
                            'units' => $units,
                            'velocity_units_per_day' => $velocity,
                            'window_days_available' => $windowDays,
                            'effective_weights' => $effectiveWeights,
                            'last_sale_at' => $row->last_sale_at === null
                                ? null
                                : CarbonImmutable::parse((string) $row->last_sale_at, 'UTC')
                                    ->setTimezone(self::TIMEZONE)
                                    ->toIso8601String(),
                        ],
                        'cover' => [
                            'days' => $daysCover,
                            'estimated_stockout_at' => $stockoutAt,
                        ],
                        'movement_class' => $this->movementClass($velocity),
                        'stock_risk' => $this->stockRisk(
                            $available,
                            $velocity,
                            $daysCover,
                            $confidence,
                        ),
                        'confidence' => [
                            'state' => $confidence,
                            'history_days' => $historyDays,
                            'cold_start' => $historyDays < 7,
                        ],
                        'aging' => $agingByProduct[(int) $row->id]
                            ?? $this->emptyAging(
                                max(0.0, $onHand),
                                $row->cost_price === null ? null : (float) $row->cost_price,
                            ),
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array{days_1_7:float,days_8_14:float,days_15_30:float,days_1_30:float}  $units
     * @return array{0:float,1:array<string,int>,2:array<string,float>}
     */
    private function velocity(array $units, int $historyDays): array
    {
        $days = [
            'days_1_7' => min(7, $historyDays),
            'days_8_14' => min(7, max(0, $historyDays - 7)),
            'days_15_30' => min(16, max(0, $historyDays - 14)),
        ];

        $weighted = 0.0;
        $weightTotal = 0.0;
        $effectiveWeights = [];

        foreach (self::VELOCITY_WEIGHTS as $window => $weight) {
            if ($days[$window] <= 0) {
                $effectiveWeights[$window] = 0.0;
                continue;
            }

            $weighted += ($units[$window] / $days[$window]) * $weight;
            $weightTotal += $weight;
            $effectiveWeights[$window] = $weight;
        }

        if ($weightTotal <= 0) {
            return [0.0, $days, $effectiveWeights];
        }

        foreach ($effectiveWeights as $window => $weight) {
            $effectiveWeights[$window] = round($weight / $weightTotal, 3);
        }

        return [round($weighted / $weightTotal, 3), $days, $effectiveWeights];
    }

    private function confidence(int $historyDays): string
    {
        return match (true) {
            $historyDays < 7 => 'insufficient',
            $historyDays < 14 => 'low',
            $historyDays < 30 => 'medium',
            default => 'high',
        };
    }

    private function movementClass(float $velocity): string
    {
        return match (true) {
            $velocity <= 0 => 'no_demand',
            $velocity >= self::FAST_MOVER_UNITS_PER_DAY => 'fast',
            $velocity <= self::SLOW_MOVER_UNITS_PER_DAY => 'slow',
            default => 'normal',
        };
    }

    private function stockRisk(
        float $available,
        float $velocity,
        ?float $daysCover,
        string $confidence,
    ): string {
        if ($available < 0) {
            return 'inventory_inconsistent';
        }

        if ($confidence === 'insufficient') {
            return 'insufficient_data';
        }

        if ($velocity <= 0) {
            return $available > 0 ? 'overstock_no_demand' : 'no_stock_no_demand';
        }

        return match (true) {
            $daysCover !== null && $daysCover <= self::CRITICAL_COVER_DAYS => 'critical_understock',
            $daysCover !== null && $daysCover <= self::UNDERSTOCK_COVER_DAYS => 'understock',
            $daysCover !== null && $daysCover >= self::OVERSTOCK_COVER_DAYS => 'overstock',
            default => 'balanced',
        };
    }

    /**
     * @param  array<int,object>  $products
     * @return array<int,array<string,mixed>>
     */
    private function aging(int $storeId, CarbonImmutable $asOf, array $products): array
    {
        if ($products === []) {
            return [];
        }

        $productIds = array_map('intval', array_keys($products));
        $onHandByProduct = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('warehouses.store_id', $storeId)
            ->where('warehouses.is_active', true)
            ->whereIn('inventories.product_id', $productIds)
            ->groupBy('inventories.product_id')
            ->select('inventories.product_id')
            ->selectRaw('SUM(inventories.quantity) as on_hand')
            ->get()
            ->pluck('on_hand', 'product_id');

        $movements = DB::table('stock_movements')
            ->join('inventories', 'inventories.id', '=', 'stock_movements.inventory_id')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('warehouses.store_id', $storeId)
            ->where('warehouses.is_active', true)
            ->whereIn('inventories.product_id', $productIds)
            ->where('stock_movements.quantity', '>', 0)
            ->whereNotIn('stock_movements.type', ['reserve', 'release', 'transfer_in'])
            ->orderBy('inventories.product_id')
            ->orderByDesc('stock_movements.created_at')
            ->orderByDesc('stock_movements.id')
            ->get([
                'inventories.product_id',
                'stock_movements.quantity',
                'stock_movements.created_at',
            ])
            ->groupBy('product_id');

        $result = [];

        foreach ($products as $productId => $product) {
            $remaining = max(0.0, (float) ($onHandByProduct[$productId] ?? 0));
            $buckets = [
                'days_0_7' => 0.0,
                'days_8_30' => 0.0,
                'days_31_60' => 0.0,
                'days_61_plus' => 0.0,
                'unknown' => 0.0,
            ];

            foreach ($movements->get($productId, collect()) as $movement) {
                if ($remaining <= 0) {
                    break;
                }

                $quantity = min($remaining, max(0.0, (float) $movement->quantity));
                if ($quantity <= 0) {
                    continue;
                }

                $receivedAt = CarbonImmutable::parse((string) $movement->created_at, 'UTC')
                    ->setTimezone(self::TIMEZONE);
                $ageDays = max(
                    0,
                    (int) $receivedAt->startOfDay()->diffInDays(
                        $asOf->startOfDay(),
                        false,
                    ),
                );
                $bucket = match (true) {
                    $ageDays <= 7 => 'days_0_7',
                    $ageDays <= 30 => 'days_8_30',
                    $ageDays <= 60 => 'days_31_60',
                    default => 'days_61_plus',
                };

                $buckets[$bucket] += $quantity;
                $remaining -= $quantity;
            }

            if ($remaining > 0) {
                $buckets['unknown'] = $remaining;
            }

            $cost = $product->cost_price === null ? null : (float) $product->cost_price;
            $result[(int) $productId] = [
                'assumption' => 'fifo_remaining_stock',
                'cost_price' => $cost === null ? null : round($cost, 3),
                'buckets' => collect($buckets)
                    ->map(fn (float $quantity): array => [
                        'quantity' => round($quantity, 3),
                        'value' => $cost === null ? null : round($quantity * $cost, 3),
                    ])
                    ->all(),
            ];
        }

        return $result;
    }

    private function emptyAging(float $onHand, ?float $cost): array
    {
        return [
            'assumption' => 'fifo_remaining_stock',
            'cost_price' => $cost === null ? null : round($cost, 3),
            'buckets' => [
                'days_0_7' => ['quantity' => 0.0, 'value' => $cost === null ? null : 0.0],
                'days_8_30' => ['quantity' => 0.0, 'value' => $cost === null ? null : 0.0],
                'days_31_60' => ['quantity' => 0.0, 'value' => $cost === null ? null : 0.0],
                'days_61_plus' => ['quantity' => 0.0, 'value' => $cost === null ? null : 0.0],
                'unknown' => [
                    'quantity' => round($onHand, 3),
                    'value' => $cost === null ? null : round($onHand * $cost, 3),
                ],
            ],
        ];
    }

    private function assertRetailStore(int $storeId): void
    {
        $exists = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('store_types.code', 'B2C')
            ->exists();

        abort_unless($exists, 404);
    }
}
