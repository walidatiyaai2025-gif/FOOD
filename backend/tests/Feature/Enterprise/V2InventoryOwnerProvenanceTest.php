<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Wms\InventoryAdjustmentService;
use App\Enterprise\Wms\InventoryCountService;
use App\Enterprise\Wms\InventoryLegalTitleAttestationVerifier;
use App\Enterprise\Wms\InventoryOwnerProvenanceReadService;
use App\Enterprise\Wms\InventoryOwnershipRuleService;
use App\Enterprise\Wms\StockReservationService;
use App\Enterprise\Wms\UnknownInventoryOwnerProjection;
use App\Enterprise\Wms\WarehouseTransferService;
use App\Models\Inventory;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Services\DemoDataManager;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\TestCase;

final class V2InventoryOwnerProvenanceTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    public function test_upgrade_marks_existing_inventory_unknown_without_changing_stock_or_reservations(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('LEGACY');
        $migration = require database_path('migrations/2026_11_15_000100_create_inventory_owner_provenance_positions.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('enterprise_inventory_owner_positions'));
        $migration->up();

        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'],
            'owner_key' => 'UNKNOWN',
            'owner_kind' => 'UNKNOWN',
            'quantity' => 10,
            'reserved_quantity' => 3,
            'certified_by' => null,
        ]);
        $this->assertDatabaseHas('inventories', [
            'id' => $stock['inventory_id'],
            'quantity' => 10,
            'reserved_quantity' => 3,
        ]);

        $actor = $this->inventoryActor($stock['store_id']);
        $snapshot = app(InventoryOwnerProvenanceReadService::class)->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNKNOWN', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
        $this->assertSame('10.000', $snapshot['quantity']);
        $this->assertSame('3.000', $snapshot['reserved_quantity']);
    }

    public function test_populated_owner_positions_refuse_destructive_migration_rollback(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('ROLLBACK-GUARD');
        $now = now();
        DB::table('enterprise_inventory_owner_positions')->insert([
            [
                'inventory_id' => $stock['inventory_id'],
                'owner_key' => 'UNKNOWN',
                'owner_kind' => 'UNKNOWN',
                'supplier_id' => null,
                'quantity' => 6,
                'reserved_quantity' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'inventory_id' => $stock['inventory_id'],
                'owner_key' => 'FOODEX',
                'owner_kind' => 'FOODEX',
                'supplier_id' => null,
                'quantity' => 4,
                'reserved_quantity' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $migration = require database_path('migrations/2026_11_15_000100_create_inventory_owner_provenance_positions.php');

        try {
            $migration->down();
            $this->fail('Rollback must not delete nonempty owner position history.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('preserved', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('enterprise_inventory_owner_positions'));
        $this->assertSame(2, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->count());
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'],
            'owner_key' => 'FOODEX',
            'quantity' => 4,
            'reserved_quantity' => 1,
        ]);
        $this->assertDatabaseHas('inventories', [
            'id' => $stock['inventory_id'],
            'quantity' => 10,
            'reserved_quantity' => 3,
        ]);
    }

    public function test_removing_a_signed_evidence_guard_disables_title_certification(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Trigger integrity can be safely exercised through isolated SQLite DDL.');
        }

        $verifier = app(InventoryLegalTitleAttestationVerifier::class);
        $guards = new \ReflectionMethod(InventoryLegalTitleAttestationVerifier::class, 'hasProtectedStockMovementHistory');
        $this->assertTrue($guards->invoke($verifier));

        DB::unprepared('DROP TRIGGER IF EXISTS p110_attestation_no_delete');
        try {
            $this->assertFalse($guards->invoke($verifier));
        } finally {
            $migration = require database_path('migrations/2026_11_16_070000_protect_inventory_title_attestation_records.php');
            $migration->up();
        }
        $this->assertTrue($guards->invoke($verifier));
    }

    public function test_canonical_movement_records_immutable_owner_snapshot_atomically(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('OWNER-MOVEMENT-EVENT');
        $now = now();
        $ownerPositionId = (int) DB::table('enterprise_inventory_owner_positions')->insertGetId([
            'inventory_id' => $stock['inventory_id'],
            'owner_key' => 'UNKNOWN',
            'owner_kind' => 'UNKNOWN',
            'supplier_id' => null,
            'quantity' => '10.000',
            'reserved_quantity' => '3.000',
            'source_inventory_updated_at' => DB::table('inventories')
                ->where('id', $stock['inventory_id'])->value('updated_at'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $movement = [
            'inventory_id' => $stock['inventory_id'],
            'store_id' => $stock['store_id'],
            'user_id' => null,
            'type' => 'p110_owner_event_test',
            'quantity' => '0.125',
            'reference_type' => 'p110-owner-event-test',
            'reference_id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $journalCount = DB::table('enterprise_finance_journals')->count();
        $journalLineCount = DB::table('enterprise_finance_journal_lines')->count();
        $auditCount = DB::table('audit_logs')->count();
        $inventoryBefore = (array) DB::table('inventories')->where('id', $stock['inventory_id'])->first();
        $positionBefore = (array) DB::table('enterprise_inventory_owner_positions')
            ->where('id', $ownerPositionId)->first();

        $movementId = (int) DB::table('stock_movements')->insertGetId($movement);
        $event = DB::table('enterprise_inventory_owner_movement_events')
            ->where('source_stock_movement_id', $movementId)->first();

        $this->assertNotNull($event);
        $this->assertSame($movementId, (int) $event->source_stock_movement_id);
        $this->assertSame($stock['inventory_id'], (int) $event->inventory_id);
        $this->assertSame('OWN-OWNER-MOVEMENT-EVENT', $event->movement_store_code);
        $this->assertSame('p110_owner_event_test', $event->movement_type);
        $this->assertSame('0.125', number_format((float) $event->movement_quantity, 3, '.', ''));
        $this->assertSame(1, (int) $event->owner_position_count);
        $this->assertSame('UNKNOWN', $event->owner_snapshot_state);
        $this->assertSame($ownerPositionId, (int) $event->owner_position_id);
        $this->assertSame('UNKNOWN', $event->owner_key);
        $this->assertSame('UNKNOWN', $event->owner_kind);
        $this->assertNull($event->supplier_id);
        $this->assertNull($event->observed_attestation_id);
        $this->assertNull($event->observed_trusted_key_id);
        $this->assertSame('10.000', number_format((float) $event->observed_inventory_quantity, 3, '.', ''));
        $this->assertSame('3.000', number_format((float) $event->observed_inventory_reserved_quantity, 3, '.', ''));
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $this->assertCount(0, DB::select('PRAGMA foreign_key_list("enterprise_inventory_owner_movement_events")'));
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->assertSame(0, DB::table('information_schema.KEY_COLUMN_USAGE')
                ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', 'enterprise_inventory_owner_movement_events')
                ->whereNotNull('REFERENCED_TABLE_NAME')
                ->count());
        }
        $this->assertSame($inventoryBefore, (array) DB::table('inventories')->where('id', $stock['inventory_id'])->first());
        $this->assertSame($positionBefore, (array) DB::table('enterprise_inventory_owner_positions')
            ->where('id', $ownerPositionId)->first());
        $this->assertSame($journalCount, DB::table('enterprise_finance_journals')->count());
        $this->assertSame($journalLineCount, DB::table('enterprise_finance_journal_lines')->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());

        // Neither the immutable event nor a real source movement can be
        // rewritten after the canonical insert. Reversals remain new inserts.
        foreach ([
            fn () => DB::table('enterprise_inventory_owner_movement_events')
                ->where('source_stock_movement_id', $movementId)->update(['movement_type' => 'tampered']),
            fn () => DB::table('enterprise_inventory_owner_movement_events')
                ->where('source_stock_movement_id', $movementId)->delete(),
            fn () => DB::table('stock_movements')->where('id', $movementId)->update(['quantity' => '9.999']),
            fn () => DB::table('stock_movements')->where('id', $movementId)->delete(),
        ] as $forbiddenMutation) {
            try {
                $forbiddenMutation();
                $this->fail('P110 owner movement event and source snapshots must reject rewrites.');
            } catch (QueryException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        $this->assertDatabaseHas('stock_movements', ['id' => $movementId, 'quantity' => '0.125']);
        $this->assertDatabaseHas('enterprise_inventory_owner_movement_events', [
            'source_stock_movement_id' => $movementId,
            'movement_type' => 'p110_owner_event_test',
            'owner_snapshot_state' => 'UNKNOWN',
        ]);
        $duplicateEvent = (array) $event;
        unset($duplicateEvent['id']);
        try {
            DB::table('enterprise_inventory_owner_movement_events')->insert($duplicateEvent);
            $this->fail('One source movement cannot create a duplicate owner event.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertSame(1, DB::table('enterprise_inventory_owner_movement_events')
            ->where('source_stock_movement_id', $movementId)->count());

        $reversal = $movement;
        $reversal['type'] = 'p110_owner_event_reversal';
        $reversal['reference_type'] = 'p110-owner-event-reversal';
        $reversal['reference_id'] = 2;
        $reversal['quantity'] = '0.125';
        $reversalId = (int) DB::table('stock_movements')->insertGetId($reversal);
        $this->assertSame(1, DB::table('enterprise_inventory_owner_movement_events')
            ->where('source_stock_movement_id', $reversalId)->count());

        $noPositionStock = $this->stock('OWNER-EVENT-NO-POSITION');
        $noPositionMovement = $movement;
        $noPositionMovement['inventory_id'] = $noPositionStock['inventory_id'];
        $noPositionMovement['store_id'] = $noPositionStock['store_id'];
        $noPositionMovement['type'] = 'p110_owner_event_no_position';
        $noPositionMovement['reference_id'] = 6;
        $noPositionMovementId = (int) DB::table('stock_movements')->insertGetId($noPositionMovement);
        $noPositionEvent = DB::table('enterprise_inventory_owner_movement_events')
            ->where('source_stock_movement_id', $noPositionMovementId)->firstOrFail();
        $this->assertSame(0, (int) $noPositionEvent->owner_position_count);
        $this->assertSame('NO_OWNER_POSITION', $noPositionEvent->owner_snapshot_state);
        $this->assertNull($noPositionEvent->owner_position_id);
        $this->assertNull($noPositionEvent->owner_key);
        $this->assertNull($noPositionEvent->supplier_id);

        $multiplePositionStock = $this->stock('OWNER-EVENT-MULTIPLE-POSITIONS');
        $multiplePositionInventory = DB::table('inventories')
            ->where('id', $multiplePositionStock['inventory_id'])->firstOrFail();
        foreach ([['UNKNOWN', 'UNKNOWN', 5], ['FOODEX', 'FOODEX', 5]] as [$ownerKey, $ownerKind, $quantity]) {
            DB::table('enterprise_inventory_owner_positions')->insert([
                'inventory_id' => $multiplePositionStock['inventory_id'],
                'owner_key' => $ownerKey,
                'owner_kind' => $ownerKind,
                'supplier_id' => null,
                'quantity' => $quantity,
                'reserved_quantity' => 0,
                'source_inventory_updated_at' => $multiplePositionInventory->updated_at,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $multiplePositionMovement = $movement;
        $multiplePositionMovement['inventory_id'] = $multiplePositionStock['inventory_id'];
        $multiplePositionMovement['store_id'] = $multiplePositionStock['store_id'];
        $multiplePositionMovement['type'] = 'p110_owner_event_multiple_positions';
        $multiplePositionMovement['reference_id'] = 7;
        $multiplePositionMovementId = (int) DB::table('stock_movements')->insertGetId($multiplePositionMovement);
        $multiplePositionEvent = DB::table('enterprise_inventory_owner_movement_events')
            ->where('source_stock_movement_id', $multiplePositionMovementId)->firstOrFail();
        $this->assertSame(2, (int) $multiplePositionEvent->owner_position_count);
        $this->assertSame('MULTIPLE_OWNER_POSITIONS', $multiplePositionEvent->owner_snapshot_state);
        $this->assertNull($multiplePositionEvent->owner_position_id);
        $this->assertNull($multiplePositionEvent->owner_key);
        $this->assertNull($multiplePositionEvent->supplier_id);

        $movementCountBeforeRollback = DB::table('stock_movements')
            ->where('inventory_id', $stock['inventory_id'])->count();
        $eventCountBeforeRollback = DB::table('enterprise_inventory_owner_movement_events')
            ->where('inventory_id', $stock['inventory_id'])->count();
        try {
            DB::transaction(function () use ($movement): void {
                $rollbackMovement = $movement;
                $rollbackMovement['type'] = 'p110_owner_event_rolled_back';
                $rollbackMovement['reference_type'] = 'p110-owner-event-rollback';
                $rollbackMovement['reference_id'] = 3;
                $rollbackMovementId = (int) DB::table('stock_movements')->insertGetId($rollbackMovement);
                $this->assertSame(1, DB::table('enterprise_inventory_owner_movement_events')
                    ->where('source_stock_movement_id', $rollbackMovementId)->count());
                throw new \RuntimeException('rollback P110 owner event fixture');
            });
            $this->fail('Movement and owner event must share the posting transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback P110 owner event fixture', $exception->getMessage());
        }
        $this->assertSame($movementCountBeforeRollback, DB::table('stock_movements')
            ->where('inventory_id', $stock['inventory_id'])->count());
        $this->assertSame($eventCountBeforeRollback, DB::table('enterprise_inventory_owner_movement_events')
            ->where('inventory_id', $stock['inventory_id'])->count());

        $movementCountBeforeBadInventory = DB::table('stock_movements')->count();
        $eventCountBeforeBadInventory = DB::table('enterprise_inventory_owner_movement_events')->count();
        try {
            DB::table('stock_movements')->insert([
                ...$movement,
                'inventory_id' => PHP_INT_MAX,
            ]);
            $this->fail('A movement without a canonical inventory FK must fail closed.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertSame($movementCountBeforeBadInventory, DB::table('stock_movements')->count());
        $this->assertSame($eventCountBeforeBadInventory, DB::table('enterprise_inventory_owner_movement_events')->count());

        // Every movement is evented, and a demo_seed marker on a live store
        // cannot opt its source row out of the append-only guard.
        $demoSeedMovement = $movement;
        $demoSeedMovement['type'] = 'demo_seed';
        $demoSeedMovement['reference_type'] = 'demo_seed';
        $demoSeedMovement['reference_id'] = 4;
        $demoSeedId = (int) DB::table('stock_movements')->insertGetId($demoSeedMovement);
        $this->assertSame(1, DB::table('enterprise_inventory_owner_movement_events')
            ->where('source_stock_movement_id', $demoSeedId)->count());
        try {
            DB::table('stock_movements')->where('id', $demoSeedId)->delete();
            $this->fail('A live-store movement cannot evade its immutable event with a demo_seed reference.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        // A mutable live-store code change after an event must not retroactively
        // grant the synthetic cleanup exception to the event-linked movement.
        $renamedLiveStock = $this->stock('OWNER-EVENT-RENAMED-LIVE');
        $renamedLiveMovement = $movement;
        $renamedLiveMovement['inventory_id'] = $renamedLiveStock['inventory_id'];
        $renamedLiveMovement['store_id'] = $renamedLiveStock['store_id'];
        $renamedLiveMovement['type'] = 'p110_owner_event_renamed_live';
        $renamedLiveMovement['reference_id'] = 9;
        $renamedLiveMovementId = (int) DB::table('stock_movements')->insertGetId($renamedLiveMovement);
        DB::table('stores')->where('id', $renamedLiveStock['store_id'])
            ->update(['code' => 'FOODEX-DEMO-B2C-RENAMED-LIVE']);
        $this->assertDatabaseHas('enterprise_inventory_owner_movement_events', [
            'source_stock_movement_id' => $renamedLiveMovementId,
            'movement_store_code' => 'OWN-OWNER-EVENT-RENAMED-LIVE',
        ]);
        foreach ([
            fn () => DB::table('stock_movements')->where('id', $renamedLiveMovementId)->update(['quantity' => '8.888']),
            fn () => DB::table('stock_movements')->where('id', $renamedLiveMovementId)->delete(),
        ] as $forbiddenMutation) {
            try {
                $forbiddenMutation();
                $this->fail('Renaming a live store into the reserved demo prefix cannot grant source update/delete access.');
            } catch (QueryException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        $coverage = new \ReflectionMethod(InventoryLegalTitleAttestationVerifier::class, 'hasCompleteOwnerMovementEventCoverage');
        $this->assertTrue($coverage->invoke(app(InventoryLegalTitleAttestationVerifier::class), $renamedLiveStock['inventory_id']));
        $this->assertDatabaseHas('enterprise_inventory_owner_movement_events', [
            'source_stock_movement_id' => $renamedLiveMovementId,
        ]);
        DB::table('stores')->where('id', $renamedLiveStock['store_id'])
            ->update(['code' => 'OWN-OWNER-EVENT-RENAMED-LIVE']);

        // The performance namespace is narrower: a matching mutable code is
        // insufficient unless this is the deterministic fixture event type.
        $perfLookalikeStock = $this->stock('OWNER-EVENT-PERF-LOOKALIKE');
        DB::table('stores')->where('id', $perfLookalikeStock['store_id'])
            ->update(['code' => 'FOODEX-DEMO-B2C-PERF-LOOKALIKE']);
        $perfLookalikeMovement = $movement;
        $perfLookalikeMovement['inventory_id'] = $perfLookalikeStock['inventory_id'];
        $perfLookalikeMovement['store_id'] = $perfLookalikeStock['store_id'];
        $perfLookalikeMovement['type'] = 'p110_owner_event_perf_lookalike';
        $perfLookalikeMovement['reference_type'] = 'non_performance_source';
        $perfLookalikeMovement['reference_id'] = 10;
        $perfLookalikeMovementId = (int) DB::table('stock_movements')->insertGetId($perfLookalikeMovement);
        try {
            DB::table('stock_movements')->where('id', $perfLookalikeMovementId)->delete();
            $this->fail('The performance fixture cleanup exception requires both its insertion-time namespace and reference type.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertDatabaseHas('enterprise_inventory_owner_movement_events', [
            'source_stock_movement_id' => $perfLookalikeMovementId,
            'movement_store_code' => 'FOODEX-DEMO-B2C-PERF-LOOKALIKE',
            'movement_reference_type' => 'non_performance_source',
        ]);

        // A mutable reserved code alone is not sufficient for cleanup. Only
        // the actual demo seeder's reference marker can authorize source removal.
        $demoLookalikeStock = $this->stock('OWNER-EVENT-DEMO-LOOKALIKE');
        DB::table('stores')->where('id', $demoLookalikeStock['store_id'])
            ->update(['code' => 'FOODEX-DEMO-B2C-LEGACY-LIVE']);
        $nonSeedDemoMovement = $movement;
        $nonSeedDemoMovement['inventory_id'] = $demoLookalikeStock['inventory_id'];
        $nonSeedDemoMovement['store_id'] = $demoLookalikeStock['store_id'];
        $nonSeedDemoMovement['type'] = 'p110_owner_event_demo_lookalike';
        $nonSeedDemoMovement['reference_type'] = 'live_receipt';
        $nonSeedDemoMovement['reference_id'] = 11;
        $nonSeedDemoMovementId = (int) DB::table('stock_movements')->insertGetId($nonSeedDemoMovement);
        try {
            DB::table('stock_movements')->where('id', $nonSeedDemoMovementId)->delete();
            $this->fail('A non-seed event at a reserved demo prefix must remain append-only.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertDatabaseHas('enterprise_inventory_owner_movement_events', [
            'source_stock_movement_id' => $nonSeedDemoMovementId,
            'movement_store_code' => 'FOODEX-DEMO-B2C-LEGACY-LIVE',
            'movement_reference_type' => 'live_receipt',
        ]);
        DB::table('stores')->where('id', $demoLookalikeStock['store_id'])
            ->update(['code' => 'OWN-OWNER-EVENT-DEMO-LOOKALIKE']);

        // DemoDataManager's verified synthetic store can still be cleared.
        // Only authentic demo_seed source movements qualify for cleanup.
        $demoStock = $this->stock('OWNER-EVENT-DEMO-CLEANUP');
        $demoProductId = (int) DB::table('inventories')
            ->where('id', $demoStock['inventory_id'])->value('product_id');
        DB::table('products')->where('id', $demoProductId)
            ->update(['sku' => 'FOODEX-DEMO-P-OWNER-EVENT-CLEANUP']);
        DB::table('stores')->where('id', $demoStock['store_id'])
            ->update(['code' => 'FOODEX-DEMO-B2C-OWNER-EVENT']);
        $demoInventoryMovement = $movement;
        $demoInventoryMovement['inventory_id'] = $demoStock['inventory_id'];
        $demoInventoryMovement['store_id'] = $demoStock['store_id'];
        $demoInventoryMovement['type'] = 'seed_opening_balance';
        $demoInventoryMovement['reference_type'] = 'demo_seed';
        $demoInventoryMovement['reference_id'] = 5;
        $demoInventoryMovementId = (int) DB::table('stock_movements')->insertGetId($demoInventoryMovement);
        $this->assertSame(1, DB::table('enterprise_inventory_owner_movement_events')
            ->where('source_stock_movement_id', $demoInventoryMovementId)->count());
        $demoSeedInventoryMovement = $demoInventoryMovement;
        $demoSeedInventoryMovement['type'] = 'seed_opening_balance';
        $demoSeedInventoryMovement['reference_type'] = 'demo_seed';
        $demoSeedInventoryMovement['reference_id'] = 8;
        $demoSeedInventoryMovementId = (int) DB::table('stock_movements')->insertGetId($demoSeedInventoryMovement);
        $this->assertSame(1, DB::table('enterprise_inventory_owner_movement_events')
            ->where('source_stock_movement_id', $demoSeedInventoryMovementId)->count());

        app(DemoDataManager::class)->clear();

        $this->assertDatabaseMissing('stock_movements', ['id' => $demoInventoryMovementId]);
        $this->assertDatabaseMissing('stock_movements', ['id' => $demoSeedInventoryMovementId]);
        $this->assertDatabaseMissing('inventories', ['id' => $demoStock['inventory_id']]);
        $this->assertDatabaseMissing('stores', ['id' => $demoStock['store_id']]);
        $this->assertDatabaseHas('enterprise_inventory_owner_movement_events', [
            'source_stock_movement_id' => $demoInventoryMovementId,
            'inventory_id' => $demoStock['inventory_id'],
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_movement_events', [
            'source_stock_movement_id' => $demoSeedInventoryMovementId,
            'inventory_id' => $demoStock['inventory_id'],
        ]);

        $migration = require database_path('migrations/2026_11_18_130000_create_enterprise_inventory_owner_movement_events.php');
        try {
            $migration->down();
            $this->fail('The event ledger migration must not delete recorded event snapshots.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('preserved', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('enterprise_inventory_owner_movement_events'));
        $this->assertDatabaseHas('enterprise_inventory_owner_movement_events', [
            'source_stock_movement_id' => $movementId,
        ]);
    }

    public function test_missing_owner_event_trigger_disables_title_certification(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Trigger disappearance is safely exercised through isolated SQLite DDL.');
        }

        $verifier = app(InventoryLegalTitleAttestationVerifier::class);
        $guards = new \ReflectionMethod(InventoryLegalTitleAttestationVerifier::class, 'hasProtectedStockMovementHistory');
        $this->assertTrue($guards->invoke($verifier));

        $migration = require database_path('migrations/2026_11_18_130000_create_enterprise_inventory_owner_movement_events.php');
        foreach ([
            'p110_owner_event_after_movement_insert',
            'p110_owner_event_movement_no_update',
            'p110_owner_event_movement_no_delete',
            'p110_owner_event_no_update',
            'p110_owner_event_no_delete',
        ] as $missingTrigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$missingTrigger);
            try {
                $this->assertFalse($guards->invoke($verifier), 'Missing '.$missingTrigger.' must fail title certification closed.');
            } finally {
                $migration->up();
            }
            $this->assertTrue($guards->invoke($verifier));
        }
    }

    public function test_event_cleanup_guard_without_demo_seed_predicate_fails_closed(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Altered trigger policy is safely exercised through isolated SQLite DDL.');
        }

        $verifier = app(InventoryLegalTitleAttestationVerifier::class);
        $guards = new \ReflectionMethod(InventoryLegalTitleAttestationVerifier::class, 'hasProtectedStockMovementHistory');
        $this->assertTrue($guards->invoke($verifier));

        DB::unprepared('DROP TRIGGER IF EXISTS p110_owner_event_movement_no_delete');
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

        $migration = require database_path('migrations/2026_11_18_130000_create_enterprise_inventory_owner_movement_events.php');
        try {
            $this->assertFalse($guards->invoke($verifier), 'A generic demo-prefix delete rule without demo_seed must not certify title.');
        } finally {
            $migration->up();
        }

        $this->assertTrue($guards->invoke($verifier));
    }

    public function test_missing_event_for_real_movement_keeps_title_uncertified_after_trigger_restore(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Missing-event recovery is isolated to SQLite DDL in this fixture.');
        }

        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('OWNER-EVENT-MISSING-ROW');
        // Even when somebody mutates the code directly after seeding, it does
        // not exempt unrecorded legacy movement rows from coverage.
        DB::table('stores')->where('id', $stock['store_id'])
            ->update(['code' => 'FOODEX-DEMO-B2C-MISSING-EVENT']);
        $movement = [
            'inventory_id' => $stock['inventory_id'],
            'store_id' => $stock['store_id'],
            'type' => 'p110_owner_event_unrecorded',
            'quantity' => '0.125',
            'reference_type' => 'p110-owner-event-unrecorded',
            'reference_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $migration = require database_path('migrations/2026_11_18_130000_create_enterprise_inventory_owner_movement_events.php');
        DB::unprepared('DROP TRIGGER IF EXISTS p110_owner_event_after_movement_insert');
        try {
            $movementId = (int) DB::table('stock_movements')->insertGetId($movement);
            $this->assertDatabaseMissing('enterprise_inventory_owner_movement_events', [
                'source_stock_movement_id' => $movementId,
            ]);
        } finally {
            $migration->up();
        }
        $this->assertSame(1, DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->where('name', 'p110_owner_event_after_movement_insert')
            ->count());
        $this->assertDatabaseMissing('enterprise_inventory_owner_movement_events', [
            'source_stock_movement_id' => $movementId,
        ]);
        try {
            DB::table('stock_movements')->where('id', $movementId)->delete();
            $this->fail('A missing owner event cannot be replaced by the mutable demo prefix to authorize source deletion.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $verifier = app(InventoryLegalTitleAttestationVerifier::class);
        $guards = new \ReflectionMethod(InventoryLegalTitleAttestationVerifier::class, 'hasProtectedStockMovementHistory');
        $this->assertFalse($guards->invoke($verifier), 'An existing movement with no event remains uncertified even after its live store is renamed into the demo prefix and the trigger is restored.');
    }

    public function test_inventory_deletion_cannot_cascade_erase_legal_owner_claims(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('OWNER-HISTORY-PARENT-FK');
        $actor = $this->inventoryActor($stock['store_id']);
        $supplierId = $this->supplier($stock['store_id']);
        $now = now();

        DB::table('enterprise_inventory_owner_positions')->insert([
            [
                'inventory_id' => $stock['inventory_id'],
                'owner_key' => 'FOODEX',
                'owner_kind' => 'FOODEX',
                'supplier_id' => null,
                'quantity' => '6.000',
                'reserved_quantity' => '2.000',
                'proposed_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'inventory_id' => $stock['inventory_id'],
                'owner_key' => 'SUPPLIER:'.$supplierId,
                'owner_kind' => 'SUPPLIER',
                'supplier_id' => $supplierId,
                'quantity' => '4.000',
                'reserved_quantity' => '1.000',
                'proposed_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Assert the actual DDL rule, not an unrelated FK denial.
        if (DB::connection()->getDriverName() === 'sqlite') {
            $foreign = collect(DB::select('PRAGMA foreign_key_list("enterprise_inventory_owner_positions")'))
                ->first(static fn (object $row): bool => (string) $row->{'from'} === 'inventory_id'
                    && (string) $row->table === 'inventories');
            $this->assertNotNull($foreign);
            $this->assertContains(strtoupper((string) $foreign->on_delete), ['RESTRICT', 'NO ACTION']);
        }

        try {
            DB::table('inventories')->where('id', $stock['inventory_id'])->delete();
            $this->fail('Parent deletion must not erase FOODEX and supplier ownership claims.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $this->assertDatabaseHas('inventories', [
            'id' => $stock['inventory_id'],
            'quantity' => 10,
            'reserved_quantity' => 3,
        ]);
        $this->assertSame(2, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->count());
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'],
            'owner_key' => 'FOODEX',
            'quantity' => 6,
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'],
            'owner_key' => 'SUPPLIER:'.$supplierId,
            'quantity' => 4,
        ]);
    }

    public function test_new_inventory_without_ownership_writers_is_unclassified_not_free_foodex_stock(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('NEW');
        $actor = $this->inventoryActor($stock['store_id']);

        $snapshot = app(InventoryOwnerProvenanceReadService::class)->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNKNOWN', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
        $this->assertSame('UNKNOWN', $snapshot['positions'][0]['owner_kind']);
        $this->assertSame('10.000', $snapshot['positions'][0]['quantity']);
        $this->assertSame(0, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->count());
    }

    public function test_missing_owner_positions_never_mask_invalid_canonical_balances_as_merely_unknown(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('INVALID-CANONICAL-NO-OWNER');
        $actor = $this->inventoryActor($stock['store_id']);
        $service = app(InventoryOwnerProvenanceReadService::class);

        foreach ([
            ['quantity' => 10, 'reserved_quantity' => 11, 'label' => 'over-reserved'],
            ['quantity' => -1, 'reserved_quantity' => 0, 'label' => 'negative stock'],
            ['quantity' => 10, 'reserved_quantity' => -1, 'label' => 'negative reservation'],
        ] as $invalid) {
            DB::table('inventories')->where('id', $stock['inventory_id'])->update([
                'quantity' => $invalid['quantity'],
                'reserved_quantity' => $invalid['reserved_quantity'],
                'updated_at' => now()->addMinute(),
            ]);

            $result = $service->snapshot($actor, $stock['inventory_id']);
            $this->assertSame('STALE', $result['state'], $invalid['label']);
            $this->assertFalse($result['certified']);
            $this->assertSame('UNKNOWN', $result['positions'][0]['owner_kind']);
            $this->assertFalse($result['positions'][0]['certified']);
            $this->assertSame(0, DB::table('enterprise_inventory_owner_positions')
                ->where('inventory_id', $stock['inventory_id'])->count());
        }

        DB::table('inventories')->where('id', $stock['inventory_id'])->update([
            'quantity' => 10,
            'reserved_quantity' => 3,
            'updated_at' => now()->addMinutes(2),
        ]);
        $restored = $service->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNKNOWN', $restored['state']);
        $this->assertFalse($restored['certified']);
        $this->assertSame('10.000', $restored['quantity']);
        $this->assertSame('3.000', $restored['reserved_quantity']);
    }

    public function test_projection_rolls_back_invalid_canonical_stock_without_owner_reclassification(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('ATOMIC-BALANCE-GUARD');
        $projection = app(UnknownInventoryOwnerProjection::class);

        foreach ([
            ['quantity' => '10.000', 'reserved_quantity' => '10.001'],
            ['quantity' => '-0.001', 'reserved_quantity' => '0.000'],
            ['quantity' => '10.000', 'reserved_quantity' => '-0.001'],
        ] as $invalid) {
            try {
                DB::transaction(function () use ($stock, $projection, $invalid): void {
                    DB::table('inventories')->where('id', $stock['inventory_id'])->update($invalid);
                    $projection->afterUnattributedInventoryMutation($stock['inventory_id']);
                });
                $this->fail('Inconsistent canonical stock must roll back before any owner projection.');
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('reservation invariants', $exception->getMessage());
            }

            $this->assertDatabaseHas('inventories', [
                'id' => $stock['inventory_id'],
                'quantity' => 10,
                'reserved_quantity' => 3,
            ]);
            $this->assertSame(0, DB::table('enterprise_inventory_owner_positions')
                ->where('inventory_id', $stock['inventory_id'])->count());
        }

        $actor = $this->inventoryActor($stock['store_id']);
        $this->position($stock['inventory_id'], 'FOODEX', null, 10, 3, $actor->id, $actor->id, false);
        try {
            DB::transaction(function () use ($stock, $projection): void {
                DB::table('inventories')->where('id', $stock['inventory_id'])
                    ->update(['quantity' => 10, 'reserved_quantity' => 11]);
                $projection->afterUnattributedInventoryMutation($stock['inventory_id']);
            });
            $this->fail('Claimed legal title cannot bypass canonical stock balance checks.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('reservation invariants', $exception->getMessage());
        }

        $this->assertDatabaseHas('inventories', [
            'id' => $stock['inventory_id'],
            'quantity' => 10,
            'reserved_quantity' => 3,
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'],
            'owner_kind' => 'FOODEX',
            'quantity' => 10,
            'reserved_quantity' => 3,
        ]);
    }

    public function test_reservation_projection_rejects_over_reservation_inside_canonical_transaction(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('RESERVE-ATOMIC-GUARD');
        $projection = app(UnknownInventoryOwnerProjection::class);

        try {
            DB::transaction(function () use ($stock, $projection): void {
                $inventory = Inventory::query()
                    ->whereKey($stock['inventory_id'])->lockForUpdate()->firstOrFail();
                $inventory->forceFill(['reserved_quantity' => '10.001'])->save();
                $projection->afterReservationMutation($inventory);
            });
            $this->fail('Invalid reservation must fail before UNKNOWN owner upsert.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('reservation invariants', $exception->getMessage());
        }

        $this->assertDatabaseHas('inventories', [
            'id' => $stock['inventory_id'],
            'quantity' => 10,
            'reserved_quantity' => 3,
        ]);
        $this->assertSame(0, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->count());
    }

    public function test_self_approval_and_missing_provenance_cannot_certify_foodex_title(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('SOD');
        $actor = $this->inventoryActor($stock['store_id']);
        $checker = User::query()->create([
            'name' => 'Independent Stock Checker',
            'email' => 'checker-stock@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->position($stock['inventory_id'], 'FOODEX', null, 10, 3, $actor->id, $actor->id, false);

        $service = app(InventoryOwnerProvenanceReadService::class);
        $this->assertSame('UNVERIFIED', $service->snapshot($actor, $stock['inventory_id'])['state']);

        DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])
            ->update(['certified_by' => $checker->id]);
        $this->assertSame('UNVERIFIED', $service->snapshot($actor, $stock['inventory_id'])['state']);

        DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])
            ->update([
                'evidence_type' => 'goods_receipt_legal_title',
                'evidence_reference' => 'TEST-TITLE-001',
                'certified_at' => now(),
            ]);
        // A self-described document and a second user are NOT independent
        // verification of legal ownership. This must remain fail-closed.
        $untrusted = $service->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNVERIFIED', $untrusted['state']);
        $this->assertFalse($untrusted['certified']);
        $this->assertFalse($untrusted['positions'][0]['certified']);
    }

    public function test_supplier_title_cannot_be_certified_from_forged_evidence_and_distinct_checker_ids(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('FORGED-TITLE');
        $actor = $this->inventoryActor($stock['store_id']);
        $checker = User::query()->create([
            'name' => 'Separate But Unverified Checker',
            'email' => 'checker-unverified@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $supplierId = $this->supplier($stock['store_id']);

        // All database fields can look internally consistent while evidence
        // is nothing more than attacker-controlled free-text metadata.
        $this->position($stock['inventory_id'], 'SUPPLIER', $supplierId, 10, 3, $actor->id, $checker->id, true);
        $snapshot = app(InventoryOwnerProvenanceReadService::class)->snapshot($actor, $stock['inventory_id']);

        $this->assertSame('UNVERIFIED', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
        $this->assertSame('SUPPLIER', $snapshot['positions'][0]['owner_kind']);
        $this->assertSame($supplierId, $snapshot['positions'][0]['supplier_id']);
        $this->assertFalse($snapshot['positions'][0]['certified']);
        $this->assertSame('10.000', $snapshot['quantity']);
        $this->assertSame('3.000', $snapshot['reserved_quantity']);
    }

    public function test_mixed_supplier_and_foodex_positions_are_stale_after_any_unaccounted_stock_change(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('MIXED');
        $actor = $this->inventoryActor($stock['store_id']);
        $checker = User::query()->create([
            'name' => 'Independent Position Checker',
            'email' => 'checker-mixed@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $supplierId = $this->supplier($stock['store_id']);
        $this->position($stock['inventory_id'], 'FOODEX', null, 6, 2, $actor->id, $checker->id, true);
        $this->position($stock['inventory_id'], 'SUPPLIER', $supplierId, 4, 1, $actor->id, $checker->id, true);

        $service = app(InventoryOwnerProvenanceReadService::class);
        $snapshot = $service->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNVERIFIED', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
        $this->assertSame(2, count($snapshot['positions']));
        $this->assertSame($supplierId, $snapshot['positions'][1]['supplier_id']);
        $this->assertFalse($snapshot['positions'][0]['certified']);
        $this->assertFalse($snapshot['positions'][1]['certified']);

        DB::table('inventories')->where('id', $stock['inventory_id'])
            ->update(['quantity' => 11, 'updated_at' => now()->addMinute()]);
        $changed = $service->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('STALE', $changed['state']);
        $this->assertFalse($changed['certified']);
        $this->assertSame('11.000', $changed['quantity']);
        $this->assertFalse($changed['positions'][0]['certified']);
        $this->assertFalse($changed['positions'][1]['certified']);
    }

    public function test_owner_report_uses_scoped_localized_supplier_name_not_raw_foreign_id(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('SUPPLIER-DISPLAY');
        $actor = $this->inventoryActor($stock['store_id']);
        $supplierId = $this->supplier($stock['store_id']);
        DB::table('enterprise_suppliers')->where('id', $supplierId)->update([
            'name_ar' => 'المورد المعتمد في المتجر',
        ]);
        $this->position($stock['inventory_id'], 'SUPPLIER', $supplierId, 10, 3, $actor->id, $actor->id, false);

        $originalLocale = app()->getLocale();
        try {
            foreach (['en' => 'Verified Supplier Fixture', 'ar' => 'المورد المعتمد في المتجر'] as $locale => $expected) {
                app()->setLocale($locale);
                $snapshot = app(InventoryOwnerProvenanceReadService::class)
                    ->snapshot($actor, $stock['inventory_id']);
                $this->assertSame('UNVERIFIED', $snapshot['state']);
                $this->assertFalse($snapshot['certified']);
                $this->assertSame('SUPPLIER', $snapshot['positions'][0]['owner_kind']);
                $this->assertSame($supplierId, $snapshot['positions'][0]['supplier_id']);
                $this->assertSame($expected, $snapshot['positions'][0]['supplier_name']);
                $this->assertFalse($snapshot['positions'][0]['certified']);
            }
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_company_scoped_supplier_title_read_respects_ar_en_and_denies_revoked_or_foreign_scope(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('SHARED-SUPPLIER-OWNER');
        $actor = $this->inventoryActor($stock['store_id']);
        $supplierId = $this->supplier($stock['store_id']);
        DB::table('enterprise_suppliers')->where('id', $supplierId)->update([
            'scope_type' => 'company',
            'store_id' => null,
            'name_ar' => 'مورد الشركة المعتمد',
        ]);
        $this->position($stock['inventory_id'], 'SUPPLIER', $supplierId, 10, 3, $actor->id, $actor->id, false);
        $reader = app(InventoryOwnerProvenanceReadService::class);
        $previousLocale = app()->getLocale();
        try {
            foreach (['en' => 'Verified Supplier Fixture', 'ar' => 'مورد الشركة المعتمد'] as $locale => $expectedName) {
                app()->setLocale($locale);
                $snapshot = $reader->snapshot($actor, $stock['inventory_id']);
                $this->assertSame('UNVERIFIED', $snapshot['state']);
                $this->assertFalse($snapshot['certified']);
                $this->assertSame('SUPPLIER', $snapshot['positions'][0]['owner_kind']);
                $this->assertSame($supplierId, $snapshot['positions'][0]['supplier_id']);
                $this->assertSame($expectedName, $snapshot['positions'][0]['supplier_name']);
                $this->assertFalse($snapshot['positions'][0]['certified']);
            }
        } finally {
            app()->setLocale($previousLocale);
        }

        DB::table('enterprise_suppliers')->where('id', $supplierId)->update(['status' => 'inactive']);
        $revoked = $reader->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNKNOWN', $revoked['state']);
        $this->assertNull($revoked['positions'][0]['supplier_id']);
        $this->assertFalse($revoked['certified']);

        DB::table('enterprise_suppliers')->where('id', $supplierId)->update([
            'status' => 'active', 'scope_type' => 'store', 'store_id' => null,
        ]);
        $forgedScope = $reader->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNKNOWN', $forgedScope['state']);
        $this->assertNull($forgedScope['positions'][0]['supplier_name']);

        $company = DB::table('enterprise_companies')->first(['organization_id']);
        $foreignCompanyId = DB::table('enterprise_companies')->insertGetId([
            'organization_id' => $company->organization_id,
            'code' => 'P110-FOREIGN-SHARED-SUPPLIER',
            'name' => 'Foreign Shared Supplier Company',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('enterprise_suppliers')->where('id', $supplierId)->update([
            'scope_type' => 'company', 'store_id' => null, 'company_id' => $foreignCompanyId,
        ]);
        $foreignCompany = $reader->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNKNOWN', $foreignCompany['state']);
        $this->assertNull($foreignCompany['positions'][0]['supplier_id']);
        $this->assertNull($foreignCompany['positions'][0]['supplier_name']);
        $this->assertFalse($foreignCompany['certified']);
    }

    public function test_supplier_legal_owner_claim_cannot_cross_enterprise_company_with_same_store_id(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('CROSS-COMPANY-SUP');
        $actor = $this->inventoryActor($stock['store_id']);
        $supplierId = $this->supplier($stock['store_id']);
        $company = DB::table('enterprise_companies')->first(['id', 'organization_id']);
        $otherCompanyId = DB::table('enterprise_companies')->insertGetId([
            'organization_id' => $company->organization_id,
            'code' => 'P110-FOREIGN-COMPANY-SUPPLIER',
            'name' => 'Other Enterprise Company',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('enterprise_suppliers')->where('id', $supplierId)
            ->update(['company_id' => $otherCompanyId]);
        $this->position($stock['inventory_id'], 'SUPPLIER', $supplierId, 10, 3, $actor->id, $actor->id, false);

        $snapshot = app(InventoryOwnerProvenanceReadService::class)
            ->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNKNOWN', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
        $this->assertSame('UNKNOWN', $snapshot['positions'][0]['owner_kind']);
        $this->assertNull($snapshot['positions'][0]['supplier_id']);
        $this->assertNull($snapshot['positions'][0]['supplier_name']);
        $this->assertFalse($snapshot['positions'][0]['certified']);
    }

    public function test_foreign_store_supplier_cannot_be_certified_as_local_legal_title(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $own = $this->stock('LOCAL-SUP');
        $foreign = $this->stock('FOREIGN-SUP');
        $actor = $this->inventoryActor($own['store_id']);
        $checker = User::query()->create([
            'name' => 'Scoped Independent Checker',
            'email' => 'checker-scoped@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $foreignSupplierId = $this->supplier($foreign['store_id']);
        $this->position($own['inventory_id'], 'FOODEX', null, 6, 2, $actor->id, $checker->id, true);
        $this->position($own['inventory_id'], 'SUPPLIER', $foreignSupplierId, 4, 1, $actor->id, $checker->id, true);

        $snapshot = app(InventoryOwnerProvenanceReadService::class)->snapshot($actor, $own['inventory_id']);
        $this->assertSame('UNKNOWN', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
        $this->assertNull($snapshot['positions'][1]['supplier_id']);
        $this->assertFalse($snapshot['positions'][0]['certified']);
        $this->assertFalse($snapshot['positions'][1]['certified']);
    }

    public function test_unknown_projection_tracks_canonical_stock_without_claiming_legal_title(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('UNKNOWN-RECEIPT');
        $inventory = Inventory::query()->findOrFail($stock['inventory_id']);

        DB::transaction(function () use ($inventory): void {
            $inventory->forceFill(['quantity' => 12])->save();
            app(UnknownInventoryOwnerProjection::class)->afterProcurementMutation($inventory);
        });

        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'],
            'owner_key' => 'UNKNOWN',
            'owner_kind' => 'UNKNOWN',
            'supplier_id' => null,
            'quantity' => 12,
            'reserved_quantity' => 3,
            'certified_by' => null,
        ]);

        DB::transaction(function () use ($inventory): void {
            $inventory->forceFill(['quantity' => 9])->save();
            app(UnknownInventoryOwnerProjection::class)->afterProcurementMutation($inventory);
        });

        $this->assertSame(1, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->count());
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'],
            'owner_key' => 'UNKNOWN',
            'quantity' => 9,
            'reserved_quantity' => 3,
        ]);
        $snapshot = app(InventoryOwnerProvenanceReadService::class)
            ->snapshot($this->inventoryActor($stock['store_id']), $stock['inventory_id']);
        $this->assertSame('UNKNOWN', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
        $this->assertSame('9.000', $snapshot['quantity']);
        $this->assertSame('3.000', $snapshot['reserved_quantity']);
    }

    public function test_unknown_projection_does_not_rewrite_known_owner_assertions_or_hide_stale_stock(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('MIXED-PRESERVED');
        $actor = $this->inventoryActor($stock['store_id']);
        $checker = User::query()->create([
            'name' => 'Separate Title Checker',
            'email' => 'p110-mixed-checker@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->position($stock['inventory_id'], 'FOODEX', null, 10, 3, $actor->id, $checker->id, true);
        $inventory = Inventory::query()->findOrFail($stock['inventory_id']);

        DB::transaction(function () use ($inventory): void {
            $inventory->forceFill(['quantity' => 11])->save();
            app(UnknownInventoryOwnerProjection::class)->afterProcurementMutation($inventory);
        });

        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'],
            'owner_key' => 'FOODEX',
            'quantity' => 10,
            'reserved_quantity' => 3,
        ]);
        $this->assertSame(0, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])
            ->where('owner_key', 'UNKNOWN')->count());
        $snapshot = app(InventoryOwnerProvenanceReadService::class)
            ->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('STALE', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
    }

    public function test_unknown_projection_is_rolled_back_with_failed_canonical_stock_mutation(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('ROLLBACK-PROJECTION');
        $inventory = Inventory::query()->findOrFail($stock['inventory_id']);

        try {
            DB::transaction(function () use ($inventory): void {
                $inventory->forceFill(['quantity' => 15])->save();
                app(UnknownInventoryOwnerProjection::class)->afterProcurementMutation($inventory);
                throw new \RuntimeException('simulated movement failure');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated movement failure', $exception->getMessage());
        }
        // If no exception occurs, the committed stock/position assertions
        // below still fail. A real rollback must preserve both together.

        $this->assertSame(10.0, (float) DB::table('inventories')
            ->where('id', $stock['inventory_id'])->value('quantity'));
        $this->assertSame(0, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->count());
    }

    public function test_wms_dispatch_and_receive_keep_unattributed_positions_current_without_stock_double_posting(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('TRANSFER-UNKNOWN');
        $target = $this->transferTarget($stock, 'TRANSFER-UNKNOWN');
        $actor = $this->inventoryActor($stock['store_id']);
        $service = app(WarehouseTransferService::class);

        $transfer = $service->create(
            $actor,
            $target['source_warehouse_id'],
            $target['target_warehouse_id'],
            [['product_id' => $target['product_id'], 'quantity' => 2]],
            'p110-unknown-transfer',
        );

        $service->dispatch($actor, $transfer->id);
        $service->dispatch($actor, $transfer->id);
        $this->assertDatabaseHas('inventories', [
            'id' => $stock['inventory_id'],
            'quantity' => 8,
            'reserved_quantity' => 3,
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'],
            'owner_key' => 'UNKNOWN',
            'owner_kind' => 'UNKNOWN',
            'quantity' => 8,
            'reserved_quantity' => 3,
        ]);

        $service->receive($actor, $transfer->id);
        $service->receive($actor, $transfer->id);
        $this->assertDatabaseHas('inventories', [
            'id' => $target['target_inventory_id'],
            'quantity' => 3,
            'reserved_quantity' => 0,
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $target['target_inventory_id'],
            'owner_key' => 'UNKNOWN',
            'owner_kind' => 'UNKNOWN',
            'supplier_id' => null,
            'quantity' => 3,
            'reserved_quantity' => 0,
        ]);

        $this->assertSame(2, DB::table('enterprise_inventory_owner_positions')
            ->whereIn('inventory_id', [$stock['inventory_id'], $target['target_inventory_id']])
            ->count());
        $this->assertSame(2, DB::table('stock_movements')
            ->where('reference_type', 'enterprise_warehouse_transfer')
            ->where('reference_id', $transfer->id)
            ->count());

        $read = app(InventoryOwnerProvenanceReadService::class);
        foreach ([$stock['inventory_id'], $target['target_inventory_id']] as $inventoryId) {
            $snapshot = $read->snapshot($actor, $inventoryId);
            $this->assertSame('UNKNOWN', $snapshot['state']);
            $this->assertFalse($snapshot['certified']);
            $this->assertSame('UNKNOWN', $snapshot['positions'][0]['owner_kind']);
        }
    }

    public function test_wms_transfer_never_silently_converts_unverified_foodex_title_to_unknown_or_certified(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('TRANSFER-KNOWN');
        $target = $this->transferTarget($stock, 'TRANSFER-KNOWN');
        $actor = $this->inventoryActor($stock['store_id']);
        $checker = User::query()->create([
            'name' => 'WMS Distinct Checker',
            'email' => 'p110-wms-distinct-checker@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->position($stock['inventory_id'], 'FOODEX', null, 10, 3, $actor->id, $checker->id, true);
        $service = app(WarehouseTransferService::class);
        $transfer = $service->create(
            $actor,
            $target['source_warehouse_id'],
            $target['target_warehouse_id'],
            [['product_id' => $target['product_id'], 'quantity' => 2]],
            'p110-unverified-transfer',
        );

        $service->dispatch($actor, $transfer->id);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'],
            'owner_key' => 'FOODEX',
            'quantity' => 10,
            'reserved_quantity' => 3,
        ]);
        $this->assertSame(0, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])
            ->where('owner_key', 'UNKNOWN')->count());

        $read = app(InventoryOwnerProvenanceReadService::class);
        $source = $read->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('STALE', $source['state']);
        $this->assertFalse($source['certified']);

        $service->receive($actor, $transfer->id);
        $destination = $read->snapshot($actor, $target['target_inventory_id']);
        $this->assertSame('UNKNOWN', $destination['state']);
        $this->assertFalse($destination['certified']);
        $this->assertSame('UNKNOWN', $destination['positions'][0]['owner_kind']);
        $this->assertDatabaseHas('inventories', ['id' => $stock['inventory_id'], 'quantity' => 8]);
        $this->assertDatabaseHas('inventories', ['id' => $target['target_inventory_id'], 'quantity' => 3]);
    }

    public function test_wms_adjustment_posts_current_unknown_title_once_and_replay_has_no_second_stock_mutation(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('ADJUST-UNKNOWN');
        $actor = $this->inventoryActor($stock['store_id']);
        $inventory = DB::table('inventories')->where('id', $stock['inventory_id'])
            ->first(['warehouse_id', 'product_id']);
        $service = app(InventoryAdjustmentService::class);

        $movement = $service->post(
            $actor, (int) $inventory->warehouse_id, (int) $inventory->product_id,
            2.0, 'correction', 'p110_unknown_adjustment', 1102844,
            'Verified stock correction, title still unknown',
        );
        $replay = $service->post(
            $actor, (int) $inventory->warehouse_id, (int) $inventory->product_id,
            2.0, 'correction', 'p110_unknown_adjustment', 1102844,
            'Verified stock correction, title still unknown',
        );

        $this->assertSame($movement->getKey(), $replay->getKey());

        // A reused idempotency reference with different economic facts must
        // fail inside the locked canonical transaction, without double-post.
        try {
            $service->post(
                $actor, (int) $inventory->warehouse_id, (int) $inventory->product_id,
                3.0, 'correction', 'p110_unknown_adjustment', 1102844,
                'Verified stock correction, title still unknown',
            );
            $this->fail('Changed quantity cannot replay an already-posted stock movement.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reference', $exception->errors());
        }
        $this->assertSame(1, DB::table('stock_movements')
            ->where('inventory_id', $stock['inventory_id'])
            ->where('reference_type', 'p110_unknown_adjustment')
            ->where('reference_id', 1102844)->count());
        $this->assertDatabaseHas('inventories', [
            'id' => $stock['inventory_id'], 'quantity' => 12, 'reserved_quantity' => 3,
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'], 'owner_key' => 'UNKNOWN',
            'quantity' => 12, 'reserved_quantity' => 3, 'certified_by' => null,
        ]);
        $this->assertSame(1, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->count());
        $snapshot = app(InventoryOwnerProvenanceReadService::class)
            ->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNKNOWN', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
    }

    public function test_wms_cycle_count_reconciles_unknown_title_in_same_inventory_transaction(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('COUNT-UNKNOWN');
        $actor = $this->inventoryActor($stock['store_id']);
        $warehouseId = (int) DB::table('inventories')->where('id', $stock['inventory_id'])
            ->value('warehouse_id');
        $service = app(InventoryCountService::class);

        $count = $service->start($actor, $warehouseId, [$stock['inventory_id']], 'p110-count-unknown-title');
        $service->complete($actor, $count->getKey(), [$stock['inventory_id'] => 12]);
        $service->complete($actor, $count->getKey(), [$stock['inventory_id'] => 12]);

        $this->assertDatabaseHas('inventories', [
            'id' => $stock['inventory_id'], 'quantity' => 12, 'reserved_quantity' => 3,
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'], 'owner_key' => 'UNKNOWN',
            'quantity' => 12, 'reserved_quantity' => 3, 'certified_at' => null,
        ]);
        $this->assertSame(1, DB::table('stock_movements')
            ->where('reference_type', 'enterprise_inventory_count')
            ->where('reference_id', $count->getKey())->count());
        $snapshot = app(InventoryOwnerProvenanceReadService::class)
            ->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNKNOWN', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
    }

    public function test_wms_adjustment_never_reclassifies_an_unverified_owner_position(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('ADJUST-PRESERVE');
        $actor = $this->inventoryActor($stock['store_id']);
        $checker = User::query()->create([
            'name' => 'Stock Title Checker',
            'email' => 'p110-adjust-title-checker@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->position($stock['inventory_id'], 'FOODEX', null, 10, 3, $actor->id, $checker->id, true);
        $inventory = DB::table('inventories')->where('id', $stock['inventory_id'])
            ->first(['warehouse_id', 'product_id']);
        app(InventoryAdjustmentService::class)->post(
            $actor, (int) $inventory->warehouse_id, (int) $inventory->product_id,
            -1.0, 'loss', 'p110_preserve_title', 1102845,
            'Unverified title must not be reallocated',
        );

        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'], 'owner_key' => 'FOODEX',
            'quantity' => 10, 'reserved_quantity' => 3,
        ]);
        $this->assertSame(0, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->where('owner_key', 'UNKNOWN')->count());
        $snapshot = app(InventoryOwnerProvenanceReadService::class)
            ->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('STALE', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
    }

    public function test_wms_reservation_and_release_reconcile_unknown_title_and_replays_do_not_double_post(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('RESERVE-UNKNOWN');
        $actor = $this->inventoryActor($stock['store_id']);
        $inventory = DB::table('inventories')->where('id', $stock['inventory_id'])
            ->first(['warehouse_id', 'product_id']);
        $service = app(StockReservationService::class);

        $this->assertSame(2.0, $service->ensureReservation(
            $actor, (int) $inventory->warehouse_id, (int) $inventory->product_id,
            2.0, 'p110_owner_reservation', 1102846,
        ));
        $this->assertSame(2.0, $service->ensureReservation(
            $actor, (int) $inventory->warehouse_id, (int) $inventory->product_id,
            2.0, 'p110_owner_reservation', 1102846,
        ));

        $this->assertDatabaseHas('inventories', [
            'id' => $stock['inventory_id'], 'quantity' => 10, 'reserved_quantity' => 5,
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'], 'owner_key' => 'UNKNOWN',
            'quantity' => 10, 'reserved_quantity' => 5, 'certified_by' => null,
        ]);
        $this->assertSame(1, DB::table('stock_movements')
            ->where('reference_type', 'p110_owner_reservation')->where('reference_id', 1102846)
            ->where('type', 'reserve')->count());

        $this->assertSame(0.0, $service->releaseReservation(
            $actor, (int) $inventory->warehouse_id, (int) $inventory->product_id,
            'p110_owner_reservation', 1102846,
        ));
        $this->assertSame(0.0, $service->releaseReservation(
            $actor, (int) $inventory->warehouse_id, (int) $inventory->product_id,
            'p110_owner_reservation', 1102846,
        ));

        $this->assertDatabaseHas('inventories', [
            'id' => $stock['inventory_id'], 'quantity' => 10, 'reserved_quantity' => 3,
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'], 'owner_key' => 'UNKNOWN',
            'quantity' => 10, 'reserved_quantity' => 3, 'certified_at' => null,
        ]);
        $this->assertSame(1, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->count());
        $this->assertSame(2, DB::table('stock_movements')
            ->where('reference_type', 'p110_owner_reservation')->where('reference_id', 1102846)
            ->count());
        $snapshot = app(InventoryOwnerProvenanceReadService::class)
            ->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNKNOWN', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
    }

    public function test_wms_reservation_never_reclassifies_a_claimed_foodex_title(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('RESERVE-PRESERVE');
        $actor = $this->inventoryActor($stock['store_id']);
        $checker = User::query()->create([
            'name' => 'Reservation Title Checker',
            'email' => 'p110-reservation-title-checker@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->position($stock['inventory_id'], 'FOODEX', null, 10, 3, $actor->id, $checker->id, true);
        $inventory = DB::table('inventories')->where('id', $stock['inventory_id'])
            ->first(['warehouse_id', 'product_id']);

        app(StockReservationService::class)->ensureReservation(
            $actor, (int) $inventory->warehouse_id, (int) $inventory->product_id,
            1.0, 'p110_unverified_owner_reservation', 1102847,
        );

        $this->assertDatabaseHas('inventories', [
            'id' => $stock['inventory_id'], 'quantity' => 10, 'reserved_quantity' => 4,
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'], 'owner_key' => 'FOODEX',
            'quantity' => 10, 'reserved_quantity' => 3,
        ]);
        $this->assertSame(0, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->where('owner_key', 'UNKNOWN')->count());
        $snapshot = app(InventoryOwnerProvenanceReadService::class)
            ->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('STALE', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
    }

    public function test_wms_reservation_does_not_refresh_unknown_when_mixed_legal_title_is_present(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('RESERVE-MIXED');
        $actor = $this->inventoryActor($stock['store_id']);
        $checker = User::query()->create([
            'name' => 'Mixed Title Checker',
            'email' => 'p110-reservation-mixed-title-checker@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->position($stock['inventory_id'], 'UNKNOWN', null, 6, 2, $actor->id, $checker->id, false);
        $this->position($stock['inventory_id'], 'FOODEX', null, 4, 1, $actor->id, $checker->id, true);
        $inventory = DB::table('inventories')->where('id', $stock['inventory_id'])
            ->first(['warehouse_id', 'product_id']);

        app(StockReservationService::class)->ensureReservation(
            $actor, (int) $inventory->warehouse_id, (int) $inventory->product_id,
            2.0, 'p110_mixed_title_reservation', 1102848,
        );

        $this->assertDatabaseHas('inventories', [
            'id' => $stock['inventory_id'], 'quantity' => 10, 'reserved_quantity' => 5,
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'], 'owner_key' => 'UNKNOWN',
            'quantity' => 6, 'reserved_quantity' => 2,
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'], 'owner_key' => 'FOODEX',
            'quantity' => 4, 'reserved_quantity' => 1,
        ]);
        $this->assertSame(2, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->count());
        $snapshot = app(InventoryOwnerProvenanceReadService::class)
            ->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('STALE', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
    }

    public function test_other_store_inventory_is_never_exposed_through_owner_read_service(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $own = $this->stock('OWN');
        $foreign = $this->stock('FOREIGN');
        $actor = $this->inventoryActor($own['store_id']);
        $this->expectException(HttpException::class);
        app(InventoryOwnerProvenanceReadService::class)->snapshot($actor, $foreign['inventory_id']);
    }

    public function test_actual_inventory_rest_api_denies_foreign_store_forged_title_and_stale_balance_retry(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $home = $this->stock('REST-OWNER-HOME');
        $foreign = $this->stock('REST-OWNER-FOREIGN');
        $actor = $this->inventoryActor($home['store_id']);

        Sanctum::actingAs($actor);
        $url = '/api/v1/admin/inventory/'.$home['inventory_id'].'/adjust';
        $request = [
            'quantity_delta' => -1,
            'reason' => 'Direct P110 stock API must not create verified title',
            'expected_quantity' => 10,
            'expected_reserved_quantity' => 3,
            // These fields are not legal proof and are deliberately outside
            // the existing inventory API's validated stock contract.
            'owner_kind' => 'FOODEX',
            'owner_key' => 'FOODEX',
            'supplier_id' => $this->supplier($home['store_id']),
            'certified_by' => $actor->id,
            'legal_title_verified' => true,
            'stock_cost' => '1000000.00',
        ];

        $this->postJson($url, $request)
            ->assertOk()
            ->assertJsonPath('data.quantity', 9);

        $this->assertDatabaseHas('inventories', [
            'id' => $home['inventory_id'], 'quantity' => 9, 'reserved_quantity' => 3,
        ]);
        $this->assertSame(0, DB::table('enterprise_inventory_title_attestations')->count());
        $this->assertSame(0, DB::table('enterprise_finance_journals')->count());
        $this->assertSame('UNKNOWN', app(InventoryOwnerProvenanceReadService::class)
            ->snapshot($actor, $home['inventory_id'])['state']);

        // Optimistic stale-state failure must roll back before either
        // a second physical movement or fabricated title can be recorded.
        $this->postJson($url, $request)->assertUnprocessable();
        $this->postJson('/api/v1/admin/inventory/'.$foreign['inventory_id'].'/adjust', [
            ...$request,
            'expected_quantity' => 10,
        ])->assertNotFound();

        $this->assertDatabaseHas('inventories', [
            'id' => $home['inventory_id'], 'quantity' => 9, 'reserved_quantity' => 3,
        ]);
        $this->assertDatabaseHas('inventories', [
            'id' => $foreign['inventory_id'], 'quantity' => 10, 'reserved_quantity' => 3,
        ]);
        $this->assertSame(1, DB::table('stock_movements')
            ->where('inventory_id', $home['inventory_id'])
            ->where('reference_type', 'inventory_adjustment')->count());
        $this->assertSame(0, DB::table('enterprise_inventory_title_attestations')->count());
        $this->assertSame(0, DB::table('enterprise_finance_journals')->count());
    }

    public function test_inventory_only_actor_cannot_direct_post_finance_journal_with_forged_stock_cost(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('FINANCE-FORGED-OWNER-COST');
        $actor = $this->inventoryActor($stock['store_id']);
        config()->set('security.mfa.enforce_privileged', false);

        // This route is deliberately invoked directly: the actor has an
        // inventory role, not a Finance posting or GL approval permission.
        $this->actingAs($actor)->post(route('admin.erp.finance.journals.store'), [
            'store_id' => $stock['store_id'],
            'idempotency_key' => (string) Str::uuid(),
            'entry_date' => now()->toDateString(),
            'description' => 'Forged supplier-owned stock cost posting',
            'lines' => [
                ['account_id' => 1, 'debit' => '999999.00', 'credit' => '0.00'],
            ],
        ])->assertForbidden();

        $this->assertSame(0, DB::table('enterprise_finance_journals')->count());
    }

    public function test_owner_rule_configuration_requires_independent_review_and_never_certifies_stock(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('POLICY-RULE');
        $unitId = DB::table('enterprise_business_units')->value('id');
        DB::table('enterprise_store_scopes')->insert([
            'store_id' => $stock['store_id'],
            'business_unit_id' => $unitId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $role = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $maker = User::query()->create([
            'name' => 'Ownership Policy Maker',
            'email' => 'p110-policy-maker@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $checker = User::query()->create([
            'name' => 'Independent Ownership Policy Checker',
            'email' => 'p110-policy-checker@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        foreach ([$maker, $checker] as $user) {
            DB::table('role_user')->insert(['user_id' => $user->id, 'role_id' => $role->id]);
        }

        $service = app(InventoryOwnershipRuleService::class);
        $request = [
            'store_id' => $stock['store_id'],
            'channel' => 'B2C',
            'warehouse_id' => DB::table('inventories')->where('id', $stock['inventory_id'])->value('warehouse_id'),
            'initial_owner_kind' => 'FOODEX',
            'title_transfer_trigger' => 'CONTRACT_EVENT',
            'required_evidence_classes' => ['SIGNED_CONTRACT', 'TITLE_ADDENDUM'],
            'checker_permission' => 'inventory_ownership_rules.review',
            'effective_at' => now()->addDays(2)->startOfDay()->toDateTimeString(),
            'request_uuid' => (string) Str::uuid(),
        ];
        $draft = $service->propose($maker, $request);
        $this->assertSame('DRAFT', $draft->status);
        $this->assertSame($draft->id, $service->propose($maker, $request)->id);
        $this->assertSame(1, DB::table('enterprise_inventory_ownership_rule_revisions')->count());

        try {
            $service->review($maker, (int) $draft->id, true);
            $this->fail('A maker must not review their own rule, even as Super Admin.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // A company/store may keep the same numeric identity while a
        // policy's stored channel becomes stale. The independent reviewer
        // must not approve this as a live B2B ownership rule for a B2C store.
        DB::table('enterprise_inventory_ownership_rule_revisions')
            ->where('id', $draft->id)->update(['channel' => 'B2B']);
        try {
            $service->review($checker, (int) $draft->id, true);
            $this->fail('A mismatched channel cannot receive legal-title policy approval.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertDatabaseHas('enterprise_inventory_ownership_rule_revisions', [
            'id' => $draft->id, 'status' => 'DRAFT', 'reviewed_by' => null,
        ]);
        DB::table('enterprise_inventory_ownership_rule_revisions')
            ->where('id', $draft->id)->update(['channel' => 'B2C']);

        $approved = $service->review($checker, (int) $draft->id, true);
        $this->assertSame('APPROVED_RULE', $approved->status);
        $this->assertSame($approved->id, $service->review($checker, (int) $draft->id, true)->id);
        $this->travelTo(now()->addDays(3));
        $replayCount = DB::table('enterprise_inventory_ownership_rule_revisions')->count();
        $replayAuditCount = DB::table('audit_logs')->count();
        $expiredReplay = $service->propose($maker, $request);
        $this->assertSame($approved->id, $expiredReplay->id);
        $this->assertSame($replayCount, DB::table('enterprise_inventory_ownership_rule_revisions')->count());
        $this->assertSame($replayAuditCount, DB::table('audit_logs')->count());
        $advisory = $service->advisoryFor($checker, $stock['store_id'], 'B2C', null, (int) $request['warehouse_id']);
        $this->assertSame('UNKNOWN', $advisory['state']);
        $this->assertFalse($advisory['certified']);
        $this->assertSame($approved->id, $advisory['policy']->id);

        // A proposed immutable SHA must bind the same exact trigger and
        // evidence policy later reviewed. Direct-row drift cannot become
        // an approved operational policy, and its request UUID cannot be
        // replayed to hide the tamper.
        DB::table('enterprise_inventory_ownership_rule_revisions')
            ->where('id', $approved->id)->update(['title_transfer_trigger' => 'NONE']);
        $tampered = $service->advisoryFor(
            $checker, $stock['store_id'], 'B2C', null, (int) $request['warehouse_id'],
        );
        $this->assertSame('UNKNOWN', $tampered['state']);
        $this->assertFalse($tampered['certified']);
        $this->assertNull($tampered['policy']);
        try {
            $service->review($checker, (int) $approved->id, true);
            $this->fail('Tampered approved policy may not be replayed as approved.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $beforeTamperedReplay = (array) DB::table('enterprise_inventory_ownership_rule_revisions')
            ->where('id', $approved->id)->first();
        $tamperedReplayCount = DB::table('enterprise_inventory_ownership_rule_revisions')->count();
        $tamperedReplayAuditCount = DB::table('audit_logs')->count();
        try {
            $service->propose($maker, $request);
            $this->fail('Request UUID replay must not return a modified revision.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request_uuid', $exception->errors());
        }
        $this->assertSame($beforeTamperedReplay, (array) DB::table('enterprise_inventory_ownership_rule_revisions')
            ->where('id', $approved->id)->first());
        $this->assertSame($tamperedReplayCount, DB::table('enterprise_inventory_ownership_rule_revisions')->count());
        $this->assertSame($tamperedReplayAuditCount, DB::table('audit_logs')->count());
        DB::table('enterprise_inventory_ownership_rule_revisions')
            ->where('id', $approved->id)->update(['title_transfer_trigger' => 'CONTRACT_EVENT']);
        $restored = $service->advisoryFor(
            $checker, $stock['store_id'], 'B2C', null, (int) $request['warehouse_id'],
        );
        $this->assertSame($approved->id, $restored['policy']->id);
        $this->assertFalse($restored['certified']);

        // Even a legitimately approved generic FOODEX policy cannot be used
        // to paper over a caller-supplied foreign store's supplier identity.
        $otherStore = $this->stock('FOREIGN-ADVISORY-SUPPLIER');
        $foreignSupplierId = $this->supplier($otherStore['store_id']);
        try {
            $service->advisoryFor(
                $checker, $stock['store_id'], 'B2C', $foreignSupplierId, (int) $request['warehouse_id'],
            );
            $this->fail('A generic rule is not authority to use a foreign supplier context.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        // An ownership policy for a different sales channel cannot be
        // presented as title guidance for the current warehouse/store.
        try {
            $service->advisoryFor(
                $checker, $stock['store_id'], 'B2B', null, (int) $request['warehouse_id'],
            );
            $this->fail('A B2C store must reject a B2B legal-title policy query.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertSame('UNKNOWN', app(InventoryOwnerProvenanceReadService::class)
            ->snapshot($this->inventoryActor($stock['store_id']), $stock['inventory_id'])['state']);
        $this->travelBack();

        $conflictingRequest = $request;
        $conflictingRequest['request_uuid'] = (string) Str::uuid();
        $duplicateScope = $service->propose($maker, $conflictingRequest);
        try {
            $service->review($checker, (int) $duplicateScope->id, true);
            $this->fail('Overlapping approved policy identities must not coexist.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $migration = require database_path('migrations/2026_11_16_030000_create_enterprise_inventory_ownership_rule_revisions.php');
        try {
            $migration->down();
            $this->fail('Immutable policy/review history must not disappear on rollback.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('preserved', $e->getMessage());
        }
        $this->assertDatabaseHas('enterprise_inventory_ownership_rule_revisions', [
            'id' => $draft->id,
            'status' => 'APPROVED_RULE',
            'reviewed_by' => $checker->id,
        ]);
    }

    public function test_owner_rule_configuration_denies_wrong_store_supplier_and_retroactivity(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $home = $this->stock('POLICY-HOME');
        $foreign = $this->stock('POLICY-FOREIGN');
        DB::table('enterprise_store_scopes')->insert([
            'store_id' => $home['store_id'],
            'business_unit_id' => DB::table('enterprise_business_units')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor = User::query()->create([
            'name' => 'Ownership Rule Admin',
            'email' => 'p110-ownership-admin@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        DB::table('role_user')->insert(['user_id' => $actor->id, 'role_id' => $role->id]);
        $service = app(InventoryOwnershipRuleService::class);
        $request = [
            'store_id' => $home['store_id'],
            'channel' => 'B2C',
            'warehouse_id' => DB::table('inventories')->where('id', $foreign['inventory_id'])->value('warehouse_id'),
            'initial_owner_kind' => 'UNKNOWN',
            'title_transfer_trigger' => 'NONE',
            'required_evidence_classes' => ['SIGNED_CONTRACT'],
            'checker_permission' => 'inventory_ownership_rules.review',
            'effective_at' => now()->addDay()->toDateTimeString(),
            'request_uuid' => (string) Str::uuid(),
        ];
        try {
            $service->propose($actor, $request);
            $this->fail('Cross-store warehouse policy must fail closed.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $request['warehouse_id'] = null;
        $request['checker_permission'] = 'inventory.view';
        try {
            $service->propose($actor, $request);
            $this->fail('A maker must not downgrade independent checker permission.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('checker_permission', $e->errors());
        }
        $request['checker_permission'] = 'inventory_ownership_rules.review';
        $request['initial_owner_kind'] = 'SUPPLIER';
        $request['supplier_id'] = $this->supplier($foreign['store_id']);
        try {
            $service->propose($actor, $request);
            $this->fail('A foreign-store supplier cannot be scoped into this store.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        unset($request['supplier_id']);
        $request['initial_owner_kind'] = 'UNKNOWN';
        $request['effective_at'] = now()->subDay()->toDateTimeString();
        try {
            $service->propose($actor, $request);
            $this->fail('Retroactive policy must fail closed.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('effective_at', $e->errors());
        }
        $this->assertSame(0, DB::table('enterprise_inventory_ownership_rule_revisions')->count());
    }

    public function test_owner_rules_accept_only_canonical_company_or_store_supplier_scopes(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('P110-POLICY-SUPPLIER-SCOPE');
        DB::table('enterprise_store_scopes')->insert([
            'store_id' => $stock['store_id'],
            'business_unit_id' => DB::table('enterprise_business_units')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $maker = User::query()->create([
            'name' => 'Title Rule Maker',
            'email' => 'p110-scoped-maker@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        DB::table('role_user')->insert(['user_id' => $maker->id, 'role_id' => $role->id]);
        $supplierId = $this->supplier($stock['store_id']);
        $input = [
            'store_id' => $stock['store_id'],
            'channel' => 'B2C',
            'initial_owner_kind' => 'SUPPLIER',
            'supplier_id' => $supplierId,
            'title_transfer_trigger' => 'CONTRACT_EVENT',
            'required_evidence_classes' => ['SIGNED_CONTRACT'],
            'checker_permission' => 'inventory_ownership_rules.review',
            'effective_at' => now()->addDay()->toDateTimeString(),
            'request_uuid' => (string) Str::uuid(),
        ];
        $service = app(InventoryOwnershipRuleService::class);
        DB::table('enterprise_suppliers')->where('id', $supplierId)->update([
            'scope_type' => 'store', 'store_id' => null,
        ]);
        try {
            $service->propose($maker, $input);
            $this->fail('Malformed store-scoped supplier must not masquerade as a company supplier.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        DB::table('enterprise_suppliers')->where('id', $supplierId)->update([
            'scope_type' => 'company', 'store_id' => null,
        ]);
        $rule = $service->propose($maker, $input);
        $this->assertSame('DRAFT', $rule->status);
        $this->assertSame($supplierId, (int) $rule->supplier_id);
        $this->assertSame(1, DB::table('enterprise_inventory_ownership_rule_revisions')->count());
    }

    public function test_owner_rule_review_and_advisory_fail_closed_after_reference_revocation(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('POLICY-REVOCATION');
        $foreign = $this->stock('POLICY-FOREIGN-WAREHOUSE');
        DB::table('enterprise_store_scopes')->insert([
            'store_id' => $stock['store_id'],
            'business_unit_id' => DB::table('enterprise_business_units')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $role = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $maker = User::query()->create([
            'name' => 'Revocation Policy Maker',
            'email' => 'p110-revocation-maker@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $checker = User::query()->create([
            'name' => 'Revocation Policy Checker',
            'email' => 'p110-revocation-checker@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        foreach ([$maker, $checker] as $user) {
            DB::table('role_user')->insert(['user_id' => $user->id, 'role_id' => $role->id]);
        }

        $supplierId = $this->supplier($stock['store_id']);
        $warehouseId = (int) DB::table('inventories')
            ->where('id', $stock['inventory_id'])->value('warehouse_id');
        $foreignWarehouseId = (int) DB::table('inventories')
            ->where('id', $foreign['inventory_id'])->value('warehouse_id');
        $service = app(InventoryOwnershipRuleService::class);
        $documentId = (int) DB::table('enterprise_supplier_documents')->insertGetId([
            'supplier_id' => $supplierId,
            'category' => 'CONSIGNMENT_AGREEMENT',
            'title' => 'Supplier policy reference (not a signed title certificate)',
            'reference' => 'P110-REVOCATION-EVIDENCE',
            'expires_at' => now()->addDays(10)->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $draft = $service->propose($maker, [
            'store_id' => $stock['store_id'],
            'channel' => 'B2C',
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'supplier_document_id' => $documentId,
            'initial_owner_kind' => 'SUPPLIER',
            'title_transfer_trigger' => 'CONTRACT_EVENT',
            'required_evidence_classes' => ['CONSIGNMENT_AGREEMENT'],
            'checker_permission' => 'inventory_ownership_rules.review',
            'effective_at' => now()->addDays(2)->startOfDay()->toDateTimeString(),
            'request_uuid' => (string) Str::uuid(),
        ]);

        // A proposal validated yesterday is not evidence that its supplier
        // or warehouse is still authorized at independent review time.
        DB::table('enterprise_suppliers')->where('id', $supplierId)
            ->update(['status' => 'inactive']);
        try {
            $service->review($checker, (int) $draft->id, true);
            $this->fail('A revoked supplier must not receive policy approval.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseHas('enterprise_inventory_ownership_rule_revisions', [
            'id' => $draft->id, 'status' => 'DRAFT', 'reviewed_by' => null,
        ]);

        DB::table('enterprise_suppliers')->where('id', $supplierId)
            ->update(['status' => 'active']);
        DB::table('warehouses')->where('id', $warehouseId)
            ->update(['is_active' => false]);
        try {
            $service->review($checker, (int) $draft->id, true);
            $this->fail('An inactive warehouse must not receive policy approval.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseHas('enterprise_inventory_ownership_rule_revisions', [
            'id' => $draft->id, 'status' => 'DRAFT', 'reviewed_by' => null,
        ]);

        DB::table('warehouses')->where('id', $warehouseId)
            ->update(['is_active' => true]);
        DB::table('enterprise_supplier_documents')->where('id', $documentId)
            ->update(['expires_at' => now()->subDay()->toDateString()]);
        try {
            $service->review($checker, (int) $draft->id, true);
            $this->fail('An expired supplier document must not authorize policy approval.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseHas('enterprise_inventory_ownership_rule_revisions', [
            'id' => $draft->id, 'status' => 'DRAFT', 'reviewed_by' => null,
        ]);

        DB::table('enterprise_supplier_documents')->where('id', $documentId)
            ->update(['expires_at' => now()->addDays(10)->toDateString()]);
        $approved = $service->review($checker, (int) $draft->id, true);
        $this->assertSame('APPROVED_RULE', $approved->status);

        $this->travelTo(now()->addDays(3));
        $valid = $service->advisoryFor($checker, $stock['store_id'], 'B2C', $supplierId, $warehouseId);
        $this->assertSame($approved->id, $valid['policy']->id);
        $this->assertFalse($valid['certified']);

        DB::table('enterprise_suppliers')->where('id', $supplierId)
            ->update(['status' => 'inactive']);
        try {
            $service->advisoryFor($checker, $stock['store_id'], 'B2C', $supplierId, $warehouseId);
            $this->fail('An inactive explicit supplier cannot obtain a generic title policy.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        try {
            $service->review($checker, (int) $approved->id, true);
            $this->fail('An approval replay must not return a now-revoked supplier policy as active.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        DB::table('enterprise_suppliers')->where('id', $supplierId)
            ->update(['status' => 'active']);
        DB::table('enterprise_supplier_documents')->where('id', $documentId)
            ->update(['expires_at' => now()->subDay()->toDateString()]);
        $expired = $service->advisoryFor($checker, $stock['store_id'], 'B2C', $supplierId, $warehouseId);
        $this->assertNull($expired['policy']);
        $this->assertFalse($expired['certified']);
        try {
            $service->review($checker, (int) $approved->id, true);
            $this->fail('An approval replay must not accept now-expired supplier evidence.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        DB::table('enterprise_supplier_documents')->where('id', $documentId)
            ->update(['expires_at' => now()->addDays(10)->toDateString()]);
        DB::table('enterprise_suppliers')->where('id', $supplierId)
            ->update(['store_id' => $foreign['store_id']]);
        try {
            $service->advisoryFor(
                $checker, $stock['store_id'], 'B2C', $supplierId, $warehouseId,
            );
            $this->fail('Explicit foreign supplier context must be rejected, not mapped to fallback.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        DB::table('enterprise_suppliers')->where('id', $supplierId)
            ->update(['store_id' => $stock['store_id']]);
        try {
            $service->advisoryFor(
                $checker, $stock['store_id'], 'B2C', $supplierId, $foreignWarehouseId,
            );
            $this->fail('Foreign warehouse context must not return policy guidance.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $restored = $service->advisoryFor($checker, $stock['store_id'], 'B2C', $supplierId, $warehouseId);
        $this->assertSame($approved->id, $restored['policy']->id);
        $this->assertFalse($restored['certified']);
        $this->travelBack();

        // A policy's approval is never a title certificate or stock posting.
        $this->assertSame('UNKNOWN', app(InventoryOwnerProvenanceReadService::class)
            ->snapshot($this->inventoryActor($stock['store_id']), $stock['inventory_id'])['state']);
        $this->assertSame(0, DB::table('stock_movements')
            ->where('inventory_id', $stock['inventory_id'])->count());
    }

    public function test_owner_policy_request_uuid_index_uses_mysql_safe_identifier(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $indexes = Schema::getIndexes('enterprise_inventory_ownership_rule_revisions');
        $names = array_values(array_map(
            static fn (array $index): string => (string) $index['name'],
            $indexes,
        ));

        $this->assertContains('eior_request_uuid_uq', $names);
        $this->assertNotContains('enterprise_inventory_ownership_rule_revisions_request_uuid_unique', $names);
        $this->assertLessThanOrEqual(64, max(array_map('strlen', $names)));
    }

    public function test_signed_supplier_consignment_requires_scoped_independent_certificate(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('SIGNED-SUPPLIER-TITLE');
        $actor = $this->inventoryActor($stock['store_id']);
        $checker = User::query()->create([
            'name' => 'Independent Supplier Title Checker',
            'email' => 'p110-supplier-title-checker@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        // The signature names a checker; only an active, independently
        // authorized reviewer can actually certify stock in this store.
        $checkerRole = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        DB::table('role_user')->insert(['user_id' => $checker->id, 'role_id' => $checkerRole->id]);
        $supplierId = $this->supplier($stock['store_id']);
        $this->position($stock['inventory_id'], 'SUPPLIER', $supplierId, 10, 3, $actor->id, $checker->id, true);

        $inventory = DB::table('inventories')->where('id', $stock['inventory_id'])->first();
        $position = DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->first();
        $verifier = app(InventoryLegalTitleAttestationVerifier::class);

        Storage::fake('local');
        $path = 'inventory-title-evidence/'.Str::uuid().'.pdf';
        $bytes = '%PDF-1.7 P110 test-only independent supplier consignment evidence';
        Storage::disk('local')->put($path, $bytes);

        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        $this->assertNotFalse($privateKey);
        $details = openssl_pkey_get_details($privateKey);
        $this->assertIsArray($details);
        config()->set('enterprise_inventory_title.trusted_public_keys.test-supplier-issuer', $details['key']);
        config()->set('enterprise_inventory_title.revoked_key_ids', []);

        $attestation = (object) [
            'document_kind' => 'CONSIGNMENT_AGREEMENT',
            'document_path' => $path,
            'document_sha256' => hash('sha256', $bytes),
            'trusted_key_id' => 'test-supplier-issuer',
            'checked_by' => (int) $checker->id,
            'signed_at' => now()->subMinute()->toDateTimeString(),
            'expires_at' => now()->addDay()->toDateTimeString(),
        ];
        $this->assertTrue(openssl_sign(
            $verifier->signedPayload($inventory, $position, $stock['store_id'], $attestation),
            $signature,
            $privateKey,
            OPENSSL_ALGO_SHA256,
        ));
        DB::table('enterprise_inventory_title_attestations')->insert([
            'owner_position_id' => $position->id,
            'stock_revision_sha256' => $verifier->stockRevisionSha256($inventory, $position, $stock['store_id']),
            'document_kind' => $attestation->document_kind,
            'document_path' => $path,
            'document_sha256' => $attestation->document_sha256,
            'trusted_key_id' => $attestation->trusted_key_id,
            'signature_base64' => base64_encode($signature),
            'checked_by' => $checker->id,
            'signed_at' => $attestation->signed_at,
            'expires_at' => $attestation->expires_at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $read = app(InventoryOwnerProvenanceReadService::class);
        $certified = $read->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('CERTIFIED', $certified['state']);
        $this->assertTrue($certified['certified']);
        $this->assertSame('SUPPLIER', $certified['positions'][0]['owner_kind']);
        $this->assertSame($supplierId, $certified['positions'][0]['supplier_id']);

        // Revocation is tied to the exact signer ID in this verified
        // attestation. An unrelated revoked ID leaves the valid signature
        // usable, while revoking its signer fails closed without any write.
        config()->set('enterprise_inventory_title.revoked_key_ids', ['other-test-issuer']);
        $unrelatedKeyRevocation = $read->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('CERTIFIED', $unrelatedKeyRevocation['state']);
        $this->assertTrue($unrelatedKeyRevocation['certified']);

        $positionBeforeRevocation = (array) DB::table('enterprise_inventory_owner_positions')
            ->where('id', $position->id)->first();
        $positionCountBeforeRevocation = DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->count();
        $attestationBeforeRevocation = (array) DB::table('enterprise_inventory_title_attestations')
            ->where('owner_position_id', $position->id)->first();
        $attestationCountBeforeRevocation = DB::table('enterprise_inventory_title_attestations')
            ->where('owner_position_id', $position->id)->count();
        $movementsBeforeRevocation = DB::table('stock_movements')
            ->where('inventory_id', $stock['inventory_id'])->count();
        $journalsBeforeRevocation = DB::table('enterprise_finance_journals')->count();
        $journalLinesBeforeRevocation = DB::table('enterprise_finance_journal_lines')->count();
        $auditBeforeRevocation = DB::table('audit_logs')->count();

        config()->set('enterprise_inventory_title.revoked_key_ids', ['test-supplier-issuer']);
        $revokedKey = $read->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNVERIFIED', $revokedKey['state']);
        $this->assertFalse($revokedKey['certified']);
        $this->assertFalse($revokedKey['positions'][0]['certified']);
        $this->assertSame($positionBeforeRevocation, (array) DB::table('enterprise_inventory_owner_positions')
            ->where('id', $position->id)->first());
        $this->assertSame($positionCountBeforeRevocation, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->count());
        $this->assertSame($attestationBeforeRevocation, (array) DB::table('enterprise_inventory_title_attestations')
            ->where('owner_position_id', $position->id)->first());
        $this->assertSame($attestationCountBeforeRevocation, DB::table('enterprise_inventory_title_attestations')
            ->where('owner_position_id', $position->id)->count());
        $this->assertSame($movementsBeforeRevocation, DB::table('stock_movements')
            ->where('inventory_id', $stock['inventory_id'])->count());
        $this->assertSame($journalsBeforeRevocation, DB::table('enterprise_finance_journals')->count());
        $this->assertSame($journalLinesBeforeRevocation, DB::table('enterprise_finance_journal_lines')->count());
        $this->assertSame($auditBeforeRevocation, DB::table('audit_logs')->count());

        config()->set('enterprise_inventory_title.revoked_key_ids', 'test-supplier-issuer');
        $malformedRevocation = $read->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNVERIFIED', $malformedRevocation['state']);
        $this->assertFalse($malformedRevocation['certified']);

        config()->set('enterprise_inventory_title.revoked_key_ids', []);
        $this->assertSame('CERTIFIED', $read->snapshot($actor, $stock['inventory_id'])['state']);

        // A signed checker ID cannot override current RBAC or disabled-user
        // status. These read decisions must never mutate the stored proof.
        DB::table('role_user')->where('user_id', $checker->id)->where('role_id', $checkerRole->id)->delete();
        $roleless = $read->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNVERIFIED', $roleless['state']);
        $this->assertFalse($roleless['certified']);
        DB::table('role_user')->insert(['user_id' => $checker->id, 'role_id' => $checkerRole->id]);
        $this->assertSame('CERTIFIED', $read->snapshot($actor, $stock['inventory_id'])['state']);

        $checker->forceFill(['is_active' => false])->save();
        $this->assertSame('UNVERIFIED', $read->snapshot($actor, $stock['inventory_id'])['state']);
        $checker->forceFill(['is_active' => true])->save();
        $this->assertSame('CERTIFIED', $read->snapshot($actor, $stock['inventory_id'])['state']);

        // A normal migration rollback must never remove SQL evidence guards
        // while a signed title claim is persisted in this same store.
        $attestationMigration = require database_path('migrations/2026_11_16_070000_protect_inventory_title_attestation_records.php');
        try {
            $attestationMigration->down();
            $this->fail('Signed evidence downgrade must refuse removal of the immutable guards.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('recovery', $exception->getMessage());
        }

        // A forged checker ID must be rejected at the SQL boundary, before
        // an existing genuine signature can be altered and later restored.
        try {
            DB::table('enterprise_inventory_title_attestations')
                ->where('owner_position_id', $position->id)->update(['checked_by' => $actor->id]);
            $this->fail('Signed legal-title checker identity must be immutable.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertDatabaseHas('enterprise_inventory_title_attestations', [
            'owner_position_id' => $position->id,
            'checked_by' => $checker->id,
        ]);

        // Removing legal title evidence must also be denied: otherwise the
        // movement-history trigger could be bypassed by deleting this row.
        try {
            DB::table('enterprise_inventory_title_attestations')
                ->where('owner_position_id', $position->id)->delete();
            $this->fail('Signed legal-title evidence must not be deleted.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertSame('CERTIFIED', $read->snapshot($actor, $stock['inventory_id'])['state']);

        // Physical stock custody does not make an out-of-store supplier a
        // legal owner of this store's consignment.
        $foreignStock = $this->stock('SIGNED-SUPPLIER-FOREIGN');
        DB::table('enterprise_suppliers')->where('id', $supplierId)->update([
            'store_id' => $foreignStock['store_id'],
        ]);
        $invalidScope = $read->snapshot($actor, $stock['inventory_id']);
        $this->assertFalse($invalidScope['certified']);
        $this->assertSame('UNKNOWN', $invalidScope['state']);
        $this->assertFalse($invalidScope['positions'][0]['certified']);
    }

    public function test_missing_attested_stock_movement_trigger_disables_title_certification(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQL trigger disappearance is isolated to SQLite in this fixture.');
        }

        $verifier = app(InventoryLegalTitleAttestationVerifier::class);
        $guards = new \ReflectionMethod(InventoryLegalTitleAttestationVerifier::class, 'hasProtectedStockMovementHistory');
        $this->assertTrue($guards->invoke($verifier));

        // A partial trigger deployment or privileged DDL tamper must never
        // count as a protected owner-title ledger.
        DB::unprepared('DROP TRIGGER IF EXISTS p110_attested_movement_no_delete');

        try {
            $this->assertFalse($guards->invoke($verifier));
        } finally {
            // Restore both guards before the next test; a failed assertion
            // must not weaken this connection's state for other test cases.
            $migration = require database_path('migrations/2026_11_16_060000_protect_attested_stock_movement_history.php');
            $migration->up();
        }

        $this->assertTrue($guards->invoke($verifier));
    }

    public function test_private_signed_title_proof_is_bound_to_owner_stock_document_and_trusted_key(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('SIGNED-LEGAL-PROOF');
        $actor = $this->inventoryActor($stock['store_id']);
        $checker = User::query()->create([
            'name' => 'Independent Legal Title Checker',
            'email' => 'title-checker@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $checkerRole = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        DB::table('role_user')->insert(['user_id' => $checker->id, 'role_id' => $checkerRole->id]);
        $this->position($stock['inventory_id'], 'FOODEX', null, 10, 3, $actor->id, $checker->id, true);

        $inventory = DB::table('inventories')->where('id', $stock['inventory_id'])->first();
        $position = DB::table('enterprise_inventory_owner_positions')->where('inventory_id', $stock['inventory_id'])->first();
        $verifier = app(InventoryLegalTitleAttestationVerifier::class);

        Storage::fake('local');
        $path = 'inventory-title-evidence/'.Str::uuid().'.pdf';
        $bytes = '%PDF-1.7 test-only legal title fixture; not a real certificate';
        Storage::disk('local')->put($path, $bytes);

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        config()->set('enterprise_inventory_title.trusted_public_keys.test-issuer', $details['key']);

        $stamp = now()->subMinute()->toDateTimeString();
        $expiry = now()->addDay()->toDateTimeString();
        $attestation = (object) [
            'document_kind' => 'SIGNED_CONTRACT',
            'document_path' => $path,
            'document_sha256' => hash('sha256', $bytes),
            'trusted_key_id' => 'test-issuer',
            'checked_by' => (int) $checker->id,
            'signed_at' => $stamp,
            'expires_at' => $expiry,
        ];
        $payload = $verifier->signedPayload($inventory, $position, $stock['store_id'], $attestation);
        $this->assertTrue(openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256));
        DB::table('enterprise_inventory_title_attestations')->insert([
            'owner_position_id' => $position->id,
            'stock_revision_sha256' => $verifier->stockRevisionSha256($inventory, $position, $stock['store_id']),
            'document_kind' => $attestation->document_kind,
            'document_path' => $path,
            'document_sha256' => $attestation->document_sha256,
            'trusted_key_id' => $attestation->trusted_key_id,
            'signature_base64' => base64_encode($signature),
            'checked_by' => $checker->id,
            'signed_at' => $stamp,
            'expires_at' => $expiry,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $read = app(InventoryOwnerProvenanceReadService::class);
        $trusted = $read->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('CERTIFIED', $trusted['state']);
        $this->assertTrue($trusted['certified']);
        $this->assertTrue($trusted['positions'][0]['certified']);

        // An append-only canonical stock movement invalidates this proof
        // even if the inventory's timestamp and net quantity look identical
        // (same-second / ABA stale-certificate replay).
        $originalRevision = $verifier->stockRevisionSha256($inventory, $position, $stock['store_id']);
        $movementId = DB::table('stock_movements')->insertGetId([
            'inventory_id' => $stock['inventory_id'],
            'store_id' => $stock['store_id'],
            'type' => 'adjustment',
            'quantity' => '0.001',
            'reference_type' => 'test-owner-attestation-replay',
            'reference_id' => $stock['inventory_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertNotSame(
            $originalRevision,
            $verifier->stockRevisionSha256($inventory, $position, $stock['store_id']),
        );
        $this->assertSame('UNVERIFIED', $read->snapshot($actor, $stock['inventory_id'])['state']);
        // Database authority, not just the read-side watermark, must reject
        // erasing an evidence-linked movement. Otherwise a movement's
        // INSERT→DELETE would re-certify a superseded signed legal title.
        try {
            DB::table('stock_movements')->where('id', $movementId)->delete();
            $this->fail('Attested stock movement deletion must fail at the SQL boundary.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertDatabaseHas('stock_movements', ['id' => $movementId]);
        $this->assertSame('UNVERIFIED', $read->snapshot($actor, $stock['inventory_id'])['state']);

        // Mutating the movement payload or its inventory scope must likewise
        // fail, without changing canonical stock or owner evidence.
        try {
            DB::table('stock_movements')->where('id', $movementId)
                ->update(['quantity' => '0.002']);
            $this->fail('Attested movement history must not be rewritten.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertDatabaseHas('stock_movements', [
            'id' => $movementId,
            'quantity' => '0.001',
        ]);
        $this->assertSame('UNVERIFIED', $read->snapshot($actor, $stock['inventory_id'])['state']);

        // Legal-owner entity is independent of the store/warehouse holder.
        // An attested store cannot move under another legal entity and keep
        // using the old signed title proof without a new independent review.
        $originalScope = DB::table('enterprise_store_scopes as scope')
            ->join('enterprise_business_units as unit', 'unit.id', '=', 'scope.business_unit_id')
            ->join('enterprise_legal_entities as entity', 'entity.id', '=', 'unit.legal_entity_id')
            ->where('scope.store_id', $stock['store_id'])
            ->first(['scope.business_unit_id', 'entity.company_id']);
        $this->assertNotNull($originalScope);
        $newLegalEntityId = DB::table('enterprise_legal_entities')->insertGetId([
            'company_id' => $originalScope->company_id,
            'code' => 'P110-OTHER-LEGAL-TITLE-ENTITY',
            'name' => 'Alternative Legal Title Entity',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $newBusinessUnitId = DB::table('enterprise_business_units')->insertGetId([
            'legal_entity_id' => $newLegalEntityId,
            'code' => 'P110-OTHER-TITLE-UNIT',
            'name' => 'Alternative Legal Title Business Unit',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('enterprise_store_scopes')->where('store_id', $stock['store_id'])
            ->update(['business_unit_id' => $newBusinessUnitId]);
        $this->assertSame('UNVERIFIED', $read->snapshot($actor, $stock['inventory_id'])['state']);
        DB::table('enterprise_store_scopes')->where('store_id', $stock['store_id'])
            ->update(['business_unit_id' => $originalScope->business_unit_id]);
        // Reverting a store's legal-entity mapping must NOT resurrect a
        // certificate already invalidated by a newer stock movement.
        // Title can only be re-certified using fresh independently signed
        // evidence for the latest canonical movement revision.
        $this->assertSame('UNVERIFIED', $read->snapshot($actor, $stock['inventory_id'])['state']);

        Storage::disk('local')->put($path, 'tampered document');
        $this->assertSame('UNVERIFIED', $read->snapshot($actor, $stock['inventory_id'])['state']);
        Storage::disk('local')->put($path, $bytes);

        config()->set('enterprise_inventory_title.trusted_public_keys', []);
        $this->assertSame('UNVERIFIED', $read->snapshot($actor, $stock['inventory_id'])['state']);
        config()->set('enterprise_inventory_title.trusted_public_keys.test-issuer', $details['key']);

        DB::table('enterprise_inventory_owner_positions')->where('id', $position->id)->update([
            'quantity' => 9,
        ]);
        $this->assertSame('STALE', $read->snapshot($actor, $stock['inventory_id'])['state']);
    }

    public function test_owner_snapshot_preserves_decimal_milli_units_and_denies_divergent_partition(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('MILLI-READ');
        $actor = $this->inventoryActor($stock['store_id']);

        DB::table('inventories')->where('id', $stock['inventory_id'])->update([
            'quantity' => '10.001',
            'reserved_quantity' => '3.002',
            'updated_at' => now(),
        ]);

        $read = app(InventoryOwnerProvenanceReadService::class);
        $unattributed = $read->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNKNOWN', $unattributed['state']);
        $this->assertFalse($unattributed['certified']);
        $this->assertSame('10.001', $unattributed['quantity']);
        $this->assertSame('3.002', $unattributed['reserved_quantity']);

        DB::table('enterprise_inventory_owner_positions')->insert([
            'inventory_id' => $stock['inventory_id'],
            'owner_key' => 'UNKNOWN',
            'owner_kind' => 'UNKNOWN',
            'supplier_id' => null,
            'quantity' => '10.001',
            'reserved_quantity' => '3.002',
            'source_inventory_updated_at' => DB::table('inventories')
                ->where('id', $stock['inventory_id'])->value('updated_at'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $matched = $read->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNKNOWN', $matched['state']);
        $this->assertSame('10.001', $matched['positions'][0]['quantity']);
        $this->assertSame('3.002', $matched['positions'][0]['reserved_quantity']);
        $this->assertFalse($matched['positions'][0]['certified']);

        // A one-milli-unit owner-row tamper cannot be rounded into a
        // consistent balance or mistaken for legal title.
        DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])
            ->update(['quantity' => '10.002']);
        $tampered = $read->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('STALE', $tampered['state']);
        $this->assertFalse($tampered['certified']);
        $this->assertSame('10.002', $tampered['positions'][0]['quantity']);
        $this->assertSame('10.001', $tampered['quantity']);
    }

    public function test_owner_balance_conversion_remains_exact_at_decimal_14_3_boundary(): void
    {
        $millis = new \ReflectionMethod(InventoryOwnerProvenanceReadService::class, 'millis');
        $decimal = new \ReflectionMethod(InventoryOwnerProvenanceReadService::class, 'decimal');

        $this->assertSame(99999999999001, $millis->invoke(null, '99999999999.001'));
        $this->assertSame(99999999999002, $millis->invoke(null, '99999999999.002'));
        $this->assertSame('99999999999.001', $decimal->invoke(null, 99999999999001));
        $this->assertSame('-0.001', $decimal->invoke(null, -1));
        $this->expectException(\UnexpectedValueException::class);
        $millis->invoke(null, '99999999999.0001');
    }

    public function test_signed_owner_revision_hash_preserves_exact_large_milli_unit_differences(): void
    {
        $verifier = app(InventoryLegalTitleAttestationVerifier::class);
        $inventory = (object) ['id' => 1, 'warehouse_id' => 2, 'product_id' => 3];
        $position = (object) [
            'id' => 4,
            'owner_key' => 'FOODEX',
            'owner_kind' => 'FOODEX',
            'supplier_id' => null,
            'quantity' => '99999999999.001',
            'reserved_quantity' => '0.001',
            'source_inventory_updated_at' => '2026-11-16 00:00:00',
            'evidence_type' => 'SIGNED_CONTRACT',
            'evidence_reference' => 'TITLE-REV-001',
            'proposed_by' => 5,
            'certified_by' => 6,
            'certified_at' => '2026-11-16 00:01:00',
        ];

        $first = $verifier->stockRevisionSha256($inventory, $position, 7);
        $position->quantity = '99999999999.002';
        $this->assertNotSame($first, $verifier->stockRevisionSha256($inventory, $position, 7));

        $position->quantity = '99999999999.001';
        $position->reserved_quantity = '0.002';
        $this->assertNotSame($first, $verifier->stockRevisionSha256($inventory, $position, 7));
    }

    /** @return array{store_id:int,inventory_id:int} */
    private function stock(string $suffix): array
    {
        $storeType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeType,
            'code' => 'OWN-'.$suffix,
            'name' => 'Owner Stock '.$suffix,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-OWN-'.$suffix,
            'name' => 'Each',
            'decimal_places' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'catalog-own-'.$suffix,
            'name' => 'Owner Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'SKU-OWN-'.$suffix,
            'name' => 'Owner Stock Item',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WH-OWN-'.$suffix,
            'name' => 'Owner Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 10,
            'reserved_quantity' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['store_id' => $storeId, 'inventory_id' => $inventoryId];
    }

    /** @param array{store_id:int,inventory_id:int} $stock
     * @return array{source_warehouse_id:int,target_warehouse_id:int,target_inventory_id:int,product_id:int}
     */
    private function transferTarget(array $stock, string $suffix): array
    {
        $source = DB::table('inventories')->where('id', $stock['inventory_id'])
            ->first(['warehouse_id', 'product_id']);
        $targetWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $stock['store_id'],
            'code' => 'P110-WMS-TARGET-'.$suffix,
            'name' => 'P110 Target Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $targetInventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $targetWarehouseId,
            'product_id' => (int) $source->product_id,
            'quantity' => 1,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'source_warehouse_id' => (int) $source->warehouse_id,
            'target_warehouse_id' => $targetWarehouseId,
            'target_inventory_id' => $targetInventoryId,
            'product_id' => (int) $source->product_id,
        ];
    }

    private function inventoryActor(int $storeId): User
    {
        $this->enableEnterpriseWmsForStore($storeId);
        $actor = User::query()->create([
            'name' => 'Owner Read Operator',
            'email' => 'owner-read-'.$storeId.'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
        ]);

        return $actor;
    }

    private function supplier(int $storeId): int
    {
        $company = DB::table('enterprise_companies')->first(['id', 'organization_id']);

        return (int) DB::table('enterprise_suppliers')->insertGetId([
            'organization_id' => $company->organization_id,
            'company_id' => $company->id,
            'store_id' => $storeId,
            'scope_type' => 'store',
            'code' => 'LEGAL-OWNER-'.$storeId,
            'name_en' => 'Verified Supplier Fixture',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function position(
        int $inventoryId,
        string $kind,
        ?int $supplierId,
        int $quantity,
        int $reserved,
        int $proposer,
        int $checker,
        bool $withEvidence,
    ): void {
        DB::table('enterprise_inventory_owner_positions')->insert([
            'inventory_id' => $inventoryId,
            'owner_key' => $kind === 'SUPPLIER' ? 'SUPPLIER:'.$supplierId : $kind,
            'owner_kind' => $kind,
            'supplier_id' => $supplierId,
            'quantity' => $quantity,
            'reserved_quantity' => $reserved,
            'source_inventory_updated_at' => DB::table('inventories')
                ->where('id', $inventoryId)->value('updated_at'),
            'evidence_type' => $withEvidence ? 'goods_receipt_legal_title' : null,
            'evidence_reference' => $withEvidence ? 'TEST-PROOF-'.$inventoryId : null,
            'proposed_by' => $proposer,
            'certified_by' => $checker,
            'certified_at' => $withEvidence ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
