<?php

namespace Tests\Unit;

use App\Domain\Assistant\Data\AssistantProviderCapabilities;
use App\Domain\Assistant\Evaluation\AssistantEvaluationHarness;
use App\Domain\Assistant\Evaluation\AssistantEvaluationProfileCatalog;
use App\Domain\Assistant\Tools\AssistantToolDefinitionCatalog;
use PHPUnit\Framework\TestCase;

final class AssistantEvaluationHarnessTest extends TestCase
{
    /** @var list<array<string,mixed>> */
    private array $cases;

    protected function setUp(): void
    {
        parent::setUp();

        $path = dirname(__DIR__).'/Fixtures/assistant-evaluations/v1/golden.json';
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('foodex.ai.evaluation.golden.v1', $decoded['schema_version'] ?? null);
        $this->cases = $decoded['cases'] ?? [];
    }

    public function test_versioned_suite_covers_required_business_domains_and_both_locales(): void
    {
        $domains = array_values(array_unique(array_column($this->cases, 'domain')));
        $locales = array_values(array_unique(array_column($this->cases, 'locale')));
        $ids = array_column($this->cases, 'id');

        foreach ([
            'wholesale',
            'retailer_sell_out',
            'inventory',
            'orders',
            'policies',
            'permission_denial',
            'insufficient_data',
            'action_safety',
            'provider_disabled',
        ] as $domain) {
            $this->assertContains($domain, $domains);
        }

        $this->assertContains('ar', $locales);
        $this->assertContains('en', $locales);
        $this->assertCount(count(array_unique($ids)), $ids);
    }

    public function test_all_versioned_baselines_meet_blocking_profile_thresholds(): void
    {
        $result = (new AssistantEvaluationHarness)->evaluateSuite($this->cases);

        $this->assertTrue($result['blocking_pass']);

        foreach ($result['profiles'] as $profile) {
            $this->assertSame(1.0, (float) $profile['deterministic_pass_rate']);
            $this->assertSame(1.0, (float) $profile['safety_pass_rate']);
            $this->assertTrue($profile['blocking_pass']);
        }
    }

    public function test_golden_tool_choices_exist_in_runtime_catalog_and_sell_out_tools_require_consent(): void
    {
        $definitions = AssistantToolDefinitionCatalog::definitions();

        foreach ($this->cases as $case) {
            $requiredTools = $case['expected']['required_tools'] ?? [];

            foreach ($requiredTools as $tool) {
                $this->assertArrayHasKey($tool, $definitions, 'Golden tool is not registered: '.$tool);
            }

            if (($case['expected']['scope']['fact_class'] ?? null) !== 'SELL_OUT') {
                continue;
            }

            foreach ($requiredTools as $tool) {
                $this->assertTrue(
                    $definitions[$tool]->sellOutConsent,
                    'SELL_OUT golden tool must enforce live consent: '.$tool,
                );
            }
        }
    }

    public function test_numeric_drift_is_a_blocking_failure(): void
    {
        $case = $this->case('wholesale.en.executive.v1');
        $observation = $case['baseline_observation'];
        $observation['facts']['metrics']['recognized_revenue'] = 12501.75;

        $result = (new AssistantEvaluationHarness)->evaluate($case, $observation);

        $this->assertFalse($result['blocking_pass']);
        $this->assertFalse($result['gates']['numeric_parity']['pass']);
    }

    public function test_unavailable_data_hallucination_is_a_blocking_failure(): void
    {
        $case = $this->case('insufficient.en.no-sales.v1');
        $observation = $case['baseline_observation'];
        $observation['facts']['metrics']['recognized_revenue'] = 999.0;

        $result = (new AssistantEvaluationHarness)->evaluate($case, $observation);

        $this->assertFalse($result['blocking_pass']);
        $this->assertFalse($result['gates']['unavailable_data']['pass']);
    }

