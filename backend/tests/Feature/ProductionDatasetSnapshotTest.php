<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\ProductionDatasetSnapshot;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionDatasetSnapshotTest extends TestCase
{
    private string $databasePath;

    private string $metadataPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = storage_path('framework/testing/snapshot-db-'.bin2hex(random_bytes(5)).'.sqlite');
        touch($this->databasePath);
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(CoreReferenceSeeder::class);
        $this->metadataPath = storage_path('framework/testing/snapshot-'.bin2hex(random_bytes(5)).'.json');
        config(['foodex_reset.snapshot_metadata_path' => $this->metadataPath]);
    }

    protected function tearDown(): void
    {
        @unlink($this->metadataPath);
        DB::disconnect('sqlite');

        foreach ([$this->databasePath, $this->databasePath.'-wal', $this->databasePath.'-shm', $this->databasePath.'-journal'] as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_verified_snapshot_restores_exact_prelaunch_dataset_and_is_idempotent(): void
    {
        $actor = User::query()->create([
            'name' => 'Snapshot Operator',
            'email' => 'snapshot-'.uniqid().'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $unitId = DB::table('units')->value('id');
        DB::table('units')->where('id', $unitId)->update(['name' => 'Legacy exact value']);
        $before = $this->fingerprint('units');

        $request = Request::create('/admin/settings/foodex-reset', 'POST');
        $request->setUserResolver(static fn () => $actor);

        $service = app(ProductionDatasetSnapshot::class);
        $created = $service->create($actor, $request);
        $this->assertSame(ProductionDatasetSnapshot::SNAPSHOT_READY, $created['state']);
        $this->assertTrue($created['verified']);
        $this->assertNotEmpty($created['migration_fingerprint']);
        $service->assertReady();

        DB::table('units')->where('id', $unitId)->update(['name' => 'Production trial mutation']);
        DB::table('units')->insert(['name' => 'Production-only row', 'code' => 'PROD-'.uniqid()]);
        $this->assertNotSame($before, $this->fingerprint('units'));

        $service->markLaunchActive($actor, $request);
        $restored = $service->restore($actor, $request);

        $this->assertSame(ProductionDatasetSnapshot::LEGACY_RESTORED, $restored['state']);
        $this->assertNotEmpty($restored['restore_compatibility_verified_at']);
        $this->assertNotEmpty($restored['restore_health_verified_at']);
        $this->assertSame($before, $this->fingerprint('units'));
        $this->assertSame('Legacy exact value', DB::table('units')->where('id', $unitId)->value('name'));

        $again = $service->restore($actor, $request);
        $this->assertSame(ProductionDatasetSnapshot::LEGACY_RESTORED, $again['state']);
        $this->assertSame($before, $this->fingerprint('units'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'system.production_snapshot.restored']);
    }


    public function test_restore_refuses_migration_mismatch_before_mutating_live_dataset(): void
    {
        $actor = User::query()->create([
            'name' => 'Snapshot Compatibility Operator',
            'email' => 'snapshot-compat-'.uniqid().'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $service = app(ProductionDatasetSnapshot::class);
        $service->create($actor);
        $service->markLaunchActive($actor);

        $unitId = DB::table('units')->value('id');
        DB::table('units')->where('id', $unitId)->update(['name' => 'Do not overwrite on incompatible restore']);

        $metadata = json_decode((string) file_get_contents($this->metadataPath), true, flags: JSON_THROW_ON_ERROR);
        $metadata['migration_fingerprint'] = str_repeat('f', 64);
        file_put_contents(
            $this->metadataPath,
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
            LOCK_EX,
        );

        try {
            $service->restore($actor);
            $this->fail('Restore should fail closed when the migration fingerprint differs.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('migration state is incompatible', $exception->getMessage());
        }

        $this->assertSame(
            'Do not overwrite on incompatible restore',
            DB::table('units')->where('id', $unitId)->value('name'),
        );
    }

    public function test_tampered_snapshot_fails_closed_before_launch(): void
    {
        $actor = User::query()->create([
            'name' => 'Snapshot Operator 2',
            'email' => 'snapshot2-'.uniqid().'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $service = app(ProductionDatasetSnapshot::class);
        $service->create($actor);

        $metadata = json_decode((string) file_get_contents($this->metadataPath), true, flags: JSON_THROW_ON_ERROR);
        file_put_contents($metadata['snapshot_file'], 'tampered', FILE_APPEND);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Snapshot verification failed.');
        $service->assertReady();
    }

    private function fingerprint(string $table): string
    {
        $rows = DB::table($table)->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all();

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
