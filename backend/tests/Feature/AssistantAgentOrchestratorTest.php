<?php

namespace Tests\Feature;

use App\Domain\Assistant\Agent\AssistantAgentOrchestrator;
use App\Domain\Assistant\Contracts\AssistantBrainInterface;
use App\Domain\Assistant\Contracts\AssistantProviderGatewayInterface;
use App\Domain\Assistant\Contracts\AssistantToolInterface;
use App\Domain\Assistant\Data\AgentExecutionBudget;
use App\Domain\Assistant\Data\AgentTurnRequest;
use App\Domain\Assistant\Data\AgentTurnResult;
use App\Domain\Assistant\Data\AssistantBrainRequest;
use App\Domain\Assistant\Data\AssistantBrainResult;
use App\Domain\Assistant\Data\AssistantProviderCapabilities;
use App\Domain\Assistant\Data\AssistantProviderHealth;
use App\Domain\Assistant\Data\AssistantProviderRequest;
use App\Domain\Assistant\Data\AssistantProviderResult;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use App\Domain\Assistant\Security\AiPrincipalContextResolver;
use App\Domain\Assistant\Tools\AssistantToolRegistry;
use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class AssistantAgentOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        config([
            'assistant.agent.max_steps' => 2,
            'assistant.agent.max_tool_calls' => 4,
            'assistant.agent.max_result_bytes' => 65536,
            'assistant.agent.max_seconds' => 12,
            'assistant.agent.planning_tokens' => 384,
            'assistant.agent.synthesis_tokens' => 640,
        ]);

        $this->bindBrain();
    }

    public function test_multi_tool_plan_executes_registered_tools_and_returns_grounded_synthesis(): void
    {
        $storeId = $this->store('b2b', 'AI-AGENT-MULTI');
        $actor = $this->globalUser('SUPER_ADMIN');

        $this->bindTools([
            $this->tool('sales.summary', ['recognized_revenue' => 20.5]),
            $this->tool('inventory.alerts', ['low_stock' => 2]),
        ]);
        $this->bindProvider([
            $this->providerResult(toolCalls: [
                ['name' => 'sales__summary', 'arguments' => [], 'id' => 'call-1'],
                ['name' => 'inventory__alerts', 'arguments' => [], 'id' => 'call-2'],
            ]),
            $this->providerResult(structured: [
                'message' => 'Revenue is 20.5 and two inventory alerts need attention.',
                'used_evidence' => ['ev-call-1', 'ev-call-2'],
                'uncertainty' => [],
            ]),
        ]);

        $result = app(AssistantAgentOrchestrator::class)->run(
            $this->turn($actor, $storeId, 'Compare sales and inventory risk.', 'agent-multi'),
        );

        $this->assertSame(AgentTurnResult::COMPLETE, $result->status);
        $this->assertSame(
            'Revenue is 20.5 and two inventory alerts need attention.',
            $result->message,
        );
        $this->assertCount(2, $result->toolExecutions);
        $this->assertSame('COMPLETE', $result->toolExecutions[0]['status']);
        $this->assertSame('COMPLETE', $result->toolExecutions[1]['status']);
        $this->assertCount(2, $result->evidence);
        $this->assertNotNull($result->toolResult);
        $this->assertSame(20.5, $result->toolResult->data['results']['sales.summary']['recognized_revenue']);
        $this->assertSame(2, $result->toolResult->data['results']['inventory.alerts']['low_stock']);
        $this->assertFalse($result->toolResult->data['_agent']['chain_of_thought_stored']);
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'assistant.tool.read')->count());
    }

    public function test_no_tool_plan_completes_without_executing_business_tools(): void
    {
        $storeId = $this->store('b2b', 'AI-AGENT-NO-TOOL');
        $actor = $this->globalUser('SUPER_ADMIN');

        $this->bindTools([$this->tool('sales.summary', ['recognized_revenue' => 10])]);
        $this->bindProvider([
            $this->providerResult(structured: [
                'action' => 'no_tool',
                'message' => 'Hello — no business read is needed.',
            ]),
        ]);

        $result = app(AssistantAgentOrchestrator::class)->run(
            $this->turn($actor, $storeId, 'Hello', 'agent-no-tool'),
        );

        $this->assertSame(AgentTurnResult::COMPLETE, $result->status);
        $this->assertSame('Hello — no business read is needed.', $result->message);
        $this->assertNull($result->toolResult);
        $this->assertSame([], $result->toolExecutions);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'assistant.tool.read')->count());
    }

    public function test_unknown_tool_plan_fails_closed_without_execution(): void
    {
        $storeId = $this->store('b2b', 'AI-AGENT-UNKNOWN');
        $actor = $this->globalUser('SUPER_ADMIN');

        $this->bindTools([$this->tool('sales.summary', ['recognized_revenue' => 10])]);
        $this->bindProvider([
            $this->providerResult(toolCalls: [
                ['name' => 'database__raw_query', 'arguments' => ['sql' => 'SELECT *'], 'id' => 'bad'],
            ]),
        ]);

        $result = app(AssistantAgentOrchestrator::class)->run(
            $this->turn($actor, $storeId, 'Run a database query.', 'agent-unknown'),
        );

        $this->assertSame(AgentTurnResult::DEGRADED, $result->status);
        $this->assertNull($result->toolResult);
        $this->assertSame('FAILED', $result->toolExecutions[0]['status']);
        $this->assertSame('TOOL_NOT_REGISTERED', $result->toolExecutions[0]['failure_code']);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'assistant.tool.read')->count());
    }

    public function test_duplicate_tool_plan_is_bounded_and_marked_degraded(): void
    {
        $storeId = $this->store('b2b', 'AI-AGENT-LOOP');
        $actor = $this->globalUser('SUPER_ADMIN');

        $this->bindTools([$this->tool('sales.summary', ['recognized_revenue' => 10])]);
        $this->bindProvider([
            $this->providerResult(toolCalls: [
                [
                    'name' => 'sales__summary',
                    'arguments' => ['from' => '2026-10-01', 'to' => '2026-10-01'],
                    'id' => 'same-1',
                ],
                [
                    'name' => 'sales__summary',
                    'arguments' => ['from' => '2026-10-01', 'to' => '2026-10-01'],
                    'id' => 'same-2',
                ],
            ]),
            $this->providerResult(structured: [
                'message' => 'Only one unique sales read was executed.',
                'used_evidence' => ['ev-call-1'],
                'uncertainty' => ['A duplicate tool step was suppressed.'],
            ]),
        ]);

        $result = app(AssistantAgentOrchestrator::class)->run(
            $this->turn($actor, $storeId, 'Check the same sales period twice.', 'agent-loop'),
        );

        $this->assertSame(AgentTurnResult::DEGRADED, $result->status);
        $this->assertCount(2, $result->toolExecutions);
        $this->assertSame('COMPLETE', $result->toolExecutions[0]['status']);
        $this->assertSame('FAILED', $result->toolExecutions[1]['status']);
        $this->assertSame('EXECUTION_BUDGET_EXCEEDED', $result->toolExecutions[1]['failure_code']);
        $this->assertCount(1, $result->evidence);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'assistant.tool.read')->count());
    }

    public function test_runtime_tool_failure_yields_partial_grounded_result_without_retry_loop(): void
    {
        $storeId = $this->store('b2b', 'AI-AGENT-FAIL');
        $actor = $this->globalUser('SUPER_ADMIN');

        $this->bindTools([
            $this->tool('sales.summary', ['recognized_revenue' => 11]),
            $this->failingTool('inventory.alerts'),
        ]);
        $this->bindProvider([
            $this->providerResult(toolCalls: [
                ['name' => 'sales__summary', 'arguments' => [], 'id' => 'ok'],
                ['name' => 'inventory__alerts', 'arguments' => [], 'id' => 'fail'],
            ]),
            $this->providerResult(structured: [
                'message' => 'Sales evidence is available; inventory evidence is unavailable.',
                'used_evidence' => ['ev-call-1'],
                'uncertainty' => ['Inventory tool failed.'],
            ]),
        ]);

        $result = app(AssistantAgentOrchestrator::class)->run(
            $this->turn($actor, $storeId, 'Check sales and inventory.', 'agent-failure'),
        );

        $this->assertSame(AgentTurnResult::DEGRADED, $result->status);
        $this->assertSame('COMPLETE', $result->toolExecutions[0]['status']);
        $this->assertSame('FAILED', $result->toolExecutions[1]['status']);
        $this->assertSame('TOOL_UNAVAILABLE', $result->toolExecutions[1]['failure_code']);
        $this->assertCount(2, $result->evidence);
        $this->assertSame('unavailable', $result->evidence[1]['status']);
        $this->assertSame('TOOL_UNAVAILABLE', $result->evidence[1]['failure_code']);
        $this->assertSame(
            'Sales evidence is available; inventory evidence is unavailable.',
            $result->message,
        );
    }

    public function test_permission_is_rechecked_by_real_executor_before_planned_tool_runs(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $actor = $this->globalUser('INVENTORY');

        $this->bindTools([$this->tool('sales.summary', ['recognized_revenue' => 10])]);
        $this->bindProvider([
            $this->providerResult(toolCalls: [
                ['name' => 'sales__summary', 'arguments' => [], 'id' => 'denied'],
            ]),
        ]);

        $result = app(AssistantAgentOrchestrator::class)->run(
            $this->turn($actor, $storeId, 'Show sales.', 'agent-permission'),
        );

        $this->assertSame(AgentTurnResult::DENIED, $result->status);
        $this->assertNull($result->toolResult);
        $this->assertSame('FAILED', $result->toolExecutions[0]['status']);
        $this->assertSame('SCOPE_DENIED', $result->toolExecutions[0]['failure_code']);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'assistant.tool.read')->count());
    }

    public function test_structured_execute_plan_normalizes_provider_tool_name(): void
    {
        $storeId = $this->store('b2b', 'AI-AGENT-STRUCTURED');
        $actor = $this->globalUser('SUPER_ADMIN');

        $this->bindTools([$this->tool('sales.summary', ['recognized_revenue' => 15])]);
        $this->bindProvider([
            $this->providerResult(structured: [
                'action' => 'execute',
                'message' => '',
                'steps' => [
                    ['tool' => 'sales__summary', 'arguments' => []],
                ],
            ]),
            $this->providerResult(structured: [
                'message' => 'Revenue is 15.',
                'used_evidence' => ['ev-call-1'],
                'uncertainty' => [],
            ]),
        ]);

        $result = app(AssistantAgentOrchestrator::class)->run(
            $this->turn($actor, $storeId, 'Show sales.', 'agent-structured'),
        );

        $this->assertSame(AgentTurnResult::COMPLETE, $result->status);
        $this->assertSame('sales.summary', $result->toolExecutions[0]['tool']);
        $this->assertSame('Revenue is 15.', $result->message);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'assistant.tool.read')->count());
    }

    public function test_insufficient_data_is_degraded_with_explicit_uncertainty(): void
    {
        $storeId = $this->store('b2b', 'AI-AGENT-INSUFFICIENT');
        $actor = $this->globalUser('SUPER_ADMIN');

        $this->bindTools([$this->tool('sales.summary', ['found' => false])]);
        $this->bindProvider([
            $this->providerResult(toolCalls: [
                ['name' => 'sales__summary', 'arguments' => [], 'id' => 'missing'],
            ]),
            $this->providerResult(structured: [
                'message' => 'The requested sales data is not available for this scope.',
                'used_evidence' => ['ev-call-1'],
                'uncertainty' => ['The tool returned no matching facts.'],
            ]),
        ]);

        $result = app(AssistantAgentOrchestrator::class)->run(
            $this->turn($actor, $storeId, 'Show sales.', 'agent-insufficient'),
        );

        $this->assertSame(AgentTurnResult::DEGRADED, $result->status);
        $this->assertSame('PARTIAL', $result->toolExecutions[0]['status']);
        $this->assertCount(1, $result->evidence);
        $this->assertSame(
            'The requested sales data is not available for this scope.',
            $result->message,
        );
    }

    public function test_malformed_plan_degrades_to_existing_deterministic_brain(): void
    {
        $storeId = $this->store('b2b', 'AI-AGENT-MALFORMED');
        $actor = $this->globalUser('SUPER_ADMIN');

        $this->bindTools([$this->tool('sales.summary', ['recognized_revenue' => 10])]);
        $this->bindProvider([
            $this->providerResult(structured: [
                'action' => 'execute',
                'steps' => [
                    ['tool' => 'sales.summary', 'arguments' => 'not-an-object'],
                ],
            ]),
        ]);

        $result = app(AssistantAgentOrchestrator::class)->run(
            $this->turn($actor, $storeId, 'Show sales.', 'agent-malformed'),
        );

        $this->assertSame(AgentTurnResult::DEGRADED, $result->status);
        $this->assertSame('Deterministic fallback.', $result->message);
        $this->assertNull($result->toolResult);
        $this->assertSame('PROVIDER_MALFORMED', $result->providerMetadata['failure']);
    }

    public function test_provider_failure_preserves_core_assistant_fallback(): void
    {
        $storeId = $this->store('b2b', 'AI-AGENT-PROVIDER-DOWN');
        $actor = $this->globalUser('SUPER_ADMIN');

        $this->bindTools([$this->tool('sales.summary', ['recognized_revenue' => 10])]);
        $this->bindProvider([
            new AssistantProviderResult(
                ok: false,
                provider: 'test',
                model: 'test',
                failureReason: AssistantProviderResult::FAILURE_UNAVAILABLE,
            ),
        ]);

        $result = app(AssistantAgentOrchestrator::class)->run(
            $this->turn($actor, $storeId, 'Hello', 'agent-provider-down'),
        );

        $this->assertSame(AgentTurnResult::DEGRADED, $result->status);
        $this->assertSame('Deterministic fallback.', $result->message);
        $this->assertNull($result->toolResult);
    }

    private function bindBrain(?string $intent = null, string $message = 'Deterministic fallback.'): void
    {
        $brain = new class($intent, $message) implements AssistantBrainInterface
        {
            public function __construct(
                private ?string $intent,
                private string $message,
            ) {}

            public function respond(AssistantBrainRequest $request): AssistantBrainResult
            {
                return new AssistantBrainResult(
                    message: $this->message,
                    intent: $this->intent,
                    confidence: $this->intent === null ? 0.5 : 1.0,
                    state: [
                        'pending_clarification' => false,
                        'entities' => [],
                        'locale' => $request->locale,
                    ],
                );
            }
        };

        $this->app->instance(AssistantBrainInterface::class, $brain);
    }

    private function turn(
        User $actor,
        int $storeId,
        string $message,
        string $correlationId,
    ): AgentTurnRequest {
        $context = [
            'path' => '/admin/b2b/dashboard',
            'channel' => 'b2b',
            'store_id' => $storeId,
            'authorized_entities' => [
                'channel' => 'b2b',
                'store_id' => $storeId,
            ],
        ];

        $principal = app(AiPrincipalContextResolver::class)->resolve(
            $actor,
            'en',
            $context,
            $correlationId,
        );

        return new AgentTurnRequest(
            actorUserId: (int) $actor->getKey(),
            userMessage: $message,
            locale: 'en',
            principal: $principal,
            correlationId: $correlationId,
            context: $context,
            state: [],
            budget: new AgentExecutionBudget(
                maxSteps: 2,
                maxToolCalls: 4,
                maxProviderCalls: 2,
                maxTotalResultBytes: 65536,
                maxPerToolResultBytes: 16384,
                maxPlanningTokens: 384,
                maxSynthesisTokens: 640,
                maxElapsedMs: 12000,
            ),
        );
    }

    /** @param list<AssistantToolInterface> $tools */
    private function bindTools(array $tools): void
    {
        $this->app->instance(AssistantToolRegistry::class, new AssistantToolRegistry($tools));
    }

    /** @param list<AssistantProviderResult> $results */
    private function bindProvider(array $results): void
    {
        $provider = new class($results) implements AssistantProviderGatewayInterface
        {
            /** @param list<AssistantProviderResult> $results */
            public function __construct(private array $results) {}

            public function capabilities(): AssistantProviderCapabilities
            {
                return new AssistantProviderCapabilities(
                    provider: 'test',
                    model: 'test',
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
            }

            public function complete(AssistantProviderRequest $request): AssistantProviderResult
            {
                return array_shift($this->results) ?? new AssistantProviderResult(
                    ok: false,
                    provider: 'test',
                    model: 'test',
                    failureReason: AssistantProviderResult::FAILURE_MALFORMED,
                );
            }

            public function health(): AssistantProviderHealth
            {
                return new AssistantProviderHealth(
                    provider: 'test',
                    model: 'test',
                    enabled: true,
                    reachable: true,
                    healthy: true,
                    latencyMs: 1,
                );
            }
        };

        $this->app->instance(AssistantProviderGatewayInterface::class, $provider);
    }

    /**
     * @param  list<array{name:string,arguments:array<string,mixed>,id:?string}>  $toolCalls
     * @param  array<string,mixed>|null  $structured
     */
    private function providerResult(
        ?string $text = null,
        array $toolCalls = [],
        ?array $structured = null,
    ): AssistantProviderResult {
        return new AssistantProviderResult(
            ok: true,
            provider: 'test',
            model: 'test',
            text: $text,
            structuredPayload: $structured,
            toolCalls: $toolCalls,
            finishReason: $toolCalls === [] ? 'stop' : 'tool_calls',
            latencyMs: 1,
        );
    }

    /** @param array<string,mixed> $data */
    private function tool(string $key, array $data): AssistantToolInterface
    {
        return new class($key, $data) implements AssistantToolInterface
        {
            /** @param array<string,mixed> $data */
            public function __construct(
                private string $keyName,
                private array $data,
            ) {}

            public function key(): string
            {
                return $this->keyName;
            }

            public function execute(AssistantToolRequest $request): AssistantToolResult
            {
                return new AssistantToolResult(data: $this->data);
            }
        };
    }

    private function failingTool(string $key): AssistantToolInterface
    {
        return new class($key) implements AssistantToolInterface
        {
            public function __construct(private string $keyName) {}

            public function key(): string
            {
                return $this->keyName;
            }

            public function execute(AssistantToolRequest $request): AssistantToolResult
            {
                throw new RuntimeException('simulated tool outage');
            }
        };
    }

    private function globalUser(string $roleCode): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-'.uniqid().'@agent.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function store(string $channel, string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', strtoupper($channel))->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
