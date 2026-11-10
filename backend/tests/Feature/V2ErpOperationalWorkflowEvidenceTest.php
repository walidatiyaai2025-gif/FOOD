<?php

namespace Tests\Feature;

use App\Enterprise\Health\ModuleHealthCheck;
use App\Enterprise\Health\ModuleHealthProbe;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleOperationalReadiness;
use App\Enterprise\Modules\ModuleState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P135: operational certification is a reviewed, version-bound workflow
 * proof, never an implication of ENABLED, entitlement or healthy probes.
 */
final class V2ErpOperationalWorkflowEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_registered_erp_module_has_an_explicit_workflow_matrix_without_automatic_approval(): void
    {
        $modules = (array) config('enterprise_modules.modules', []);
        $requirements = (array) config('enterprise_module_readiness.required_workflows', []);

        foreach ($modules as $code => $manifest) {
            if (! in_array($manifest['category'] ?? null, ['finance', 'operations', 'analytics'], true)) {
                continue;
            }

            $this->assertArrayHasKey($code, $requirements);
            $this->assertNotEmpty($requirements[$code]);
            $this->assertContains('audit', $requirements[$code]);
        }

        $this->assertSame([], config('enterprise_module_readiness.workflow_attestations'));
        $this->assertSame([], config('enterprise_module_readiness.certified_versions'));
    }

    public function test_new_erp_module_or_missing_workflow_stage_fails_closed_in_arabic_and_english(): void
    {
        $this->enableSample();
        config()->set('enterprise_module_readiness.certified_versions.sample_optional', 1);
        config()->set('enterprise_modules.modules.sample_optional.category', 'operations');
        $readiness = app(ModuleOperationalReadiness::class);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            // A newly registered operational module has no approved matrix.
            $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

            config()->set('enterprise_module_readiness.required_workflows.sample_optional', ['create', 'approve', 'audit']);
            $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

            // A missing workflow stage cannot be silently omitted from proof.
            $evidence = $this->evidence();
            unset($evidence['checks']['audit']);
            config()->set('enterprise_module_readiness.workflow_attestations.sample_optional', $evidence);
            $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
        }
    }

    public function test_exact_manifest_and_reviewed_complete_workflow_proof_are_required(): void
    {
        $this->enableSample();
        $this->requireSampleWorkflow();
        $readiness = app(ModuleOperationalReadiness::class);
        $good = $this->evidence();

        config()->set('enterprise_module_readiness.workflow_attestations.sample_optional', $good);
        $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

        foreach ([
            ['version' => 2],
            ['head_sha' => 'not-a-git-sha'],
            ['review_issue' => 0],
            ['ci_run' => 'https://example.invalid/green'],
            ['checks' => ['create' => ['result' => 'passed', 'test' => 'only-one-step']]],
        ] as $invalidFields) {
            config()->set('enterprise_module_readiness.workflow_attestations.sample_optional', [
                ...$good,
                ...$invalidFields,
            ]);
            $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
        }

        // Reapproval of the previous module version cannot authorize a new one.
        config()->set('enterprise_module_readiness.workflow_attestations.sample_optional', $good);
        config()->set('enterprise_module_readiness.certified_versions.sample_optional', 2);
        $this->assertSame('uncertified', $readiness->status('sample_optional', 2, ModuleState::ENABLED));
    }

    public function test_operational_proof_never_bypasses_disabled_lifecycle_or_failing_health(): void
    {
        config()->set('enterprise_module_readiness.certified_versions.sample_optional', 1);
        config()->set('enterprise_module_readiness.required_workflows.sample_optional', ['create', 'approve', 'audit']);
        config()->set('enterprise_module_readiness.workflow_attestations.sample_optional', $this->evidence());

        $readiness = app(ModuleOperationalReadiness::class);
        $this->assertSame('inactive', $readiness->status('sample_optional', 1, ModuleState::DISABLED));

        $this->enableSample();
        config()->set('enterprise_module_health.probes.sample_optional', [V2FailedWorkflowProbe::class]);

        $this->assertSame('unhealthy', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
    }

    private function requireSampleWorkflow(): void
    {
        config()->set('enterprise_module_readiness.certified_versions.sample_optional', 1);
        config()->set('enterprise_module_readiness.required_workflows.sample_optional', ['create', 'approve', 'audit']);
    }

    private function enableSample(): void
    {
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('sample_optional', ModuleState::ENABLING);
        $lifecycle->transition('sample_optional', ModuleState::ENABLED);
    }

    /** @return array<string, mixed> */
    private function evidence(): array
    {
        return [
            'version' => 1,
            'head_sha' => str_repeat('a', 40),
            'review_issue' => 2503,
            'ci_run' => 'https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/1',
            'checks' => [
                'create' => ['result' => 'passed', 'test' => 'Synthetic::create'],
                'approve' => ['result' => 'passed', 'test' => 'Synthetic::approve'],
                'audit' => ['result' => 'passed', 'test' => 'Synthetic::audit'],
            ],
        ];
    }
}

final class V2FailedWorkflowProbe implements ModuleHealthProbe
{
    public function check(): ModuleHealthCheck
    {
        return ModuleHealthCheck::error('runtime');
    }
}
