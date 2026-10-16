<?php

namespace App\Enterprise\Wms;

use App\Models\Inventory;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InventoryAdjustmentService
{
    private const CLASSIFICATION_TO_TYPE = [
        'correction' => 'adjustment',
        'damage' => 'damage',
        'loss' => 'loss',
    ];

    private const MOVEMENT_TYPES = ['adjustment', 'damage', 'loss'];

    public function __construct(
        private readonly WarehouseScope $warehouseScope,
        private readonly AuditLogger $audit,
    ) {}

    public function post(
        User $actor,
        int $warehouseId,
        int $productId,
        float $quantityDelta,
        string $classification,
        string $referenceType,
        int $referenceId,
        string $reason,
        ?string $notes = null,
    ): StockMovement {
        $warehouse = $this->warehouseScope->assertWarehouse($actor, $warehouseId, 'inventory.manage');
        $delta = $this->normalizeQuantityDelta($quantityDelta);
        $classification = $this->normalizeClassification($classification);
        $referenceType = $this->normalizeReferenceType($referenceType);
        $reason = $this->normalizeReason($reason);
        $notes = $this->normalizeNotes($notes);

        if ($referenceId < 1) {
            throw ValidationException::withMessages([
                'reference_id' => ['Adjustment reference id must be a positive integer.'],
            ]);
        }

        if ($classification !== 'correction' && $delta > 0) {
            throw ValidationException::withMessages([
                'quantity_delta' => ['Damage and loss adjustments must reduce on-hand inventory.'],
            ]);
        }

        $movementType = self::CLASSIFICATION_TO_TYPE[$classification];
        $storeId = (int) $warehouse->store_id;

        return DB::transaction(function () use (
            $actor,
            $warehouseId,
            $productId,
            $delta,
            $classification,
            $referenceType,
            $referenceId,
            $reason,
            $notes,
            $movementType,
            $storeId,
        ): StockMovement {
            $inventory = Inventory::query()
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if (! $inventory instanceof Inventory) {
                throw ValidationException::withMessages([
                    'product_id' => ['The product must be stocked in the selected warehouse.'],
                ]);
            }

            $existing = StockMovement::query()
                ->where('store_id', $storeId)
                ->where('inventory_id', $inventory->getKey())
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->whereIn('type', self::MOVEMENT_TYPES)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($existing->count() > 1) {
                throw ValidationException::withMessages([
                    'reference' => ['Adjustment reference provenance is inconsistent and requires review.'],
                ]);
            }

            /** @var StockMovement|null $posted */
            $posted = $existing->first();
            if ($posted instanceof StockMovement) {
                $this->assertReplayMatches(
                    $posted,
                    $movementType,
                    $delta,
                    $reason,
                    $notes,
                );

                return $posted;
            }

            $current = round((float) $inventory->quantity, 3);
            $reserved = round((float) $inventory->reserved_quantity, 3);
            $next = round($current + $delta, 3);

            if ($next < -0.0005) {
                throw ValidationException::withMessages([
                    'quantity_delta' => ['Adjustment cannot reduce inventory below zero.'],
                ]);
            }

            if ($next + 0.0005 < $reserved) {
                throw ValidationException::withMessages([
                    'quantity_delta' => ['Adjustment cannot reduce on-hand inventory below reserved quantity.'],
                ]);
            }

            $inventory->forceFill([
                'quantity' => max(0.0, $next),
            ])->save();

            $movement = StockMovement::query()->create([
                'inventory_id' => $inventory->getKey(),
                'store_id' => $storeId,
                'user_id' => $actor->getKey(),
                'type' => $movementType,
                'quantity' => $delta,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reason' => $reason,
                'notes' => $notes,
            ]);

            $this->audit->record(
                'enterprise.wms.inventory_adjustment.posted',
                $actor,
                $inventory,
                [
                    'quantity' => $current,
                    'reserved_quantity' => $reserved,
                ],
                [
                    'quantity' => max(0.0, $next),
                    'reserved_quantity' => $reserved,
                    'classification' => $classification,
                    'quantity_delta' => $delta,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'reason' => $reason,
                    'notes' => $notes,
                    'stock_movement_id' => $movement->getKey(),
                ],
            );

            return $movement;
        }, attempts: 3);
    }

    /** @return Collection<int, StockMovement> */
    public function recentHistory(
        User $actor,
        int $warehouseId,
        ?int $productId = null,
        ?string $classification = null,
        int $limit = 50,
    ): Collection {
        $warehouse = $this->warehouseScope->assertWarehouse($actor, $warehouseId, 'inventory.view');
        $movementType = $classification === null
            ? null
            : self::CLASSIFICATION_TO_TYPE[$this->normalizeClassification($classification)];

        if ($productId !== null && $productId < 1) {
            throw ValidationException::withMessages([
                'product_id' => ['Product id must be a positive integer.'],
            ]);
        }

        return StockMovement::query()
            ->select('stock_movements.*')
            ->join('inventories', 'inventories.id', '=', 'stock_movements.inventory_id')
            ->where('stock_movements.store_id', $warehouse->store_id)
            ->where('inventories.warehouse_id', $warehouseId)
            ->whereIn('stock_movements.type', self::MOVEMENT_TYPES)
            ->when(
                $productId !== null,
                fn ($query) => $query->where('inventories.product_id', $productId),
            )
            ->when(
                $movementType !== null,
                fn ($query) => $query->where('stock_movements.type', $movementType),
            )
            ->orderByDesc('stock_movements.id')
            ->limit($this->boundedLimit($limit))
            ->get();
    }

    private function assertReplayMatches(
        StockMovement $movement,
        string $movementType,
        float $delta,
        string $reason,
        ?string $notes,
    ): void {
        $matches = $movement->type === $movementType
            && $this->sameQuantity((float) $movement->quantity, $delta)
            && (string) $movement->reason === $reason
            && $movement->notes === $notes;

        if (! $matches) {
            throw ValidationException::withMessages([
                'reference' => ['This adjustment reference was already posted with different data.'],
            ]);
        }
    }

    private function normalizeQuantityDelta(float $quantityDelta): float
    {
        $quantityDelta = round($quantityDelta, 3);

        if (abs($quantityDelta) < 0.0005 || abs($quantityDelta) > 99999999999.999) {
            throw ValidationException::withMessages([
                'quantity_delta' => ['Adjustment quantity must be non-zero and within supported inventory precision.'],
            ]);
        }

        return $quantityDelta;
    }

    private function normalizeClassification(string $classification): string
    {
        $classification = strtolower(trim($classification));

        if (! array_key_exists($classification, self::CLASSIFICATION_TO_TYPE)) {
            throw ValidationException::withMessages([
                'classification' => ['Adjustment classification must be correction, damage or loss.'],
            ]);
        }

        return $classification;
    }

    private function normalizeReferenceType(string $referenceType): string
    {
        $referenceType = trim($referenceType);

        if (preg_match('/\A[A-Za-z0-9_.:-]{1,120}\z/', $referenceType) !== 1) {
            throw ValidationException::withMessages([
                'reference_type' => ['Adjustment reference type must contain only letters, numbers, dot, underscore, colon or dash.'],
            ]);
        }

        return $referenceType;
    }

    private function normalizeReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'reason' => ['Adjustment reason is required and must not exceed 500 characters.'],
            ]);
        }

        return $reason;
    }

    private function normalizeNotes(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }

        $notes = trim($notes);
        if ($notes === '') {
            return null;
        }

        if (mb_strlen($notes) > 2000) {
            throw ValidationException::withMessages([
                'notes' => ['Adjustment notes must not exceed 2000 characters.'],
            ]);
        }

        return $notes;
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
