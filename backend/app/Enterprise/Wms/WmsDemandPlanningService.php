<?php

namespace App\Enterprise\Wms;

use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WmsDemandPlanningService
{
    private const TIMEZONE = 'Asia/Kuwait';

    private const DEFAULT_LEAD_TIME_DAYS = 7.0;

    private const SAFETY_STOCK_DAYS = 3.0;

    private const SALES_EXCLUDED_STATUSES = ['cancelled', 'refunded'];

    private const VELOCITY_WEIGHTS = [
        'days_1_7' => 0.50,
        'days_8_14' => 0.30,
        'days_15_30' => 0.20,
    ];

    public function __construct(
        private readonly WarehouseScope $warehouseScope,
        private readonly AuditLogger $audit,
    ) {}

    /** @return Collection<int, array<string, mixed>> */
    public function plan(
        User $actor,
        int $storeId,
        ?int $warehouseId = null,
        int $limit = 100,
        ?CarbonImmutable $asOf = null,
    ): Collection {
        $warehouseIds = $this->authorizedWarehouseIds($actor, $storeId, $warehouseId);
        if ($warehouseIds === []) {
            return collect();
        }

        $asOf = ($asOf ?? CarbonImmutable::now(self::TIMEZONE))->setTimezone(self::TIMEZONE);
        $day = $asOf->startOfDay();
        $recentStart = $day->subDays(6)->utc()->toDateTimeString();
        $middleStart = $day->subDays(13)->utc()->toDateTimeString();
        $olderStart = $day->subDays(29)->utc()->toDateTimeString();
        $end = $day->endOfDay()->utc()->toDateTimeString();

        $sales = DB::table('order_items as item')
            ->join('orders as orders', 'orders.id', '=', 'item.order_id')
            ->where('orders.store_id', $storeId)
            ->whereNotIn('orders.status', self::SALES_EXCLUDED_STATUSES)
            ->whereBetween('orders.created_at', [$olderStart, $end])
            ->whereNotNull('item.product_id')
            ->groupBy('item.product_id')
            ->select('item.product_id')
            ->selectRaw(
                'SUM(CASE WHEN orders.created_at >= ? THEN item.quantity ELSE 0 END) as units_1_7',
                [$recentStart],
            )
            ->selectRaw(
                'SUM(CASE WHEN orders.created_at >= ? AND orders.created_at < ? THEN item.quantity ELSE 0 END) as units_8_14',
                [$middleStart, $recentStart],
            )
            ->selectRaw(
                'SUM(CASE WHEN orders.created_at >= ? AND orders.created_at < ? THEN item.quantity ELSE 0 END) as units_15_30',
                [$olderStart, $middleStart],
            )
            ->selectRaw('SUM(item.quantity) as units_30')
            ->selectRaw('MAX(orders.created_at) as last_sale_at');

        $rows = DB::table('inventories as inventory')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'inventory.warehouse_id')
            ->join('products as product', 'product.id', '=', 'inventory.product_id')
            ->leftJoin('enterprise_reorder_policies as policy', function ($join): void {
                $join
                    ->on('policy.warehouse_id', '=', 'inventory.warehouse_id')
                    ->on('policy.product_id', '=', 'inventory.product_id')
                    ->where('policy.is_active', true);
            })
            ->leftJoinSub($sales, 'sales', 'sales.product_id', '=', 'inventory.product_id')
            ->where('warehouse.store_id', $storeId)
            ->whereIn('inventory.warehouse_id', $warehouseIds)
            ->orderBy('inventory.warehouse_id')
            ->orderBy('product.name')
            ->limit(max(1, min(250, $limit)))
            ->get([
                'inventory.id as inventory_id',
                'inventory.warehouse_id',
                'inventory.product_id',
                'inventory.quantity',
                'inventory.reserved_quantity',
                'warehouse.code as warehouse_code',
                'warehouse.name as warehouse_name',
                'product.sku',
                'product.name as product_name',
                'policy.id as policy_id',
                'policy.minimum_quantity',
                'policy.reorder_quantity',
                'policy.maximum_quantity',
                'sales.units_1_7',
                'sales.units_8_14',
                'sales.units_15_30',
                'sales.units_30',
                'sales.last_sale_at',
            ]);

        $leadTimes = $this->leadTimes($storeId);

        /** @var Collection<int, array<string, mixed>> $plan */
        $plan = $rows->map(function (object $row) use ($asOf, $leadTimes): array {
            $onHand = round((float) $row->quantity, 3);
            $reserved = round((float) $row->reserved_quantity, 3);
            $available = round($onHand - $reserved, 3);
            $velocity = $this->velocity(
                (float) ($row->units_1_7 ?? 0),
                (float) ($row->units_8_14 ?? 0),
                (float) ($row->units_15_30 ?? 0),
            );

            $lead = $leadTimes['products'][(string) $row->sku] ?? $leadTimes['global'];
            $leadDays = (float) $lead['expected_days'];
            $target = round($velocity * ($leadDays + self::SAFETY_STOCK_DAYS), 3);
            $recommended = round(max(0.0, $target - max(0.0, $available)), 3);
            $policyTriggered = false;

            if ($row->policy_id !== null && $available <= (float) $row->minimum_quantity + 0.0005) {
                $policyTriggered = true;
                $recommended = max($recommended, round((float) $row->reorder_quantity, 3));
            }

            if ($row->maximum_quantity !== null) {
                $capacity = round(max(0.0, (float) $row->maximum_quantity - max(0.0, $available)), 3);
                $recommended = min($recommended, $capacity);
            }

            $inputs = [
                'as_of' => $asOf->toDateString(),
                'warehouse_id' => (int) $row->warehouse_id,
                'product_id' => (int) $row->product_id,
                'on_hand' => $onHand,
                'reserved' => $reserved,
                'available' => $available,
                'sales_units' => [
                    'days_1_7' => round((float) ($row->units_1_7 ?? 0), 3),
                    'days_8_14' => round((float) ($row->units_8_14 ?? 0), 3),
                    'days_15_30' => round((float) ($row->units_15_30 ?? 0), 3),
                    'days_1_30' => round((float) ($row->units_30 ?? 0), 3),
                ],
                'velocity_units_per_day' => $velocity,
                'lead_time_days' => $leadDays,
                'lead_time_source' => $lead['source'],
                'lead_time_samples' => (int) $lead['sample_size'],
                'safety_stock_days' => self::SAFETY_STOCK_DAYS,
                'policy' => $row->policy_id === null ? null : [
                    'policy_id' => (int) $row->policy_id,
                    'minimum_quantity' => round((float) $row->minimum_quantity, 3),
                    'reorder_quantity' => round((float) $row->reorder_quantity, 3),
                    'maximum_quantity' => $row->maximum_quantity === null
                        ? null
                        : round((float) $row->maximum_quantity, 3),
                    'triggered' => $policyTriggered,
                ],
            ];

            return [
                'inventory_id' => (int) $row->inventory_id,
                'warehouse_id' => (int) $row->warehouse_id,
                'warehouse_code' => (string) $row->warehouse_code,
                'warehouse_name' => (string) $row->warehouse_name,
                'product_id' => (int) $row->product_id,
                'sku' => (string) $row->sku,
                'product_name' => (string) $row->product_name,
                'state' => $recommended > 0 ? 'reorder' : 'healthy',
                'recommended_quantity' => $recommended,
                'target_quantity' => $target,
                'inputs' => $inputs,
                'input_fingerprint' => hash(
                    'sha256',
                    json_encode($inputs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ),
                'confidence' => match (true) {
                    (int) $lead['sample_size'] >= 6 && (float) ($row->units_30 ?? 0) > 0 => 'high',
                    (int) $lead['sample_size'] >= 3 || (float) ($row->units_30 ?? 0) > 0 => 'medium',
                    $row->policy_id !== null => 'policy',
                    default => 'low',
                },
            ];
        });

        return $plan;
    }

    public function approve(
        User $actor,
        int $storeId,
        int $warehouseId,
        int $productId,
        ?string $notes = null,
    ): object {
        $this->warehouseScope->assertWarehouse($actor, $warehouseId, 'inventory.manage');
        $row = $this->plan($actor, $storeId, $warehouseId, 250)
            ->firstWhere('product_id', $productId);

        if (! is_array($row)) {
            throw ValidationException::withMessages([
                'product_id' => ['Product is not stocked in the selected authorized warehouse.'],
            ]);
        }
        if ((float) $row['recommended_quantity'] <= 0) {
            throw ValidationException::withMessages([
                'product_id' => ['This product has no positive replenishment recommendation to approve.'],
            ]);
        }

        $notes = $notes === null ? null : trim($notes);
        if ($notes !== null && mb_strlen($notes) > 1000) {
            throw ValidationException::withMessages([
                'notes' => ['Approval notes must not exceed 1000 characters.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $storeId, $warehouseId, $productId, $notes, $row): object {
            $existing = DB::table('enterprise_wms_demand_approvals')
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('as_of', $row['inputs']['as_of'])
                ->where('input_fingerprint', $row['input_fingerprint'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $id = DB::table('enterprise_wms_demand_approvals')->insertGetId([
                'store_id' => $storeId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'as_of' => $row['inputs']['as_of'],
                'input_fingerprint' => $row['input_fingerprint'],
                'recommended_quantity' => $row['recommended_quantity'],
                'status' => 'approved',
                'notes' => $notes === '' ? null : $notes,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record('enterprise.wms.demand_plan.approved', $actor, null, null, [
                'store_id' => $storeId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'approval_id' => $id,
                'recommended_quantity' => $row['recommended_quantity'],
                'input_fingerprint' => $row['input_fingerprint'],
            ]);

            return DB::table('enterprise_wms_demand_approvals')->where('id', $id)->first();
        }, attempts: 3);
    }

    /** @return Collection<int, \stdClass> */
    public function approvals(User $actor, int $storeId, ?int $warehouseId = null, int $limit = 100): Collection
    {
        $warehouseIds = $this->authorizedWarehouseIds($actor, $storeId, $warehouseId);
        if ($warehouseIds === []) {
            return collect();
        }

        return DB::table('enterprise_wms_demand_approvals as approval')
            ->join('products as product', 'product.id', '=', 'approval.product_id')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'approval.warehouse_id')
            ->where('approval.store_id', $storeId)
            ->whereIn('approval.warehouse_id', $warehouseIds)
            ->orderByDesc('approval.id')
            ->limit(max(1, min(250, $limit)))
            ->get([
                'approval.*',
                'product.sku',
                'product.name as product_name',
                'warehouse.code as warehouse_code',
                'warehouse.name as warehouse_name',
            ]);
    }

    /** @return array{global:array<string,mixed>,products:array<string,array<string,mixed>>} */
    private function leadTimes(int $storeId): array
    {
        $samples = DB::table('enterprise_goods_receipt_lines as receipt_line')
            ->join('enterprise_goods_receipts as receipt', 'receipt.id', '=', 'receipt_line.goods_receipt_id')
            ->join('enterprise_purchase_order_lines as order_line', 'order_line.id', '=', 'receipt_line.purchase_order_line_id')
            ->join('enterprise_purchase_orders as purchase_order', 'purchase_order.id', '=', 'order_line.purchase_order_id')
            ->where('purchase_order.store_id', $storeId)
            ->where('receipt.store_id', $storeId)
            ->where('receipt.status', 'posted')
            ->whereNotNull('purchase_order.issued_at')
            ->whereNotNull('receipt.posted_at')
            ->orderByDesc('receipt.posted_at')
            ->limit(200)
            ->get([
                'order_line.item_code',
                'purchase_order.issued_at',
                'receipt.posted_at',
            ]);

        $global = [];
        $bySku = [];
        foreach ($samples as $sample) {
            $ordered = CarbonImmutable::parse((string) $sample->issued_at, 'UTC');
            $received = CarbonImmutable::parse((string) $sample->posted_at, 'UTC');
            $days = round(max(0, $received->getTimestamp() - $ordered->getTimestamp()) / 86400, 2);
            $global[] = $days;
            $sku = trim((string) ($sample->item_code ?? ''));
            if ($sku !== '') {
                $bySku[$sku][] = $days;
            }
        }

        $globalStats = $this->leadStats($global, 'store_history');
        $products = [];
        foreach ($bySku as $sku => $values) {
            $products[$sku] = count($values) >= 2
                ? $this->leadStats($values, 'product_history')
                : $globalStats;
        }

        return ['global' => $globalStats, 'products' => $products];
    }

    /** @param list<float> $samples
     * @return array{expected_days:float,sample_size:int,source:string}
     */
    private function leadStats(array $samples, string $source): array
    {
        if (count($samples) < 3) {
            return [
                'expected_days' => self::DEFAULT_LEAD_TIME_DAYS,
                'sample_size' => count($samples),
                'source' => 'policy_default',
            ];
        }

        sort($samples, SORT_NUMERIC);
        $count = count($samples);
        $middle = intdiv($count, 2);
        $median = $count % 2 === 1
            ? (float) $samples[$middle]
            : (((float) $samples[$middle - 1] + (float) $samples[$middle]) / 2);

        return [
            'expected_days' => round(max(0.25, $median), 2),
            'sample_size' => $count,
            'source' => $source,
        ];
    }

    private function velocity(float $recent, float $middle, float $older): float
    {
        return round(
            (($recent / 7) * self::VELOCITY_WEIGHTS['days_1_7'])
            + (($middle / 7) * self::VELOCITY_WEIGHTS['days_8_14'])
            + (($older / 16) * self::VELOCITY_WEIGHTS['days_15_30']),
            3,
        );
    }

    /** @return list<int> */
    private function authorizedWarehouseIds(User $actor, int $storeId, ?int $warehouseId): array
    {
        if ($warehouseId !== null) {
            $warehouse = $this->warehouseScope->assertWarehouse($actor, $warehouseId, 'inventory.view');
            abort_unless((int) $warehouse->store_id === $storeId, 404);

            return [$warehouseId];
        }

        return $this->warehouseScope->authorizedWarehouseIds($actor, $storeId, 'inventory.view');
    }
}