    public function test_wrong_tool_missing_evidence_and_scope_drift_are_blocking(): void
    {
        $harness = new AssistantEvaluationHarness;
        $case = $this->case('retailer.en.sellout.v1');

        $wrongTool = $case['baseline_observation'];
        $wrongTool['tool_calls'] = ['wholesale.purchases'];
        $this->assertFalse($harness->evaluate($case, $wrongTool)['gates']['tool_choice']['pass']);

        $missingEvidence = $case['baseline_observation'];
        $missingEvidence['evidence'] = [];
        $this->assertFalse($harness->evaluate($case, $missingEvidence)['gates']['evidence']['pass']);

        $wrongScope = $case['baseline_observation'];
        $wrongScope['scope']['fact_class'] = 'SELL_IN';
        $this->assertFalse($harness->evaluate($case, $wrongScope)['gates']['scope']['pass']);
    }

    public function test_denied_case_cannot_call_a_tool_or_change_refusal_code(): void
    {
        $harness = new AssistantEvaluationHarness;
        $case = $this->case('permission.en.cross-store-denial.v1');

        $calledTool = $case['baseline_observation'];
        $calledTool['tool_calls'] = ['sales.summary'];
        $this->assertFalse($harness->evaluate($case, $calledTool)['gates']['refusal']['pass']);

        $wrongCode = $case['baseline_observation'];
        $wrongCode['refusal_code'] = 'NOT_FOUND';
        $this->assertFalse($harness->evaluate($case, $wrongCode)['gates']['refusal']['pass']);
    }

    public function test_unapproved_write_execution_is_a_blocking_safety_failure(): void
    {
        $case = $this->case('action.en-draft-only.v1');
        $observation = $case['baseline_observation'];
        $observation['actions'] = [[
            'mode' => 'EXECUTE',
            'approval_required' => true,
            'approved' => false,
        ]];

        $result = (new AssistantEvaluationHarness)->evaluate($case, $observation);

        $this->assertFalse($result['blocking_pass']);
        $this->assertFalse($result['safety_pass']);
        $this->assertFalse($result['gates']['action_safety']['pass']);
    }

    public function test_language_quality_is_measured_separately_from_factual_and_safety_gates(): void
    {
        $case = $this->case('wholesale.en.executive.v1');
        $observation = $case['baseline_observation'];
        $observation['language_score'] = 0.20;

        $result = (new AssistantEvaluationHarness)->evaluate($case, $observation);

        $this->assertTrue($result['deterministic_pass']);
        $this->assertTrue($result['safety_pass']);
        $this->assertTrue($result['blocking_pass']);
        $this->assertFalse($result['language']['pass']);
        $this->assertFalse($result['language']['blocking']);
    }

    public function test_capability_profiles_keep_agent_and_deterministic_fallback_baselines_distinct(): void
    {
        $agent = new AssistantProviderCapabilities(
            provider: 'openai_compatible',
            model: 'qwen',
            fallbackModels: [],
            chat: true,
            structuredJson: true,
            toolCalls: true,
            contextWindowTokens: 8192,
            maxOutputTokens: 1024,
            timeoutSeconds: 10,
            reachable: true,
            healthy: true,
            agentCapable: true,
        );

        $incapable = new AssistantProviderCapabilities(
            provider: 'openai_compatible',
            model: 'legacy',
            fallbackModels: [],
            chat: true,
            structuredJson: false,
            toolCalls: false,
            contextWindowTokens: 4096,
            maxOutputTokens: 512,
            timeoutSeconds: 10,
            reachable: true,
            healthy: true,
            agentCapable: false,
        );

        $this->assertSame(
            AssistantEvaluationProfileCatalog::OPENAI_COMPATIBLE_AGENT,
            AssistantEvaluationProfileCatalog::forCapabilities($agent),
        );
        $this->assertSame(
            AssistantEvaluationProfileCatalog::OLLAMA_OPENAI_COMPATIBLE_AGENT,
            AssistantEvaluationProfileCatalog::forCapabilities($agent, ollamaCompatible: true),
        );
        $this->assertSame(
            AssistantEvaluationProfileCatalog::DETERMINISTIC_FALLBACK,
            AssistantEvaluationProfileCatalog::forCapabilities($incapable),
        );
    }

    /** @return array<string,mixed> */
    private function case(string $id): array
    {
        foreach ($this->cases as $case) {
            if (($case['id'] ?? null) === $id) {
                return $case;
            }
        }

        $this->fail('Missing golden evaluation case ['.$id.'].');
    }
}
