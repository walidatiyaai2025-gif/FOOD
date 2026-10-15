<?php

namespace App\Enterprise\Wms;

use App\Models\EnterpriseInventoryCount;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InventoryCountService
{
    private const REFERENCE_TYPE = 'enterprise_inventory_count';

    public function __construct(
        private readonly WarehouseScope $warehouseScope,
        private readonly AuditLogger $audit,
    ) {}

    /** @param list<int> $inventoryIds */
    public function start(
        User $actor,
        int $warehouseId,
        array $inventoryIds,
        string $idempotencyKey,
    ): EnterpriseInventoryCount {
        $warehouse = $this->warehouseScope->assertWarehouse($actor, $warehouseId);
        $ids = $this->normalizeInventoryIds($inventoryIds);
        $key = $this->normalizeIdempotencyKey($idempotencyKey);
        $fingerprint = hash('sha256', json_encode([
            'warehouse_id' => $warehouseId,
            'inventory_ids' => $ids,
        ], JSON_THROW_ON_ERROR));
        $storeId = (int) $warehouse->store_id;

        return DB::transaction(function () use (
            $actor,
            $warehouseId,
            $ids,
            $key,
            $fingerprint,
            $storeId,
        ): EnterpriseInventoryCount {
            $inventories = DB::table('inventories')
                ->where('warehouse_id', $warehouseId)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'product_id', 'quantity']);

            if ($inventories->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'inventory_ids' => ['All count lines must belong to the selected warehouse.'],
                ]);
            }

            $count = EnterpriseInventoryCount::query()->firstOrCreate(
                [
                    'store_id' => $storeId,
                    'idempotency_key' => $key,
                ],
                [
                    'warehouse_id' => $warehouseId,
                    'status' => EnterpriseInventoryCount::STATUS_OPEN,
                    'request_fingerprint' => $fingerprint,
                    'created_by' => $actor->getKey(),
                    'started_at' => now(),
                ],
            );

            if ((int) $count->warehouse_id !== $warehouseId || $count->request_fingerprint !== $fingerprint) {
                throw ValidationException::withMessages([
                    'idempotency_key' => ['This idempotency key was already used for a different inventory count.'],
                ]);
            }

            if (! $count->wasRecentlyCreated) {
                return $count;
            }

            $now = now();
            DB::table('enterprise_inventory_count_lines')->insert(
                $inventories->map(fn (object $inventory): array => [
                    'count_id' => $count->getKey(),
                    'inventory_id' => (int) $inventory->id,
                    'product_id' => (int) $inventory->product_id,
                    'expected_quantity' => (float) $inventory->quantity,
                    'counted_quantity' => null,
                    'variance_quantity' => null,
                    'counted_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
            );

            $this->audit->record(
                'enterprise.inventory_count.started',
                $actor,
                $count,
                null,
                [
                    'store_id' => $storeId,
                    'warehouse_id' => $warehouseId,
                    'status' => EnterpriseInventoryCount::STATUS_OPEN,
                    'line_count' => count($ids),
                ],
            );

            return $count;
        }, attempts: 3);
    }

    /** @param array<int|string, int|float|string> $countedQuantities */
    public function complete(
        User $actor,
        int $countId,
        array $countedQuantities,
    ): EnterpriseInventoryCount {
        $normalized = $this->normalizeCountedQuantities($countedQuantities);

        return DB::transaction(function () use ($actor, $countId, $normalized): EnterpriseInventoryCount {
            $count = EnterpriseInventoryCount::query()
                ->lockForUpdate()
                ->findOrFail($countId);

            $this->warehouseScope->assertWarehouse($actor, (int) $count->warehouse_id);

            $lines = DB::table('enterprise_inventory_count_lines')
                ->where('count_id', $count->getKey())
                ->orderBy('inventory_id')
                ->get();

            $lineIds = $lines
                ->pluck('inventory_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $submittedIds = array_keys($normalized);
            sort($submittedIds);

            if ($lineIds !== $submittedIds) {
                throw ValidationException::withMessages([
                    'counts' => ['A completed count must provide exactly one quantity for every session line.'],
                ]);
            }

            if ($count->status === EnterpriseInventoryCount::STATUS_COMPLETED) {
                foreach ($lines as $line) {
                    if (! $this->sameQuantity(
                        (float) $line->counted_quantity,
                        $normalized[(int) $line->inventory_id],
                    )) {
                        throw ValidationException::withMessages([
                            'counts' => ['This completed inventory count was replayed with different quantities.'],
                        ]);
                    }
                }

                return $count;
            }

            if ($count->status !== EnterpriseInventoryCount::STATUS_OPEN) {
                throw ValidationException::withMessages([
                    'status' => ['Only open inventory counts can be completed.'],
                ]);
            }

            $inventories = DB::table('inventories')
                ->whereIn('id', $lineIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($lines as $line) {
                $inventory = $inventories->get((int) $line->inventory_id);
                if ($inventory === null || (int) $inventory->warehouse_id !== (int) $count->warehouse_id) {
                    throw ValidationException::withMessages([
                        'counts' => ['Inventory ownership changed while this count was open.'],
                    ]);
                }

                if (! $this->sameQuantity((float) $inventory->quantity, (float) $line->expected_quantity)) {
                    throw ValidationException::withMessages([
                        'counts' => ['Inventory changed after the count session started; refresh and recount before posting.'],
                    ]);
                }

                if ((float) $inventory->reserved_quantity > $normalized[(int) $line->inventory_id] + 0.0005) {
                    throw ValidationException::withMessages([
                        'counts' => ['Counted quantity cannot be lower than reserved quantity.'],
                    ]);
                }
            }

            $now = now();
            $movements = [];
            foreach ($lines as $line) {
                $inventory = $inventories->get((int) $line->inventory_id);
                $inventoryId = (int) $inventory->id;
                $counted = $normalized[$inventoryId];
                $current = (float) $inventory->quantity;
                $variance = round($counted - $current, 3);

                if (! $this->sameQuantity($variance, 0.0)) {
                    DB::table('inventories')
                        ->where('id', $inventoryId)
                        ->update([
                            'quantity' => $counted,
                            'updated_at' => $now,
                        ]);

                    $movements[] = [
                        'inventory_id' => $inventoryId,
                        'store_id' => (int) $count->store_id,
                        'user_id' => $actor->getKey(),
                        'type' => 'cycle_count_adjustment',
                        'quantity' => $variance,
                        'reference_type' => self::REFERENCE_TYPE,
                        'reference_id' => $count->getKey(),
                        'reason' => 'Enterprise inventory count reconciliation',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('enterprise_inventory_count_lines')
                    ->where('id', $line->id)
                    ->update([
                        'counted_quantity' => $counted,
                        'variance_quantity' => $variance,
                        'counted_at' => $now,
                        'updated_at' => $now,
                    ]);
            }

            if ($movements !== []) {
                DB::table('stock_movements')->insert($movements);
            }

            $before = ['status' => $count->status];
            $count->forceFill([
                'status' => EnterpriseInventoryCount::STATUS_COMPLETED,
                'completed_by' => $actor->getKey(),
                'completed_at' => $now,
            ])->save();

            $this->audit->record(
                'enterprise.inventory_count.completed',
                $actor,
                $count,
                $before,
                ['status' => $count->status],
            );

            return $count->refresh();
        }, attempts: 3);
    }

    /** @return Collection<int, EnterpriseInventoryCount> */
    public function listForStore(
        User $actor,
        int $storeId,
        ?string $status = null,
        int $limit = 25,
    ): Collection {
        $warehouseIds = $this->warehouseScope->authorizedWarehouseIds(
            $actor,
            $storeId,
            'inventory.view',
        );

        if ($warehouseIds === []) {
            return collect();
        }

        return EnterpriseInventoryCount::query()
            ->where('store_id', $storeId)
            ->whereIn('warehouse_id', $warehouseIds)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->limit($this->boundedLimit($limit))
            ->get();
    }

    /** @param list<int> $inventoryIds
     * @return list<int>
     */
    private function normalizeInventoryIds(array $inventoryIds): array
    {
        $ids = collect($inventoryIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($ids === [] || count($ids) !== count($inventoryIds)) {
            throw ValidationException::withMessages([
                'inventory_ids' => ['Inventory count lines must contain unique positive inventory IDs.'],
            ]);
        }

        return $ids;
    }

    /** @param array<int|string, int|float|string> $countedQuantities
     * @return array<int, float>
     */
    private function normalizeCountedQuantities(array $countedQuantities): array
    {
        if ($countedQuantities === []) {
            throw ValidationException::withMessages([
                'counts' => ['At least one counted quantity is required.'],
            ]);
        }

        $normalized = [];
        foreach ($countedQuantities as $inventoryId => $quantity) {
            $id = (int) $inventoryId;
            if ($id < 1 || ! is_numeric($quantity) || (float) $quantity < 0) {
                throw ValidationException::withMessages([
                    'counts' => ['Count quantities require positive inventory IDs and non-negative numeric values.'],
                ]);
            }

            $normalized[$id] = round((float) $quantity, 3);
        }

        ksort($normalized);

        return $normalized;
    }

    private function normalizeIdempotencyKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || mb_strlen($key) > 120) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['A non-empty idempotency key of at most 120 characters is required.'],
            ]);
        }

        return $key;
    }

    private function sameQuantity(float $left, float $right): bool
    {
        return abs($left - $right) < 0.0005;
    }

    private function boundedLimit(int $limit): int
    {
        return min(max($limit, 1), 100);
    }
}
