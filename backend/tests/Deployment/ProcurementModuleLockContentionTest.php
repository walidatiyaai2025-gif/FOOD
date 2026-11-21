<?php

namespace Tests\Deployment;

use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Runs exclusively against the real disposable MySQL service in Backend CI.
 *
 * The feature suite's QueryExecuted listeners simulate stale snapshots in one
 * connection. This test separately proves that the module guard's FOR UPDATE
 * lock participates in genuine, independent InnoDB session contention. It is
 * not a substitute for a two-process supplier HTTP transaction/load test.
 */
final class ProcurementModuleLockContentionTest extends TestCase
{
    public function test_two_mysql_sessions_serialize_procurement_shutdown_and_supplier_write_lock(): void
    {
        $this->assertSame('true', getenv('FOODEX_DISPOSABLE_SERVICES'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('foodex_acceptance', DB::connection()->getDatabaseName());
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));

        $original = DB::table('enterprise_modules')->where('code', 'procurement')->first();
        app(ModuleRegistry::class)->syncManifest('procurement');

        $configuration = config('database.connections.mysql');
        $this->assertIsArray($configuration);
        $this->assertSame('mysql', $configuration['driver'] ?? null);

        $peer = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) ($configuration['host'] ?? '127.0.0.1'),
                (int) ($configuration['port'] ?? 3306),
                (string) $configuration['database'],
            ),
            (string) ($configuration['username'] ?? ''),
            (string) ($configuration['password'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );

        try {
            DB::table('enterprise_modules')
                ->where('code', 'procurement')
                ->update(['state' => ModuleState::ENABLED->value]);

            $primaryId = (int) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
            $peerId = (int) $peer->query('SELECT CONNECTION_ID()')->fetchColumn();
            $this->assertGreaterThan(0, $primaryId);
            $this->assertGreaterThan(0, $peerId);
            $this->assertNotSame($primaryId, $peerId, 'Contending sessions must have distinct MySQL connection IDs.');

            // Supplier's transactional lock wins first: a concurrent module
            // shutdown cannot acquire the same row until the quote is done.
            DB::beginTransaction();
            try {
                $locked = DB::table('enterprise_modules')
                    ->where('code', 'procurement')
                    ->lockForUpdate()
                    ->first();
                $this->assertNotNull($locked);
                $this->assertSame(ModuleState::ENABLED->value, $locked->state);

                // InnoDB locking reads need an explicit transaction in each
                // session; running peer NOWAIT in autocommit mode is not
                // reliable proof of a competing transactional row lock.
                $peer->beginTransaction();
                try {
                    $this->assertPeerNowaitBlocked($peer);
                } finally {
                    if ($peer->inTransaction()) {
                        $peer->rollBack();
                    }
                }
            } finally {
                DB::rollBack();
            }

            // A shutdown attempt that ROLLS BACK must not revoke a supplier's
            // subsequent fresh authorization. This is a second independent
            // MySQL session, not a mocked QueryExecuted interleaving.
            $peer->beginTransaction();
            try {
                $abortShutdown = $peer->prepare(
                    'UPDATE enterprise_modules SET state = ? WHERE code = ?',
                );
                $abortShutdown->execute([ModuleState::SUSPENDED->value, 'procurement']);
                $this->assertSame(
                    ModuleState::SUSPENDED->value,
                    $peer->query("SELECT state FROM enterprise_modules WHERE code = 'procurement'")->fetchColumn(),
                );
            } finally {
                $peer->rollBack();
            }

            DB::beginTransaction();
            try {
                $afterRollback = DB::table('enterprise_modules')
                    ->where('code', 'procurement')
                    ->lockForUpdate()
                    ->first();
                $this->assertNotNull($afterRollback);
                $this->assertSame(
                    ModuleState::ENABLED->value,
                    $afterRollback->state,
                    'A rolled-back operator shutdown must not poison the next locked authorization.',
                );
            } finally {
                DB::rollBack();
            }

            // Admin shutdown now wins first. The independent supplier session
            // cannot lock/approve ENABLED while SUSPENDED is uncommitted.
            $peer->beginTransaction();
            try {
                $read = $peer->prepare('SELECT state FROM enterprise_modules WHERE code = ? FOR UPDATE');
                $read->execute(['procurement']);
                $this->assertSame(ModuleState::ENABLED->value, $read->fetchColumn());
                $change = $peer->prepare('UPDATE enterprise_modules SET state = ? WHERE code = ?');
                $change->execute([ModuleState::SUSPENDED->value, 'procurement']);

                DB::beginTransaction();
                try {
                    $blocked = false;
                    try {
                        DB::select('SELECT state FROM enterprise_modules WHERE code = ? FOR UPDATE NOWAIT', [
                            'procurement',
                        ]);
                    } catch (QueryException $exception) {
                        $blocked = true;
                        $this->assertSame(
                            3572,
                            (int) ($exception->errorInfo[1] ?? 0),
                            'InnoDB NOWAIT must report real conflicting-row lock error 3572.',
                        );
                    }
                    $this->assertTrue($blocked, 'Supplier session must not read an unlocked stale ENABLED state.');
                } finally {
                    DB::rollBack();
                }

                $peer->commit();
            } finally {
                if ($peer->inTransaction()) {
                    $peer->rollBack();
                }
            }

            // After a COMMITTED shutdown, the next fresh supplier transaction
            // must see SUSPENDED under its own locking read, not stale ENABLED.
            DB::beginTransaction();
            try {
                $afterCommit = DB::table('enterprise_modules')
                    ->where('code', 'procurement')
                    ->lockForUpdate()
                    ->first();
                $this->assertNotNull($afterCommit);
                $this->assertSame(
                    ModuleState::SUSPENDED->value,
                    $afterCommit->state,
                    'Committed operator shutdown wins before any later supplier write.',
                );
            } finally {
                DB::rollBack();
            }

            $this->assertSame(
                ModuleState::SUSPENDED->value,
                DB::table('enterprise_modules')->where('code', 'procurement')->value('state'),
            );
        } finally {
            if ($peer->inTransaction()) {
                $peer->rollBack();
            }
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }

            if ($original === null) {
                DB::table('enterprise_modules')->where('code', 'procurement')->delete();
            } else {
                DB::table('enterprise_modules')
                    ->where('code', 'procurement')
                    ->update(['state' => $original->state]);
            }
        }
    }

    public function test_two_mysql_sessions_serialize_scoped_supplier_eligibility_and_operator_hold(): void
    {
        $this->assertSame('true', getenv('FOODEX_DISPOSABLE_SERVICES'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('foodex_acceptance', DB::connection()->getDatabaseName());
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));

        $company = DB::table('enterprise_companies')->orderBy('id')->first();
        $this->assertNotNull($company);
        $supplierId = DB::table('enterprise_suppliers')->insertGetId([
            'organization_id' => $company->organization_id,
            'company_id' => $company->id,
            'scope_type' => 'company',
            'code' => 'P135-MYSQL-LOCK-'.bin2hex(random_bytes(8)),
            'name_en' => 'Disposable supplier row lock acceptance',
            'status' => 'active',
            'qualification_status' => 'qualified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $configuration = config('database.connections.mysql');
        $this->assertIsArray($configuration);
        $peer = null;

        try {
            $peer = new PDO(
                sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    (string) ($configuration['host'] ?? '127.0.0.1'),
                    (int) ($configuration['port'] ?? 3306),
                    (string) $configuration['database'],
                ),
                (string) ($configuration['username'] ?? ''),
                (string) ($configuration['password'] ?? ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );
            $primaryConnectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $peerConnectionId = (int) $peer->query('SELECT CONNECTION_ID()')->fetchColumn();
            $this->assertGreaterThan(0, $primaryConnectionId);
            $this->assertGreaterThan(0, $peerConnectionId);
            $this->assertNotSame($primaryConnectionId, $peerConnectionId);

            // Award's scoped supplier eligibility lock commits first: a
            // different MySQL transaction cannot hold/disqualify this row.
            DB::beginTransaction();
            try {
                $supplier = DB::table('enterprise_suppliers')
                    ->where('id', $supplierId)
                    ->where('organization_id', $company->organization_id)
                    ->where('company_id', $company->id)
                    ->where('scope_type', 'company')
                    ->lockForUpdate()
                    ->first();
                $this->assertNotNull($supplier);
                $this->assertSame('active', $supplier->status);
                $this->assertSame('qualified', $supplier->qualification_status);

                $peer->beginTransaction();
                try {
                    $blocked = false;
                    try {
                        $check = $peer->prepare(
                            'SELECT status FROM enterprise_suppliers WHERE id = ? FOR UPDATE NOWAIT',
                        );
                        $check->execute([$supplierId]);
                    } catch (PDOException $exception) {
                        $blocked = true;
                        $this->assertSame(3572, (int) ($exception->errorInfo[1] ?? 0));
                    }
                    $this->assertTrue($blocked, 'Operator must not commit a supplier hold during locked award eligibility.');
                } finally {
                    if ($peer->inTransaction()) {
                        $peer->rollBack();
                    }
                }
            } finally {
                DB::rollBack();
            }

            // Operator hold now wins first. A supplier eligibility read
            // cannot acquire the same row until the hold commits.
            $peer->beginTransaction();
            try {
                $update = $peer->prepare('UPDATE enterprise_suppliers SET status = ? WHERE id = ?');
                $update->execute(['on_hold', $supplierId]);

                DB::beginTransaction();
                try {
                    $blocked = false;
                    try {
                        DB::select(
                            'SELECT status FROM enterprise_suppliers WHERE id = ? FOR UPDATE NOWAIT',
                            [$supplierId],
                        );
                    } catch (QueryException $exception) {
                        $blocked = true;
                        $this->assertSame(3572, (int) ($exception->errorInfo[1] ?? 0));
                    }
                    $this->assertTrue($blocked, 'Award must not read stale active while supplier hold is uncommitted.');
                } finally {
                    DB::rollBack();
                }

                $peer->commit();
            } finally {
                if ($peer->inTransaction()) {
                    $peer->rollBack();
                }
            }

            DB::beginTransaction();
            try {
                $afterCommit = DB::table('enterprise_suppliers')
                    ->where('id', $supplierId)
                    ->where('organization_id', $company->organization_id)
                    ->where('company_id', $company->id)
                    ->lockForUpdate()
                    ->first();
                $this->assertNotNull($afterCommit);
                $this->assertSame('on_hold', $afterCommit->status);
            } finally {
                DB::rollBack();
            }
        } finally {
            if ($peer !== null && $peer->inTransaction()) {
                $peer->rollBack();
            }
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::table('enterprise_suppliers')->where('id', $supplierId)->delete();
        }
    }

    public function test_two_mysql_sessions_serialize_scoped_contract_termination_and_rfq_binding(): void
    {
        $this->assertSame('true', getenv('FOODEX_DISPOSABLE_SERVICES'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('foodex_acceptance', DB::connection()->getDatabaseName());
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));

        $company = DB::table('enterprise_companies')->orderBy('id')->first();
        $this->assertNotNull($company);
        // Migrated disposable MySQL starts without stores. Provision a
        // canonical B2B principal rather than depending on another test's seed.
        $store = DB::table('stores')->where('id', app(WholesalePrincipal::class)->storeId())->first();
        $this->assertNotNull($store);
        $actor = User::factory()->create(['is_active' => true]);
        $supplierId = null;
        $contractId = null;
        $peer = null;

        try {
            $key = bin2hex(random_bytes(8));
            $supplierId = DB::table('enterprise_suppliers')->insertGetId([
                'organization_id' => $company->organization_id,
                'company_id' => $company->id,
                'scope_type' => 'company',
                'code' => 'P135-CONTRACT-LOCK-'.$key,
                'name_en' => 'Disposable contract lock supplier',
                'status' => 'active',
                'qualification_status' => 'qualified',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $contractId = DB::table('enterprise_procurement_contracts')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'contract_number' => 'P135-CON-'.$key,
                'organization_id' => $company->organization_id,
                'company_id' => $company->id,
                'store_id' => $store->id,
                'supplier_id' => $supplierId,
                'status' => 'active',
                'title' => 'Disposable contract termination interleave',
                'currency_code' => 'KWD',
                'starts_on' => now()->subDay()->toDateString(),
                'ends_on' => now()->addMonth()->toDateString(),
                'created_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $config = config('database.connections.mysql');
            $this->assertIsArray($config);
            $peer = new PDO(
                sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    (string) ($config['host'] ?? '127.0.0.1'),
                    (int) ($config['port'] ?? 3306),
                    (string) $config['database'],
                ),
                (string) ($config['username'] ?? ''),
                (string) ($config['password'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
            );
            $primaryId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $peerId = (int) $peer->query('SELECT CONNECTION_ID()')->fetchColumn();
            $this->assertGreaterThan(0, $primaryId);
            $this->assertGreaterThan(0, $peerId);
            $this->assertNotSame($primaryId, $peerId);

            // RFQ binding's scoped contract lock wins first: a separate
            // operator connection cannot terminate or replace its status
            // until the RFQ transaction releases the row.
            DB::beginTransaction();
            try {
                $locked = DB::table('enterprise_procurement_contracts')
                    ->where('id', $contractId)
                    ->where('organization_id', $company->organization_id)
                    ->where('company_id', $company->id)
                    ->where('store_id', $store->id)
                    ->lockForUpdate()
                    ->first();
                $this->assertNotNull($locked);
                $this->assertSame('active', $locked->status);

                $peer->beginTransaction();
                try {
                    $blocked = false;
                    try {
                        $statement = $peer->prepare(
                            'SELECT status FROM enterprise_procurement_contracts WHERE id = ? FOR UPDATE NOWAIT',
                        );
                        $statement->execute([$contractId]);
                    } catch (PDOException $error) {
                        $blocked = true;
                        $this->assertSame(3572, (int) ($error->errorInfo[1] ?? 0));
                    }
                    $this->assertTrue($blocked, 'Termination cannot bypass a locked RFQ contract snapshot.');
                } finally {
                    if ($peer->inTransaction()) {
                        $peer->rollBack();
                    }
                }
            } finally {
                DB::rollBack();
            }

            // Operator termination wins first: the separately running
            // RFQ binding cannot acquire an active contract until commit.
            $peer->beginTransaction();
            try {
                $statement = $peer->prepare(
                    'UPDATE enterprise_procurement_contracts SET status = ? WHERE id = ?',
                );
                $statement->execute(['terminated', $contractId]);
                $this->assertSame(
                    'terminated',
                    $peer->query(
                        'SELECT status FROM enterprise_procurement_contracts WHERE id = '.$contractId,
                    )->fetchColumn(),
                );

                DB::beginTransaction();
                try {
                    $blocked = false;
                    try {
                        DB::select(
                            'SELECT status FROM enterprise_procurement_contracts WHERE id = ? FOR UPDATE NOWAIT',
                            [$contractId],
                        );
                    } catch (QueryException $error) {
                        $blocked = true;
                        $this->assertSame(3572, (int) ($error->errorInfo[1] ?? 0));
                    }
                    $this->assertTrue($blocked, 'RFQ creation must not read an uncommitted termination.');
                } finally {
                    DB::rollBack();
                }

                $peer->commit();
            } finally {
                if ($peer->inTransaction()) {
                    $peer->rollBack();
                }
            }

            DB::beginTransaction();
            try {
                $afterCommit = DB::table('enterprise_procurement_contracts')
                    ->where('id', $contractId)
                    ->where('organization_id', $company->organization_id)
                    ->where('company_id', $company->id)
                    ->where('store_id', $store->id)
                    ->lockForUpdate()
                    ->first();
                $this->assertNotNull($afterCommit);
                $this->assertSame('terminated', $afterCommit->status);
            } finally {
                DB::rollBack();
            }
        } finally {
            if ($peer !== null && $peer->inTransaction()) {
                $peer->rollBack();
            }
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($contractId !== null) {
                DB::table('enterprise_procurement_contracts')->where('id', $contractId)->delete();
            }
            if ($supplierId !== null) {
                DB::table('enterprise_suppliers')->where('id', $supplierId)->delete();
            }
            $actor->delete();
        }
    }

    private function assertPeerNowaitBlocked(PDO $peer): void
    {
        $blocked = false;

        try {
            $statement = $peer->prepare(
                'SELECT state FROM enterprise_modules WHERE code = ? FOR UPDATE NOWAIT',
            );
            $statement->execute(['procurement']);
        } catch (PDOException $exception) {
            $blocked = true;
            $this->assertSame(
                3572,
                (int) ($exception->errorInfo[1] ?? 0),
                'InnoDB NOWAIT must report real conflicting-row lock error 3572.',
            );
        }

        $this->assertTrue($blocked, 'Admin session must not bypass the supplier quote lock.');
    }
}
