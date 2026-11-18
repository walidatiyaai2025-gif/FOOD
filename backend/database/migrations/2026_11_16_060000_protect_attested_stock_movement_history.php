<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preserve canonical stock movements whenever legal-title evidence is
     * recorded for that inventory. A COUNT/MAX snapshot alone allows an
     * INSERT/DELETE replay to re-certify stale legal ownership.
     *
     * This bounded guard is not a complete immutable per-owner event ledger.
     */
    public function up(): void
    {
        if (! Schema::hasTable('stock_movements')
            || ! Schema::hasTable('enterprise_inventory_owner_positions')
            || ! Schema::hasTable('enterprise_inventory_title_attestations')) {
            throw new RuntimeException('Canonical movement and legal-title evidence tables are required.');
        }

        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            try {
                $this->dropGuards();

                DB::unprepared(<<<'SQL'
CREATE TRIGGER p110_attested_movement_no_delete
BEFORE DELETE ON stock_movements
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM enterprise_inventory_owner_positions AS owner_position
        INNER JOIN enterprise_inventory_title_attestations AS evidence
            ON evidence.owner_position_id = owner_position.id
        WHERE owner_position.inventory_id = OLD.inventory_id
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Cannot delete stock movement with attested legal title';
    END IF;
END
SQL);
                DB::unprepared(<<<'SQL'
CREATE TRIGGER p110_attested_movement_no_update
BEFORE UPDATE ON stock_movements
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM enterprise_inventory_owner_positions AS owner_position
        INNER JOIN enterprise_inventory_title_attestations AS evidence
            ON evidence.owner_position_id = owner_position.id
        WHERE owner_position.inventory_id IN (OLD.inventory_id, NEW.inventory_id)
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Cannot rewrite stock movement with attested legal title';
    END IF;
END
SQL);
            } catch (QueryException $exception) {
                // Least-privilege shared hosting may deny TRIGGER (1142),
                // SUPER (1227), or CREATE TRIGGER with binary logging (1419).
                // Do not ask operators to weaken global logging or grant SUPER.
                if (! in_array((int) ($exception->errorInfo[1] ?? 0), [1142, 1227, 1419], true)) {
                    throw $exception;
                }

                // MySQL DDL is not atomic: remove an incomplete trigger pair.
                // The runtime verifier MUST reject every certificate unless
                // both verified SQL guards exist. No insecure title fallback.
                try {
                    $this->dropGuards();
                } catch (QueryException $cleanup) {
                    if (! in_array((int) ($cleanup->errorInfo[1] ?? 0), [1142, 1227], true)) {
                        throw $cleanup;
                    }
                }
            }

            return;
        }

        if ($driver === 'sqlite') {
            $this->dropGuards();

            DB::unprepared(<<<'SQL'
CREATE TRIGGER p110_attested_movement_no_delete
BEFORE DELETE ON stock_movements
FOR EACH ROW
WHEN EXISTS (
    SELECT 1
    FROM enterprise_inventory_owner_positions AS owner_position
    INNER JOIN enterprise_inventory_title_attestations AS evidence
        ON evidence.owner_position_id = owner_position.id
    WHERE owner_position.inventory_id = OLD.inventory_id
)
BEGIN
    SELECT RAISE(ABORT, 'Cannot delete stock movement with attested legal title');
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER p110_attested_movement_no_update
BEFORE UPDATE ON stock_movements
FOR EACH ROW
WHEN EXISTS (
    SELECT 1
    FROM enterprise_inventory_owner_positions AS owner_position
    INNER JOIN enterprise_inventory_title_attestations AS evidence
        ON evidence.owner_position_id = owner_position.id
    WHERE owner_position.inventory_id IN (OLD.inventory_id, NEW.inventory_id)
)
BEGIN
    SELECT RAISE(ABORT, 'Cannot rewrite stock movement with attested legal title');
END
SQL);

            return;
        }

        throw new RuntimeException('Attested stock-movement history requires supported MySQL/MariaDB or SQLite.');
    }

    public function down(): void
    {
        // Never silently downgrade live legal-title audit evidence.
        if (Schema::hasTable('enterprise_inventory_title_attestations')
            && DB::table('enterprise_inventory_title_attestations')->exists()) {
            throw new RuntimeException('Signed stock history requires verified recovery before removing guards.');
        }

        $this->dropGuards();
    }

    private function dropGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS p110_attested_movement_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS p110_attested_movement_no_delete');
    }
};
