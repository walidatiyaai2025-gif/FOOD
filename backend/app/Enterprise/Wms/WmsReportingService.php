<?php

namespace App\Enterprise\Wms;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class WmsReportingService
{
    public const LARGE_EXPORT_ROW_THRESHOLD = 5000;

    public const EXPORT_CHUNK_SIZE = 500;

    public const EXPORT_QUEUE = 'enterprise-report-exports';

    private const MAX_PAGE_SIZE = 100;

    private const MAX_REPORT_RANGE_DAYS = 366;

    private const MAX_EXPIRY_RANGE_DAYS = 90;

    private const INBOUND_MOVEMENT_TYPES = [
        'purchase_receipt',
        'warehouse_transfer_in',
        'cycle_count_adjustment',
        'adjustment',
    ];

    private const ADJUSTMENT_TYPES = [
        'adjustment',
        'damage',
        'loss',
    ];

    private const REPORTS = [
        'wms.inventory_summary' => [
            'read_model' => 'wms_inventory_summary',
            'filters' => ['warehouse_id', 'product_id', 'after_inventory_id'],
        ],
        'wms.lot_expiry' => [
            'read_model' => 'wms_lot_expiry',
            'filters' => ['warehouse_id', 'product_id', 'after_lot_id'],
        ],
        'wms.adjustments' => [
            'read_model' => 'wms_adjustment_exceptions',
            'filters' => ['warehouse_id', 'product_id', 'classification', 'after_movement_id'],
        ],
    ];

    public function __construct(private readonly WarehouseScope $warehouseScope) {}

    /** @return Collection<int, \stdClass> */
    public function inventorySummary(
        User $actor,
        int $storeId,
        ?int $warehouseId = null,
        ?int $productId = null,
        ?int $afterInventoryId = null,
        int $limit = 25,
    ): Collection {
        $warehouseIds = $this->authorizedWarehouseIds($actor, $storeId, $warehouseId);
        $productId = $this->positiveIdentifier($productId, 'product_id');
        $afterInventoryId = $this->positiveIdentifier($afterInventoryId, 'after_inventory_id');

        if ($warehouseIds === []) {
            return collect();
        }

        $inbound = DB::table('stock_movements as inbound_movement')
            ->join('inventories as inbound_inventory', 'inbound_inventory.id', '=', 'inbound_movement.inventory_id')
            ->where('inbound_movement.store_id', $storeId)
            ->whereIn('inbound_inventory.warehouse_id', $warehouseIds)
            ->where('inbound_movement.quantity', '>', 0)
            ->whereIn('inbound_movement.type', self::INBOUND_MOVEMENT_TYPES)
            ->selectRaw('inbound_movement.inventory_id, MAX(inbound_movement.created_at) as last_inbound_at')
            ->groupBy('inbound_movement.inventory_id');

        $rows = DB::table('inventories as inventory')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'inventory.warehouse_id')
            ->join('products as product', 'product.id', '=', 'inventory.product_id')
            ->leftJoin('enterprise_inventory_valuation_policies as valuation', function ($join): void {
                $join
                    ->on('valuation.warehouse_id', '=', 'inventory.warehouse_id')
                    ->on('valuation.product_id', '=', 'inventory.product_id')
                    ->where('valuation.is_active', true);
            })
            ->leftJoinSub($inbound, 'inbound', 'inbound.inventory_id', '=', 'inventory.id')
            ->where('warehouse.store_id', $storeId)
            ->whereIn('inventory.warehouse_id', $warehouseIds)
            ->when($productId !== null, fn ($query) => $query->where('inventory.product_id', $productId))
            ->when($afterInventoryId !== null, fn ($query) => $query->where('inventory.id', '>', $afterInventoryId))
            ->select([
                'inventory.id as inventory_id',
                'inventory.warehouse_id',
                'inventory.product_id',
                'inventory.quantity',
                'inventory.reserved_quantity',
                'inventory.created_at as inventory_created_at',
                'warehouse.code as warehouse_code',
                'warehouse.name as warehouse_name',
                'product.sku as product_sku',
                'product.name as product_name',
                'valuation.id as valuation_policy_id',
                'valuation.cost_method',
                'valuation.unit_cost',
                'inbound.last_inbound_at',
            ])
            ->orderBy('inventory.id')
            ->limit($this->boundedLimit($limit))
            ->get();

