<?php

namespace Tests\Feature;

use App\Enterprise\Health\ModuleHealthCheck;
use App\Enterprise\Health\ModuleHealthProbe;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleOperationalReadiness;
use App\Enterprise\Modules\ModuleState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EnterpriseModuleOperationalReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_without_version_certification_is_not_operationally_ready(): void
    {
        $readiness = app(ModuleOperationalReadiness::class);
        $this->assertSame('inactive', $readiness->status('sample_optional', 1, ModuleState::DISABLED));

        $this->enableSampleModule();
        $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

        config()->set('enterprise_module_readiness.certified_versions.sample_optional', 2);
        $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

        config()->set('enterprise_module_readiness.certified_versions.sample_optional', 1);
        $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
    }

    public function test_certification_does_not_override_failed_runtime_health(): void
    {
        config()->set('enterprise_module_readiness.certified_versions.sample_optional', 1);
        config()->set('enterprise_module_health.probes.sample_optional', [ReadinessFailingProbe::class]);
        $this->enableSampleModule();

        $this->assertSame(
            'unhealthy',
            app(ModuleOperationalReadiness::class)->status('sample_optional', 1, ModuleState::ENABLED),
        );
    }

    private function enableSampleModule(): void
    {
        $manager = app(ModuleLifecycleManager::class);
        $manager->transition('sample_optional', ModuleState::ENABLING);
        $manager->transition('sample_optional', ModuleState::ENABLED);
    }
}

final class ReadinessFailingProbe implements ModuleHealthProbe
{
    public function check(): ModuleHealthCheck
    {
        return ModuleHealthCheck::error('runtime');
    }
}
