<?php

namespace Tests\Feature;

use App\Domain\Updater\UpdateRuntime;
use App\Models\Role;
use App\Models\User;
use App\Services\ProductionSnapshotService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $backupDirectory;
    private string $metadataPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        $this->backupDirectory = sys_get_temp_dir().'/foodex-snapshot-'.bin2hex(random_bytes(4));
        mkdir($this->backupDirectory, 0700, true);
        $snapshot = $this->backupDirectory.'/database.sql';
        file_put_contents($snapshot, 'verified snapshot payload');
        file_put_contents($this->backupDirectory.'/database.json', json_encode([
            'driver' => 'mysql',
            'snapshot' => $snapshot,
        ], JSON_THROW_ON_ERROR));
        $this->metadataPath = $this->backupDirectory.'/private/snapshot.json';
        config(['foodex_snapshot.metadata_path' => $this->metadataPath]);
    }

    public function test_verified_snapshot_is_reused_and_restore_is_idempotent(): void
    {
        $owner = $this->owner();
        $original = $owner->name;
        $runtime = \Mockery::mock(UpdateRuntime::class);
        $runtime->shouldReceive('backupDatabase')->once()->with(true)->andReturn($this->backupDirectory);
        $runtime->shouldReceive('rollbackDatabase')->once()->with($this->backupDirectory)
            ->andReturnUsing(function () use ($owner, $original): void {
                DB::table('users')->where('id', $owner->id)->update(['name' => $original]);
            });
        $runtime->shouldReceive('enterMaintenanceMode')->once();
        $runtime->shouldReceive('exitMaintenanceMode')->once();
        $this->app->instance(UpdateRuntime::class, $runtime);

        $request = Request::create('/admin/settings/production-launch/snapshot', 'POST');
        $request->setUserResolver(static fn (): User => $owner);

        $service = app(ProductionSnapshotService::class);
        $first = $service->createVerified($owner, $request, ['source_commit' => str_repeat('a', 40)]);
        $second = $service->createVerified($owner, $request, ['source_commit' => str_repeat('a', 40)]);

        $this->assertSame($first['snapshot_id'], $second['snapshot_id']);
        $this->assertSame(ProductionSnapshotService::STATUS_READY, $first['status']);
        $this->assertTrue($first['verified']);

        DB::table('users')->where('id', $owner->id)->update(['name' => 'launch-mutated']);
        $restored = $service->restore($owner, $request);
        $again = $service->restore($owner, $request);

        $this->assertSame($original, $owner->fresh()->name);
        $this->assertSame(ProductionSnapshotService::STATUS_RESTORED, $restored['status']);
        $this->assertSame(1, $restored['restore_count']);
        $this->assertSame(1, $again['restore_count']);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'foodex.production_snapshot.created')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'foodex.production_snapshot.restored')->count());
    }

    public function test_corrupt_snapshot_fails_closed_before_restore(): void
    {
        $owner = $this->owner();
        $runtime = \Mockery::mock(UpdateRuntime::class);
        $runtime->shouldReceive('backupDatabase')->once()->andReturn($this->backupDirectory);
        $runtime->shouldNotReceive('rollbackDatabase');
        $this->app->instance(UpdateRuntime::class, $runtime);

        $request = Request::create('/admin/settings/production-launch/snapshot', 'POST');
        $request->setUserResolver(static fn (): User => $owner);
        $service = app(ProductionSnapshotService::class);
        $service->createVerified($owner, $request);
        file_put_contents($this->backupDirectory.'/database.sql', 'tampered');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->restore($owner, $request);
    }

    private function owner(): User
    {
        $user = User::query()->create([
            'name' => 'Snapshot Owner',
            'email' => 'snapshot-'.uniqid().'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
