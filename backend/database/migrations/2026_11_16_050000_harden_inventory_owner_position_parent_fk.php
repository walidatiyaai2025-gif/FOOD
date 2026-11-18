<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Upgrade V2 databases that applied the original owner-position FK while
     * it still used CASCADE. Fresh installs already use RESTRICT.
     */
    public function up(): void
    {
        if (! Schema::hasTable('enterprise_inventory_owner_positions')) {
            throw new RuntimeException('Owner positions must exist before legal-title retention hardening.');
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite needs a table rebuild to change a destructive FK.
            // Fail closed on legacy schemas rather than deleting owner history.
            $foreign = collect(DB::select('PRAGMA foreign_key_list("enterprise_inventory_owner_positions")'))
                ->first(static fn (object $row): bool => (string) $row->{'from'} === 'inventory_id'
                    && (string) $row->table === 'inventories');
            $action = strtoupper((string) ($foreign->on_delete ?? ''));

            if (! in_array($action, ['RESTRICT', 'NO ACTION'], true)) {
                throw new RuntimeException('Legacy SQLite owner FK requires verified backup and offline rebuild.');
            }

            return;
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Inventory legal-title FK upgrade requires a supported SQL driver.');
        }

        $foreign = DB::selectOne(<<<'SQL'
SELECT k.CONSTRAINT_NAME AS constraint_name, r.DELETE_RULE AS delete_rule
FROM information_schema.KEY_COLUMN_USAGE AS k
INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS AS r
    ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
    AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
WHERE k.TABLE_SCHEMA = ?
    AND k.TABLE_NAME = 'enterprise_inventory_owner_positions'
    AND k.COLUMN_NAME = 'inventory_id'
    AND k.REFERENCED_TABLE_NAME = 'inventories'
SQL, [DB::connection()->getDatabaseName()]);

        if ($foreign === null) {
            throw new RuntimeException('Cannot verify inventory-owner parent FK.');
        }

        $action = strtoupper((string) $foreign->delete_rule);
        if (in_array($action, ['RESTRICT', 'NO ACTION'], true)) {
            return;
        }

        if ($action !== 'CASCADE') {
            throw new RuntimeException('Unexpected inventory owner parent delete rule.');
        }

        $constraint = (string) $foreign->constraint_name;
        if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $constraint) !== 1) {
            throw new RuntimeException('Unsafe FK name in database metadata.');
        }

        // One MySQL ALTER replaces CASCADE without copying or changing rows.
        DB::statement('ALTER TABLE enterprise_inventory_owner_positions '
            .'DROP FOREIGN KEY '.$constraint.', '
            .'ADD CONSTRAINT eio_inventory_restrict_fk '
            .'FOREIGN KEY (inventory_id) REFERENCES inventories (id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        // Forward-only protection: never restore destructive parent CASCADE.
    }
};
