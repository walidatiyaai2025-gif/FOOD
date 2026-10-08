<?php

namespace App\Services;

use App\Domain\Pricing\B2bPriceResolver;
use App\Models\B2bCustomer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class RetailReorderIntelligenceService
{
    private const TIMEZONE = 'Asia/Kuwait';

    private const DEFAULT_LEAD_TIME_DAYS = 7.0;

    private const SAFETY_STOCK_DAYS = 3.0;

    private const REVIEW_PERIOD_DAYS = 14.0;

    private const PRIORITY_VELOCITY_REFERENCE = 2.0;

    private const PRIORITY_MARGIN_REFERENCE = 0.50;

    public function __construct(
        private readonly RetailInventoryIntelligenceService $inventory,
        private readonly RetailWholesaleLineageService $lineage,
        private readonly B2bPriceResolver $pricing,
    ) {}

    /**
     * @return array{
     *   retail_store_id:int,
     *   timezone:string,
     *   as_of:string,
     *   policy:array<string,mixed>,
     *   lead_time:array<string,mixed>,
     *   recommendations:list<array<string,mixed>>
     * }
     */
    public function forStore(int $retailStoreId, ?CarbonImmutable $asOf = null): array
    {
        $asOf = ($asOf ?? CarbonImmutable::now(self::TIMEZONE))->setTimezone(self::TIMEZONE);
        $inventory = $this->inventory->forStore($retailStoreId, $asOf);
        $leadTime = $this->leadTime($retailStoreId);
        $mappings = collect($this->lineage->mappingsForStore($retailStoreId))
            ->groupBy('retail_product_id');
        $customer = $this->linkedWholesaleCustomer($retailStoreId);

        $rows = collect($inventory['products'])
            ->map(function (array $product) use (
                $retailStoreId,
                $leadTime,
                $mappings,
                $customer,
            ): array {
                return $this->recommendation(
                    $retailStoreId,
                    $product,
                    $leadTime,
                    $mappings->get($product['product_id'], collect())->values()->all(),
                    $customer,
                );
            })
            ->values();

        $maxDailyRevenue = (float) $rows->max(
            static fn (array $row): float => (float) $row['economics']['daily_revenue'],
        );

        $recommendations = $rows
            ->map(fn (array $row): array => $this->withPriority($row, $maxDailyRevenue, $leadTime))
            ->sortByDesc('priority_score')
            ->values()
            ->all();

        return [
            'retail_store_id' => $retailStoreId,
            'timezone' => self::TIMEZONE,
            'as_of' => $asOf->toIso8601String(),
            'policy' => [
                'default_lead_time_days' => self::DEFAULT_LEAD_TIME_DAYS,
                'safety_stock_days' => self::SAFETY_STOCK_DAYS,
                'review_period_days' => self::REVIEW_PERIOD_DAYS,
                'target_cover_formula' => 'expected_lead_time + safety_stock_days + review_period_days',
                'reorder_point_formula' => 'daily_velocity * expected_lead_time + safety_stock',
                'quantity_validation' => 'minimum_quantity + n * ordering_increment',
            ],
            'lead_time' => $leadTime,
            'recommendations' => $recommendations,
        ];
    }

    /** @return array<string,mixed> */
    private function recommendation(
        int $retailStoreId,
        array $product,
        array $leadTime,
        array $productMappings,
        ?B2bCustomer $customer,
    ): array {
        $velocity = (float) $product['sales']['velocity_units_per_day'];
        $availableRetail = (float) $product['stock']['available'];
        $daysCover = $product['cover']['days'] === null
            ? null
            : (float) $product['cover']['days'];
        $expectedLead = (float) $leadTime['expected_days'];
        $safetyStock = round($velocity * self::SAFETY_STOCK_DAYS, 3);
        $reorderPoint = round(($velocity * $expectedLead) + $safetyStock, 3);
        $targetCoverDays = round(
            $expectedLead + self::SAFETY_STOCK_DAYS + self::REVIEW_PERIOD_DAYS,
            2,
        );
        $targetStock = round($velocity * $targetCoverDays, 3);
        $retailNeed = round(max(0.0, $targetStock - max(0.0, $availableRetail)), 3);

        $retailPrice = $this->retailSellingPrice($retailStoreId, (int) $product['product_id']);
        $dailyRevenue = round($velocity * ($retailPrice ?? 0.0), 3);

        $base = [
            'retail_product_id' => (int) $product['product_id'],
            'sku' => (string) $product['sku'],
            'name' => (string) $product['name'],
            'inventory' => [
                'available' => round($availableRetail, 3),
                'velocity_units_per_day' => round($velocity, 3),
                'days_of_cover' => $daysCover,
                'estimated_stockout_at' => $product['cover']['estimated_stockout_at'],
                'movement_class' => (string) $product['movement_class'],
                'stock_risk' => (string) $product['stock_risk'],
                'confidence' => $product['confidence'],
            ],
            'reorder_model' => [
                'expected_lead_time_days' => $expectedLead,
                'safety_stock' => $safetyStock,
                'reorder_point' => $reorderPoint,
                'target_cover_days' => $targetCoverDays,
                'target_stock' => $targetStock,
                'retail_units_needed' => $retailNeed,
            ],
            'mapping' => null,
            'commercial' => null,
            'economics' => [
                'retail_unit_price' => $retailPrice,
                'retail_unit_cost' => null,
                'gross_margin_per_retail_unit' => null,
                'gross_margin_percent' => null,
                'daily_revenue' => $dailyRevenue,
                'lost_sales_risk_units' => $this->lostSalesRiskUnits($velocity, $daysCover, $expectedLead),
                'lost_sales_risk_revenue' => null,
            ],
            'recommendation' => [
                'action' => 'healthy',
                'state' => 'no_order_needed',
                'unconstrained_wholesale_quantity' => 0.0,
                'recommended_wholesale_quantity' => 0.0,
                'recommended_retail_equivalent' => 0.0,
                'expected_cost' => 0.0,
                'is_executable' => false,
            ],
            'reason_facts' => [],
            'explanation' => [
                'code' => 'healthy_stock',
                'message_key' => 'merchant_intelligence.reorder.healthy_stock',
            ],
            'priority_score' => 0.0,
            'priority_components' => [],
        ];

        $base['economics']['lost_sales_risk_revenue'] = $retailPrice === null
            ? null
            : round($base['economics']['lost_sales_risk_units'] * $retailPrice, 3);

        if (count($productMappings) === 0) {
            return $this->blocked(
                $base,
                'missing_mapping',
                'blocked_missing_mapping',
                'missing_wholesale_mapping',
                [
                    'retail_units_needed' => $retailNeed,
                    'stock_risk' => $product['stock_risk'],
                ],
            );
        }

        $validMappings = array_values(array_filter(
            $productMappings,
            static fn (array $mapping): bool => ($mapping['status'] ?? null) === 'valid',
        ));

        if (count($validMappings) !== 1) {
            return $this->blocked(
                $base,
                count($validMappings) === 0 ? 'invalid_mapping' : 'ambiguous_mapping',
                'blocked_mapping_integrity',
                count($validMappings) === 0 ? 'invalid_wholesale_mapping' : 'ambiguous_wholesale_mapping',
                [
                    'mapping_count' => count($productMappings),
                    'valid_mapping_count' => count($validMappings),
                ],
            );
        }

        $mapping = $validMappings[0];
        $factor = (float) $mapping['quantity_conversion_factor'];
        if ($factor <= 0) {
            return $this->blocked(
                $base,
                'invalid_mapping',
                'blocked_mapping_integrity',
                'invalid_conversion_factor',
                ['quantity_conversion_factor' => $factor],
            );
        }

        $source = $this->wholesaleSource((int) $mapping['source_wholesale_product_id']);
        if ($source === null) {
            return $this->blocked(
                $base,
                'invalid_mapping',
                'blocked_mapping_integrity',
                'source_not_wholesale',
                [],
            );
        }

        $base['mapping'] = [
            'source_wholesale_product_id' => (int) $mapping['source_wholesale_product_id'],
            'source_sku' => (string) $mapping['source_sku'],
            'source_name' => (string) $mapping['source_name'],
            'source_wholesale_store_id' => (int) $source->store_id,
            'quantity_conversion_factor' => round($factor, 3),
        ];

        $retailUnitCost = $mapping['current_unit_cost'] === null
            ? null
            : (float) $mapping['current_unit_cost'];
        $base['economics']['retail_unit_cost'] = $retailUnitCost;
        if ($retailPrice !== null && $retailUnitCost !== null) {
            $margin = round($retailPrice - $retailUnitCost, 3);
            $base['economics']['gross_margin_per_retail_unit'] = $margin;
            $base['economics']['gross_margin_percent'] = $retailPrice > 0
                ? round($margin / $retailPrice, 4)
                : null;
        }

        if (
            in_array((string) $product['movement_class'], ['slow', 'no_demand'], true)
            || in_array((string) $product['stock_risk'], ['overstock', 'overstock_no_demand'], true)
        ) {
            return $this->blocked(
                $base,
                'do_not_reorder',
                'working_capital_protection',
                (string) $product['movement_class'] === 'no_demand'
                    ? 'do_not_reorder_no_demand'
                    : 'do_not_reorder_slow_or_overstock',
                [
                    'movement_class' => $product['movement_class'],
                    'stock_risk' => $product['stock_risk'],
                    'days_of_cover' => $daysCover,
                ],
            );
        }

        if (($product['confidence']['state'] ?? null) === 'insufficient') {
            return $this->blocked(
                $base,
                'insufficient_data',
                'blocked_insufficient_history',
                'insufficient_sales_history',
                [
                    'history_days' => $product['confidence']['history_days'] ?? null,
                    'retail_units_needed' => $retailNeed,
                ],
            );
        }

        if ($velocity <= 0 || $availableRetail >= $reorderPoint) {
            $base['reason_facts'] = [
                'available_retail_stock' => round($availableRetail, 3),
                'reorder_point' => $reorderPoint,
                'retail_units_needed' => $retailNeed,
            ];

            return $base;
        }

        if (! $customer instanceof B2bCustomer) {
            return $this->blocked(
                $base,
                'reorder',
                'blocked_wholesale_account',
                'wholesale_account_unavailable',
                ['retail_units_needed' => $retailNeed],
            );
        }

        try {
            $price = $this->pricing->resolve(
                $customer,
                (int) $source->store_id,
                (int) $mapping['source_wholesale_product_id'],
            );
        } catch (HttpException $exception) {
            return $this->blocked(
                $base,
                'reorder',
                'blocked_pricing',
                'authoritative_pricing_unavailable',
                [
                    'retail_units_needed' => $retailNeed,
                    'pricing_status' => $exception->getStatusCode(),
                ],
            );
        }

        $wholesaleAvailable = $this->wholesaleAvailable(
            (int) $source->store_id,
            (int) $mapping['source_wholesale_product_id'],
        );
        $rawWholesaleNeed = $retailNeed / $factor;
        $fullQuantity = $this->roundUpToB2bQuantity(
            $rawWholesaleNeed,
            (float) $price['minimum_quantity'],
            (float) $price['ordering_increment'],
        );
        $availableExecutable = $this->roundDownToB2bQuantity(
            $wholesaleAvailable,
            (float) $price['minimum_quantity'],
            (float) $price['ordering_increment'],
        );
        $recommendedQuantity = min($fullQuantity, $availableExecutable);
        $availabilityLimited = $recommendedQuantity + 0.0001 < $fullQuantity;

        $base['commercial'] = [
            'unit_price' => round((float) $price['price'], 3),
            'minimum_order_quantity' => round((float) $price['minimum_quantity'], 3),
            'ordering_increment' => round((float) $price['ordering_increment'], 3),
            'pack_size' => round((float) $price['pack_size'], 3),
            'case_size' => $price['case_size'] === null ? null : round((float) $price['case_size'], 3),
            'pack_label' => $price['pack_label'],
            'wholesale_available_quantity' => round($wholesaleAvailable, 3),
            'recommended_pack_equivalent' => $recommendedQuantity > 0
                ? round($recommendedQuantity / max(0.001, (float) $price['pack_size']), 3)
                : 0.0,
            'recommended_case_equivalent' => $price['case_size'] !== null && $recommendedQuantity > 0
                ? round($recommendedQuantity / max(0.001, (float) $price['case_size']), 3)
                : null,
        ];

        $action = $daysCover !== null && $daysCover <= $expectedLead
            ? 'reorder_now'
            : 'reorder_soon';
        $state = match (true) {
            $recommendedQuantity <= 0 => 'blocked_by_availability',
            $availabilityLimited => 'availability_limited',
            default => 'executable',
        };
        $isExecutable = $recommendedQuantity > 0;

        $base['recommendation'] = [
            'action' => $action,
            'state' => $state,
            'unconstrained_wholesale_quantity' => round($fullQuantity, 3),
            'recommended_wholesale_quantity' => round($recommendedQuantity, 3),
            'recommended_retail_equivalent' => round($recommendedQuantity * $factor, 3),
            'expected_cost' => round($recommendedQuantity * (float) $price['price'], 3),
            'is_executable' => $isExecutable,
        ];
        $base['reason_facts'] = [
            'available_retail_stock' => round($availableRetail, 3),
            'days_of_cover' => $daysCover,
            'expected_lead_time_days' => $expectedLead,
            'reorder_point' => $reorderPoint,
            'target_stock' => $targetStock,
            'retail_units_needed' => $retailNeed,
            'quantity_conversion_factor' => round($factor, 3),
            'minimum_order_quantity' => round((float) $price['minimum_quantity'], 3),
            'ordering_increment' => round((float) $price['ordering_increment'], 3),
            'wholesale_available_quantity' => round($wholesaleAvailable, 3),
            'availability_limited' => $availabilityLimited,
        ];
        $base['explanation'] = [
            'code' => match ($state) {
                'blocked_by_availability' => 'reorder_blocked_by_availability',
                'availability_limited' => 'reorder_partially_available',
                default => $action === 'reorder_now' ? 'reorder_now_below_lead_time' : 'reorder_soon_below_point',
            },
            'message_key' => match ($state) {
                'blocked_by_availability' => 'merchant_intelligence.reorder.blocked_by_availability',
                'availability_limited' => 'merchant_intelligence.reorder.partially_available',
                default => $action === 'reorder_now'
                    ? 'merchant_intelligence.reorder.now_below_lead_time'
                    : 'merchant_intelligence.reorder.soon_below_point',
            },
        ];

        return $base;
    }

    /** @return array<string,mixed> */
    private function withPriority(array $row, float $maxDailyRevenue, array $leadTime): array
    {
        if (
            in_array($row['recommendation']['action'], ['healthy', 'do_not_reorder', 'insufficient_data'], true)
        ) {
            return $row;
        }

        $stockRisk = match ($row['inventory']['stock_risk']) {
            'critical_understock', 'inventory_inconsistent' => 40.0,
            'understock' => 32.0,
            'insufficient_data' => 8.0,
            'balanced' => 12.0,
            default => 0.0,
        };
        $daysCover = $row['inventory']['days_of_cover'];
        $expectedLead = max(0.001, (float) $leadTime['expected_days']);
        $coverRisk = $daysCover === null
            ? 0.0
            : 20.0 * $this->clamp(($expectedLead - (float) $daysCover) / $expectedLead);
        $velocity = 15.0 * $this->clamp(
            (float) $row['inventory']['velocity_units_per_day'] / self::PRIORITY_VELOCITY_REFERENCE,
        );
        $revenue = $maxDailyRevenue > 0
            ? 10.0 * $this->clamp((float) $row['economics']['daily_revenue'] / $maxDailyRevenue)
            : 0.0;
        $marginPercent = $row['economics']['gross_margin_percent'];
        $margin = $marginPercent === null
            ? 0.0
            : 10.0 * $this->clamp((float) $marginPercent / self::PRIORITY_MARGIN_REFERENCE);
        $leadRisk = 5.0 * $this->clamp($expectedLead / 14.0);

        $row['priority_components'] = [
            'stock_risk' => round($stockRisk, 1),
            'cover_vs_lead_time' => round($coverRisk, 1),
            'sales_velocity' => round($velocity, 1),
            'revenue_contribution' => round($revenue, 1),
            'margin_contribution' => round($margin, 1),
            'lead_time_risk' => round($leadRisk, 1),
        ];
        $row['priority_score'] = round(min(100.0, array_sum($row['priority_components'])), 1);

        return $row;
    }

    /** @return array<string,mixed> */
    private function leadTime(int $retailStoreId): array
    {
        $samples = DB::table('retail_replenishments as replenishments')
            ->join('orders as source_orders', 'source_orders.id', '=', 'replenishments.source_order_id')
            ->where('replenishments.retail_store_id', $retailStoreId)
            ->orderByDesc('replenishments.received_at')
            ->get([
                'source_orders.created_at as ordered_at',
                'replenishments.received_at',
            ])
            ->map(function (object $row): float {
                $ordered = CarbonImmutable::parse((string) $row->ordered_at, 'UTC');
                $received = CarbonImmutable::parse((string) $row->received_at, 'UTC');

                return round(
                    max(0, $received->getTimestamp() - $ordered->getTimestamp()) / 86400,
                    2,
                );
            })
            ->values()
            ->all();

        $sampleCount = count($samples);
        $average = $sampleCount === 0 ? null : round(array_sum($samples) / $sampleCount, 2);
        $median = $sampleCount === 0 ? null : $this->median($samples);
        $recent = $sampleCount === 0 ? null : $samples[0];
        $useHistory = $sampleCount >= 3;

        return [
            'average_days' => $average,
            'median_days' => $median,
            'recent_days' => $recent,
            'sample_size' => $sampleCount,
            'confidence' => match (true) {
                $sampleCount >= 6 => 'high',
                $sampleCount >= 3 => 'medium',
                $sampleCount >= 1 => 'low',
                default => 'fallback',
            },
            'expected_days' => $useHistory ? $median : self::DEFAULT_LEAD_TIME_DAYS,
            'expected_source' => $useHistory ? 'historical_median' : 'policy_default',
        ];
    }

    private function linkedWholesaleCustomer(int $retailStoreId): ?B2bCustomer
    {
        $customerId = DB::table('retail_wholesale_accounts')
            ->where('retail_store_id', $retailStoreId)
            ->value('b2b_customer_id');

        return $customerId === null ? null : B2bCustomer::query()->find((int) $customerId);
    }

    private function wholesaleSource(int $sourceProductId): ?object
    {
        return DB::table('products')
            ->join('catalogs', 'catalogs.id', '=', 'products.catalog_id')
            ->join('stores', 'stores.id', '=', 'catalogs.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('products.id', $sourceProductId)
            ->where('products.is_active', true)
            ->where('catalogs.channel', 'b2b')
            ->where('catalogs.is_active', true)
            ->where('catalogs.is_migration_quarantine', false)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2B')
            ->first([
                'products.id',
                'catalogs.store_id',
            ]);
    }

    private function wholesaleAvailable(int $storeId, int $productId): float
    {
        $row = DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('warehouses.store_id', $storeId)
            ->where('warehouses.is_active', true)
            ->where('inventories.product_id', $productId)
            ->selectRaw('COALESCE(SUM(inventories.quantity - inventories.reserved_quantity), 0) as available')
            ->first();

        return round(max(0.0, (float) ($row->available ?? 0)), 3);
    }

    private function retailSellingPrice(int $storeId, int $productId): ?float
    {
        $price = DB::table('store_products')
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->value('price');

        return $price === null ? null : round((float) $price, 3);
    }

    private function roundUpToB2bQuantity(float $quantity, float $minimum, float $increment): float
    {
        $minimum = max(0.001, $minimum);
        $increment = max(0.001, $increment);
        if ($quantity <= $minimum + 0.0001) {
            return round($minimum, 3);
        }

        $steps = (int) ceil((($quantity - $minimum) / $increment) - 0.0000001);

        return round($minimum + ($steps * $increment), 3);
    }

    private function roundDownToB2bQuantity(float $available, float $minimum, float $increment): float
    {
        $minimum = max(0.001, $minimum);
        $increment = max(0.001, $increment);
        if ($available + 0.0001 < $minimum) {
            return 0.0;
        }

        $steps = (int) floor((($available - $minimum) / $increment) + 0.0000001);

        return round($minimum + (max(0, $steps) * $increment), 3);
    }

    private function lostSalesRiskUnits(float $velocity, ?float $daysCover, float $expectedLead): float
    {
        if ($daysCover === null || $velocity <= 0) {
            return 0.0;
        }

        return round(max(0.0, $expectedLead - $daysCover) * $velocity, 3);
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return round((float) $values[$middle], 2);
        }

        return round(((float) $values[$middle - 1] + (float) $values[$middle]) / 2, 2);
    }

    /** @return array<string,mixed> */
    private function blocked(
        array $base,
        string $action,
        string $state,
        string $reasonCode,
        array $facts,
    ): array {
        $base['recommendation']['action'] = $action;
        $base['recommendation']['state'] = $state;
        $base['reason_facts'] = $facts;
        $base['explanation'] = [
            'code' => $reasonCode,
            'message_key' => 'merchant_intelligence.reorder.'.$reasonCode,
        ];

        return $base;
    }

    private function clamp(float $value): float
    {
        return min(1.0, max(0.0, $value));
    }
}
