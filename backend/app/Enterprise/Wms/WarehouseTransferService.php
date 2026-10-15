<?php

namespace App\Enterprise\Wms;

use App\Models\EnterpriseWarehouseTransfer;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WarehouseTransferService
{
    private const REFERENCE_TYPE = 'enterprise_warehouse_transfer';

    public function __construct(
        private readonly WarehouseScope $warehouseScope,
        private readonly AuditLogger $audit,
    ) {}

    /** @param list<array{product_id:int,quantity:int|float|string}> $lines */
    public function create(
        User $actor,
        int $sourceWarehouseId,
        int $targetWarehouseId,
        array $lines,
        string $idempotencyKey,
    ): EnterpriseWarehouseTransfer {
        [$source] = $this->warehouseScope->assertTransferPair(
            $actor,
            $sourceWarehouseId,
            $targetWarehouseId,
        );

        $normalizedLines = $this->normalizeLines($lines);
        $key = $this->normalizeIdempotencyKey($idempotencyKey);
        $fingerprint = $this->fingerprint($sourceWarehouseId, $targetWarehouseId, $normalizedLines);
        $storeId = (int) $source->store_id;

        return DB::transaction(function () use (
            $actor,
            $sourceWarehouseId,
            $targetWarehouseId,
            $normalizedLines,
            $key,
            $fingerprint,
            $storeId,
        ): EnterpriseWarehouseTransfer {
            $productIds = array_keys($normalizedLines);
            $inventoryRows = DB::table('inventories')
                ->whereIn('warehouse_id', [$sourceWarehouseId, $targetWarehouseId])
                ->whereIn('product_id', $productIds)
                ->orderBy('id')
                ->get(['id', 'warehouse_id', 'product_id']);

            $inventoryMap = [];
            foreach ($inventoryRows as $inventory) {
                $inventoryMap[(int) $inventory->warehouse_id][(int) $inventory->product_id] = (int) $inventory->id;
            }

            foreach ($productIds as $productId) {
                if (! isset($inventoryMap[$sourceWarehouseId][$productId])) {
                    throw ValidationException::withMessages([
                        'lines' => ["Product {$productId} is not stocked in the source warehouse."],
                    ]);
                }

                if (! isset($inventoryMap[$targetWarehouseId][$productId])) {
                    throw ValidationException::withMessages([
                        'lines' => ["Product {$productId} is not stocked in the target warehouse."],
                    ]);
                }
            }

            $transfer = EnterpriseWarehouseTransfer::query()->firstOrCreate(
                [
                    'store_id' => $storeId,
                    'idempotency_key' => $key,
                ],
                [
                    'source_warehouse_id' => $sourceWarehouseId,
                    'target_warehouse_id' => $targetWarehouseId,
                    'status' => EnterpriseWarehouseTransfer::STATUS_DRAFT,
                    'request_fingerprint' => $fingerprint,
                    'created_by' => $actor->getKey(),
                ],
            );

            if (
                (int) $transfer->source_warehouse_id !== $sourceWarehouseId
                || (int) $transfer->target_warehouse_id !== $targetWarehouseId
                || $transfer->request_fingerprint !== $fingerprint
            ) {
                throw ValidationException::withMessages([
                    'idempotency_key' => ['This idempotency key was already used for a different warehouse transfer.'],
                ]);
            }

            if (! $transfer->wasRecentlyCreated) {
                return $transfer;
            }

            $now = now();
            $rows = [];
            foreach ($normalizedLines as $productId => $quantity) {
                $rows[] = [
                    'transfer_id' => $transfer->getKey(),
                    'product_id' => $productId,
                    'source_inventory_id' => $inventoryMap[$sourceWarehouseId][$productId],
                    'target_inventory_id' => $inventoryMap[$targetWarehouseId][$productId],
                    'quantity' => $quantity,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('enterprise_warehouse_transfer_lines')->insert($rows);

            $this->audit->record(
                'enterprise.warehouse_transfer.created',
                $actor,
                $transfer,
                null,
                [
                    'store_id' => $storeId,
                    'source_warehouse_id' => $sourceWarehouseId,
                    'target_warehouse_id' => $targetWarehouseId,
                    'status' => EnterpriseWarehouseTransfer::STATUS_DRAFT,
                ],
            );

            return $transfer;
        }, attempts: 3);
    }

    public function dispatch(User $actor, int $transferId): EnterpriseWarehouseTransfer
    {
        return DB::transaction(function () use ($actor, $transferId): EnterpriseWarehouseTransfer {
            $transfer = EnterpriseWarehouseTransfer::query()
                ->lockForUpdate()
                ->findOrFail($transferId);

            $this->warehouseScope->assertTransferPair(
                $actor,
                (int) $transfer->source_warehouse_id,
                (int) $transfer->target_warehouse_id,
            );

            if (in_array($transfer->status, [
                EnterpriseWarehouseTransfer::STATUS_DISPATCHED,
                EnterpriseWarehouseTransfer::STATUS_RECEIVED,
            ], true)) {
                return $transfer;
            }

            if ($transfer->status !== EnterpriseWarehouseTransfer::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => ['Only draft warehouse transfers can be dispatched.'],
                ]);
            }

            $lines = DB::table('enterprise_warehouse_transfer_lines')
                ->where('transfer_id', $transfer->getKey())
                ->orderBy('source_inventory_id')
                ->get();

            $sourceInventoryIds = $lines
                ->pluck('source_inventory_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $inventories = DB::table('inventories')
                ->whereIn('id', $sourceInventoryIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($lines as $line) {
                $inventory = $inventories->get((int) $line->source_inventory_id);
                if ($inventory === null) {
                    throw ValidationException::withMessages([
                        'lines' => ['Source inventory changed before dispatch.'],
                    ]);
                }

                $quantity = (float) $line->quantity;
                $available = (float) $inventory->quantity - (float) $inventory->reserved_quantity;
                if ($available + 0.0005 < $quantity) {
                    throw ValidationException::withMessages([
                        'lines' => ["Insufficient available inventory for product {$line->product_id}."],
                    ]);
                }
            }

            $now = now();
            $movements = [];
            foreach ($lines as $line) {
                $inventory = $inventories->get((int) $line->source_inventory_id);
                $quantity = (float) $line->quantity;
                $newQuantity = round((float) $inventory->quantity - $quantity, 3);

                DB::table('inventories')
                    ->where('id', $inventory->id)
                    ->update([
                        'quantity' => $newQuantity,
                        'updated_at' => $now,
                    ]);

                $movements[] = [
                    'inventory_id' => (int) $inventory->id,
                    'store_id' => (int) $transfer->store_id,
                    'user_id' => $actor->getKey(),
                    'type' => 'warehouse_transfer_out',
                    'quantity' => -$quantity,
                    'reference_type' => self::REFERENCE_TYPE,
                    'reference_id' => $transfer->getKey(),
                    'reason' => 'Enterprise warehouse transfer dispatch',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('stock_movements')->insert($movements);

            $before = ['status' => $transfer->status];
            $transfer->forceFill([
                'status' => EnterpriseWarehouseTransfer::STATUS_DISPATCHED,
                'dispatched_by' => $actor->getKey(),
                'dispatched_at' => $now,
            ])->save();

            $this->audit->record(
                'enterprise.warehouse_transfer.dispatched',
                $actor,
                $transfer,
                $before,
                ['status' => $transfer->status],
            );

            return $transfer->refresh();
        }, attempts: 3);
    }

    public function receive(User $actor, int $transferId): EnterpriseWarehouseTransfer
    {
        return DB::transaction(function () use ($actor, $transferId): EnterpriseWarehouseTransfer {
            $transfer = EnterpriseWarehouseTransfer::query()
                ->lockForUpdate()
                ->findOrFail($transferId);

            $this->warehouseScope->assertTransferPair(
                $actor,
                (int) $transfer->source_warehouse_id,
                (int) $transfer->target_warehouse_id,
            );

            if ($transfer->status === EnterpriseWarehouseTransfer::STATUS_RECEIVED) {
                return $transfer;
            }

            if ($transfer->status !== EnterpriseWarehouseTransfer::STATUS_DISPATCHED) {
                throw ValidationException::withMessages([
                    'status' => ['Only dispatched warehouse transfers can be received.'],
                ]);
            }

            $lines = DB::table('enterprise_warehouse_transfer_lines')
                ->where('transfer_id', $transfer->getKey())
                ->orderBy('target_inventory_id')
                ->get();

            $targetInventoryIds = $lines
                ->pluck('target_inventory_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $inventories = DB::table('inventories')
                ->whereIn('id', $targetInventoryIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $now = now();
            $movements = [];
            foreach ($lines as $line) {
                $inventory = $inventories->get((int) $line->target_inventory_id);
                if ($inventory === null) {
                    throw ValidationException::withMessages([
                        'lines' => ['Target inventory changed before receipt.'],
                    ]);
                }

                $quantity = (float) $line->quantity;
                $newQuantity = round((float) $inventory->quantity + $quantity, 3);

                DB::table('inventories')
                    ->where('id', $inventory->id)
                    ->update([
                        'quantity' => $newQuantity,
                        'updated_at' => $now,
                    ]);

                $movements[] = [
                    'inventory_id' => (int) $inventory->id,
                    'store_id' => (int) $transfer->store_id,
                    'user_id' => $actor->getKey(),
                    'type' => 'warehouse_transfer_in',
                    'quantity' => $quantity,
                    'reference_type' => self::REFERENCE_TYPE,
                    'reference_id' => $transfer->getKey(),
                    'reason' => 'Enterprise warehouse transfer receipt',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('stock_movements')->insert($movements);

            $before = ['status' => $transfer->status];
            $transfer->forceFill([
                'status' => EnterpriseWarehouseTransfer::STATUS_RECEIVED,
                'received_by' => $actor->getKey(),
                'received_at' => $now,
            ])->save();

            $this->audit->record(
                'enterprise.warehouse_transfer.received',
                $actor,
                $transfer,
                $before,
                ['status' => $transfer->status],
            );

            return $transfer->refresh();
        }, attempts: 3);
    }

    /** @return Collection<int, EnterpriseWarehouseTransfer> */
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

        return EnterpriseWarehouseTransfer::query()
            ->where('store_id', $storeId)
            ->whereIn('source_warehouse_id', $warehouseIds)
            ->whereIn('target_warehouse_id', $warehouseIds)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->limit($this->boundedLimit($limit))
            ->get();
    }

    /** @param list<array{product_id:int,quantity:int|float|string}> $lines
     * @return array<int, float>
     */
    private function normalizeLines(array $lines): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => ['At least one warehouse transfer line is required.'],
            ]);
        }

        $normalized = [];
        foreach ($lines as $line) {
            $productId = is_array($line) ? (int) ($line['product_id'] ?? 0) : 0;
            $quantity = is_array($line) && is_numeric($line['quantity'] ?? null)
                ? round((float) $line['quantity'], 3)
                : 0.0;

            if ($productId < 1 || $quantity <= 0) {
                throw ValidationException::withMessages([
                    'lines' => ['Every transfer line requires a valid product and positive quantity.'],
                ]);
            }

            if (array_key_exists($productId, $normalized)) {
                throw ValidationException::withMessages([
                    'lines' => ['A product may appear only once in a warehouse transfer.'],
                ]);
            }

            $normalized[$productId] = $quantity;
        }

        ksort($normalized);

        return $normalized;
    }

    /** @param array<int, float> $lines */
    private function fingerprint(int $sourceWarehouseId, int $targetWarehouseId, array $lines): string
    {
        return hash('sha256', json_encode([
            'source_warehouse_id' => $sourceWarehouseId,
            'target_warehouse_id' => $targetWarehouseId,
            'lines' => $lines,
        ], JSON_THROW_ON_ERROR));
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

    private function boundedLimit(int $limit): int
    {
        return min(max($limit, 1), 100);
    }
}
