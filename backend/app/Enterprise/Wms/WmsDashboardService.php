<?php

namespace App\Enterprise\Wms;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class WmsDashboardService
{
    private const INBOUND_TYPES = [
        'purchase_receipt',
        'warehouse_transfer_in',
        'cycle_count_adjustment',
        'adjustment',
    ];

    private const ADJUSTMENT_TYPES = ['adjustment', 'damage', 'loss'];

    public function __construct(private readonly WarehouseScope $warehouseScope) {}

    /** @return array<string, mixed> */
    public function overview(User $actor, int $storeId, ?int $warehouseId = null): array
    {
        $warehouseIds = $this->authorizedWarehouseIds($actor, $storeId, $warehouseId);
        if ($warehouseIds === []) {
            return $this->emptyOverview();
        }

        $now = CarbonImmutable::now();
        $from30 = $now->subDays(29)->startOfDay();
        $today = $now->startOfDay();

        $inventoryBase = DB::table('inventories as inventory')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'inventory.warehouse_id')
            ->leftJoin('enterprise_inventory_valuation_policies as valuation', function ($join): void {
                $join
                    ->on('valuation.warehouse_id', '=', 'inventory.warehouse_id')
                    ->on('valuation.product_id', '=', 'inventory.product_id')
                    ->where('valuation.is_active', true);
            })
            ->where('warehouse.store_id', $storeId)
            ->whereIn('inventory.warehouse_id', $warehouseIds);

        $inventory = (clone $inventoryBase)
            ->selectRaw('COUNT(*) as sku_rows')
            ->selectRaw('COALESCE(SUM(inventory.quantity),0) as on_hand')
            ->selectRaw('COALESCE(SUM(inventory.reserved_quantity),0) as reserved')
            ->selectRaw('COALESCE(SUM(inventory.quantity - inventory.reserved_quantity),0) as available')
            ->selectRaw('COALESCE(SUM(CASE WHEN valuation.id IS NOT NULL THEN inventory.quantity * valuation.unit_cost ELSE 0 END),0) as inventory_value')
            ->selectRaw('COALESCE(SUM(CASE WHEN valuation.id IS NULL THEN 1 ELSE 0 END),0) as missing_valuation')
            ->selectRaw('COALESCE(SUM(CASE WHEN (inventory.quantity - inventory.reserved_quantity) <= 0 THEN 1 ELSE 0 END),0) as out_of_stock')
            ->first();

        $lowStock = DB::table('enterprise_reorder_policies as policy')
            ->join('inventories as inventory', function ($join): void {
                $join
                    ->on('inventory.warehouse_id', '=', 'policy.warehouse_id')
                    ->on('inventory.product_id', '=', 'policy.product_id');
            })
            ->where('policy.store_id', $storeId)
            ->whereIn('policy.warehouse_id', $warehouseIds)
            ->where('policy.is_active', true)
            ->whereRaw('(inventory.quantity - inventory.reserved_quantity) <= policy.minimum_quantity')
            ->count();

        $inbound = DB::table('stock_movements as inbound')
            ->whereIn('inbound.type', self::INBOUND_TYPES)
            ->where('inbound.quantity', '>', 0)
            ->selectRaw('inbound.inventory_id, MAX(inbound.created_at) as last_inbound_at')
            ->groupBy('inbound.inventory_id');

