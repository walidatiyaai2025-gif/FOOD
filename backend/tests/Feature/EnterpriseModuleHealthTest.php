<?php

namespace Tests\Feature;

use App\Enterprise\Health\ModuleHealthCheck;
use App\Enterprise\Health\ModuleHealthProbe;
use App\Enterprise\Health\ModuleHealthService;
use App\Enterprise\Health\ModuleHealthStatus;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class EnterpriseModuleHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CountingHealthyProbe::$calls = 0;
        FailingProbe::$calls = 0;
        StaleProbe::$calls = 0;
    }

    public function test_disabled_module_returns_without_resolving_or_running_probes(): void
    {
        config()->set('enterprise_module_health.probes.sample_optional', [
            CountingHealthyProbe::class,
        ]);
        app(ModuleRegistry::class)->syncManifest('sample_optional');

        $report = app(ModuleHealthService::class)->report('sample_optional');

        $this->assertSame(ModuleHealthStatus::DISABLED, $report->status);
        $this->assertSame(0, CountingHealthyProbe::$calls);
        $this->assertSame([], $report->checks);
    }

    public function test_not_installed_module_returns_without_provider_or_probe_work(): void
    {
        config()->set('enterprise_module_health.probes.uninstalled_optional', [
            FailingProbe::class,
        ]);

        $report = app(ModuleHealthService::class)->report('uninstalled_optional');

        $this->assertSame(ModuleHealthStatus::NOT_INSTALLED, $report->status);
        $this->assertSame(0, FailingProbe::$calls);
    }

    public function test_enabled_module_probe_failure_is_isolated_as_error_report(): void
    {
        config()->set('enterprise_module_health.probes.sample_optional', [
            CountingHealthyProbe::class,
            FailingProbe::class,
        ]);
        $this->enableSampleModule();

        $report = app(ModuleHealthService::class)->report('sample_optional');

        $this->assertSame(ModuleHealthStatus::ERROR, $report->status);
        $this->assertCount(2, $report->checks);
        $this->assertSame(1, CountingHealthyProbe::$calls);
        $this->assertSame(1, FailingProbe::$calls);
        $this->assertSame('Health probe failed.', $report->checks[1]->message);
        $this->assertSame(RuntimeException::class, $report->checks[1]->metadata['exception_class']);
        $this->assertStringNotContainsString('secret-provider-message', (string) $report->checks[1]->message);
    }

    public function test_stale_probe_result_degrades_health_without_throwing(): void
    {
        config()->set('enterprise_module_health.probes.sample_optional', [
            StaleProbe::class,
        ]);
        $this->enableSampleModule();

        $report = app(ModuleHealthService::class)->report('sample_optional');

        $this->assertSame(ModuleHealthStatus::DEGRADED, $report->status);
        $this->assertTrue($report->checks[0]->isStale());
    }

    public function test_timeout_has_explicit_degraded_representation(): void
    {
        $check = ModuleHealthCheck::timeout('provider', ['timeout_ms' => 250]);

        $this->assertSame(ModuleHealthStatus::DEGRADED, $check->status);
        $this->assertTrue((bool) $check->metadata['timed_out']);
        $this->assertSame(250, $check->metadata['timeout_ms']);
    }

    public function test_maintenance_and_error_lifecycle_states_do_not_run_probes(): void
    {
        config()->set('enterprise_module_health.probes.sample_optional', [
            CountingHealthyProbe::class,
        ]);
        $manager = app(ModuleLifecycleManager::class);
        $manager->transition('sample_optional', ModuleState::ENABLING);
        $manager->transition('sample_optional', ModuleState::ENABLED);
        $manager->transition('sample_optional', ModuleState::MAINTENANCE);

        $maintenance = app(ModuleHealthService::class)->report('sample_optional');

        $this->assertSame(ModuleHealthStatus::MAINTENANCE, $maintenance->status);
        $this->assertSame(0, CountingHealthyProbe::$calls);
    }

    public function test_request_scoped_memo_prevents_duplicate_probe_work(): void
    {
        config()->set('enterprise_module_health.probes.sample_optional', [
            CountingHealthyProbe::class,
        ]);
        $this->enableSampleModule();

        $health = app(ModuleHealthService::class);
        $first = $health->report('sample_optional');
        $second = $health->report('sample_optional');

        $this->assertSame(ModuleHealthStatus::HEALTHY, $first->status);
        $this->assertSame($first, $second);
        $this->assertSame(1, CountingHealthyProbe::$calls);
    }

    private function enableSampleModule(): void
    {
        $manager = app(ModuleLifecycleManager::class);
        $manager->transition('sample_optional', ModuleState::ENABLING);
        $manager->transition('sample_optional', ModuleState::ENABLED);
    }
}

final class CountingHealthyProbe implements ModuleHealthProbe
{
    public static int $calls = 0;

    public function check(): ModuleHealthCheck
    {
        self::$calls++;

        return ModuleHealthCheck::healthy('database');
    }
}

final class FailingProbe implements ModuleHealthProbe
{
    public static int $calls = 0;

    public function check(): ModuleHealthCheck
    {
        self::$calls++;

        throw new RuntimeException('secret-provider-message');
    }
}

final class StaleProbe implements ModuleHealthProbe
{
    public static int $calls = 0;

    public function check(): ModuleHealthCheck
    {
        self::$calls++;

        $checkedAt = CarbonImmutable::now('UTC')->subMinutes(5);

        return ModuleHealthCheck::healthy(
            key: 'scheduler',
            checkedAt: $checkedAt,
            staleAt: $checkedAt->addMinute(),
        );
    }
}
