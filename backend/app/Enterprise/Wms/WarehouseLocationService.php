<?php

namespace App\Enterprise\Wms;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WarehouseLocationService
{
    public function __construct(
        private readonly WarehouseScope $warehouseScope,
        private readonly AuditLogger $audit,
    ) {}

    public function saveZone(
        User $actor,
        int $warehouseId,
        string $code,
        string $name,
        bool $isActive = true,
    ): object {
        $warehouse = $this->warehouseScope->assertWarehouse($actor, $warehouseId, 'inventory.manage');
        $code = $this->code($code);
        $name = $this->name($name);

        return DB::transaction(function () use ($actor, $warehouse, $warehouseId, $code, $name, $isActive): object {
            $existing = DB::table('enterprise_warehouse_zones')
                ->where('warehouse_id', $warehouseId)
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            $values = [
                'store_id' => (int) $warehouse->store_id,
                'warehouse_id' => $warehouseId,
                'code' => $code,
                'name' => $name,
                'is_active' => $isActive,
                'updated_by' => $actor->getKey(),
                'updated_at' => now(),
            ];

            if ($existing === null) {
                $id = DB::table('enterprise_warehouse_zones')->insertGetId([
                    ...$values,
                    'created_by' => $actor->getKey(),
                    'created_at' => now(),
                ]);
                $this->audit->record('enterprise.wms.zone.created', $actor, null, null, [
                    'store_id' => (int) $warehouse->store_id,
                    'warehouse_id' => $warehouseId,
                    'zone_id' => $id,
                    'code' => $code,
                ]);

                return DB::table('enterprise_warehouse_zones')->where('id', $id)->first();
            }

            DB::table('enterprise_warehouse_zones')->where('id', $existing->id)->update($values);
            $this->audit->record('enterprise.wms.zone.updated', $actor, null, (array) $existing, [
                ...$values,
                'zone_id' => (int) $existing->id,
            ]);

            return DB::table('enterprise_warehouse_zones')->where('id', $existing->id)->first();
        }, attempts: 3);
    }

    public function saveBin(
        User $actor,
        int $warehouseId,
        ?int $zoneId,
        string $code,
        string $name,
        bool $isActive = true,
    ): object {
        $warehouse = $this->warehouseScope->assertWarehouse($actor, $warehouseId, 'inventory.manage');
        $code = $this->code($code);
        $name = $this->name($name);

        if ($zoneId !== null) {
            $zone = DB::table('enterprise_warehouse_zones')
                ->where('id', $zoneId)
                ->where('warehouse_id', $warehouseId)
                ->first();
            if ($zone === null) {
                throw ValidationException::withMessages([
                    'zone_id' => ['Zone must belong to the selected warehouse.'],
                ]);
            }
        }

        return DB::transaction(function () use ($actor, $warehouse, $warehouseId, $zoneId, $code, $name, $isActive): object {
            $existing = DB::table('enterprise_warehouse_bins')
                ->where('warehouse_id', $warehouseId)
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            $values = [
                'store_id' => (int) $warehouse->store_id,
                'warehouse_id' => $warehouseId,
                'zone_id' => $zoneId,
                'code' => $code,
                'name' => $name,
                'is_active' => $isActive,
                'updated_by' => $actor->getKey(),
                'updated_at' => now(),
            ];

            if ($existing === null) {
                $id = DB::table('enterprise_warehouse_bins')->insertGetId([
                    ...$values,
                    'created_by' => $actor->getKey(),
                    'created_at' => now(),
                ]);
                $this->audit->record('enterprise.wms.bin.created', $actor, null, null, [
                    'store_id' => (int) $warehouse->store_id,
                    'warehouse_id' => $warehouseId,
                    'bin_id' => $id,
                    'code' => $code,
                ]);

                return DB::table('enterprise_warehouse_bins')->where('id', $id)->first();
            }

            DB::table('enterprise_warehouse_bins')->where('id', $existing->id)->update($values);
            $this->audit->record('enterprise.wms.bin.updated', $actor, null, (array) $existing, [
                ...$values,
                'bin_id' => (int) $existing->id,
            ]);

            return DB::table('enterprise_warehouse_bins')->where('id', $existing->id)->first();
        }, attempts: 3);
    }

