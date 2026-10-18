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

final class AssistantAgentOrchestratorTest extends TestCase
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
    }

    public function test_multi_tool_plan_executes_registered_reads_and_returns_grounded_synthesis(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $actor = $this->globalUser('SUPER_ADMIN');

        $this->bindTools([
            $this->tool('sales.summary', ['recognized_revenue' => 20.5, 'currency' => 'KWD']),
            $this->tool('inventory.alerts', ['low_stock' => 2]),
        ]);
        $this->bindProvider([
            $this->providerResult(toolCalls: [
                ['name' => 'sales__summary', 'arguments' => [], 'id' => 'plan-1'],
                ['name' => 'inventory__alerts', 'arguments' => [], 'id' => 'plan-2'],
            ]),
            $this->providerResult(structured: [
                'message' => 'Revenue is 20.5 KWD and two inventory alerts need attention.',
                'used_evidence' => ['ev-call-1', 'ev-call-2'],
                'uncertainty' => [],
            ]),
        ]);

        $result = $this->run($actor, $storeId, 'Compare sales and inventory risk.', 'agent-multi');

        $this->assertSame(AgentTurnResult::COMPLETE, $result->status);
        $this->assertSame('sales.summary', $result->brainResult->intent);
        $this->assertCount(2, $result->toolExecutions);
        $this->assertCount(2, $result->evidence);
        $this->assertSame('sales.summary', $result->toolExecutions[0]['tool']);
        $this->assertSame('inventory.alerts', $result->toolExecutions[1]['tool']);
        $this->assertSame(
            'Revenue is 20.5 KWD and two inventory alerts need attention.',
            $result->message,
        );
        $this->assertSame(20.5, $result->toolResult?->data['results']['sales.summary']['recognized_revenue']);
        $this->assertFalse($result->toolResult?->data['_agent']['chain_of_thought_stored']);
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'assistant.tool.read')->count());
    }

    public function test_no_tool_plan_returns_safe_answer_without_business_execution(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $actor = $this->globalUser('SUPER_ADMIN');

        $this->bindTools([$this->tool('sales.summary', ['recognized_revenue' => 10])]);
        $this->bindProvider([
            $this->providerResult(structured: [
                'action' => 'no_tool',
                'message' => 'Hello. How can I help with FOODEX?',
                'steps' => [],
            ]),
        ]);

        $result = $this->run($actor, $storeId, 'Hello', 'agent-no-tool');

        $this->assertSame(AgentTurnResult::COMPLETE, $result->status);
        $this->assertNull($result->toolResult);
        $this->assertSame([], $result->toolExecutions);
        $this->assertSame('Hello. How can I help with FOODEX?', $result->message);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'assistant.tool.read')->count());
    }

    public function test_over_budget_plan_is_rejected_before_any_tool_executes(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $actor = $this->globalUser('SUPER_ADMIN');

        $this->bindTools([
            $this->tool('sales.summary', ['recognized_revenue' => 10]),
            $this->tool('inventory.alerts', ['low_stock' => 1]),
            $this->tool('orders.summary', ['orders' => 1]),
            $this->tool('stores.summary', ['stores' => 1]),
        ]);
        $this->bindProvider([
            $this->providerResult(toolCalls: [
                ['name' => 'sales__summary', 'arguments' => [], 'id' => '1'],
                ['name' => 'inventory__alerts', 'arguments' => [], 'id' => '2'],
                ['name' => 'orders__summary', 'arguments' => [], 'id' => '3'],
                ['name' => 'stores__summary', 'arguments' => [], 'id' => '4'],
                ['name' => 'sales__compare', 'arguments' => [], 'id' => '5'],
            ]),
        ]);

        $result = $this->run(
            $actor,
            $storeId,
            'Give me a broad operational analysis.',
            'agent-budget',
            new AgentExecutionBudget(maxToolCalls: 4),
        );

        $this->assertSame(AgentTurnResult::DEGRADED, $result->status);
        $this->assertSame([], $result->toolExecutions);
        $this->assertSame('EXECUTION_BUDGET_EXCEEDED', $result->providerMetadata['failure']);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'assistant.tool.read')->count());
    }

    public function test_duplicate_tool_call_is_executed_once_and_marked_degraded(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $actor = $this->globalUser('SUPER_ADMIN');

        $this->bindTools([$this->tool('sales.summary', ['recognized_revenue' => 10])]);
        $this->bindProvider([
            $this->providerResult(toolCalls: [
                ['name' => 'sales__summary', 'arguments' => [], 'id' => '1'],
                ['name' => 'sales__summary', 'arguments' => [], 'id' => '2'],
            ]),
            $this->providerResult(structured: [
                'message' => 'One unique sales read was enough.',
                'used_evidence' => ['ev-call-1'],
                'uncertainty' => ['A duplicate planner call was suppressed.'],
            ]),
        ]);

        $result = $this->run($actor, $storeId, 'Check sales twice.', 'agent-duplicate');

        $this->assertSame(AgentTurnResult::DEGRADED, $result->status);
        $this->assertSame('COMPLETE', $result->toolExecutions[0]['status']);
        $this->assertSame('FAILED', $result->toolExecutions[1]['status']);
        $this->assertSame('EXECUTION_BUDGET_EXCEEDED', $result->toolExecutions[1]['failure_code']);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'assistant.tool.read')->count());
    }

    public function test_tool_failure_returns_partial_grounded_answer_and_unavailable_evidence(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
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
                'message' => 'Sales evidence is available; inventory is currently unavailable.',
                'used_evidence' => ['ev-call-1', 'ev-call-2'],
                'uncertainty' => ['Inventory read failed.'],
            ]),
        ]);

        $result = $this->run($actor, $storeId, 'Check sales and inventory.', 'agent-failure');

        $this->assertSame(AgentTurnResult::DEGRADED, $result->status);
        $this->assertSame('TOOL_UNAVAILABLE', $result->toolExecutions[1]['failure_code']);
        $this->assertSame('unavailable', $result->evidence[1]['status']);
        $this->assertSame('TOOL_FAILURE', $result->evidence[1]['source_type']);
    }

    public function test_insufficient_data_is_explicitly_partial(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $actor = $this->globalUser('SUPER_ADMIN');

        $this->bindTools([$this->tool('orders.lookup', ['found' => false])]);
        $this->bindProvider([
            $this->providerResult(toolCalls: [
                ['name' => 'orders__lookup', 'arguments' => ['order_number' => 'MISSING'], 'id' => 'lookup'],
            ]),
            $this->providerResult(structured: [
                'message' => 'That order was not found in the authorized scope.',
                'used_evidence' => ['ev-call-1'],
                'uncertainty' => ['No matching order was available.'],
            ]),
        ]);

        $result = $this->run($actor, $storeId, 'Find order MISSING.', 'agent-missing');

        $this->assertSame(AgentTurnResult::DEGRADED, $result->status);
        $this->assertSame('PARTIAL', $result->toolExecutions[0]['status']);
        $this->assertFalse($result->toolResult?->data['results']['orders.lookup']['found']);
    }

    public function test_permission_is_rechecked_by_executor_and_denied_plan_does_not_audit_read(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $actor = $this->globalUser('INVENTORY');

        $this->bindTools([$this->tool('sales.summary', ['recognized_revenue' => 10])]);
        $this->bindProvider([
            $this->providerResult(toolCalls: [
                ['name' => 'sales__summary', 'arguments' => [], 'id' => 'denied'],
            ]),
        ]);

        $result = $this->run($actor, $storeId, 'Show sales.', 'agent-permission');

        $this->assertSame(AgentTurnResult::DENIED, $result->status);
        $this->assertSame('SCOPE_DENIED', $result->toolExecutions[0]['failure_code']);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'assistant.tool.read')->count());
    }

    public function test_provider_failure_falls_back_to_existing_brain_and_tool_path(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $actor = $this->globalUser('SUPER_ADMIN');

        $this->bindTools([$this->tool('sales.summary', ['recognized_revenue' => 9])]);
        $this->app->instance(AssistantBrainInterface::class, new class implements AssistantBrainInterface
        {
            public function respond(AssistantBrainRequest $request): AssistantBrainResult
            {
                return new AssistantBrainResult(
                    message: 'Using deterministic sales.',
                    intent: 'sales.summary',
                    confidence: 1.0,
                    state: [
                        'entities' => [],
                        'period' => null,
                        'pending_clarification' => false,
                        'locale' => 'en',
                    ],
                );
            }
        });
        $this->bindProvider([
            new AssistantProviderResult(
                ok: false,
                provider: 'test',
                model: 'test',
                failureReason: AssistantProviderResult::FAILURE_DISABLED,
            ),
        ]);

        $result = $this->run($actor, $storeId, 'Sales today.', 'agent-disabled');

        $this->assertSame(AgentTurnResult::COMPLETE, $result->status);
        $this->assertSame('sales.summary', $result->brainResult->intent);
        $this->assertSame(9, $result->toolResult?->data['recognized_revenue']);
        $this->assertSame(AssistantProviderResult::FAILURE_DISABLED, $result->providerMetadata['failure_reason']);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'assistant.tool.read')->count());
    }

    private function run(
        User $actor,
        int $storeId,
        string $message,
        string $correlationId,
        ?AgentExecutionBudget $budget = null,
    ): AgentTurnResult {
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

        return app(AssistantAgentOrchestrator::class)->run(new AgentTurnRequest(
            actorUserId: (int) $actor->getKey(),
            userMessage: $message,
            locale: 'en',
            principal: $principal,
            correlationId: $correlationId,
            context: $context,
            state: [],
            budget: $budget ?? AgentExecutionBudget::fromConfig(),
        ));
    }

    /** @param list<AssistantToolInterface> $tools */
    private function bindTools(array $tools): void
    {
        $this->app->instance(AssistantToolRegistry::class, new AssistantToolRegistry($tools));
    }

    /** @param list<AssistantProviderResult> $results */
    private function bindProvider(array $results): void
    {
        $this->app->instance(AssistantProviderGatewayInterface::class, new class($results) implements AssistantProviderGatewayInterface
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
                    failureReason: AssistantProviderResult::FAILURE_UNAVAILABLE,
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
        });
    }

    /**
     * @param list<array{name:string,arguments:array<string,mixed>,id:?string}> $toolCalls
     * @param array<string,mixed>|null $structured
     */
    private function providerResult(
        ?array $structured = null,
        array $toolCalls = [],
        ?string $text = null,
    ): AssistantProviderResult {
        return new AssistantProviderResult(
            ok: true,
            provider: 'test',
            model: 'test',
            text: $text ?? ($structured === null ? null : json_encode($structured, JSON_THROW_ON_ERROR)),
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
            public function __construct(private string $keyName, private array $data) {}

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
}
