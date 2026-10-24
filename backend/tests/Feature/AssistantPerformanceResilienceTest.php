<?php

namespace Tests\Feature;

use App\Domain\Assistant\Data\AiPrincipalContext;
use App\Domain\Assistant\Data\AgentExecutionBudget;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use App\Domain\Assistant\Performance\AssistantPerformanceManager;
use App\Domain\Assistant\Performance\AssistantToolCache;
use App\Domain\Assistant\Performance\RefreshAssistantAnalyticsJob;
use App\Domain\Assistant\Tools\AssistantToolDefinition;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AssistantPerformanceResilienceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'assistant.performance.enabled' => true,
            'assistant.performance.provider_global_concurrency' => 3,
            'assistant.performance.provider_tenant_concurrency' => 2,
            'assistant.performance.provider_user_concurrency' => 1,
            'assistant.performance.provider_lease_seconds' => 120,
            'assistant.performance.cancellation_ttl_seconds' => 300,
            'assistant.performance.tool_cache_enabled' => true,
            'assistant.performance.tool_cache_seconds' => 45,
            'assistant.performance.tool_cache_max_seconds' => 120,
            'assistant.performance.cacheable_sources' => [
                'AnalyticsReadModelService',
                'DeterministicAnalyticsEngine',
            ],
        ]);

        Cache::flush();
    }

    public function test_provider_capacity_load_sheds_per_user_and_tenant_then_recovers(): void
    {
        $manager = app(AssistantPerformanceManager::class);

        $first = $manager->acquireProvider(10, 'tenant-a', 'cap-1');
        $sameUser = $manager->acquireProvider(10, 'tenant-a', 'cap-2');
        $secondUser = $manager->acquireProvider(11, 'tenant-a', 'cap-3');
        $tenantOverflow = $manager->acquireProvider(12, 'tenant-a', 'cap-4');

        $this->assertTrue($first->acquired);
        $this->assertFalse($sameUser->acquired);
        $this->assertTrue($secondUser->acquired);
        $this->assertFalse($tenantOverflow->acquired);

        $first->release();

        $recovered = $manager->acquireProvider(12, 'tenant-a', 'cap-5');
        $this->assertTrue($recovered->acquired);

        $secondUser->release();
        $recovered->release();
    }

    public function test_provider_admission_control_meets_control_plane_latency_target_under_repeated_load(): void
    {
        $manager = app(AssistantPerformanceManager::class);
        $durations = [];

        for ($i = 0; $i < 200; $i++) {
            $startedAt = hrtime(true);
            $lease = $manager->acquireProvider(1000 + $i, 'tenant-'.($i % 20), 'load-'.$i);
            $this->assertTrue($lease->acquired);
            $lease->release();
            $durations[] = (hrtime(true) - $startedAt) / 1_000_000;
        }

        sort($durations);
        $p50 = $durations[(int) floor((count($durations) - 1) * 0.50)];
        $p95 = $durations[(int) floor((count($durations) - 1) * 0.95)];

        $this->assertLessThan((float) config('assistant.performance.simple_p50_ms', 900), $p50);
        $this->assertLessThan((float) config('assistant.performance.simple_p95_ms', 2500), $p95);
    }

    public function test_cancellation_is_scoped_to_actor_and_active_conversation(): void
    {
        $manager = app(AssistantPerformanceManager::class);

        $manager->registerActiveRequest(41, 'conversation-a', 'request-a');

        $this->assertTrue($manager->cancelActiveRequest(41, 'conversation-a'));
        $this->assertTrue($manager->isCancelled(41, 'request-a'));
        $this->assertFalse($manager->isCancelled(42, 'request-a'));
        $this->assertFalse($manager->cancelActiveRequest(41, 'conversation-b'));

        $manager->clearActiveRequest(41, 'conversation-a', 'request-a');
        $this->assertFalse($manager->cancelActiveRequest(41, 'conversation-a'));
    }

    public function test_tool_cache_is_opt_in_and_isolated_by_actor_store_permissions_and_arguments(): void
    {
        $cache = app(AssistantToolCache::class);
        $definition = $this->definition('AnalyticsReadModelService', ['stale_after_minutes' => 20]);
        $request = new AssistantToolRequest(
            actorUserId: 7,
            locale: 'en',
            channel: 'b2b',
            storeId: 101,
            entities: ['from' => '2026-10-01', 'to' => '2026-10-18'],
            context: ['store_id' => 101, 'channel' => 'b2b'],
        );
        $principal = $this->principal(7, 101, ['reports.view']);
        $otherActor = $this->principal(8, 101, ['reports.view']);
        $otherStore = $this->principal(7, 102, ['reports.view']);
        $otherPermission = $this->principal(7, 101, ['reports.view', 'inventory.view']);

        $this->assertSame(45, $cache->ttlSeconds($definition));
        $this->assertNotSame($cache->key($definition, $request, $principal), $cache->key($definition, $request, $otherActor));
        $this->assertNotSame($cache->key($definition, $request, $principal), $cache->key($definition, $request, $otherStore));
        $this->assertNotSame($cache->key($definition, $request, $principal), $cache->key($definition, $request, $otherPermission));

        $result = new AssistantToolResult(data: ['recognized_revenue' => 123.45]);
        $cache->put($definition, $request, $principal, $result);

        $this->assertSame(123.45, $cache->get($definition, $request, $principal)?->data['recognized_revenue']);
        $this->assertNull($cache->get($definition, $request, $otherActor));
        $this->assertSame(0, $cache->ttlSeconds($this->definition('TransactionalSource', ['stale_after_minutes' => 20])));
        $this->assertSame(0, $cache->ttlSeconds($this->definition('AnalyticsReadModelService', [])));
    }

    public function test_long_running_ai_materialization_is_dispatched_to_the_background_queue(): void
    {
        Queue::fake();

        RefreshAssistantAnalyticsJob::dispatch([101, 101, 102]);

        Queue::assertPushedOn(
            'assistant-background',
            RefreshAssistantAnalyticsJob::class,
            static fn (RefreshAssistantAnalyticsJob $job): bool => $job->storeIds === [101, 101, 102],
        );
    }

    public function test_cpu_provider_latency_budget_allows_slow_chat_without_becoming_unbounded(): void
    {
        config([
            'assistant.performance.simple_hard_ms' => 25000,
            'assistant.performance.analysis_hard_ms' => 30000,
            'assistant.agent.max_seconds' => 30,
        ]);

        $manager = app(AssistantPerformanceManager::class);
        $budget = AgentExecutionBudget::fromConfig();

        $this->assertSame(30000, $budget->maxElapsedMs);
        $this->assertSame(25000, $manager->hardLatencyBudgetMs(1));
        $this->assertSame(30000, $manager->hardLatencyBudgetMs(2));
    }

    public function test_latency_classes_have_distinct_hard_budgets(): void
    {
        $manager = app(AssistantPerformanceManager::class);

        $simple = $manager->latencyMetadata(1, 500);
        $analysis = $manager->latencyMetadata(2, 1500);

        $this->assertSame('simple_lookup', $simple['class']);
        $this->assertSame('multi_tool_analysis', $analysis['class']);
        $this->assertLessThan($analysis['hard_ms'], $simple['hard_ms']);
        $this->assertLessThan($analysis['target_p95_ms'], $simple['target_p95_ms']);
    }

    private function principal(int $actorUserId, int $storeId, array $permissions): AiPrincipalContext
    {
        return new AiPrincipalContext(
            actorUserId: $actorUserId,
            locale: 'en',
            roleCodes: ['TEST'],
            permissionCodes: $permissions,
            businessScope: 'wholesale',
            channel: 'b2b',
            activeStoreId: $storeId,
            permittedStoreIds: [$storeId],
            pageContext: ['store_id' => $storeId, 'channel' => 'b2b'],
            retailerDataGrants: [],
            correlationId: 'test-correlation',
        );
    }

    /** @param array<string,mixed> $freshness */
    private function definition(string $source, array $freshness): AssistantToolDefinition
    {
        return new AssistantToolDefinition(
            key: 'analytics.summary',
            schemaVersion: '1.0',
            labels: ['en' => 'Analytics', 'ar' => 'تحليلات'],
            mode: 'READ',
            requiredPermissions: ['reports.view'],
            permissionMode: 'all',
            channels: ['b2b', 'b2c'],
            inputSchema: ['type' => 'object', 'properties' => []],
            resultSchema: ['type' => 'object'],
            timeoutMs: 2500,
            rowLimit: 100,
            dataClass: 'INTERNAL',
            sellOutConsent: false,
            source: $source,
            freshness: $freshness,
        );
    }
}
