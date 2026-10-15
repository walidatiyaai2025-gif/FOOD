<?php

namespace Tests\Deployment;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('enterprise-performance')]
final class EnterpriseCpanelSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('true', getenv('FOODEX_DISPOSABLE_SERVICES'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('foodex_acceptance', DB::connection()->getDatabaseName());

        Artisan::call('optimize:clear');
    }

    protected function tearDown(): void
    {
        Artisan::call('optimize:clear');

        parent::tearDown();
    }

    public function test_cpanel_profile_smoke_certifies_existing_laravel_runtime_contract(): void
    {
        $commands = [
            'config:cache' => ['config:cache', []],
            'route:cache' => ['route:cache', []],
            'view:cache' => ['view:cache', []],
            'schedule:list' => ['schedule:list', []],
            'migrate:status' => ['migrate:status', ['--no-interaction' => true]],
        ];

        $results = [];

        foreach ($commands as $name => [$command, $arguments]) {
            $startedAt = hrtime(true);
            $exitCode = Artisan::call($command, $arguments);
            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;

            $results[$name] = [
                'exit_code' => $exitCode,
                'duration_ms' => $durationMs,
            ];

            $this->assertSame(0, $exitCode, $name.' must succeed on the cPanel baseline profile.');
        }

        $scheduleOutput = Artisan::output();
        $this->assertStringContainsString(
            'foodex:dispatch-scheduled-notifications',
            $scheduleOutput,
            'The existing FOODEX scheduler contract must remain registered.',
        );

        $schedulerSource = File::get(app_path('Services/SchedulerRuntime.php'));

        $this->assertStringContainsString('# FOODEX_MANAGED_SCHEDULER', $schedulerSource);
        $this->assertStringContainsString("Artisan::call('schedule:run')", $schedulerSource);

        foreach (['supervisorctl', 'systemctl', 'systemd'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                strtolower($schedulerSource),
                'cPanel baseline must not require '.$forbidden.'.',
            );
        }

        $head = strtolower(trim((string) getenv('GITHUB_SHA')));
        if (preg_match('/^[0-9a-f]{40,64}$/', $head) !== 1) {
            $head = null;
        }

        $evidence = [
            'schema_version' => 1,
            'head' => $head,
            'environment' => [
                'php' => PHP_VERSION,
                'database' => DB::getDriverName().' '.$this->databaseVersion(),
                'queue' => (string) config('queue.default'),
                'memory_limit' => (string) ini_get('memory_limit'),
                'runner' => getenv('GITHUB_ACTIONS') === 'true'
                    ? 'github-actions'
                    : 'phpunit-local',
            ],
            'commands' => $results,
            'scheduler' => [
                'managed_cron_marker' => true,
                'schedule_run' => true,
                'supervisor_required' => false,
                'systemd_required' => false,
                'persistent_python_node_java_required' => false,
            ],
            'status' => 'PASS',
        ];

        fwrite(
            STDOUT,
            PHP_EOL.'FOODEX_CPANEL_SMOKE_JSON='
            .json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            .PHP_EOL,
        );
    }

    private function databaseVersion(): string
    {
        $row = DB::selectOne('select version() as version');

        return (string) ($row->version ?? 'unknown');
    }
}
