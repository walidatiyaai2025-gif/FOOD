<?php

namespace Tests\Feature;

use App\Domain\Assistant\Agent\AssistantAgentOrchestrator;
use App\Domain\Assistant\Contracts\AssistantProviderGatewayInterface;
use App\Domain\Assistant\Contracts\AssistantToolInterface;
use App\Domain\Assistant\Data\AssistantBrainRequest;
use App\Domain\Assistant\Data\AssistantProviderCapabilities;
use App\Domain\Assistant\Data\AssistantProviderHealth;
use App\Domain\Assistant\Data\AssistantProviderRequest;
use App\Domain\Assistant\Data\AssistantProviderResult;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use App\Domain\Assistant\Tools\AssistantToolRegistry;
use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
    }

    public function test_multi_tool_plan_executes_only_registered_tools_and_returns_grounded_synthesis(): void
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
            $this->providerResult(text: 'READY'),
            $this->providerResult(structured: [
                'answer' => 'Revenue is 20.5 and two inventory alerts need attention.',
                'partial' => false,
                'uncertainty' => null,
                'evidence' => ['E1', 'E2'],
            ]),
        ]);

        $result = app(AssistantAgentOrchestrator::class)->run(
            $actor,
            $this->request($storeId, 'Compare sales and inventory risk.'),
            'agent-multi',
        );

        $this->assertNotNull($result);
        $this->assertSame(2, $result->toolCalls);
        $this->assertSame(['sales.summary', 'inventory.alerts'], $result->toolKeys);
        $this->assertFalse($result->partial);
        $this->assertSame(
            'Revenue is 20.5 and two inventory alerts need attention.',
            $result->message,
        );
        $this->assertCount(2, $result->toolResult->data['results']);
        $this->assertFalse($result->toolResult->data['_agent']['model_sql_allowed']);
        $this->assertFalse($result->toolResult->data['_agent']['chain_of_thought_stored']);
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'assistant.tool.read')->count());
    }

    public function test_no_tool_plan_falls_back_to_existing_assistant_path(): void
    {
        $storeId = $this->store('b2b', 'AI-AGENT-NO-TOOL');
        $actor = $this->globalUser('SUPER_ADMIN');

        $this->bindTools([$this->tool('sales.summary', ['recognized_revenue' => 10])]);
        $this->bindProvider([$this->providerResult(text: 'No business tool is needed.')]);

        $result = app(AssistantAgentOrchestrator::class)->run(
            $actor,
            $this->request($storeId, 'Hello'),
            'agent-no-tool',
        );

        $this->assertNull($result);
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
            $actor,
            $this->request($storeId, 'Run a database query.'),
            'agent-unknown',
        );

        $this->assertNotNull($result);
        $this->assertTrue($result->partial);
        $this->assertSame(0, $result->toolCalls);
        $this->assertSame([], $result->toolKeys);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'assistant.tool.read')->count());
    }

    public function test_duplicate_tool_loop_is_bounded_and_marked_partial(): void
    {
        $storeId = $this->store('b2b', 'AI-AGENT-LOOP');
        $actor = $this->globalUser('SUPER_ADMIN');

        $this->bindTools([$this->tool('sales.summary', ['recognized_revenue' => 10])]);
        $duplicate = [
            ['name' => 'sales__summary', 'arguments' => ['from' => '2026-10-01', 'to' => '2026-10-01'], 'id' => 'same'],
        ];
        $this->bindProvider([
            $this->providerResult(toolCalls: $duplicate),
            $this->providerResult(toolCalls: $duplicate),
            $this->providerResult(structured: [
                'answer' => 'Only one unique sales read was required.',
                'partial' => false,
                'uncertainty' => null,
                'evidence' => ['E1'],
            ]),
        ]);

        $result = app(AssistantAgentOrchestrator::class)->run(
            $actor,
            $this->request($storeId, 'Check the same sales period.'),
            'agent-loop',
        );

        $this->assertNotNull($result);
        $this->assertSame(1, $result->toolCalls);
        $this->assertTrue($result->partial);
        $this->assertSame(['sales.summary'], $result->toolKeys);
    }

    public function test_runtime_tool_failure_returns_partial_grounded_result_instead_of_looping(): void
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
            $this->providerResult(text: 'READY'),
            $this->providerResult(structured: [
                'answer' => 'Sales evidence is available; inventory evidence is unavailable.',
                'partial' => true,
                'uncertainty' => 'Inventory tool failed.',
                'evidence' => ['E1', 'E2'],
            ]),
        ]);

        $result = app(AssistantAgentOrchestrator::class)->run(
            $actor,
            $this->request($storeId, 'Check sales and inventory.'),
            'agent-failure',
        );

        $this->assertNotNull($result);
        $this->assertTrue($result->partial);
        $this->assertSame(1, $result->toolCalls);
        $this->assertSame('unavailable', $result->evidence[1]['status']);
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

        $this->expectException(HttpException::class);
        app(AssistantAgentOrchestrator::class)->run(
            $actor,
            $this->request($storeId, 'Show sales.'),
            'agent-permission',
        );
    }

    private function request(int $storeId, string $message): AssistantBrainRequest
    {
        return new AssistantBrainRequest(
            message: $message,
            locale: 'en',
            context: [
                'path' => '/admin/b2b/dashboard',
                'channel' => 'b2b',
                'store_id' => $storeId,
                'authorized_entities' => [
                    'channel' => 'b2b',
                    'store_id' => $storeId,
                ],
            ],
            state: [],
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
     * @param list<array{name:string,arguments:array<string,mixed>,id:?string}> $toolCalls
     * @param array<string,mixed>|null $structured
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
