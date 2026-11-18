<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A signed legal-title claim is evidence, not a mutable application
     * preference. Deleting its attestation would otherwise disable the
     * stock-movement guards and permit an INSERT/DELETE ABA replay.
     *
     * This additional forward migration protects existing installations
     * that already applied the stock-movement guard migration.
     */
    public function up(): void
    {
        if (! Schema::hasTable('enterprise_inventory_title_attestations')) {
            throw new RuntimeException('Signed legal-title evidence must exist before immutability protection.');
        }

        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            try {
                $this->dropGuards();

                DB::unprepared(<<<'SQL'
CREATE TRIGGER p110_attestation_no_delete
BEFORE DELETE ON enterprise_inventory_title_attestations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Recorded legal title evidence cannot be deleted';
END
SQL);
                DB::unprepared(<<<'SQL'
CREATE TRIGGER p110_attestation_no_update
BEFORE UPDATE ON enterprise_inventory_title_attestations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Recorded legal title evidence cannot be rewritten';
END
SQL);
            } catch (QueryException $exception) {
                // cPanel/shared hosting may deny TRIGGER (1142), SUPER (1227)
                // or creation with binary logging (1419). Do not weaken the
                // MySQL server or silently certify stock without both guards.
                if (! in_array((int) ($exception->errorInfo[1] ?? 0), [1142, 1227, 1419], true)) {
                    throw $exception;
                }

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
CREATE TRIGGER p110_attestation_no_delete
BEFORE DELETE ON enterprise_inventory_title_attestations
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'Recorded legal title evidence cannot be deleted');
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER p110_attestation_no_update
BEFORE UPDATE ON enterprise_inventory_title_attestations
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'Recorded legal title evidence cannot be rewritten');
END
SQL);

            return;
        }

        throw new RuntimeException('Signed legal-title history protection requires MySQL/MariaDB or SQLite.');
    }

    public function down(): void
    {
        if (Schema::hasTable('enterprise_inventory_title_attestations')
            && DB::table('enterprise_inventory_title_attestations')->exists()) {
            throw new RuntimeException('Recorded signed title evidence must be preserved before recovery or downgrade.');
        }

        $this->dropGuards();
    }

    private function dropGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS p110_attestation_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS p110_attestation_no_delete');
    }
};
