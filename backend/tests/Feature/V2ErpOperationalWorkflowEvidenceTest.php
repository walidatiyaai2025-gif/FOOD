<?php

namespace Tests\Feature;

use App\Enterprise\Health\ModuleHealthCheck;
use App\Enterprise\Health\ModuleHealthProbe;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleManifestCatalog;
use App\Enterprise\Modules\ModuleOperationalReadiness;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Modules\ModuleWorkflowAttestation;
use App\Models\EnterpriseModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SyntheticErpWorkflowEvidence;
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
        $this->assertSame([], config('enterprise_module_readiness.trusted_public_keys'));
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

    public function test_missing_or_malformed_manifest_category_cannot_bypass_erp_workflow_gate(): void
    {
        $this->enableSample();
        config()->set('enterprise_module_readiness.certified_versions.sample_optional', 1);
        $readiness = app(ModuleOperationalReadiness::class);

        // A valid non-ERP module retains its independent health/version contract.
        $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

        // No workflow matrix exists for this optional module. A missing category
        // must not let a future ERP manifest be mistaken for a certified optional.
        foreach ([null, '', '   ', [], 'unreviewed_domain'] as $invalidCategory) {
            config()->set('enterprise_modules.modules.sample_optional.category', $invalidCategory);

            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
            }
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
            ['manifest_sha256' => str_repeat('0', 64)],
            ['manifest_sha256' => 'not-a-manifest-hash'],
            ['manifest_sha256' => []],
            ['review_issue' => 0],
            ['ci_run' => 'https://example.invalid/green'],
            ['key_id' => 'untrusted'],
            ['signature' => base64_encode(str_repeat('x', 64))],
            ['checks' => [
                'create' => ['result' => 'passed', 'test' => 'Forged::create'],
                'approve' => ['result' => 'passed', 'test' => 'Synthetic::approve'],
                'audit' => ['result' => 'passed', 'test' => 'Synthetic::audit'],
            ]],
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

    public function test_signed_manifest_digest_fails_closed_when_dependencies_or_scope_change_without_version_bump(): void
    {
        $this->enableSample();
        $this->requireSampleWorkflow();
        $readiness = app(ModuleOperationalReadiness::class);
        $signed = $this->evidence();
        $manifest = app(ModuleManifestCatalog::class)->require('sample_optional');
        $digest = ModuleWorkflowAttestation::manifestSha256($manifest);

        $this->assertIsString($digest);
        $this->assertSame(64, strlen($digest));
        $this->assertSame($digest, $signed['manifest_sha256']);
        $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

        // A manifest version and cryptographically valid original signature
        // cannot approve changed hard dependencies, required core, permissions,
        // capabilities or health checks without a new reviewed attestation.
        foreach ([
            'hard_dependencies' => ['finance'],
            'optional_integrations' => ['finance'],
            'required_core' => ['identity', 'tenant_context', 'audit', 'catalog'],
            'capabilities' => ['unreviewed.sensitive.action'],
            'permissions' => ['finance.post'],
            'health_checks' => ['provider.runtime'],
            'navigation' => ['unreviewed' => ['route' => 'admin.unreviewed']],
        ] as $attribute => $unreviewedDefinition) {
            $original = config('enterprise_modules.modules.sample_optional.'.$attribute);
            config()->set('enterprise_modules.modules.sample_optional.'.$attribute, $unreviewedDefinition);

            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $this->assertSame(
                    'uncertified',
                    $readiness->status('sample_optional', 1, ModuleState::ENABLED),
                    $attribute.' cannot reuse a signature for an older manifest.',
                );
            }

            config()->set('enterprise_modules.modules.sample_optional.'.$attribute, $original);
            $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
        }

        // Clearing the trusted key or approval maps must still deny a signed
        // manifest even when the original unmodified manifest is restored.
        config()->set('enterprise_module_readiness.trusted_public_keys', []);
        $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
        $this->assertSame([], config('enterprise_module_readiness.certified_versions.procurement', []));
    }

    public function test_signed_proof_rejects_stale_installed_manifest_version_or_contract_in_arabic_and_english(): void
    {
        $this->enableSample();
        $this->requireSampleWorkflow();
        $this->evidence();
        $readiness = app(ModuleOperationalReadiness::class);
        $registration = EnterpriseModule::query()->where('code', 'sample_optional')->firstOrFail();
        $original = $registration->manifest;

        $this->assertIsArray($original);
        $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

        $registration->forceFill(['manifest_version' => 2])->save();
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
        }
        $registration->forceFill(['manifest_version' => 1])->save();

        // The stored manifest itself must not lag a same-version source
        // update or replace a reviewed permission/required-core contract.
        $registration->forceFill([
            'manifest' => [...$original, 'permissions' => ['unreviewed.manage']],
        ])->save();
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
        }

        $registration->forceFill(['manifest' => $original])->save();
        $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

        // Neither the installed nor source manifest can authorize an ERP
        // module when true production certification maps are empty.
        config()->set('enterprise_module_readiness.certified_versions', []);
        $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
    }

    public function test_untrusted_or_replayed_signature_never_turns_on_an_erp_module(): void
    {
        $this->enableSample();
        $this->requireSampleWorkflow();
        $readiness = app(ModuleOperationalReadiness::class);
        $signed = $this->evidence();
        $trustedKeys = config('enterprise_module_readiness.trusted_public_keys');

        $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

        // A full-looking SHA, trusted URL and named passing tests are not proof.
        config()->set('enterprise_module_readiness.trusted_public_keys', []);
        $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

        // Keep the same valid trust root and exact workflow steps to prove
        // that the module identity itself is signed and cannot be replayed.
        config()->set('enterprise_module_readiness.trusted_public_keys', $trustedKeys);
        config()->set('enterprise_module_readiness.required_workflows.finance', ['create', 'approve', 'audit']);
        config()->set('enterprise_module_readiness.certified_versions.finance', 1);
        config()->set('enterprise_module_readiness.workflow_attestations.finance', $signed);
        $this->assertSame('uncertified', $readiness->status('finance', 1, ModuleState::ENABLED));

        // Even an allowed key cannot approve new checks by changing the payload.
        config()->set('enterprise_module_readiness.workflow_attestations.sample_optional', [
            ...$signed,
            'head_sha' => str_repeat('b', 40),
        ]);
        $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
    }

    public function test_signed_workflow_evidence_cannot_relabel_a_trusted_key_or_tamper_with_valid_looking_review_metadata(): void
    {
        $this->enableSample();
        $this->requireSampleWorkflow();
        $signed = $this->evidence();
        $readiness = app(ModuleOperationalReadiness::class);

        // A second trusted ID with the SAME public key must not be able to
        // relabel an attestation signed for the original signer identity.
        $publicKey = config('enterprise_module_readiness.trusted_public_keys.synthetic-test');
        config()->set('enterprise_module_readiness.trusted_public_keys.synthetic-copy', $publicKey);

        $tamperedChecks = $signed['checks'];
        $tamperedChecks['create']['test'] = 'Forged::passing_test';

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            config()->set('enterprise_module_readiness.workflow_attestations.sample_optional', $signed);
            $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

            foreach ([
                ['key_id' => 'synthetic-copy'],
                ['review_issue' => 2859],
                ['ci_run' => 'https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/2'],
                ['checks' => $tamperedChecks],
            ] as $mutation) {
                config()->set('enterprise_module_readiness.workflow_attestations.sample_optional', [
                    ...$signed,
                    ...$mutation,
                ]);
                $this->assertSame('uncertified', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
            }
        }
    }

    public function test_signed_workflow_proof_rechecks_persisted_lifecycle_instead_of_trusting_stale_enabled_argument(): void
    {
        $this->enableSample();
        $this->requireSampleWorkflow();
        $this->evidence();

        $readiness = app(ModuleOperationalReadiness::class);
        $lifecycle = app(ModuleLifecycleManager::class);

        $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));

        // A caller may have read ENABLED immediately before another request
        // suspended/disabled the persisted module. The signed manifest and
        // trusted workflow proof alone must not let the stale caller say ready.
        foreach ([ModuleState::SUSPENDED, ModuleState::MAINTENANCE, ModuleState::DISABLED] as $state) {
            $lifecycle->transition('sample_optional', $state);

            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $this->assertSame(
                    'uncertified',
                    $readiness->status('sample_optional', 1, ModuleState::ENABLED),
                    $state->value.' persisted lifecycle must deny stale caller state.',
                );
                $this->assertSame('inactive', $readiness->status('sample_optional', 1, $state));
            }

            if ($state === ModuleState::DISABLED) {
                $lifecycle->transition('sample_optional', ModuleState::ENABLING);
            }

            $lifecycle->transition('sample_optional', ModuleState::ENABLED);
            $this->assertSame('ready', $readiness->status('sample_optional', 1, ModuleState::ENABLED));
        }

        // This test changes only disposable Laravel fixture module state.
        // No production certification, grants or trust keys are provisioned.
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
        SyntheticErpWorkflowEvidence::install();

        return (array) config('enterprise_module_readiness.workflow_attestations.sample_optional', []);
    }
}

final class V2FailedWorkflowProbe implements ModuleHealthProbe
{
    public function check(): ModuleHealthCheck
    {
        return ModuleHealthCheck::error('runtime');
    }
}