        return $rows->map(function (\stdClass $row): \stdClass {
            $quantity = round((float) $row->quantity, 3);
            $reserved = round((float) $row->reserved_quantity, 3);
            $lastInboundAt = $row->last_inbound_at ?? $row->inventory_created_at;
            $ageDays = max(
                0,
                (int) CarbonImmutable::parse((string) $lastInboundAt)->diffInDays(CarbonImmutable::now(), true),
            );

            $row->quantity = $quantity;
            $row->reserved_quantity = $reserved;
            $row->available_quantity = round($quantity - $reserved, 3);
            $row->inventory_value = $row->unit_cost === null
                ? null
                : round($quantity * (float) $row->unit_cost, 3);
            $row->last_inbound_at = (string) $lastInboundAt;
            $row->age_days = $ageDays;
            $row->aging_bucket = $this->agingBucket($ageDays);
            $row->stock_status = $ageDays >= 180 ? 'dead' : ($ageDays >= 90 ? 'slow' : 'active');
            $row->drill_down = [
                'inventory_id' => (int) $row->inventory_id,
                'warehouse_id' => (int) $row->warehouse_id,
                'product_id' => (int) $row->product_id,
            ];

            return $row;
        });
    }

    /** @return Collection<int, \stdClass> */
    public function lotExpiry(
        User $actor,
        int $storeId,
        string $fromDate,
        string $toDate,
        ?int $warehouseId = null,
        ?int $productId = null,
        ?int $afterLotId = null,
        int $limit = 25,
    ): Collection {
        [$from, $to] = $this->boundedDateRange(
            $fromDate,
            $toDate,
            self::MAX_EXPIRY_RANGE_DAYS,
            'expiry',
        );
        $warehouseIds = $this->authorizedWarehouseIds($actor, $storeId, $warehouseId);
        $productId = $this->positiveIdentifier($productId, 'product_id');
        $afterLotId = $this->positiveIdentifier($afterLotId, 'after_lot_id');

        if ($warehouseIds === []) {
            return collect();
        }

        $today = CarbonImmutable::today();

        return DB::table('enterprise_inventory_lots as lot')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'lot.warehouse_id')
            ->join('products as product', 'product.id', '=', 'lot.product_id')
            ->where('lot.store_id', $storeId)
            ->whereIn('lot.warehouse_id', $warehouseIds)
            ->where('lot.status', '!=', 'closed')
            ->whereBetween('lot.expires_on', [$from->toDateString(), $to->toDateString()])
            ->when($productId !== null, fn ($query) => $query->where('lot.product_id', $productId))
            ->when($afterLotId !== null, fn ($query) => $query->where('lot.id', '>', $afterLotId))
            ->select([
                'lot.id as inventory_lot_id',
                'lot.warehouse_id',
                'lot.product_id',
                'lot.identifier',
                'lot.status',
                'lot.manufactured_on',
                'lot.received_on',
                'lot.expires_on',
                'warehouse.code as warehouse_code',
                'warehouse.name as warehouse_name',
                'product.sku as product_sku',
                'product.name as product_name',
            ])
            ->orderBy('lot.id')
            ->limit($this->boundedLimit($limit))
            ->get()
            ->map(static function (\stdClass $row) use ($today): \stdClass {
                $expires = CarbonImmutable::parse((string) $row->expires_on)->startOfDay();
                $row->days_to_expiry = (int) $today->diffInDays($expires, false);
                $row->drill_down = [
                    'inventory_lot_id' => (int) $row->inventory_lot_id,
                    'warehouse_id' => (int) $row->warehouse_id,
                    'product_id' => (int) $row->product_id,
                ];

                return $row;
            });
    }

    /** @return Collection<int, \stdClass> */
    public function adjustmentExceptions(
        User $actor,
        int $storeId,
        string $fromDate,
        string $toDate,
        ?int $warehouseId = null,
        ?int $productId = null,
        ?string $classification = null,
        ?int $afterMovementId = null,
        int $limit = 25,
    ): Collection {
        [$from, $to] = $this->boundedDateRange(
            $fromDate,
            $toDate,
            self::MAX_REPORT_RANGE_DAYS,
            'report',
        );
        $warehouseIds = $this->authorizedWarehouseIds($actor, $storeId, $warehouseId);
        $productId = $this->positiveIdentifier($productId, 'product_id');
        $afterMovementId = $this->positiveIdentifier($afterMovementId, 'after_movement_id');
        $movementType = $this->movementTypeForClassification($classification);

        if ($warehouseIds === []) {
            return collect();
        }

        return DB::table('stock_movements as movement')
            ->join('inventories as inventory', 'inventory.id', '=', 'movement.inventory_id')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'inventory.warehouse_id')
            ->join('products as product', 'product.id', '=', 'inventory.product_id')
            ->where('movement.store_id', $storeId)
            ->whereIn('inventory.warehouse_id', $warehouseIds)
            ->whereIn('movement.type', self::ADJUSTMENT_TYPES)
            ->whereBetween('movement.created_at', [$from->startOfDay(), $to->endOfDay()])
            ->when($productId !== null, fn ($query) => $query->where('inventory.product_id', $productId))
            ->when($movementType !== null, fn ($query) => $query->where('movement.type', $movementType))
            ->when($afterMovementId !== null, fn ($query) => $query->where('movement.id', '>', $afterMovementId))
            ->select([
                'movement.id as stock_movement_id',
                'movement.inventory_id',
                'movement.user_id',
                'movement.type',
                'movement.quantity',
                'movement.reference_type',
                'movement.reference_id',
                'movement.reason',
                'movement.notes',
                'movement.created_at',
                'inventory.warehouse_id',
                'inventory.product_id',
                'warehouse.code as warehouse_code',
                'warehouse.name as warehouse_name',
                'product.sku as product_sku',
                'product.name as product_name',
            ])
            ->orderBy('movement.id')
            ->limit($this->boundedLimit($limit))
            ->get()
            ->map(static function (\stdClass $row): \stdClass {
                $row->classification = match ((string) $row->type) {
                    'damage' => 'damage',
                    'loss' => 'loss',
                    default => 'correction',
                };
                $row->drill_down = [
                    'stock_movement_id' => (int) $row->stock_movement_id,
                    'inventory_id' => (int) $row->inventory_id,
                    'warehouse_id' => (int) $row->warehouse_id,
                    'product_id' => (int) $row->product_id,
                ];

                return $row;
            });
    }

    /**
     * Produce the existing Enterprise queued-export contract without owning an export ledger.
     *
     * @param  array<string, scalar|null>  $filters
     * @return array<string, mixed>
     */
    public function largeExportHandoff(
        User $actor,
        string $reportCode,
        int $storeId,
        array $filters,
        int $rowCountEstimate,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?string $idempotencyKey = null,
    ): array {
        $reportCode = trim($reportCode);
        $definition = self::REPORTS[$reportCode] ?? null;

        if ($definition === null) {
            throw ValidationException::withMessages([
                'report' => ['Unknown WMS report export.'],
            ]);
        }

        if ($rowCountEstimate <= self::LARGE_EXPORT_ROW_THRESHOLD) {
            throw ValidationException::withMessages([
                'row_count_estimate' => ['Large export handoff is required only above 5000 rows.'],
            ]);
        }

        $normalizedFilters = $this->normalizedExportFilters(
            $actor,
            $storeId,
            $reportCode,
            $definition['filters'],
            $filters,
        );

        [$from, $to] = $this->exportDateRange($reportCode, $fromDate, $toDate);

        $warehouseId = array_key_exists('warehouse_id', $normalizedFilters)
            ? (int) $normalizedFilters['warehouse_id']
            : null;
        $authorizedWarehouseIds = $this->authorizedWarehouseIds($actor, $storeId, $warehouseId);

        if ($authorizedWarehouseIds === []) {
            throw ValidationException::withMessages([
                'warehouse_id' => ['No authorized warehouses are available for this report export.'],
            ]);
        }

        $payload = [
            'report' => $reportCode,
            'read_model' => $definition['read_model'],
            'filters' => $normalizedFilters,
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'page' => 1,
            'per_page' => self::MAX_PAGE_SIZE,
            'drill_down_route' => null,
            'scope_type' => 'store',
            'scope_id' => $storeId,
            'chunk_size' => self::EXPORT_CHUNK_SIZE,
            'queue' => self::EXPORT_QUEUE,
            'requested_by' => (int) $actor->getKey(),
            'authorized_warehouse_ids' => $authorizedWarehouseIds,
            'row_count_estimate' => $rowCountEstimate,
            'execution_mode' => 'queued_chunked',
            'synchronous_request' => false,
        ];

        $payload['idempotency_key'] = $this->exportIdempotencyKey(
            $payload,
            $idempotencyKey,
        );

        return $payload;
    }

    /**
     * @param  list<string>  $allowedFilters
     * @param  array<string, scalar|null>  $filters
     * @return array<string, scalar|null>
     */
    private function normalizedExportFilters(
        User $actor,
        int $storeId,
        string $reportCode,
        array $allowedFilters,
        array $filters,
    ): array {
        $normalized = [];

        foreach ($filters as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowedFilters, true)) {
                throw ValidationException::withMessages([
                    'filters' => ['Unsupported WMS report export filter.'],
                ]);
            }

            if (! is_scalar($value) && $value !== null) {
                throw ValidationException::withMessages([
                    'filters' => ['WMS report export filters must be scalar values.'],
                ]);
            }

            $normalized[$key] = $value;
        }

        foreach (['warehouse_id', 'product_id', 'after_inventory_id', 'after_lot_id', 'after_movement_id'] as $identifier) {
            if (! array_key_exists($identifier, $normalized) || $normalized[$identifier] === null) {
                continue;
            }

            $normalized[$identifier] = $this->positiveIdentifier(
                (int) $normalized[$identifier],
                $identifier,
            );
        }

        if (array_key_exists('classification', $normalized) && $normalized['classification'] !== null) {
            $classification = strtolower(trim((string) $normalized['classification']));
            $this->movementTypeForClassification($classification);
            $normalized['classification'] = $classification;
        }

        $warehouseId = array_key_exists('warehouse_id', $normalized)
            ? (int) $normalized['warehouse_id']
            : null;
        $this->authorizedWarehouseIds($actor, $storeId, $warehouseId);

        if ($reportCode !== 'wms.adjustments' && array_key_exists('classification', $normalized)) {
            throw ValidationException::withMessages([
                'classification' => ['Classification is only supported by the WMS adjustments report.'],
            ]);
        }

        return $normalized;
    }

    /** @return array{0:?CarbonImmutable,1:?CarbonImmutable} */
    private function exportDateRange(
        string $reportCode,
        ?string $fromDate,
        ?string $toDate,
    ): array {
        if ($reportCode === 'wms.inventory_summary') {
            if ($fromDate !== null || $toDate !== null) {
                throw ValidationException::withMessages([
                    'date_range' => ['Current inventory summary does not accept a historical date range.'],
                ]);
            }

            return [null, null];
        }

        if ($fromDate === null || $toDate === null) {
            throw ValidationException::withMessages([
                'date_range' => ['Both from and to are required for this WMS export.'],
            ]);
        }

        return $this->boundedDateRange(
            $fromDate,
            $toDate,
            $reportCode === 'wms.lot_expiry'
                ? self::MAX_EXPIRY_RANGE_DAYS
                : self::MAX_REPORT_RANGE_DAYS,
            $reportCode === 'wms.lot_expiry' ? 'expiry' : 'report',
        );
    }

    /** @return list<int> */
    private function authorizedWarehouseIds(
        User $actor,
        int $storeId,
        ?int $warehouseId,
    ): array {
        if ($storeId < 1) {
            throw ValidationException::withMessages([
                'store_id' => ['Store id must be a positive integer.'],
            ]);
        }

        if ($warehouseId !== null) {
            $warehouse = $this->warehouseScope->assertWarehouse(
                $actor,
                $warehouseId,
                'inventory.view',
            );
            abort_unless((int) $warehouse->store_id === $storeId, 404);

            return [$warehouseId];
        }

        return $this->warehouseScope->authorizedWarehouseIds(
            $actor,
            $storeId,
            'inventory.view',
        );
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function boundedDateRange(
        string $fromDate,
        string $toDate,
        int $maximumDays,
        string $kind,
    ): array {
        $from = $this->strictDate($fromDate, 'from');
        $to = $this->strictDate($toDate, 'to');

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages([
                'date_range' => ['Report range start must not be after its end.'],
            ]);
        }

        if ($from->diffInDays($to) > $maximumDays) {
            throw ValidationException::withMessages([
                'date_range' => [
                    $kind === 'expiry'
                        ? 'Expiry report ranges may not exceed 90 days.'
                        : 'WMS report ranges may not exceed 366 days.',
                ],
            ]);
        }

        return [$from, $to];
    }

    private function strictDate(string $date, string $field): CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                $field => ['WMS report dates must use YYYY-MM-DD.'],
            ]);
        }

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages([
                $field => ['WMS report dates must use YYYY-MM-DD.'],
            ]);
        }

        return $parsed;
    }

    private function positiveIdentifier(?int $identifier, string $field): ?int
    {
        if ($identifier !== null && $identifier < 1) {
            throw ValidationException::withMessages([
                $field => ['Identifier must be a positive integer.'],
            ]);
        }

        return $identifier;
    }

    private function movementTypeForClassification(?string $classification): ?string
    {
        if ($classification === null) {
            return null;
        }

        return match (strtolower(trim($classification))) {
            'correction' => 'adjustment',
            'damage' => 'damage',
            'loss' => 'loss',
            default => throw ValidationException::withMessages([
                'classification' => ['Classification must be correction, damage or loss.'],
            ]),
        };
    }

    private function agingBucket(int $ageDays): string
    {
        return match (true) {
            $ageDays <= 30 => '0_30',
            $ageDays <= 60 => '31_60',
            $ageDays <= 90 => '61_90',
            default => '91_plus',
        };
    }

    private function boundedLimit(int $limit): int
    {
        return max(1, min(self::MAX_PAGE_SIZE, $limit));
    }

    /** @param array<string, mixed> $payload */
    private function exportIdempotencyKey(array $payload, ?string $explicit): string
    {
        if ($explicit !== null) {
            $explicit = trim($explicit);
            if ($explicit === '') {
                throw ValidationException::withMessages([
                    'idempotency_key' => ['Export idempotency key must not be empty.'],
                ]);
            }

            return hash('sha256', 'explicit:'.$explicit);
        }

        return hash(
            'sha256',
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }
}
