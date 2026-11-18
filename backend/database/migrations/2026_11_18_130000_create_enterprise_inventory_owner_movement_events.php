<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'enterprise_inventory_owner_movement_events';

    private const TRIGGERS = [
        'p110_owner_event_after_movement_insert',
        'p110_owner_event_movement_no_update',
        'p110_owner_event_movement_no_delete',
        'p110_owner_event_no_update',
        'p110_owner_event_no_delete',
    ];

    public function up(): void
    {
        foreach ([
            'inventories',
            'warehouses',
            'stores',
            'stock_movements',
            'enterprise_inventory_owner_positions',
            'enterprise_inventory_title_attestations',
        ] as $requiredTable) {
            if (! Schema::hasTable($requiredTable)) {
                throw new RuntimeException('P110 owner movement events require '.$requiredTable.'.');
            }
        }

        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                // The source movement and its AFTER INSERT snapshot must roll
                // back as one unit on MySQL/MariaDB deployments.
                $table->engine = 'InnoDB';
                $table->id();

                // These are immutable source snapshots, not cascading FKs.
                // Synthetic demo cleanup may remove source stock rows without
                // erasing an event that was already recorded for real work.
                $table->unsignedBigInteger('source_stock_movement_id');
                $table->unsignedBigInteger('inventory_id');
                $table->unsignedBigInteger('movement_store_id')->nullable();
                $table->string('movement_store_code')->nullable();
                $table->unsignedBigInteger('movement_user_id')->nullable();
                $table->string('movement_type');
                $table->decimal('movement_quantity', 14, 3);
                $table->string('movement_reference_type')->nullable();
                $table->unsignedBigInteger('movement_reference_id')->nullable();
                $table->timestamp('movement_created_at')->nullable();

                $table->unsignedInteger('owner_position_count')->default(0);
                $table->string('owner_snapshot_state', 40);
                $table->unsignedBigInteger('owner_position_id')->nullable();
                $table->string('owner_key', 80)->nullable();
                $table->string('owner_kind', 16)->nullable();
                $table->unsignedBigInteger('supplier_id')->nullable();
                $table->decimal('owner_quantity', 14, 3)->nullable();
                $table->decimal('owner_reserved_quantity', 14, 3)->nullable();
                $table->timestamp('owner_source_inventory_updated_at')->nullable();

                // This points to the latest evidence row observed at movement
                // time. It does not certify the position or make stale proof valid.
                $table->unsignedBigInteger('observed_attestation_id')->nullable();
                $table->string('observed_trusted_key_id', 64)->nullable();
                $table->char('observed_attestation_revision_sha256', 64)->nullable();
                $table->char('observed_attestation_document_sha256', 64)->nullable();

                $table->decimal('observed_inventory_quantity', 14, 3);
                $table->decimal('observed_inventory_reserved_quantity', 14, 3);
                $table->timestamp('observed_inventory_updated_at')->nullable();
                $table->timestamp('recorded_at')->useCurrent();

                $table->unique('source_stock_movement_id', 'eio_event_source_movement_uq');
                $table->index(['inventory_id', 'source_stock_movement_id'], 'eio_event_inventory_movement_idx');
            });
        }

        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->installMySqlTriggers();

            return;
        }

        if ($driver === 'sqlite') {
            $this->installSqliteTriggers();

            return;
        }

        throw new RuntimeException('P110 owner movement events require MySQL/MariaDB or SQLite triggers.');
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('P110 owner movement event snapshots must be preserved before rollback.');
        }

        $this->dropTriggers();
        Schema::dropIfExists(self::TABLE);
    }

    private function installMySqlTriggers(): void
    {
        try {
            $this->dropTriggers();

            DB::unprepared(<<<'SQL'
CREATE TRIGGER p110_owner_event_after_movement_insert
AFTER INSERT ON stock_movements
FOR EACH ROW
BEGIN
    DECLARE v_inventory_count BIGINT DEFAULT 0;
    DECLARE v_owner_position_count BIGINT DEFAULT 0;
    DECLARE v_owner_position_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_owner_snapshot_state VARCHAR(40) DEFAULT 'NO_OWNER_POSITION';
    DECLARE v_owner_key VARCHAR(80) DEFAULT NULL;
    DECLARE v_owner_kind VARCHAR(16) DEFAULT NULL;
    DECLARE v_supplier_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_owner_quantity DECIMAL(14, 3) DEFAULT NULL;
    DECLARE v_owner_reserved_quantity DECIMAL(14, 3) DEFAULT NULL;
    DECLARE v_owner_source_updated_at TIMESTAMP DEFAULT NULL;
    DECLARE v_attestation_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_trusted_key_id VARCHAR(64) DEFAULT NULL;
    DECLARE v_attestation_revision_sha256 CHAR(64) DEFAULT NULL;
    DECLARE v_attestation_document_sha256 CHAR(64) DEFAULT NULL;
    DECLARE v_observed_inventory_quantity DECIMAL(14, 3) DEFAULT NULL;
    DECLARE v_observed_inventory_reserved_quantity DECIMAL(14, 3) DEFAULT NULL;
    DECLARE v_observed_inventory_updated_at TIMESTAMP DEFAULT NULL;
    DECLARE v_movement_store_code VARCHAR(255) DEFAULT NULL;

    SELECT COUNT(*) INTO v_inventory_count
    FROM inventories
    WHERE id = NEW.inventory_id;

    IF v_inventory_count <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Cannot record P110 owner event without canonical inventory';
    END IF;

    SELECT store.code INTO v_movement_store_code
    FROM inventories AS inventory
    INNER JOIN warehouses AS warehouse ON warehouse.id = inventory.warehouse_id
    INNER JOIN stores AS store ON store.id = warehouse.store_id
    WHERE inventory.id = NEW.inventory_id;

    SELECT COUNT(*), MIN(id)
    INTO v_owner_position_count, v_owner_position_id
    FROM enterprise_inventory_owner_positions
    WHERE inventory_id = NEW.inventory_id;

        IF v_owner_position_count = 1 THEN
            SELECT owner_key, owner_kind, supplier_id, quantity, reserved_quantity, source_inventory_updated_at
            INTO v_owner_key, v_owner_kind, v_supplier_id, v_owner_quantity, v_owner_reserved_quantity, v_owner_source_updated_at
            FROM enterprise_inventory_owner_positions
            WHERE id = v_owner_position_id;

            SELECT MAX(id) INTO v_attestation_id
            FROM enterprise_inventory_title_attestations
            WHERE owner_position_id = v_owner_position_id;

            IF v_attestation_id IS NOT NULL THEN
                SELECT trusted_key_id, stock_revision_sha256, document_sha256
                INTO v_trusted_key_id, v_attestation_revision_sha256, v_attestation_document_sha256
                FROM enterprise_inventory_title_attestations
                WHERE id = v_attestation_id;
            END IF;

            IF v_owner_key = 'UNKNOWN' AND v_owner_kind = 'UNKNOWN' AND v_supplier_id IS NULL THEN
                SET v_owner_snapshot_state = 'UNKNOWN';
            ELSE
                SET v_owner_snapshot_state = 'SINGLE_OWNER_POSITION_UNVERIFIED';
            END IF;
        ELSEIF v_owner_position_count > 1 THEN
            SET v_owner_snapshot_state = 'MULTIPLE_OWNER_POSITIONS';
            SET v_owner_position_id = NULL;
        END IF;

        SELECT quantity, reserved_quantity, updated_at
        INTO v_observed_inventory_quantity, v_observed_inventory_reserved_quantity, v_observed_inventory_updated_at
        FROM inventories
        WHERE id = NEW.inventory_id;

    INSERT INTO enterprise_inventory_owner_movement_events (
        source_stock_movement_id, inventory_id, movement_store_id, movement_store_code, movement_user_id,
        movement_type, movement_quantity, movement_reference_type, movement_reference_id, movement_created_at,
        owner_position_count, owner_snapshot_state, owner_position_id, owner_key, owner_kind, supplier_id,
        owner_quantity, owner_reserved_quantity, owner_source_inventory_updated_at,
        observed_attestation_id, observed_trusted_key_id,
        observed_attestation_revision_sha256, observed_attestation_document_sha256,
        observed_inventory_quantity, observed_inventory_reserved_quantity, observed_inventory_updated_at,
        recorded_at
    ) VALUES (
        NEW.id, NEW.inventory_id, NEW.store_id, v_movement_store_code, NEW.user_id,
        NEW.type, NEW.quantity, NEW.reference_type, NEW.reference_id, NEW.created_at,
        v_owner_position_count, v_owner_snapshot_state, v_owner_position_id, v_owner_key, v_owner_kind, v_supplier_id,
        v_owner_quantity, v_owner_reserved_quantity, v_owner_source_updated_at,
        v_attestation_id, v_trusted_key_id,
        v_attestation_revision_sha256, v_attestation_document_sha256,
        v_observed_inventory_quantity, v_observed_inventory_reserved_quantity, v_observed_inventory_updated_at,
        CURRENT_TIMESTAMP
    );
END
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER p110_owner_event_movement_no_update
BEFORE UPDATE ON stock_movements
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM enterprise_inventory_owner_movement_events AS owner_event
        WHERE owner_event.source_stock_movement_id = OLD.id
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Canonical stock movement snapshots are append-only';
    END IF;
END
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER p110_owner_event_movement_no_delete
BEFORE DELETE ON stock_movements
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM enterprise_inventory_owner_movement_events AS owner_event
        WHERE owner_event.source_stock_movement_id = OLD.id
            AND (
                (
                    owner_event.movement_store_code LIKE 'FOODEX-DEMO-B2C-%'
                    AND owner_event.movement_store_code NOT LIKE 'FOODEX-DEMO-B2C-PERF-%'
                    AND owner_event.movement_reference_type = 'demo_seed'
                )
                OR (
                    owner_event.movement_store_code LIKE 'FOODEX-DEMO-B2C-PERF-%'
                    AND owner_event.movement_reference_type = 'performance_fixture'
                )
            )
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Canonical stock movement snapshots are append-only';
    END IF;
END
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER p110_owner_event_no_update
BEFORE UPDATE ON enterprise_inventory_owner_movement_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'P110 owner movement events are append-only';
END
SQL);

            DB::unprepared(<<<'SQL'
CREATE TRIGGER p110_owner_event_no_delete
BEFORE DELETE ON enterprise_inventory_owner_movement_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'P110 owner movement events are append-only';
END
SQL);
        } catch (QueryException $exception) {
            // cPanel/shared hosting may deny TRIGGER (1142), SUPER (1227), or
            // trigger creation with binary logging (1419). Leave the site
            // installable, but remove partial guards and fail closed in the
            // title verifier until all event triggers are visible.
            if (! in_array((int) ($exception->errorInfo[1] ?? 0), [1142, 1227, 1419], true)) {
                throw $exception;
            }

            try {
                $this->dropTriggers();
            } catch (QueryException $cleanup) {
                if (! in_array((int) ($cleanup->errorInfo[1] ?? 0), [1142, 1227, 1419], true)) {
                    throw $cleanup;
                }
            }
        }
    }

    private function installSqliteTriggers(): void
    {
        $this->dropTriggers();

        DB::unprepared(<<<'SQL'
CREATE TRIGGER p110_owner_event_after_movement_insert
AFTER INSERT ON stock_movements
FOR EACH ROW
BEGIN
    INSERT INTO enterprise_inventory_owner_movement_events (
        source_stock_movement_id, inventory_id, movement_store_id, movement_store_code, movement_user_id,
        movement_type, movement_quantity, movement_reference_type, movement_reference_id, movement_created_at,
        owner_position_count, owner_snapshot_state, owner_position_id, owner_key, owner_kind, supplier_id,
        owner_quantity, owner_reserved_quantity, owner_source_inventory_updated_at,
        observed_attestation_id, observed_trusted_key_id,
        observed_attestation_revision_sha256, observed_attestation_document_sha256,
        observed_inventory_quantity, observed_inventory_reserved_quantity, observed_inventory_updated_at,
        recorded_at
    )
    SELECT
        NEW.id, NEW.inventory_id, NEW.store_id, movement_store.code, NEW.user_id,
        NEW.type, NEW.quantity, NEW.reference_type, NEW.reference_id, NEW.created_at,
        COALESCE(owner_snapshot.position_count, 0),
        CASE
            WHEN COALESCE(owner_snapshot.position_count, 0) = 0 THEN 'NO_OWNER_POSITION'
            WHEN owner_snapshot.position_count > 1 THEN 'MULTIPLE_OWNER_POSITIONS'
            WHEN owner_position.owner_key = 'UNKNOWN'
                AND owner_position.owner_kind = 'UNKNOWN'
                AND owner_position.supplier_id IS NULL THEN 'UNKNOWN'
            ELSE 'SINGLE_OWNER_POSITION_UNVERIFIED'
        END,
        CASE WHEN owner_snapshot.position_count = 1 THEN owner_position.id ELSE NULL END,
        CASE WHEN owner_snapshot.position_count = 1 THEN owner_position.owner_key ELSE NULL END,
        CASE WHEN owner_snapshot.position_count = 1 THEN owner_position.owner_kind ELSE NULL END,
        CASE WHEN owner_snapshot.position_count = 1 THEN owner_position.supplier_id ELSE NULL END,
        CASE WHEN owner_snapshot.position_count = 1 THEN owner_position.quantity ELSE NULL END,
        CASE WHEN owner_snapshot.position_count = 1 THEN owner_position.reserved_quantity ELSE NULL END,
        CASE WHEN owner_snapshot.position_count = 1 THEN owner_position.source_inventory_updated_at ELSE NULL END,
        CASE WHEN owner_snapshot.position_count = 1 THEN title_attestation.id ELSE NULL END,
        CASE WHEN owner_snapshot.position_count = 1 THEN title_attestation.trusted_key_id ELSE NULL END,
        CASE WHEN owner_snapshot.position_count = 1 THEN title_attestation.stock_revision_sha256 ELSE NULL END,
        CASE WHEN owner_snapshot.position_count = 1 THEN title_attestation.document_sha256 ELSE NULL END,
        inventory.quantity, inventory.reserved_quantity, inventory.updated_at,
        CURRENT_TIMESTAMP
    FROM inventories AS inventory
    LEFT JOIN warehouses AS movement_warehouse ON movement_warehouse.id = inventory.warehouse_id
    LEFT JOIN stores AS movement_store ON movement_store.id = movement_warehouse.store_id
    LEFT JOIN (
        SELECT inventory_id, COUNT(*) AS position_count, MIN(id) AS position_id
        FROM enterprise_inventory_owner_positions
        WHERE inventory_id = NEW.inventory_id
        GROUP BY inventory_id
    ) AS owner_snapshot ON owner_snapshot.inventory_id = inventory.id
    LEFT JOIN enterprise_inventory_owner_positions AS owner_position
        ON owner_position.id = owner_snapshot.position_id
    LEFT JOIN enterprise_inventory_title_attestations AS title_attestation
        ON title_attestation.id = (
            SELECT MAX(evidence.id)
            FROM enterprise_inventory_title_attestations AS evidence
            WHERE evidence.owner_position_id = owner_position.id
        )
    WHERE inventory.id = NEW.inventory_id;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER p110_owner_event_movement_no_update
BEFORE UPDATE ON stock_movements
FOR EACH ROW
WHEN EXISTS (
    SELECT 1
    FROM enterprise_inventory_owner_movement_events AS owner_event
    WHERE owner_event.source_stock_movement_id = OLD.id
)
BEGIN
    SELECT RAISE(ABORT, 'Canonical stock movement snapshots are append-only');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER p110_owner_event_movement_no_delete
BEFORE DELETE ON stock_movements
FOR EACH ROW
WHEN NOT EXISTS (
    SELECT 1
    FROM enterprise_inventory_owner_movement_events AS owner_event
    WHERE owner_event.source_stock_movement_id = OLD.id
        AND (
            (
                owner_event.movement_store_code LIKE 'FOODEX-DEMO-B2C-%'
                AND owner_event.movement_store_code NOT LIKE 'FOODEX-DEMO-B2C-PERF-%'
                AND owner_event.movement_reference_type = 'demo_seed'
            )
            OR (
                owner_event.movement_store_code LIKE 'FOODEX-DEMO-B2C-PERF-%'
                AND owner_event.movement_reference_type = 'performance_fixture'
            )
        )
)
BEGIN
    SELECT RAISE(ABORT, 'Canonical stock movement snapshots are append-only');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER p110_owner_event_no_update
BEFORE UPDATE ON enterprise_inventory_owner_movement_events
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'P110 owner movement events are append-only');
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER p110_owner_event_no_delete
BEFORE DELETE ON enterprise_inventory_owner_movement_events
FOR EACH ROW
BEGIN
    SELECT RAISE(ABORT, 'P110 owner movement events are append-only');
END
SQL);
    }

    private function dropTriggers(): void
    {
        foreach (self::TRIGGERS as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
    }
};