        $aging = DB::table('inventories as inventory')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'inventory.warehouse_id')
            ->leftJoinSub($inbound, 'inbound', 'inbound.inventory_id', '=', 'inventory.id')
            ->where('warehouse.store_id', $storeId)
            ->whereIn('inventory.warehouse_id', $warehouseIds)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN COALESCE(inbound.last_inbound_at, inventory.created_at) < ? THEN inventory.quantity ELSE 0 END),0) as dead_quantity',
                [$today->subDays(180)->toDateTimeString()],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN COALESCE(inbound.last_inbound_at, inventory.created_at) >= ? AND COALESCE(inbound.last_inbound_at, inventory.created_at) < ? THEN inventory.quantity ELSE 0 END),0) as slow_quantity',
                [$today->subDays(180)->toDateTimeString(), $today->subDays(90)->toDateTimeString()],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN COALESCE(inbound.last_inbound_at, inventory.created_at) >= ? THEN inventory.quantity ELSE 0 END),0) as active_quantity',
                [$today->subDays(90)->toDateTimeString()],
            )
            ->first();

        $expiringLots = DB::table('enterprise_inventory_lots')
            ->where('store_id', $storeId)
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('status', '!=', 'closed')
            ->whereBetween('expires_on', [$today->toDateString(), $today->addDays(30)->toDateString()])
            ->count();

        $openTransfers = DB::table('enterprise_warehouse_transfers')
            ->where('store_id', $storeId)
            ->where(function ($query) use ($warehouseIds): void {
                $query->whereIn('source_warehouse_id', $warehouseIds)
                    ->orWhereIn('target_warehouse_id', $warehouseIds);
            })
            ->whereIn('status', ['draft', 'dispatched'])
            ->count();

        $activeCounts = DB::table('enterprise_inventory_counts')
            ->where('store_id', $storeId)
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('status', 'open')
            ->count();

        $countVariance = DB::table('enterprise_inventory_count_lines as line')
            ->join('enterprise_inventory_counts as count', 'count.id', '=', 'line.count_id')
            ->where('count.store_id', $storeId)
            ->whereIn('count.warehouse_id', $warehouseIds)
            ->whereNotNull('line.variance_quantity')
            ->where('count.started_at', '>=', $from30)
            ->selectRaw('COALESCE(SUM(ABS(line.variance_quantity)),0) as absolute_variance')
            ->value('absolute_variance');

        $adjustmentExceptions = DB::table('stock_movements as movement')
            ->join('inventories as inventory', 'inventory.id', '=', 'movement.inventory_id')
            ->where('movement.store_id', $storeId)
            ->whereIn('inventory.warehouse_id', $warehouseIds)
            ->whereIn('movement.type', self::ADJUSTMENT_TYPES)
            ->where('movement.created_at', '>=', $from30)
            ->count();

        $valueByWarehouse = (clone $inventoryBase)
            ->groupBy('warehouse.id', 'warehouse.code', 'warehouse.name')
            ->orderBy('warehouse.name')
            ->get([
                'warehouse.id as warehouse_id',
                'warehouse.code',
                'warehouse.name',
                DB::raw('COALESCE(SUM(CASE WHEN valuation.id IS NOT NULL THEN inventory.quantity * valuation.unit_cost ELSE 0 END),0) as value'),
            ]);

        $movementByType = DB::table('stock_movements as movement')
            ->join('inventories as inventory', 'inventory.id', '=', 'movement.inventory_id')
            ->where('movement.store_id', $storeId)
            ->whereIn('inventory.warehouse_id', $warehouseIds)
            ->where('movement.created_at', '>=', $from30)
            ->groupBy('movement.type')
            ->orderByDesc(DB::raw('SUM(ABS(movement.quantity))'))
            ->limit(10)
            ->get([
                'movement.type',
                DB::raw('COUNT(*) as movement_count'),
                DB::raw('SUM(ABS(movement.quantity)) as quantity'),
            ]);

        $movementTrend = DB::table('stock_movements as movement')
            ->join('inventories as inventory', 'inventory.id', '=', 'movement.inventory_id')
            ->where('movement.store_id', $storeId)
            ->whereIn('inventory.warehouse_id', $warehouseIds)
            ->where('movement.created_at', '>=', $from30)
            ->groupBy(DB::raw('DATE(movement.created_at)'))
            ->orderBy(DB::raw('DATE(movement.created_at)'))
            ->get([
                DB::raw('DATE(movement.created_at) as day'),
                DB::raw('COUNT(*) as movement_count'),
                DB::raw('SUM(ABS(movement.quantity)) as quantity'),
            ]);

        $expiryHorizon = [
            'days_0_7' => $this->expiryCount($storeId, $warehouseIds, $today, $today->addDays(7)),
            'days_8_30' => $this->expiryCount($storeId, $warehouseIds, $today->addDays(8), $today->addDays(30)),
            'days_31_60' => $this->expiryCount($storeId, $warehouseIds, $today->addDays(31), $today->addDays(60)),
            'days_61_90' => $this->expiryCount($storeId, $warehouseIds, $today->addDays(61), $today->addDays(90)),
        ];

        $transferStatus = DB::table('enterprise_warehouse_transfers')
            ->where('store_id', $storeId)
            ->where(function ($query) use ($warehouseIds): void {
                $query->whereIn('source_warehouse_id', $warehouseIds)
                    ->orWhereIn('target_warehouse_id', $warehouseIds);
            })
            ->groupBy('status')
            ->select('status')
            ->selectRaw('COUNT(*) as total')
            ->pluck('total', 'status')
            ->map(fn ($value): int => (int) $value)
            ->all();

        $countVarianceRows = DB::table('enterprise_inventory_count_lines as line')
            ->join('enterprise_inventory_counts as count', 'count.id', '=', 'line.count_id')
            ->where('count.store_id', $storeId)
            ->whereIn('count.warehouse_id', $warehouseIds)
            ->whereNotNull('line.variance_quantity')
            ->groupBy('count.id', 'count.warehouse_id', 'count.started_at')
            ->orderByDesc('count.id')
            ->limit(8)
            ->get([
                'count.id as count_id',
                'count.warehouse_id',
                'count.started_at',
                DB::raw('SUM(ABS(line.variance_quantity)) as absolute_variance'),
            ]);

        return [
            'kpis' => [
                'inventory_value' => round((float) ($inventory->inventory_value ?? 0), 3),
                'on_hand' => round((float) ($inventory->on_hand ?? 0), 3),
                'available' => round((float) ($inventory->available ?? 0), 3),
                'reserved' => round((float) ($inventory->reserved ?? 0), 3),
                'low_stock' => (int) $lowStock,
                'out_of_stock' => (int) ($inventory->out_of_stock ?? 0),
                'slow_stock' => round((float) ($aging->slow_quantity ?? 0), 3),
                'dead_stock' => round((float) ($aging->dead_quantity ?? 0), 3),
                'expiring_lots' => (int) $expiringLots,
                'open_transfers' => (int) $openTransfers,
                'active_counts' => (int) $activeCounts,
                'count_variance' => round((float) $countVariance, 3),
                'adjustment_exceptions' => (int) $adjustmentExceptions,
                'missing_valuation' => (int) ($inventory->missing_valuation ?? 0),
            ],
            'charts' => [
                'stock_status' => [
                    'out' => (int) ($inventory->out_of_stock ?? 0),
                    'low' => max(0, (int) $lowStock - (int) ($inventory->out_of_stock ?? 0)),
                    'healthy' => max(0, (int) ($inventory->sku_rows ?? 0) - (int) $lowStock),
                ],
                'value_by_warehouse' => $valueByWarehouse,
                'movement_by_type' => $movementByType,
                'movement_trend' => $movementTrend,
                'aging' => [
                    'active' => round((float) ($aging->active_quantity ?? 0), 3),
                    'slow' => round((float) ($aging->slow_quantity ?? 0), 3),
                    'dead' => round((float) ($aging->dead_quantity ?? 0), 3),
                ],
                'expiry_horizon' => $expiryHorizon,
                'count_variance' => $countVarianceRows,
                'transfer_status' => $transferStatus,
            ],
            'exceptions' => $this->exceptions($storeId, $warehouseIds, $warehouseId),
            'as_of' => $now->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function inventory360(User $actor, int $inventoryId): array
    {
        $inventory = DB::table('inventories as inventory')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'inventory.warehouse_id')
            ->join('products as product', 'product.id', '=', 'inventory.product_id')
            ->leftJoin('enterprise_inventory_valuation_policies as valuation', function ($join): void {
                $join
                    ->on('valuation.warehouse_id', '=', 'inventory.warehouse_id')
                    ->on('valuation.product_id', '=', 'inventory.product_id')
                    ->where('valuation.is_active', true);
            })
            ->leftJoin('enterprise_inventory_bin_assignments as assignment', 'assignment.inventory_id', '=', 'inventory.id')
            ->leftJoin('enterprise_warehouse_bins as bin', 'bin.id', '=', 'assignment.bin_id')
            ->leftJoin('enterprise_warehouse_zones as zone', 'zone.id', '=', 'bin.zone_id')
            ->where('inventory.id', $inventoryId)
            ->select([
                'inventory.*',
                'warehouse.store_id',
                'warehouse.code as warehouse_code',
                'warehouse.name as warehouse_name',
                'product.sku',
                'product.name as product_name',
                'valuation.unit_cost',
                'bin.id as bin_id',
                'bin.code as bin_code',
                'bin.name as bin_name',
                'zone.id as zone_id',
                'zone.code as zone_code',
                'zone.name as zone_name',
            ])
            ->first();

        if ($inventory === null) {
            abort(404);
        }
        $this->warehouseScope->assertWarehouse($actor, (int) $inventory->warehouse_id, 'inventory.view');

        $movements = DB::table('stock_movements as movement')
            ->leftJoin('enterprise_wms_bin_provenance as provenance', function ($join): void {
                $join
                    ->on('provenance.subject_id', '=', 'movement.id')
                    ->where('provenance.subject_type', 'stock_movement');
            })
            ->leftJoin('enterprise_warehouse_bins as bin', 'bin.id', '=', 'provenance.bin_id')
            ->where('movement.inventory_id', $inventoryId)
            ->orderByDesc('movement.id')
            ->limit(50)
            ->get([
                'movement.*',
                'bin.code as bin_code',
                'provenance.role as bin_role',
            ]);

        $lots = DB::table('enterprise_inventory_lots as lot')
            ->leftJoin('enterprise_wms_bin_provenance as provenance', function ($join): void {
                $join
                    ->on('provenance.subject_id', '=', 'lot.id')
                    ->where('provenance.subject_type', 'enterprise_inventory_lot')
                    ->where('provenance.role', 'placement');
            })
            ->leftJoin('enterprise_warehouse_bins as bin', 'bin.id', '=', 'provenance.bin_id')
            ->where('lot.warehouse_id', $inventory->warehouse_id)
            ->where('lot.product_id', $inventory->product_id)
            ->orderByDesc('lot.id')
            ->limit(25)
            ->get([
                'lot.*',
                'bin.code as bin_code',
            ]);

        $transfers = DB::table('enterprise_warehouse_transfer_lines as line')
            ->join('enterprise_warehouse_transfers as transfer', 'transfer.id', '=', 'line.transfer_id')
            ->leftJoin('enterprise_wms_bin_provenance as provenance', function ($join): void {
                $join
                    ->on('provenance.subject_id', '=', 'line.id')
                    ->where('provenance.subject_type', 'enterprise_warehouse_transfer_line');
            })
            ->leftJoin('enterprise_warehouse_bins as bin', 'bin.id', '=', 'provenance.bin_id')
            ->where(function ($query) use ($inventoryId): void {
                $query->where('line.source_inventory_id', $inventoryId)
                    ->orWhere('line.target_inventory_id', $inventoryId);
            })
            ->orderByDesc('transfer.id')
            ->limit(25)
            ->get([
                'transfer.id',
                'transfer.status',
                'transfer.source_warehouse_id',
                'transfer.target_warehouse_id',
                'transfer.created_at',
                'line.id as line_id',
                'line.quantity',
                'line.source_inventory_id',
                'line.target_inventory_id',
                'bin.code as bin_code',
                'provenance.role as bin_role',
            ]);

        $counts = DB::table('enterprise_inventory_count_lines as line')
            ->join('enterprise_inventory_counts as count', 'count.id', '=', 'line.count_id')
            ->leftJoin('enterprise_wms_bin_provenance as provenance', function ($join): void {
                $join
                    ->on('provenance.subject_id', '=', 'line.id')
                    ->where('provenance.subject_type', 'enterprise_inventory_count_line')
                    ->where('provenance.role', 'snapshot');
            })
            ->leftJoin('enterprise_warehouse_bins as bin', 'bin.id', '=', 'provenance.bin_id')
            ->where('line.inventory_id', $inventoryId)
            ->orderByDesc('count.id')
            ->limit(25)
            ->get([
                'count.id',
                'count.status',
                'count.started_at',
                'count.completed_at',
                'line.expected_quantity',
                'line.counted_quantity',
                'line.variance_quantity',
                'bin.code as bin_code',
            ]);

        return [
            'inventory' => $inventory,
            'movements' => $movements,
            'lots' => $lots,
            'transfers' => $transfers,
            'counts' => $counts,
            'adjustments' => $movements->whereIn('type', self::ADJUSTMENT_TYPES)->values(),
            'reservations' => $movements->whereIn('type', ['reserve', 'release', 'sale'])->values(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function exceptions(int $storeId, array $warehouseIds, ?int $warehouseId): array
    {
        $items = [];
        $low = DB::table('enterprise_reorder_policies as policy')
            ->join('inventories as inventory', function ($join): void {
                $join
                    ->on('inventory.warehouse_id', '=', 'policy.warehouse_id')
                    ->on('inventory.product_id', '=', 'policy.product_id');
            })
            ->join('products as product', 'product.id', '=', 'inventory.product_id')
            ->where('policy.store_id', $storeId)
            ->whereIn('policy.warehouse_id', $warehouseIds)
            ->where('policy.is_active', true)
            ->whereRaw('(inventory.quantity - inventory.reserved_quantity) <= policy.minimum_quantity')
            ->orderByRaw('(inventory.quantity - inventory.reserved_quantity) asc')
            ->limit(5)
            ->get([
                'inventory.id as inventory_id',
                'inventory.warehouse_id',
                'product.name as product_name',
                'product.sku',
                'policy.minimum_quantity',
                DB::raw('(inventory.quantity - inventory.reserved_quantity) as available_quantity'),
            ]);

        foreach ($low as $row) {
            $items[] = [
                'type' => 'low_stock',
                'label' => (string) $row->product_name,
                'detail' => (string) $row->sku,
                'value' => round((float) $row->available_quantity, 3),
                'url' => route('admin.erp.wms.index', [
                    'tab' => 'reorder',
                    'store_id' => $storeId,
                    'warehouse_id' => (int) $row->warehouse_id,
                    'inventory_id' => (int) $row->inventory_id,
                ]),
            ];
        }

        $lots = DB::table('enterprise_inventory_lots')
            ->where('store_id', $storeId)
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('status', '!=', 'closed')
            ->whereBetween('expires_on', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->orderBy('expires_on')
            ->limit(4)
            ->get(['id', 'warehouse_id', 'identifier', 'expires_on']);
        foreach ($lots as $row) {
            $items[] = [
                'type' => 'expiry',
                'label' => (string) $row->identifier,
                'detail' => (string) $row->expires_on,
                'value' => null,
                'url' => route('admin.erp.wms.index', [
                    'tab' => 'lots',
                    'store_id' => $storeId,
                    'warehouse_id' => (int) $row->warehouse_id,
                ]),
            ];
        }

        $transfers = DB::table('enterprise_warehouse_transfers')
            ->where('store_id', $storeId)
            ->where(function ($query) use ($warehouseIds): void {
                $query->whereIn('source_warehouse_id', $warehouseIds)
                    ->orWhereIn('target_warehouse_id', $warehouseIds);
            })
            ->whereIn('status', ['draft', 'dispatched'])
            ->orderBy('created_at')
            ->limit(4)
            ->get(['id', 'source_warehouse_id', 'status']);
        foreach ($transfers as $row) {
            $items[] = [
                'type' => 'transfer',
                'label' => '#'.(int) $row->id,
                'detail' => (string) $row->status,
                'value' => null,
                'url' => route('admin.erp.wms.index', [
                    'tab' => 'transfers',
                    'store_id' => $storeId,
                    'warehouse_id' => $warehouseId ?? (int) $row->source_warehouse_id,
                ]),
            ];
        }

        return array_slice($items, 0, 12);
    }

    private function expiryCount(int $storeId, array $warehouseIds, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return DB::table('enterprise_inventory_lots')
            ->where('store_id', $storeId)
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('status', '!=', 'closed')
            ->whereBetween('expires_on', [$from->toDateString(), $to->toDateString()])
            ->count();
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

    /** @return array<string, mixed> */
    private function emptyOverview(): array
    {
        return [
            'kpis' => [
                'inventory_value' => 0.0,
                'on_hand' => 0.0,
                'available' => 0.0,
                'reserved' => 0.0,
                'low_stock' => 0,
                'out_of_stock' => 0,
                'slow_stock' => 0.0,
                'dead_stock' => 0.0,
                'expiring_lots' => 0,
                'open_transfers' => 0,
                'active_counts' => 0,
                'count_variance' => 0.0,
                'adjustment_exceptions' => 0,
                'missing_valuation' => 0,
            ],
            'charts' => [
                'stock_status' => [],
                'value_by_warehouse' => collect(),
                'movement_by_type' => collect(),
                'movement_trend' => collect(),
                'aging' => [],
                'expiry_horizon' => [],
                'count_variance' => collect(),
                'transfer_status' => [],
            ],
            'exceptions' => [],
            'as_of' => now()->toIso8601String(),
        ];
    }
}
