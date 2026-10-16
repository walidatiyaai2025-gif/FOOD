<?php

namespace App\Enterprise\Wms;

use App\Models\EnterpriseInventoryLot;
use App\Models\EnterpriseInventoryLotMovement;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InventoryLotService
{
    private const STATUSES = ['active', 'quarantined', 'closed'];

    private const MAX_EXPIRY_WINDOW_DAYS = 90;

    public function __construct(
        private readonly WarehouseScope $warehouseScope,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{identifier?:mixed,manufactured_on?:mixed,received_on?:mixed,expires_on?:mixed}  $attributes
     */
    public function upsert(
        User $actor,
        int $warehouseId,
        int $productId,
        array $attributes,
    ): EnterpriseInventoryLot {
        $warehouse = $this->warehouseScope->assertWarehouse($actor, $warehouseId, 'inventory.manage');
        $data = Validator::make($attributes, [
            'identifier' => ['required', 'string', 'max:120'],
            'manufactured_on' => ['nullable', 'date'],
            'received_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
        ])->validate();

        $normalizedIdentifier = $this->normalizeIdentifier((string) $data['identifier']);
        if ($normalizedIdentifier === '') {
            throw ValidationException::withMessages([
                'identifier' => ['Lot or batch identifier is required.'],
            ]);
        }

        $inventoryExists = DB::table('inventories')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->exists();

        if (! $inventoryExists) {
            throw ValidationException::withMessages([
                'product_id' => ['The product must be stocked in the selected warehouse.'],
            ]);
        }

        $dates = $this->normalizedDates($data);
        $this->assertDateOrder($dates);

        $values = [
            'store_id' => (int) $warehouse->store_id,
            'identifier' => $normalizedIdentifier,
            ...$dates,
            'updated_by' => $actor->getKey(),
        ];

        return DB::transaction(function () use (
            $actor,
            $warehouseId,
            $productId,
            $normalizedIdentifier,
            $values,
        ): EnterpriseInventoryLot {
            $lot = EnterpriseInventoryLot::query()
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('normalized_identifier', $normalizedIdentifier)
                ->lockForUpdate()
                ->first();

            if ($lot instanceof EnterpriseInventoryLot) {
                $before = $lot->toArray();
                $lot->fill($values);

                if (! $lot->isDirty()) {
                    return $lot;
                }

                $lot->save();
                $this->audit->record(
                    'enterprise.wms.inventory_lot.updated',
                    $actor,
                    $lot,
                    $before,
                    $lot->fresh()->toArray(),
                );

                return $lot->refresh();
            }

            $lot = EnterpriseInventoryLot::query()->create([
                ...$values,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'normalized_identifier' => $normalizedIdentifier,
                'status' => 'active',
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->record(
                'enterprise.wms.inventory_lot.created',
                $actor,
                $lot,
                null,
                $lot->toArray(),
            );

            return $lot->refresh();
        }, attempts: 3);
    }

    public function transitionStatus(
        User $actor,
        int $lotId,
        string $targetStatus,
    ): EnterpriseInventoryLot {
        $targetStatus = Str::lower(trim($targetStatus));
        if (! in_array($targetStatus, self::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Invalid lot status.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $lotId, $targetStatus): EnterpriseInventoryLot {
            $lot = EnterpriseInventoryLot::query()
                ->lockForUpdate()
                ->findOrFail($lotId);

            $this->warehouseScope->assertWarehouse(
                $actor,
                (int) $lot->warehouse_id,
                'inventory.manage',
            );

            $currentStatus = (string) $lot->status;
            if ($currentStatus === $targetStatus) {
                return $lot;
            }

            $allowed = match ($currentStatus) {
                'active' => ['quarantined', 'closed'],
                'quarantined' => ['active', 'closed'],
                'closed' => [],
                default => [],
            };

            if (! in_array($targetStatus, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => ['The requested lot status transition is not allowed.'],
                ]);
            }

            $before = $lot->toArray();
            $lot->forceFill([
                'status' => $targetStatus,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->audit->record(
                'enterprise.wms.inventory_lot.status_changed',
                $actor,
                $lot,
                $before,
                $lot->fresh()->toArray(),
            );

            return $lot->refresh();
        }, attempts: 3);
    }

    public function linkMovement(
        User $actor,
        int $lotId,
        int $stockMovementId,
    ): EnterpriseInventoryLotMovement {
        return DB::transaction(function () use (
            $actor,
            $lotId,
            $stockMovementId,
        ): EnterpriseInventoryLotMovement {
            $lot = EnterpriseInventoryLot::query()
                ->lockForUpdate()
                ->findOrFail($lotId);

            $this->warehouseScope->assertWarehouse(
                $actor,
                (int) $lot->warehouse_id,
                'inventory.manage',
            );

            $movement = DB::table('stock_movements as movement')
                ->join('inventories as inventory', 'inventory.id', '=', 'movement.inventory_id')
                ->join('warehouses as warehouse', 'warehouse.id', '=', 'inventory.warehouse_id')
                ->where('movement.id', $stockMovementId)
                ->select([
                    'movement.id',
                    'movement.store_id',
                    'inventory.warehouse_id',
                    'inventory.product_id',
                    'warehouse.store_id as warehouse_store_id',
                ])
                ->lockForUpdate()
                ->first();

            if ($movement === null) {
                abort(404);
            }

            $this->warehouseScope->assertWarehouse(
                $actor,
                (int) $movement->warehouse_id,
                'inventory.manage',
            );

            if (
                (int) $movement->warehouse_id !== (int) $lot->warehouse_id
                || (int) $movement->product_id !== (int) $lot->product_id
                || (int) $movement->warehouse_store_id !== (int) $lot->store_id
                || ($movement->store_id !== null && (int) $movement->store_id !== (int) $lot->store_id)
            ) {
                throw ValidationException::withMessages([
                    'stock_movement_id' => ['Stock movement does not belong to the lot warehouse and product scope.'],
                ]);
            }

            $existing = EnterpriseInventoryLotMovement::query()
                ->where('stock_movement_id', $stockMovementId)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof EnterpriseInventoryLotMovement) {
                if ((int) $existing->inventory_lot_id !== $lotId) {
                    throw ValidationException::withMessages([
                        'stock_movement_id' => ['Stock movement is already linked to another lot.'],
                    ]);
                }

                return $existing;
            }

            $link = EnterpriseInventoryLotMovement::query()->create([
                'store_id' => (int) $lot->store_id,
                'inventory_lot_id' => $lotId,
                'stock_movement_id' => $stockMovementId,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->record(
                'enterprise.wms.inventory_lot.movement_linked',
                $actor,
                $link,
                null,
                $link->toArray(),
            );

            return $link->refresh();
        }, attempts: 3);
    }

    /** @return Collection<int, EnterpriseInventoryLot> */
    public function lotsForWarehouse(
        User $actor,
        int $warehouseId,
        ?int $productId = null,
        ?string $identifier = null,
        int $limit = 25,
    ): Collection {
        $this->warehouseScope->assertWarehouse($actor, $warehouseId, 'inventory.view');

        return EnterpriseInventoryLot::query()
            ->where('warehouse_id', $warehouseId)
            ->when($productId !== null, fn ($query) => $query->where('product_id', $productId))
            ->when(
                $identifier !== null,
                fn ($query) => $query->where(
                    'normalized_identifier',
                    $this->normalizeIdentifier($identifier),
                ),
            )
            ->orderBy('id')
            ->limit($this->boundedLimit($limit))
            ->get();
    }

    /** @return Collection<int, EnterpriseInventoryLot> */
    public function expiryWindow(
        User $actor,
        int $storeId,
        string $fromDate,
        string $toDate,
        ?int $warehouseId = null,
        int $limit = 25,
    ): Collection {
        $dateData = Validator::make([
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ], [
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date'],
        ])->validate();

        $from = CarbonImmutable::parse((string) $dateData['from_date'])->startOfDay();
        $to = CarbonImmutable::parse((string) $dateData['to_date'])->startOfDay();

        if ($to->lessThan($from)) {
            throw ValidationException::withMessages([
                'to_date' => ['Expiry window end must be on or after the start date.'],
            ]);
        }

        if ($from->diffInDays($to) > self::MAX_EXPIRY_WINDOW_DAYS) {
            throw ValidationException::withMessages([
                'to_date' => ['Expiry windows may not exceed 90 days.'],
            ]);
        }

        if ($warehouseId !== null) {
            $warehouse = $this->warehouseScope->assertWarehouse($actor, $warehouseId, 'inventory.view');
            abort_unless((int) $warehouse->store_id === $storeId, 404);
            $warehouseIds = [$warehouseId];
        } else {
            $warehouseIds = $this->warehouseScope->authorizedWarehouseIds(
                $actor,
                $storeId,
                'inventory.view',
            );
        }

        if ($warehouseIds === []) {
            return new Collection;
        }

        return EnterpriseInventoryLot::query()
            ->where('store_id', $storeId)
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('status', '!=', 'closed')
            ->whereBetween('expires_on', [$from->toDateString(), $to->toDateString()])
            ->orderBy('expires_on')
            ->orderBy('id')
            ->limit($this->boundedLimit($limit))
            ->get();
    }

    private function normalizeIdentifier(string $identifier): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($identifier));

        return Str::upper($collapsed ?? '');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{manufactured_on:?string,received_on:?string,expires_on:?string}
     */
    private function normalizedDates(array $data): array
    {
        return [
            'manufactured_on' => $this->dateOrNull($data['manufactured_on'] ?? null),
            'received_on' => $this->dateOrNull($data['received_on'] ?? null),
            'expires_on' => $this->dateOrNull($data['expires_on'] ?? null),
        ];
    }

    /**
     * @param  array{manufactured_on:?string,received_on:?string,expires_on:?string}  $dates
     */
    private function assertDateOrder(array $dates): void
    {
        $manufactured = $dates['manufactured_on'];
        $received = $dates['received_on'];
        $expires = $dates['expires_on'];

        if ($manufactured !== null && $received !== null && $manufactured > $received) {
            throw ValidationException::withMessages([
                'received_on' => ['Received date may not be before manufacture date.'],
            ]);
        }

        if ($manufactured !== null && $expires !== null && $manufactured > $expires) {
            throw ValidationException::withMessages([
                'expires_on' => ['Expiry date may not be before manufacture date.'],
            ]);
        }

        if ($received !== null && $expires !== null && $received > $expires) {
            throw ValidationException::withMessages([
                'expires_on' => ['Expiry date may not be before received date.'],
            ]);
        }
    }

    private function dateOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->toDateString();
    }

    private function boundedLimit(int $limit): int
    {
        return max(1, min(100, $limit));
    }
}
