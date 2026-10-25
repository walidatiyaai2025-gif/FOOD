<?php

namespace Tests\Feature;

use App\Domain\Assistant\Contracts\AssistantProviderGatewayInterface;
use App\Domain\Assistant\Data\AssistantProviderRequest;
use App\Domain\Assistant\Data\AssistantProviderResult;
use App\Domain\Assistant\Intelligence\AssistantAnalysisPipeline;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class AssistantAnalysisDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config(['assistant.enabled' => true, 'assistant.external_ai.enabled' => true, 'assistant.performance.analysis_store_batch_size' => 100, 'assistant.performance.analysis_agent_timeout_seconds' => 120]);
    }

    public function test_super_admin_can_queue_chunked_platform_analysis_without_waiting_for_ai(): void
    {
        $user = $this->superAdmin();
        $provider = Mockery::mock(AssistantProviderGatewayInterface::class);
        $provider->shouldNotReceive('complete');
        $this->app->instance(AssistantProviderGatewayInterface::class, $provider);

        $this->actingAs($user)->post(route('admin.assistant-intelligence.analyze'))->assertRedirect();

        $run = DB::table('assistant_analysis_runs')->first();
        $this->assertNotNull($run);
        $this->assertSame('manual', $run->trigger);
        $this->assertSame('queued', $run->status);
        $this->assertGreaterThan(0, (int) $run->total_chunks);
        $this->assertDatabaseHas('assistant_analysis_chunks', ['run_id' => $run->id, 'status' => 'pending', 'source_kind' => 'DATABASE']);
    }

    public function test_background_worker_waits_for_agent_result_and_persists_hybrid_provenance(): void
    {
        $user = $this->superAdmin();
        $provider = Mockery::mock(AssistantProviderGatewayInterface::class);
        $provider->shouldReceive('complete')->once()->andReturn(new AssistantProviderResult(
            ok: true, provider: 'openai_compatible', model: 'qwen3:1.7b',
            text: '{"state":"watch","opinion":"Revenue is stable but cancellations need attention.","confidence":"high","findings":["Cancellation pressure"],"recommendations":["Review cancelled orders"]}',
            structuredPayload: ['state' => 'watch', 'opinion' => 'Revenue is stable but cancellations need attention.', 'confidence' => 'high', 'findings' => ['Cancellation pressure'], 'recommendations' => ['Review cancelled orders']],
            latencyMs: 58000,
        ));
        $this->app->instance(AssistantProviderGatewayInterface::class, $provider);

        $pipeline = app(AssistantAnalysisPipeline::class);
        $run = $pipeline->startRun('manual', $user->id);
        $result = $pipeline->workOne();

        $this->assertSame('complete', $result['status']);
        $this->assertSame('HYBRID', $result['source']);
        $this->assertSame(58000, $result['agent_latency_ms']);
        $this->assertDatabaseHas('assistant_analysis_chunks', ['run_id' => $run->id, 'status' => 'complete', 'source_kind' => 'HYBRID', 'provider' => 'openai_compatible', 'model' => 'qwen3:1.7b', 'agent_latency_ms' => 58000]);
    }

    public function test_final_executive_summary_requests_arabic_user_facing_text(): void
    {
        $user = $this->superAdmin();
        $provider = Mockery::mock(AssistantProviderGatewayInterface::class);
        $provider->shouldReceive('complete')
            ->once()
            ->withArgs(function (AssistantProviderRequest $request): bool {
                $this->assertStringContainsString('executive_opinion, priorities, recommendations) in Arabic', $request->systemInstructions);
                $this->assertStringContainsString('Keep JSON keys and enum values exactly as defined by the schema.', $request->systemInstructions);

                return true;
            })
            ->andReturn(new AssistantProviderResult(
                ok: true, provider: 'openai_compatible', model: 'qwen3:1.7b',
                text: '{"overall_state":"watch","executive_opinion":"توجد مخاطر تشغيلية تحتاج متابعة.","confidence":"high","priorities":["المبيعات"],"recommendations":["راجع أسباب تراجع الإيرادات."]}',
                structuredPayload: [
                    'overall_state' => 'watch',
                    'executive_opinion' => 'توجد مخاطر تشغيلية تحتاج متابعة.',
                    'confidence' => 'high',
                    'priorities' => ['المبيعات'],
                    'recommendations' => ['راجع أسباب تراجع الإيرادات.'],
                ],
                latencyMs: 95000,
            ));
        $this->app->instance(AssistantProviderGatewayInterface::class, $provider);

        $pipeline = app(AssistantAnalysisPipeline::class);
        $run = $pipeline->startRun('manual', $user->id);
        DB::table('assistant_analysis_chunks')->where('run_id', $run->id)->update([
            'status' => 'complete',
            'source_kind' => 'HYBRID',
            'progress_percent' => 100,
            'agent_opinion' => json_encode([
                'state' => 'watch',
                'opinion' => 'تحتاج المؤشرات إلى متابعة.',
                'confidence' => 'high',
                'findings' => ['مؤشر يحتاج متابعة'],
                'recommendations' => ['راجع المؤشر'],
            ], JSON_UNESCAPED_UNICODE),
            'completed_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $this->assertNull($pipeline->workOne());

        $summary = json_decode((string) DB::table('assistant_analysis_runs')->where('id', $run->id)->value('agent_summary'), true);
        $this->assertSame(['راجع أسباب تراجع الإيرادات.'], $summary['recommendations']);
        $this->assertSame(['المبيعات'], $summary['priorities']);
    }

    public function test_dashboard_labels_agent_and_database_sources_separately(): void
    {
        $user = $this->superAdmin();
        $now = now('UTC');
        $runId = DB::table('assistant_analysis_runs')->insertGetId([
            'initiated_by' => $user->id, 'trigger' => 'manual', 'status' => 'complete', 'total_chunks' => 1, 'completed_chunks' => 1, 'failed_chunks' => 0, 'coverage_percent' => 100,
            'agent_source' => 'HYBRID', 'agent_summary' => json_encode(['source' => 'HYBRID', 'overall_state' => 'watch', 'executive_opinion' => 'The Agent sees a real inventory risk backed by database evidence.', 'confidence' => 'high', 'priorities' => ['Inventory availability'], 'recommendations' => ['Replenish high velocity SKUs']]),
            'provider' => 'openai_compatible', 'model' => 'qwen3:1.7b', 'agent_latency_ms' => 58000, 'started_at' => $now, 'last_progress_at' => $now, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('assistant_analysis_chunks')->insert([
            'run_id' => $runId, 'batch_key' => 'inventory-00001', 'domain' => 'inventory', 'store_ids' => json_encode([]), 'status' => 'complete', 'source_kind' => 'HYBRID', 'progress_percent' => 100,
            'metrics' => json_encode(['facts' => ['stockout_skus' => 12]]), 'agent_opinion' => json_encode(['opinion' => 'Inventory risk is concentrated in high velocity SKUs.']),
            'provider' => 'openai_compatible', 'model' => 'qwen3:1.7b', 'agent_latency_ms' => 58000, 'started_at' => $now, 'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->actingAs($user)->get(route('admin.assistant-intelligence.index'))->assertOk()
            ->assertSee('AI Analytics &amp; Intelligence', false)->assertSee('HYBRID')->assertSee('DATABASE')
            ->assertSee('The Agent sees a real inventory risk backed by database evidence.')->assertSee('qwen3:1.7b')->assertSee('58,000 ms');
    }

    private function superAdmin(): User
    {
        $user = User::query()->create(['name' => 'Platform analysis admin', 'email' => 'analysis-'.uniqid().'@foodex.test', 'password' => 'password', 'locale' => 'en', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
