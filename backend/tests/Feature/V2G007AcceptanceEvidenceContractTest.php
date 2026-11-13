<?php

namespace Tests\Feature;

use Tests\TestCase;

final class V2G007AcceptanceEvidenceContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function matrix(): array
    {
        $path = base_path('../docs/v2/V2_G007_ACCEPTANCE_MATRIX.json');
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_g007_source_and_required_exact_head_gates_are_explicit(): void
    {
        $matrix = $this->matrix();

        $this->assertSame(1, $matrix['schema_version']);
        $this->assertSame(2748, $matrix['issue_number']);
        $this->assertSame(2503, $matrix['parent_issue_number']);
        $this->assertSame('Foodex_V2', $matrix['integration_branch']);
        $this->assertSame(['G007', 'P109', 'P144'], $matrix['source_markers']);
        $this->assertFalse($matrix['main_integration_authorized']);
        $this->assertFalse($matrix['production_release_authorized']);

        foreach ([
            'Repository Policy',
            'FOODEX V2 Isolation Gate',
            'FOODEX Required CI Gate',
            'Laravel authorization / security tests',
            'Arabic / English localization',
            'Customer Flutter contract acceptance',
            'Driver Flutter contract acceptance',
            'Van Flutter contract acceptance',
        ] as $check) {
            $this->assertContains($check, $matrix['required_exact_head_checks']);
        }

        $keys = array_column($matrix['requirements'], 'key');
        $this->assertCount(count($keys), array_unique($keys));

        foreach ([
            'rbac_identity',
            'role_action_denies',
            'tenant_store_channel_isolation',
            'erp_module_readiness',
            'maker_checker_sod',
            'pricing_credit_profit_guard',
            'erp_e2e_operational_journey',
            'sidebar_authority',
            'sidebar_information_architecture',
            'localization_accessibility',
            'customer_runtime_compatibility',
            'driver_runtime_compatibility',
            'van_runtime_compatibility',
        ] as $required) {
            $this->assertContains($required, $keys);
        }
    }

    public function test_no_requirement_can_claim_verified_without_a_real_exact_head_and_artifact(): void
    {
        $matrix = $this->matrix();

        foreach ($matrix['requirements'] as $requirement) {
            $this->assertContains($requirement['evidence_status'], ['pending', 'verified']);
            $this->assertGreaterThan(0, $requirement['owner_issue']);
            $this->assertContains($requirement['source'], ['G007', 'P109', 'P144']);

            if ($requirement['evidence_status'] !== 'verified') {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/^[a-f0-9]{40}$/',
                (string) ($requirement['validated_head_sha'] ?? ''),
                "Verified G007 requirement [{$requirement['key']}] needs an exact source SHA.",
            );
            $this->assertNotEmpty(
                $requirement['artifact_urls'] ?? [],
                "Verified G007 requirement [{$requirement['key']}] needs traceable evidence.",
            );

            foreach ($requirement['artifact_urls'] as $url) {
                $this->assertStringStartsWith(
                    'https://github.com/walidatiyaai2025-gif/FOOD/',
                    $url,
                    "Evidence for [{$requirement['key']}] must refer to the audited repository.",
                );
            }
        }
    }

    public function test_final_acceptance_cannot_be_certified_while_any_app_or_gate_is_unverified(): void
    {
        $matrix = $this->matrix();

        foreach (['customer', 'driver', 'van'] as $app) {
            $this->assertArrayHasKey($app, $matrix['mobile_impacts']);
            $impact = $matrix['mobile_impacts'][$app];
            $this->assertContains(
                $impact['classification'],
                ['unverified', 'affected', 'not_affected'],
            );
            $this->assertGreaterThan(0, $impact['related_issue']);
        }

        if (! $matrix['accepted']) {
            $this->assertContains(
                'pending',
                array_column($matrix['requirements'], 'evidence_status'),
                'The manifest must disclose unmet acceptance rather than silently presenting a green gate.',
            );

            return;
        }

        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', (string) $matrix['acceptance_head_sha']);
        $this->assertNotEmpty($matrix['check_evidence']);
        $this->assertNotContains('pending', array_column($matrix['requirements'], 'evidence_status'));

        foreach ($matrix['requirements'] as $requirement) {
            $this->assertSame('verified', $requirement['evidence_status']);
            $this->assertSame($matrix['acceptance_head_sha'], $requirement['validated_head_sha']);
        }

        foreach (['customer', 'driver', 'van'] as $app) {
            $this->assertNotSame('unverified', $matrix['mobile_impacts'][$app]['classification']);
        }

        foreach ($matrix['required_exact_head_checks'] as $check) {
            $matches = array_values(array_filter(
                $matrix['check_evidence'],
                static fn (array $item): bool => ($item['check'] ?? null) === $check
                    && ($item['head_sha'] ?? null) === $matrix['acceptance_head_sha']
                    && ($item['conclusion'] ?? null) === 'success',
            ));
            $this->assertNotEmpty($matches, "Missing verified exact-head success evidence for [{$check}].");
        }
    }
}
