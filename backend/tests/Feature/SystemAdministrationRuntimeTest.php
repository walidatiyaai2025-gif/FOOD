<?php

namespace Tests\Feature;

use App\Domain\Updater\UpdatePackageManifest;
use App\Domain\Updater\UpdateRuntime;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SystemAdministrationRuntime;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SystemAdministrationRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private string $backupRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupRoot = storage_path('framework/testing/system-runtime-'.bin2hex(random_bytes(5)));
        File::ensureDirectoryExists($this->backupRoot);
        config()->set('foodex.update_backup_path', $this->backupRoot);
        config()->set('foodex.update_backup_retention_days', 30);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupRoot);

        parent::tearDown();
    }

    public function test_manual_backup_and_restore_are_audited_and_run_verified_recovery_pipeline(): void
    {
        $runtime = new RecoveryTestRuntime($this->backupRoot);
        $service = new SystemAdministrationRuntime($runtime, app(AuditLogger::class));
        $actor = User::query()->create([
            'name' => 'Recovery Owner',
            'email' => 'recovery-owner@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $request = Request::create('/admin/settings/system-update/backups', 'POST');
        $request->setUserResolver(static fn (): User => $actor);

        $backup = $service->createDatabaseBackup(
            $actor,
            $request,
            'Before infrastructure maintenance',
            '1.2.3',
        );

        $this->assertSame('database', $backup['kind']);
        $this->assertSame('available', $backup['status']);
        $this->assertSame('manual_backup', $backup['origin']);
        $this->assertSame('1.2.3', $backup['source_version']);
        $this->assertFileExists($this->backupRoot.'/'.$backup['reference'].'/foodex-backup.json');
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'system.backup.created',
            'user_id' => $actor->id,
        ]);

        $service->restoreDatabaseBackup(
            $actor,
            $request,
            (string) $backup['reference'],
            'Validated recovery exercise',
            (string) $backup['reference'],
            null,
            '1.2.3',
        );

        $this->assertSame([
            'backup_database',
            'verify_database_backup',
            'enter_maintenance_mode',
            'rollback_database',
            'run_migrations',
            'rebuild_caches',
            'health_check',
            'exit_maintenance_mode',
        ], $runtime->calls);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'system.restore.completed',
            'user_id' => $actor->id,
        ]);
    }

    public function test_failed_job_diagnostics_never_render_raw_exception_or_payload(): void
    {
        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', static function (Blueprint $table): void {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->nullable();
            });
        }

        DB::table('failed_jobs')->insert([
            'uuid' => '00000000-0000-4000-8000-000000000001',
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{"token":"super-secret-token"}',
            'exception' => 'RuntimeException: provider password=super-secret-password',
            'failed_at' => now(),
        ]);

        $service = new SystemAdministrationRuntime(
            new RecoveryTestRuntime($this->backupRoot),
            app(AuditLogger::class),
        );

        $snapshot = $service->healthSnapshot();
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $snapshot['queue']['failed_jobs']);
        $this->assertSame(1, $snapshot['queue']['failed_jobs_24h']);
        $this->assertCount(1, $snapshot['queue']['recent_failures']);
        $this->assertNotEmpty($snapshot['queue']['recent_failures'][0]['failure_fingerprint']);
        $this->assertStringNotContainsString('super-secret-token', $encoded);
        $this->assertStringNotContainsString('super-secret-password', $encoded);
        $this->assertStringNotContainsString('RuntimeException', $encoded);
    }
}

final class RecoveryTestRuntime implements UpdateRuntime
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly string $backupRoot) {}

    public function preflight(UpdatePackageManifest $manifest, string $packagePath): void
    {
        $this->calls[] = 'preflight';
    }

    public function backupFiles(string $packagePath): string
    {
        $this->calls[] = 'backup_files';

        return $this->backupRoot.'/unused-files';
    }

    public function backupDatabase(bool $required): ?string
    {
        $this->calls[] = 'backup_database';
        if (! $required) {
            return null;
        }

        $path = $this->backupRoot.'/20261017-120000-database-aaaaaaaaaa';
        File::ensureDirectoryExists($path);
        $snapshot = $path.'/database.sqlite';
        file_put_contents($snapshot, 'verified-test-snapshot');
        file_put_contents($path.'/database.json', json_encode([
            'driver' => 'sqlite',
            'snapshot' => $snapshot,
        ], JSON_THROW_ON_ERROR));

        return $path;
    }

    public function enterMaintenanceMode(): void
    {
        $this->calls[] = 'enter_maintenance_mode';
    }

    public function extractRelease(string $packagePath): void
    {
        $this->calls[] = 'extract_release';
    }

    public function runMigrations(): void
    {
        $this->calls[] = 'run_migrations';
    }

    public function rebuildCaches(): void
    {
        $this->calls[] = 'rebuild_caches';
    }

    public function healthCheck(): void
    {
        $this->calls[] = 'health_check';
    }

    public function rollbackFiles(string $backupReference): void
    {
        $this->calls[] = 'rollback_files';
    }

    public function verifyDatabaseBackup(string $backupReference): void
    {
        $this->calls[] = 'verify_database_backup';
    }

    public function rollbackDatabase(string $backupReference): void
    {
        $this->calls[] = 'rollback_database';
    }

    public function exitMaintenanceMode(): void
    {
        $this->calls[] = 'exit_maintenance_mode';
    }
}
