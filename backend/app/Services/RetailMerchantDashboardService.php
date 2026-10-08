<?php

namespace App\Services;

use App\Models\B2bCustomer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RetailMerchantDashboardService
{
    private const TIMEZONE = 'Asia/Kuwait';

    private const MAX_TREND_DAYS = 31;

    public function __construct(
        private readonly RetailInventoryIntelligenceService $inventory,
        private readonly RetailReorderIntelligenceService $reorder,
        private readonly RetailMerchantIdentityService $identity,
        private readonly B2bAccountLedgerService $ledger,
        private readonly SuggestedWholesalePurchasePlanService $purchasePlan,
    ) {}

    /** @return array<string,mixed> */
    public function forUserStore(
        User $user,
        int $retailStoreId,
        CarbonImmutable $rangeFrom,
        CarbonImmutable $rangeTo,
    ): array {
        $rangeFrom = $rangeFrom->setTimezone(self::TIMEZONE)->startOfDay();
        $rangeTo = $rangeTo->setTimezone(self::TIMEZONE)->startOfDay();
        if ($rangeFrom->gt($rangeTo)) {
            [$rangeFrom, $rangeTo] = [$rangeTo, $rangeFrom];
        }

        $asOf = $rangeTo->endOfDay();
        $inventory = $this->inventory->forStore($retailStoreId, $asOf);
        $reorder = $this->reorder->forStore($retailStoreId, $asOf);
        $recommendations = collect($reorder['recommendations']);
        $isOwner = in_array($retailStoreId, $this->identity->ownedRetailStoreIds($user), true);

        $attention = $recommendations
            ->filter(fn (array $row): bool => (string) data_get($row, 'recommendation.action') !== 'healthy')
            ->take(12)
            ->values();
        $missingMappings = $recommendations
            ->filter(fn (array $row): bool => in_array(
                (string) data_get($row, 'recommendation.action'),
                ['missing_mapping', 'invalid_mapping', 'ambiguous_mapping'],
                true,
            ))
            ->values();
        $slowMovers = $recommendations
            ->filter(fn (array $row): bool => (string) data_get($row, 'recommendation.action') === 'do_not_reorder')
            ->values();
        $blocked = $recommendations->filter(fn (array $row): bool => $this->isBlocked($row));
        $expectedSpend = round((float) $recommendations
            ->filter(fn (array $row): bool => (bool) data_get($row, 'recommendation.is_executable', false))
            ->sum(fn (array $row): float => (float) data_get($row, 'recommendation.expected_cost', 0)), 3);
        $lostSalesRisk = round((float) $recommendations
            ->sum(fn (array $row): float => (float) data_get($row, 'economics.lost_sales_risk_revenue', 0)), 3);
        $wholesaleAccount = $isOwner
            ? $this->wholesaleAccount($user, $retailStoreId)
            : null;
        $purchasePlan = is_array(data_get($wholesaleAccount, 'finance'))
            ? $this->purchasePlan->previewForOwner(
                $user,
                $retailStoreId,
                $recommendations->all(),
            )
            : null;

        return [
            'retail_store_id' => $retailStoreId,
            'as_of' => $asOf->toIso8601String(),
            'owner_context' => [
                'is_owner' => $isOwner,
                'wholesale_account_visible' => $isOwner,
            ],
            'summary' => [
                'reorder_now' => $recommendations->where('recommendation.action', 'reorder_now')->count(),
                'reorder_soon' => $recommendations->where('recommendation.action', 'reorder_soon')->count(),
                'healthy' => $recommendations->where('recommendation.action', 'healthy')->count(),
                'do_not_reorder' => $slowMovers->count(),
                'blocked' => $blocked->count(),
                'expected_reorder_spend' => $expectedSpend,
                'lost_sales_risk_revenue' => $lostSalesRisk,
            ],
            'lead_time' => $reorder['lead_time'],
            'attention' => $attention->all(),
            'recommendations' => $recommendations->take(25)->values()->all(),
            'sections' => [
                'missing_mapping' => $missingMappings->take(10)->all(),
                'slow_movers' => $slowMovers->take(10)->all(),
            ],
            'visualizations' => [
                'sales_sparklines' => $this->salesSparklines($inventory['products']),
                'retail_vs_wholesale' => $this->retailVsWholesaleSeries($retailStoreId, $rangeFrom, $rangeTo),
                'stock_risk' => $this->stockRiskDistribution($inventory['products']),
                'reorder_spend' => $this->reorderSpendDistribution($recommendations->all()),
                'margin_velocity' => $this->marginVelocity($recommendations->all()),
                'inventory_aging' => $this->agingDistribution($inventory['products']),
            ],
            'wholesale_account' => $wholesaleAccount,
            'purchase_plan' => $purchasePlan,
        ];
    }

    /** @param array<string,mixed> $row */
    private function isBlocked(array $row): bool
    {
        $state = (string) data_get($row, 'recommendation.state', '');
        $action = (string) data_get($row, 'recommendation.action', '');

        return str_starts_with($state, 'blocked_')
            || in_array($action, ['missing_mapping', 'invalid_mapping', 'ambiguous_mapping', 'insufficient_data'], true);
    }

    /**
     * Compact sales pace by W3 windows, normalized to units/day so unequal
     * 7/7/16-day windows remain comparable.
     *
     * @param  list<array<string,mixed>>  $products
     * @return array<int,list<float>>
     */
    private function salesSparklines(array $products): array
    {
        $result = [];

        foreach ($products as $product) {
            $units = (array) data_get($product, 'sales.units', []);
            $windowDays = (array) data_get($product, 'sales.window_days_available', []);
            $result[(int) $product['product_id']] = [
                round((float) ($units['days_15_30'] ?? 0) / max(1, (int) ($windowDays['days_15_30'] ?? 16)), 3),
                round((float) ($units['days_8_14'] ?? 0) / max(1, (int) ($windowDays['days_8_14'] ?? 7)), 3),
                round((float) ($units['days_1_7'] ?? 0) / max(1, (int) ($windowDays['days_1_7'] ?? 7)), 3),
            ];
        }

        return $result;
    }

    /** @return list<array{date:string,label:string,retail_sales:float,wholesale_purchases:float}> */
    private function retailVsWholesaleSeries(
        int $retailStoreId,
        CarbonImmutable $rangeFrom,
        CarbonImmutable $rangeTo,
    ): array {
        $chartFrom = $rangeTo->subDays(self::MAX_TREND_DAYS - 1);
        if ($chartFrom->lt($rangeFrom)) {
            $chartFrom = $rangeFrom;
        }

        $rows = [];
        for ($day = $chartFrom; $day->lte($rangeTo); $day = $day->addDay()) {
            [$from, $to] = $this->utcBounds($day);
            $retailSales = (float) DB::table('orders')
                ->where('store_id', $retailStoreId)
                ->where('channel', 'b2c')
                ->whereBetween('created_at', [$from, $to])
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->sum('grand_total');
            $wholesalePurchases = (float) DB::table('orders as source_orders')
                ->where('source_orders.channel', 'b2b')
                ->whereBetween('source_orders.created_at', [$from, $to])
                ->whereNotIn('source_orders.status', ['cancelled', 'refunded'])
                ->whereExists(function ($query) use ($retailStoreId): void {
                    $query->selectRaw('1')
                        ->from('retail_wholesale_accounts')
                        ->whereColumn(
                            'retail_wholesale_accounts.b2b_customer_id',
                            'source_orders.b2b_customer_id',
                        )
                        ->where('retail_wholesale_accounts.retail_store_id', $retailStoreId);
                })
                ->sum('source_orders.grand_total');

            $rows[] = [
                'date' => $day->toDateString(),
                'label' => $day->format('d/m'),
                'retail_sales' => round($retailSales, 3),
                'wholesale_purchases' => round($wholesalePurchases, 3),
            ];
        }

        return $rows;
    }

    /** @param list<array<string,mixed>> $products */
    private function stockRiskDistribution(array $products): array
    {
        $keys = [
            'critical_understock',
            'understock',
            'balanced',
            'overstock',
            'overstock_no_demand',
            'insufficient_data',
            'inventory_inconsistent',
        ];
        $counts = array_fill_keys($keys, 0);

        foreach ($products as $product) {
            $risk = (string) ($product['stock_risk'] ?? 'insufficient_data');
            $counts[$risk] = ($counts[$risk] ?? 0) + 1;
        }

        return $counts;
    }

    /** @param list<array<string,mixed>> $recommendations */
    private function reorderSpendDistribution(array $recommendations): array
    {
        $result = [
            'reorder_now' => 0.0,
            'reorder_soon' => 0.0,
            'availability_limited' => 0.0,
        ];

        foreach ($recommendations as $row) {
            $action = (string) data_get($row, 'recommendation.action', '');
            $state = (string) data_get($row, 'recommendation.state', '');
            $cost = (float) data_get($row, 'recommendation.expected_cost', 0);
            if (isset($result[$action])) {
                $result[$action] += $cost;
            }
            if ($state === 'availability_limited') {
                $result['availability_limited'] += $cost;
            }
        }

        return array_map(static fn (float $value): float => round($value, 3), $result);
    }

    /** @param list<array<string,mixed>> $recommendations */
    private function marginVelocity(array $recommendations): array
    {
        return collect($recommendations)
            ->filter(fn (array $row): bool => data_get($row, 'economics.gross_margin_percent') !== null)
            ->sortByDesc(fn (array $row): float => (float) ($row['priority_score'] ?? 0))
            ->take(12)
            ->map(fn (array $row): array => [
                'name' => (string) ($row['name'] ?? ''),
                'sku' => (string) ($row['sku'] ?? ''),
                'velocity' => round((float) data_get($row, 'inventory.velocity_units_per_day', 0), 3),
                'margin_percent' => round((float) data_get($row, 'economics.gross_margin_percent', 0) * 100, 1),
                'stock_risk' => (string) data_get($row, 'inventory.stock_risk', 'insufficient_data'),
                'action' => (string) data_get($row, 'recommendation.action', 'healthy'),
            ])
            ->values()
            ->all();
    }

    /** @param list<array<string,mixed>> $products */
    private function agingDistribution(array $products): array
    {
        $result = [
            'days_0_7' => ['quantity' => 0.0, 'value' => 0.0],
            'days_8_30' => ['quantity' => 0.0, 'value' => 0.0],
            'days_31_60' => ['quantity' => 0.0, 'value' => 0.0],
            'days_61_plus' => ['quantity' => 0.0, 'value' => 0.0],
            'unknown' => ['quantity' => 0.0, 'value' => 0.0],
        ];

        foreach ($products as $product) {
            foreach ((array) data_get($product, 'aging.buckets', []) as $bucket => $values) {
                if (! isset($result[$bucket])) {
                    continue;
                }
                $result[$bucket]['quantity'] += (float) ($values['quantity'] ?? 0);
                $result[$bucket]['value'] += (float) ($values['value'] ?? 0);
            }
        }

        foreach ($result as $bucket => $values) {
            $result[$bucket] = [
                'quantity' => round($values['quantity'], 3),
                'value' => round($values['value'], 3),
            ];
        }

        return $result;
    }

    /** @return array<string,mixed>|null */
    private function wholesaleAccount(User $user, int $retailStoreId): ?array
    {
        $row = DB::table('retail_wholesale_accounts')
            ->join('b2b_customers', 'b2b_customers.id', '=', 'retail_wholesale_accounts.b2b_customer_id')
            ->leftJoin('b2b_accounts', 'b2b_accounts.b2b_customer_id', '=', 'b2b_customers.id')
            ->leftJoin('b2b_price_tiers', 'b2b_price_tiers.id', '=', 'b2b_accounts.price_tier_id')
            ->where('retail_wholesale_accounts.retail_store_id', $retailStoreId)
            ->where('retail_wholesale_accounts.owner_user_id', $user->getKey())
            ->first([
                'b2b_customers.id as customer_id',
                'b2b_customers.name as customer_name',
                'b2b_accounts.company_name',
                'b2b_accounts.status',
                'b2b_accounts.credit_limit',
                'b2b_price_tiers.name as tier_name',
                'b2b_price_tiers.code as tier_code',
            ]);

        if ($row === null) {
            return null;
        }

        $finance = null;
        $customer = B2bCustomer::query()->find((int) $row->customer_id);
        if ($customer instanceof B2bCustomer && (string) $row->status === 'active') {
            try {
                $finance = $this->ledger->summary($customer);
            } catch (Throwable) {
                $finance = null;
            }
        }

        return [
            'customer_name' => (string) ($row->company_name ?: $row->customer_name),
            'status' => (string) ($row->status ?: 'inactive'),
            'price_tier' => $row->tier_name === null ? null : (string) $row->tier_name,
            'price_tier_code' => $row->tier_code === null ? null : (string) $row->tier_code,
            'finance_status' => $finance === null ? 'unavailable' : 'available',
            'finance' => $finance === null ? null : [
                'currency' => (string) $finance['currency'],
                'balance' => (float) $finance['balance'],
                'balance_direction' => (string) $finance['balance_direction'],
                'credit_limit' => (float) $finance['credit_limit'],
                'available_credit_line' => (float) $finance['available_credit_line'],
                'purchasing_power' => (float) $finance['purchasing_power'],
                'open_amount' => (float) $finance['open_amount'],
                'overdue_amount' => (float) $finance['overdue_amount'],
            ],
        ];
    }

    /** @return array{0:string,1:string} */
    private function utcBounds(CarbonImmutable $localDay): array
    {
        return [
            $localDay->startOfDay()->utc()->toDateTimeString(),
            $localDay->endOfDay()->utc()->toDateTimeString(),
        ];
    }
}
