<?php

namespace Tests\Feature\Performance;

use Tests\TestCase;

final class Wave0PerformanceEvidenceContractTest extends TestCase
{
    public function test_wave0_consumes_the_single_enterprise_performance_authority(): void
    {
        $authorities = [
            'docs/quality/CPANEL_PERFORMANCE_CONTRACT.md',
            'docs/quality/ENTERPRISE_PERFORMANCE_EVIDENCE.schema.json',
            'scripts/enterprise-performance-compare.py',
            'scripts/enterprise-performance-report.py',
            'scripts/enterprise-disabled-module-overhead.py',
            '.github/workflows/enterprise-performance-gate.yml',
            'backend/tests/Deployment/EnterpriseCpanelSmokeTest.php',
            'backend/tests/Feature/Performance/CurrentMainPerformanceBaselineTest.php',
        ];

        foreach ($authorities as $path) {
            $this->assertFileExists(base_path('../'.$path), "Missing shared Enterprise performance authority: {$path}");
        }
    }

    public function test_wave0_hot_paths_have_bounded_repository_owned_evidence(): void
    {
        $evidence = [
            'disabled_module' => 'docs/testing/ENTERPRISE_DISABLED_MODULE_OVERHEAD.md',
            'registry' => 'backend/tests/Feature/EnterpriseModuleRegistryTest.php',
            'entitlements' => 'backend/tests/Feature/EnterpriseModuleEntitlementTest.php',
            'outbox' => 'backend/tests/Feature/EnterpriseTransactionalOutboxTest.php',
            'workflow' => 'backend/tests/Feature/EnterpriseWorkflowEngineTest.php',
            'health' => 'backend/tests/Feature/EnterpriseModuleHealthTest.php',
            'inspector' => 'backend/tests/Feature/SystemInspectorTest.php',
            'scheduler' => 'backend/tests/Feature/Enterprise/EnterpriseSchedulerCompatibilityTest.php',
        ];

        foreach ($evidence as $area => $path) {
            $this->assertFileExists(base_path($path), "Missing Wave 0 performance evidence owner [{$area}]: {$path}");
        }
    }

    public function test_cpanel_reference_certification_is_not_fabricated_by_wave0(): void
    {
        $workflow = file_get_contents(base_path('../.github/workflows/enterprise-performance-gate.yml'));

        $this->assertIsString($workflow);
        $this->assertStringContainsString('FOODEX_PERF_MODE: candidate', $workflow);
        $this->assertStringContainsString('Exact-head smoke comparison', $workflow);

        $baselineTest = file_get_contents(base_path('tests/Feature/Performance/CurrentMainPerformanceBaselineTest.php'));

        $this->assertIsString($baselineTest);
        $this->assertStringContainsString("'cpanel_reference' => false", $baselineTest);
        $this->assertStringContainsString('representative heavy certification remains mandatory', $baselineTest);
    }
}
