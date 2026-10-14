<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\FoodexResetService;
use App\Services\ProductionDatasetSnapshot;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Foodex1077FinalCertificationTest extends TestCase
{
    use RefreshDatabase;

    private string $metadataPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        $this->metadataPath = storage_path('framework/testing/final-certification-'.bin2hex(random_bytes(5)).'.json');
        config(['foodex_reset.snapshot_metadata_path' => $this->metadataPath]);

        $sourceSha = config('foodex.release_source_sha');
        if (! is_string($sourceSha) || preg_match('/^[a-f0-9]{40,64}$/', $sourceSha) !== 1) {
            config([
                'foodex.release_source_sha' => str_repeat('d', 40),
                'foodex.release_run_number' => 'local-certification-test',
            ]);
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->metadataPath);
        parent::tearDown();
    }

    public function test_reversible_launch_restores_all_canonical_tables_exactly_and_can_relaunch(): void
    {
        $actor = User::query()->create([
            'name' => 'FOODEX 1.0.77 Certification Operator',
            'email' => 'certification-'.uniqid().'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $request = Request::create('/admin/settings/production-dataset', 'POST');
        $request->setUserResolver(static fn (): User => $actor);

        $snapshot = app(ProductionDatasetSnapshot::class);
        $before = $this->canonicalFingerprint();

        $first = $snapshot->create($actor, $request);
        $this->assertTrue($first['verified']);
        $this->assertSame(ProductionDatasetSnapshot::SNAPSHOT_READY, $first['state']);
        $firstSnapshotId = $first['snapshot_id'];

        $snapshot->markLaunchActive($actor, $request);
        $this->assertSame(ProductionDatasetSnapshot::LAUNCH_ACTIVE, $snapshot->status()['state']);

        $unitId = DB::table('units')->value('id');
        $this->assertNotNull($unitId);
        DB::table('units')->where('id', $unitId)->update(['name' => 'FOODEX launch trial mutation']);
        DB::table('units')->insert([
            'name' => 'FOODEX launch-only row',
            'code' => 'CERT-'.bin2hex(random_bytes(4)),
        ]);

        $duringLaunch = $this->canonicalFingerprint();
        $this->assertNotSame($before['sha256'], $duringLaunch['sha256']);

        $restored = $snapshot->restore($actor, $request);
        $this->assertSame(ProductionDatasetSnapshot::LEGACY_RESTORED, $restored['state']);

        $afterRestore = $this->canonicalFingerprint();
        $this->assertSame($before['counts'], $afterRestore['counts']);
        $this->assertSame($before['sha256'], $afterRestore['sha256']);

        $second = $snapshot->create($actor, $request);
        $this->assertTrue($second['verified']);
        $this->assertSame(ProductionDatasetSnapshot::SNAPSHOT_READY, $second['state']);
        $this->assertNotSame($firstSnapshotId, $second['snapshot_id']);

        $snapshot->markLaunchActive($actor, $request);
        $this->assertSame(ProductionDatasetSnapshot::LAUNCH_ACTIVE, $snapshot->status()['state']);

        $secondRestore = $snapshot->restore($actor, $request);
        $this->assertSame(ProductionDatasetSnapshot::LEGACY_RESTORED, $secondRestore['state']);

        $afterSecondRestore = $this->canonicalFingerprint();
        $this->assertSame($before['counts'], $afterSecondRestore['counts']);
        $this->assertSame($before['sha256'], $afterSecondRestore['sha256']);

        $directory = storage_path('app/certification');
        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }

        file_put_contents(
            $directory.'/FOODEX_1_0_77_FINAL_CERTIFICATION.json',
            json_encode([
                'schema_version' => 1,
                'release' => '1.0.77',
                'source_sha' => config('foodex.release_source_sha'),
                'run_number' => (string) config('foodex.release_run_number'),
                'canonical_tables' => array_keys($before['counts']),
                'before' => $before,
                'launch_trial' => $duringLaunch,
                'after_restore' => $afterRestore,
                'after_relaunch_restore' => $afterSecondRestore,
                'first_snapshot_id' => $firstSnapshotId,
                'relaunch_snapshot_id' => $second['snapshot_id'],
                'first_restore_state' => $restored['state'],
                'relaunch_state' => ProductionDatasetSnapshot::LAUNCH_ACTIVE,
                'second_restore_state' => $secondRestore['state'],
                'exact_restore_verified' => true,
                'relaunch_verified' => true,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
        );
    }

    /** @return array{counts:array<string,int>,sha256:string} */
    private function canonicalFingerprint(): array
    {
        $payload = [];
        $counts = [];

        foreach (FoodexResetService::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = DB::table($table)
                ->orderBy('id')
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all();

            $counts[$table] = count($rows);
            $payload[$table] = $rows;
        }

        return [
            'counts' => $counts,
            'sha256' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
    }
}
