<?php

namespace App\Enterprise\Wms;

use App\Models\Inventory;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Reconcile legally UNATTRIBUTED stock with the canonical inventory row. This is not a legal-title attribution, valuation source, or second ledger.
 *
 * Called only while the canonical inventory is locked by the surrounding
 * procurement or WMS stock-posting transaction. Known-owner positions are
 * deliberately left stale: a transfer/receipt/return cannot infer legal title.
 */
final class UnknownInventoryOwnerProjection
{
    /**
     * Keep reservation hot paths within their SQL query budget while preserving
     * the legal-title fail-closed boundary. The caller holds the canonical
     * inventory row lock; the single conditional upsert touches only UNKNOWN
     * title when it is the sole position (or there are no positions yet).
     * Known/mixed owner positions remain deliberately stale.
     */
    public function afterReservationMutation(Inventory $lockedInventory): void
    {
        $inventoryId = (int) $lockedInventory->getKey();
        if ($inventoryId < 1 || ! $lockedInventory->exists || DB::transactionLevel() < 1) {
            throw new LogicException('Reservation owner projection requires the locked canonical stock transaction.');
        }

        self::assertCanonicalBalance($lockedInventory->quantity, $lockedInventory->reserved_quantity);

        // A single statement is essential: the existing WMS reservation
        // performance contract allows no extra SELECT+INSERT/UPDATE sequence.
        // Use the already-locked canonical snapshot, never user-supplied stock.
        $sql = <<<'SQL'
INSERT INTO enterprise_inventory_owner_positions (
    inventory_id, owner_key, owner_kind, supplier_id, quantity, reserved_quantity,
    source_inventory_updated_at, created_at, updated_at
)
SELECT ?, 'UNKNOWN', 'UNKNOWN', NULL, ?, ?, ?, ?, ?
FROM (SELECT 1 AS seed) AS projection_seed
LEFT JOIN enterprise_inventory_owner_positions AS existing
    ON existing.inventory_id = ?
GROUP BY projection_seed.seed
HAVING COUNT(existing.id) = 0
    OR (
        COUNT(existing.id) = 1
        AND MIN(existing.owner_key) = 'UNKNOWN'
        AND MIN(existing.owner_kind) = 'UNKNOWN'
        AND COUNT(existing.supplier_id) = 0
    )
SQL;

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $sql .= <<<'SQL'

ON DUPLICATE KEY UPDATE
    quantity = VALUES(quantity),
    reserved_quantity = VALUES(reserved_quantity),
    source_inventory_updated_at = VALUES(source_inventory_updated_at),
    updated_at = VALUES(updated_at)
SQL;
        } elseif (in_array($driver, ['sqlite', 'pgsql'], true)) {
            $sql .= <<<'SQL'

ON CONFLICT (inventory_id, owner_key) DO UPDATE SET
    quantity = excluded.quantity,
    reserved_quantity = excluded.reserved_quantity,
    source_inventory_updated_at = excluded.source_inventory_updated_at,
    updated_at = excluded.updated_at
SQL;
        } else {
            throw new LogicException('Unsupported inventory owner projection database driver.');
        }

        $now = now();
        DB::statement($sql, [
            $inventoryId,
            $lockedInventory->quantity,
            $lockedInventory->reserved_quantity,
            $lockedInventory->updated_at,
            $now,
            $now,
            $inventoryId,
        ]);
    }

    public function afterProcurementMutation(Inventory $inventory): void
    {
        $this->afterUnattributedInventoryMutation((int) $inventory->getKey());
    }

    /**
     * Refresh ONLY a wholly UNKNOWN stock position from already-posted canonical
     * inventory. The caller must hold the inventory row lock in this transaction.
     * This is a read-model projection, never a second stock posting or title grant.
     */
    public function afterUnattributedInventoryMutation(int $inventoryId): void
    {
        if ($inventoryId < 1 || DB::transactionLevel() < 1) {
            throw new LogicException('Inventory owner projection requires the canonical stock transaction.');
        }

        // Read the already locked canonical row, not client-supplied quantity.
        $canonical = DB::table('inventories')
            ->where('id', $inventoryId)
            ->first(['quantity', 'reserved_quantity', 'updated_at']);

        if ($canonical === null) {
            throw new LogicException('Canonical inventory disappeared before owner projection.');
        }

        self::assertCanonicalBalance($canonical->quantity, $canonical->reserved_quantity);

        $rows = DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $inventoryId)
            ->lockForUpdate()
            ->get(['id', 'owner_key', 'owner_kind', 'supplier_id']);

        // Never rewrite, redistribute, or implicitly refresh a legal-owner
        // assertion. Without authenticated title evidence the entire set must
        // remain fail-closed/stale after the aggregate inventory mutation.
        if ($rows->count() > 1) {
            return;
        }

        if ($rows->count() === 1) {
            $row = $rows->first();
            if ($row->owner_key !== 'UNKNOWN'
                || $row->owner_kind !== 'UNKNOWN'
                || $row->supplier_id !== null) {
                return;
            }
        }

        $data = [
            'quantity' => $canonical->quantity,
            'reserved_quantity' => $canonical->reserved_quantity,
            'source_inventory_updated_at' => $canonical->updated_at,
            'updated_at' => now(),
        ];

        if ($rows->isNotEmpty()) {
            DB::table('enterprise_inventory_owner_positions')
                ->where('id', $rows->first()->id)
                ->update($data);

            return;
        }

        DB::table('enterprise_inventory_owner_positions')->insert([
            'inventory_id' => $inventoryId,
            'owner_key' => 'UNKNOWN',
            'owner_kind' => 'UNKNOWN',
            'supplier_id' => null,
            ...$data,
            'created_at' => now(),
        ]);
    }

    /**
     * Every projection is a transactional integrity boundary on canonical
     * stock. Never write a valid-looking UNKNOWN/known owner report while
     * reserved units exceed physical on-hand or either balance is negative.
     * Work in exact DECIMAL(14,3) milli-units; an exception rolls back the
     * surrounding canonical stock mutation and its movement.
     */
    private static function assertCanonicalBalance(mixed $quantity, mixed $reserved): void
    {
        $onHand = self::milliUnits($quantity);
        $onReserve = self::milliUnits($reserved);

        if ($onHand < 0 || $onReserve < 0 || $onReserve > $onHand) {
            throw new LogicException('Canonical stock balance violates inventory reservation invariants.');
        }
    }

    private static function milliUnits(mixed $value): int
    {
        if (preg_match('/\A(-?)([0-9]{1,11})(?:\.([0-9]{1,3}))?\z/D', trim((string) $value), $parts) !== 1) {
            throw new LogicException('Canonical stock quantity is not valid DECIMAL(14,3).');
        }

        $millis = ((int) $parts[2]) * 1000 + (int) str_pad($parts[3] ?? '', 3, '0');

        return $parts[1] === '-' ? -$millis : $millis;
    }
}