    public function assignInventory(User $actor, int $inventoryId, int $binId): object
    {
        $inventory = DB::table('inventories')->where('id', $inventoryId)->first();
        if ($inventory === null) {
            abort(404);
        }

        $warehouse = $this->warehouseScope->assertWarehouse(
            $actor,
            (int) $inventory->warehouse_id,
            'inventory.manage',
        );
        $bin = DB::table('enterprise_warehouse_bins')
            ->where('id', $binId)
            ->where('warehouse_id', $inventory->warehouse_id)
            ->where('is_active', true)
            ->first();

        if ($bin === null) {
            throw ValidationException::withMessages([
                'bin_id' => ['Bin must be active and belong to the inventory warehouse.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $warehouse, $inventory, $inventoryId, $binId): object {
            $existing = DB::table('enterprise_inventory_bin_assignments')
                ->where('inventory_id', $inventoryId)
                ->lockForUpdate()
                ->first();
            $now = now();
            $values = [
                'store_id' => (int) $warehouse->store_id,
                'warehouse_id' => (int) $inventory->warehouse_id,
                'bin_id' => $binId,
                'assigned_by' => $actor->getKey(),
                'assigned_at' => $now,
                'updated_at' => $now,
            ];

            if ($existing === null) {
                $id = DB::table('enterprise_inventory_bin_assignments')->insertGetId([
                    ...$values,
                    'inventory_id' => $inventoryId,
                    'created_at' => $now,
                ]);
            } else {
                DB::table('enterprise_inventory_bin_assignments')
                    ->where('id', $existing->id)
                    ->update($values);
                $id = (int) $existing->id;
            }

            $this->audit->record('enterprise.wms.inventory_bin.assigned', $actor, null, $existing ? (array) $existing : null, [
                'store_id' => (int) $warehouse->store_id,
                'warehouse_id' => (int) $inventory->warehouse_id,
                'inventory_id' => $inventoryId,
                'bin_id' => $binId,
            ]);

            return $this->assignment($actor, $inventoryId);
        }, attempts: 3);
    }

    public function assignment(User $actor, int $inventoryId): ?object
    {
        $inventory = DB::table('inventories')->where('id', $inventoryId)->first(['id', 'warehouse_id']);
        if ($inventory === null) {
            abort(404);
        }
        $this->warehouseScope->assertWarehouse($actor, (int) $inventory->warehouse_id, 'inventory.view');

        return DB::table('enterprise_inventory_bin_assignments as assignment')
            ->join('enterprise_warehouse_bins as bin', 'bin.id', '=', 'assignment.bin_id')
            ->leftJoin('enterprise_warehouse_zones as zone', 'zone.id', '=', 'bin.zone_id')
            ->where('assignment.inventory_id', $inventoryId)
            ->select([
                'assignment.id',
                'assignment.inventory_id',
                'assignment.warehouse_id',
                'assignment.bin_id',
                'assignment.assigned_at',
                'bin.code as bin_code',
                'bin.name as bin_name',
                'bin.is_active as bin_is_active',
                'zone.id as zone_id',
                'zone.code as zone_code',
                'zone.name as zone_name',
            ])
            ->first();
    }

    /** @return Collection<int, \stdClass> */
    public function zones(User $actor, int $warehouseId): Collection
    {
        $this->warehouseScope->assertWarehouse($actor, $warehouseId, 'inventory.view');

        return DB::table('enterprise_warehouse_zones')
            ->where('warehouse_id', $warehouseId)
            ->orderByDesc('is_active')
            ->orderBy('code')
            ->get();
    }

    /** @return Collection<int, \stdClass> */
    public function bins(User $actor, int $warehouseId): Collection
    {
        $this->warehouseScope->assertWarehouse($actor, $warehouseId, 'inventory.view');

        return DB::table('enterprise_warehouse_bins as bin')
            ->leftJoin('enterprise_warehouse_zones as zone', 'zone.id', '=', 'bin.zone_id')
            ->where('bin.warehouse_id', $warehouseId)
            ->orderByDesc('bin.is_active')
            ->orderBy('zone.code')
            ->orderBy('bin.code')
            ->get([
                'bin.*',
                'zone.code as zone_code',
                'zone.name as zone_name',
            ]);
    }

    /** @return Collection<int, \stdClass> */
    public function assignments(User $actor, int $warehouseId, int $limit = 250): Collection
    {
        $this->warehouseScope->assertWarehouse($actor, $warehouseId, 'inventory.view');

        return DB::table('enterprise_inventory_bin_assignments as assignment')
            ->join('inventories as inventory', 'inventory.id', '=', 'assignment.inventory_id')
            ->join('products as product', 'product.id', '=', 'inventory.product_id')
            ->join('enterprise_warehouse_bins as bin', 'bin.id', '=', 'assignment.bin_id')
            ->leftJoin('enterprise_warehouse_zones as zone', 'zone.id', '=', 'bin.zone_id')
            ->where('assignment.warehouse_id', $warehouseId)
            ->orderBy('zone.code')
            ->orderBy('bin.code')
            ->orderBy('product.name')
            ->limit(max(1, min(500, $limit)))
            ->get([
                'assignment.*',
                'product.name as product_name',
                'product.sku',
                'bin.code as bin_code',
                'bin.name as bin_name',
                'zone.code as zone_code',
                'zone.name as zone_name',
            ]);
    }

    public function captureTransfer(User $actor, int $transferId): void
    {
        $transfer = DB::table('enterprise_warehouse_transfers')->where('id', $transferId)->first();
        if ($transfer === null) {
            return;
        }
        $this->warehouseScope->assertTransferPair(
            $actor,
            (int) $transfer->source_warehouse_id,
            (int) $transfer->target_warehouse_id,
        );

        $lines = DB::table('enterprise_warehouse_transfer_lines')
            ->where('transfer_id', $transferId)
            ->get(['id', 'source_inventory_id', 'target_inventory_id']);

        foreach ($lines as $line) {
            $this->captureInventorySubject($actor, (int) $line->source_inventory_id, 'enterprise_warehouse_transfer_line', (int) $line->id, 'source');
            $this->captureInventorySubject($actor, (int) $line->target_inventory_id, 'enterprise_warehouse_transfer_line', (int) $line->id, 'target');
        }
    }

    public function captureCount(User $actor, int $countId): void
    {
        $count = DB::table('enterprise_inventory_counts')->where('id', $countId)->first();
        if ($count === null) {
            return;
        }
        $this->warehouseScope->assertWarehouse($actor, (int) $count->warehouse_id, 'inventory.manage');

        foreach (DB::table('enterprise_inventory_count_lines')->where('count_id', $countId)->get(['id', 'inventory_id']) as $line) {
            $this->captureInventorySubject($actor, (int) $line->inventory_id, 'enterprise_inventory_count_line', (int) $line->id, 'snapshot');
        }
    }

    public function captureLot(User $actor, int $lotId): void
    {
        $lot = DB::table('enterprise_inventory_lots')->where('id', $lotId)->first();
        if ($lot === null) {
            return;
        }
        $inventoryId = DB::table('inventories')
            ->where('warehouse_id', $lot->warehouse_id)
            ->where('product_id', $lot->product_id)
            ->value('id');

        if ($inventoryId !== null) {
            $this->captureInventorySubject($actor, (int) $inventoryId, 'enterprise_inventory_lot', $lotId, 'placement');
        }
    }

    public function captureMovement(User $actor, int $movementId, string $role = 'movement'): void
    {
        $movement = DB::table('stock_movements')->where('id', $movementId)->first(['id', 'inventory_id']);
        if ($movement !== null) {
            $this->captureInventorySubject($actor, (int) $movement->inventory_id, 'stock_movement', $movementId, $role);
        }
    }

    private function captureInventorySubject(
        User $actor,
        int $inventoryId,
        string $subjectType,
        int $subjectId,
        string $role,
    ): void {
        if ($subjectId < 1 || preg_match('/\A[a-z0-9_.:-]{1,96}\z/i', $subjectType) !== 1) {
            return;
        }
        $assignment = DB::table('enterprise_inventory_bin_assignments')
            ->where('inventory_id', $inventoryId)
            ->first(['store_id', 'warehouse_id', 'bin_id']);
        if ($assignment === null) {
            return;
        }

        $this->warehouseScope->assertWarehouse($actor, (int) $assignment->warehouse_id, 'inventory.manage');
        $role = Str::lower(trim($role));
        if (preg_match('/\A[a-z0-9_.:-]{1,48}\z/', $role) !== 1) {
            throw ValidationException::withMessages(['role' => ['Invalid bin provenance role.']]);
        }

        DB::table('enterprise_wms_bin_provenance')->insertOrIgnore([
            'store_id' => (int) $assignment->store_id,
            'warehouse_id' => (int) $assignment->warehouse_id,
            'bin_id' => (int) $assignment->bin_id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'role' => $role,
            'created_by' => $actor->getKey(),
            'created_at' => now(),
        ]);
    }

    private function code(string $value): string
    {
        $value = Str::upper(trim($value));
        if ($value === '' || mb_strlen($value) > 64 || preg_match('/\A[A-Z0-9_.:-]+\z/', $value) !== 1) {
            throw ValidationException::withMessages([
                'code' => ['Code must use letters, numbers, dot, underscore, colon or dash.'],
            ]);
        }

        return $value;
    }

    private function name(string $value): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 160) {
            throw ValidationException::withMessages([
                'name' => ['Name is required and must not exceed 160 characters.'],
            ]);
        }

        return $value;
    }
}
