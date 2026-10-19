<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AssistantProductionAcceptanceContractTest extends TestCase
{
    public function test_acceptance_matrix_is_complete_and_repository_owned_gates_pass(): void
    {
        $path = base_path('../docs/execution/FOODEX_AI_PRODUCTION_ACCEPTANCE_MATRIX.json');
        $this->assertFileExists($path);

        $matrix = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $matrix['schema_version']);
        $this->assertSame('AI-PROGRAM', $matrix['program']);
        $this->assertSame(2232, $matrix['issue']);
        $this->assertSame([2224, 2225, 2226, 2228, 2229, 2230, 2231], $matrix['dependencies']);

        $gates = collect($matrix['gates']);
        $this->assertSame($gates->count(), $gates->pluck('id')->unique()->count());

        $repositoryOwnedGateIds = [
            'dependencies',
            'wholesale-owner-journey',
            'retailer-manager-journey',
            'platform-admin-journey',
            'unauthorized-user-journey',
            'approval-actions',
            'provider-real-behavior',
            'security-regression',
            'performance-resilience',
            'upgrade-install',
            'disabled-core',
            'rollback-kill-switch',
            'observability',
            'pilot-rollout',
        ];

        foreach ([...$repositoryOwnedGateIds, 'external-production-record'] as $gateId) {
            $this->assertTrue($gates->contains('id', $gateId), "Missing production gate {$gateId}.");
        }

        foreach ($gates as $gate) {
            $this->assertContains($gate['status'], $matrix['allowed_statuses']);
            $this->assertNotSame('', trim((string) $gate['requirement']));
            $this->assertIsArray($gate['evidence']);
        }

        foreach ($repositoryOwnedGateIds as $gateId) {
            $gate = $gates->firstWhere('id', $gateId);
            $this->assertSame('PASS', $gate['status'], "{$gateId} must PASS before production acceptance can merge.");
            $this->assertNotEmpty($gate['evidence'], "{$gateId} cannot PASS without evidence.");
        }

        $external = $gates->firstWhere('id', 'external-production-record');
        $this->assertSame('EXTERNAL_REQUIRED', $external['status']);
        $this->assertNotEmpty($external['evidence']);
    }

    public function test_source_defaults_keep_ai_fail_closed_until_explicit_rollout(): void
    {
        $config = File::get(config_path('assistant.php'));

        $this->assertStringContainsString("env('ASSISTANT_ENABLED', false)", $config);
        $this->assertStringContainsString("env('ASSISTANT_READ_ONLY', true)", $config);
        $this->assertStringContainsString("env('ASSISTANT_AI_ENABLED', false)", $config);
    }

    public function test_acceptance_and_runbook_forbid_fabricated_production_evidence(): void
    {
        $acceptance = File::get(base_path('../docs/testing/FOODEX_AI_PRODUCTION_ACCEPTANCE.md'));
        $runbook = File::get(base_path('../docs/operations/FOODEX_AI_PRODUCTION_RUNBOOK.md'));

        foreach ([
            'Wholesale owner',
            'Retailer manager',
            'Platform admin',
            'Unauthorized user',
            'SELL_IN',
            'SELL_OUT',
        ] as $requiredText) {
            $this->assertStringContainsString($requiredText, $acceptance);
        }

        foreach ([
            'ASSISTANT_ENABLED=false',
            'ASSISTANT_AI_ENABLED=false',
            'ASSISTANT_AI_PROVIDER_ENABLED=false',
            'ASSISTANT_AI_TOOLS_ENABLED=false',
            'ASSISTANT_AI_WRITE_ACTIONS_ENABLED=false',
        ] as $killSwitch) {
            $this->assertStringContainsString($killSwitch, $runbook);
        }

        $this->assertStringContainsString('Do not invent', $runbook);
        $this->assertStringContainsString('EXTERNAL_REQUIRED', $acceptance);
    }
}
