<?php

namespace App\Domain\Assistant\Intelligence;

use App\Domain\Assistant\Contracts\AssistantProviderGatewayInterface;
use App\Domain\Assistant\Data\AssistantProviderRequest;
use App\Domain\Assistant\Data\AssistantProviderResult;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final readonly class AssistantAnalysisPipeline
{
    public const DOMAINS = ['sales', 'customers', 'products', 'inventory', 'finance', 'operations', 'support'];

    private const ACTIVE = ['queued', 'running', 'finalizing'];

    private const TERMINAL = ['complete', 'partial', 'failed'];

    public function __construct(private AssistantProviderGatewayInterface $provider) {}

    public function canAccessPlatformAnalysis(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->roles()->where('roles.is_active', true)->where('roles.code', 'SUPER_ADMIN')->exists()) {
            return true;
        }

        return $user->hasPermission('platform.manage') || $user->hasPermission('settings.manage');
    }

    public function coordinate(): ?int
    {
        if (! Schema::hasTable('assistant_analysis_runs') || ! Schema::hasTable('assistant_analysis_chunks')) {
            return null;
        }

        $active = DB::table('assistant_analysis_runs')->whereIn('status', self::ACTIVE)->oldest('id')->first();
        if ($active !== null) {
            $this->recoverStaleChunks((int) $active->id);

            return (int) $active->id;
        }

        $latest = DB::table('assistant_analysis_runs')->whereIn('status', self::TERMINAL)->latest('completed_at')->first();
        if ($latest !== null && $latest->completed_at !== null) {
            $cycleMinutes = (int) config('assistant.performance.analysis_cycle_minutes', 360);
            if (CarbonImmutable::parse((string) $latest->completed_at, 'UTC')->gt(now('UTC')->subMinutes($cycleMinutes))) {
                return null;
            }
        }

        return (int) $this->startRun('scheduler')->id;
    }

    public function startRun(string $trigger, ?int $initiatedBy = null): object
    {
        $trigger = in_array($trigger, ['scheduler', 'manual'], true) ? $trigger : 'scheduler';
        $active = DB::table('assistant_analysis_runs')->whereIn('status', self::ACTIVE)->oldest('id')->first();
        if ($active !== null) {
            return $active;
        }

        $storeIds = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.is_active', true)
            ->whereIn('store_types.code', ['B2B', 'B2C'])
            ->orderBy('stores.id')
            ->pluck('stores.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $batchSize = (int) config('assistant.performance.analysis_store_batch_size', 100);
        $storeBatches = array_chunk($storeIds, max(10, $batchSize));
        if ($storeBatches === []) {
            $storeBatches = [[]];
        }

        return DB::transaction(function () use ($trigger, $initiatedBy, $storeBatches): object {
            $now = now('UTC');
            $total = count($storeBatches) * count(self::DOMAINS);
            $runId = (int) DB::table('assistant_analysis_runs')->insertGetId([
                'initiated_by' => $initiatedBy,
                'trigger' => $trigger,
                'status' => 'queued',
                'total_chunks' => $total,
                'completed_chunks' => 0,
                'failed_chunks' => 0,
                'coverage_percent' => 0,
                'agent_source' => 'DATABASE',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $rows = [];
            foreach (self::DOMAINS as $domain) {
                foreach ($storeBatches as $index => $ids) {
                    $rows[] = [
                        'run_id' => $runId,
                        'batch_key' => $domain.'-'.str_pad((string) ($index + 1), 5, '0', STR_PAD_LEFT),
                        'domain' => $domain,
                        'store_ids' => json_encode($ids, JSON_THROW_ON_ERROR),
                        'status' => 'pending',
                        'source_kind' => 'DATABASE',
                        'progress_percent' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            foreach (array_chunk($rows, 500) as $slice) {
                DB::table('assistant_analysis_chunks')->insert($slice);
            }

            return DB::table('assistant_analysis_runs')->where('id', $runId)->firstOrFail();
        }, 3);
    }

    public function workOne(): ?array
    {
        if (! Schema::hasTable('assistant_analysis_runs') || ! Schema::hasTable('assistant_analysis_chunks')) {
            return null;
        }

        $this->finalizeEligibleRun();
        $chunk = DB::transaction(function (): ?object {
            $row = DB::table('assistant_analysis_chunks')->where('status', 'pending')->orderBy('run_id')->orderBy('id')->lockForUpdate()->first();
            if ($row === null) {
                return null;
            }

            $now = now('UTC');
            DB::table('assistant_analysis_chunks')->where('id', $row->id)->update([
                'status' => 'running',
                'progress_percent' => 10,
                'started_at' => $now,
                'failure_code' => null,
                'updated_at' => $now,
            ]);
            DB::table('assistant_analysis_runs')->where('id', $row->run_id)->update([
                'status' => 'running',
                'started_at' => DB::raw('COALESCE(started_at, CURRENT_TIMESTAMP)'),
                'last_progress_at' => $now,
                'updated_at' => $now,
            ]);

            return DB::table('assistant_analysis_chunks')->where('id', $row->id)->first();
        }, 3);

        if ($chunk === null) {
            $this->finalizeEligibleRun();

            return null;
        }

        $storeIds = $this->decodeIds($chunk->store_ids);
        $metrics = null;

        try {
            $metrics = $this->collectMetrics((string) $chunk->domain, $storeIds);
            DB::table('assistant_analysis_chunks')->where('id', $chunk->id)->update([
                'progress_percent' => 55,
                'metrics' => json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'evidence' => json_encode([
                    'source' => 'DATABASE',
                    'generated_at' => $metrics['generated_at'] ?? now('UTC')->toIso8601String(),
                    'window' => $metrics['window'] ?? null,
                    'store_ids' => $storeIds,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => now('UTC'),
            ]);

            $agent = $this->analyzeWithAgent((string) $chunk->domain, $metrics, (int) $chunk->run_id, (int) $chunk->id);
            $now = now('UTC');
            DB::table('assistant_analysis_chunks')->where('id', $chunk->id)->update([
                'status' => $agent['ok'] ? 'complete' : 'partial',
                'source_kind' => $agent['ok'] ? 'HYBRID' : 'DATABASE',
                'progress_percent' => 100,
                'agent_opinion' => $agent['opinion'] === null ? null : json_encode($agent['opinion'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'provider' => $agent['provider'],
                'model' => $agent['model'],
                'agent_latency_ms' => $agent['latency_ms'],
                'failure_code' => $agent['failure'],
                'completed_at' => $now,
                'updated_at' => $now,
            ]);

            $progress = $this->updateRunProgress((int) $chunk->run_id);
            $this->finalizeEligibleRun((int) $chunk->run_id);

            return [
                'run_id' => (int) $chunk->run_id,
                'chunk_id' => (int) $chunk->id,
                'domain' => (string) $chunk->domain,
                'stores' => count($storeIds),
                'status' => $agent['ok'] ? 'complete' : 'partial',
                'source' => $agent['ok'] ? 'HYBRID' : 'DATABASE',
                'coverage_percent' => $progress,
                'agent_latency_ms' => $agent['latency_ms'],
                'failure' => $agent['failure'],
            ];
        } catch (Throwable $exception) {
            report($exception);
            $now = now('UTC');
            DB::table('assistant_analysis_chunks')->where('id', $chunk->id)->update([
                'status' => 'failed',
                'source_kind' => 'DATABASE',
                'progress_percent' => 100,
                'metrics' => $metrics === null ? null : json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'failure_code' => 'ANALYSIS_CHUNK_FAILED',
                'completed_at' => $now,
                'updated_at' => $now,
            ]);
            $progress = $this->updateRunProgress((int) $chunk->run_id);
            $this->finalizeEligibleRun((int) $chunk->run_id);

            return [
                'run_id' => (int) $chunk->run_id,
                'chunk_id' => (int) $chunk->id,
                'domain' => (string) $chunk->domain,
                'stores' => count($storeIds),
                'status' => 'failed',
                'source' => 'DATABASE',
                'coverage_percent' => $progress,
                'failure' => 'ANALYSIS_CHUNK_FAILED',
            ];
        }
    }

    public function dashboardSnapshot(User $actor): ?array
    {
        if (! $this->canAccessPlatformAnalysis($actor) || ! Schema::hasTable('assistant_analysis_runs') || ! Schema::hasTable('assistant_analysis_chunks')) {
            return null;
        }

        $run = DB::table('assistant_analysis_runs')->latest('id')->first();
        if ($run === null) {
            return [
                'id' => null, 'status' => 'idle', 'coverage_percent' => 0, 'last_progress_at' => null,
                'agent' => ['source' => 'DATABASE'], 'provider' => null, 'model' => null, 'agent_latency_ms' => null,
                'kpis' => ['revenue' => 0, 'orders' => 0, 'customers' => 0, 'stockouts' => 0],
                'domains' => [], 'opinions' => [], 'trace' => [], 'history' => [],
            ];
        }

        $chunks = DB::table('assistant_analysis_chunks')->where('run_id', $run->id)->orderBy('id')->get();
        $domains = [];
        foreach (self::DOMAINS as $domain) {
            $rows = $chunks->where('domain', $domain);
            $terminal = $rows->whereIn('status', self::TERMINAL)->count();
            $total = $rows->count();
            $domains[$domain] = [
                'coverage' => $total === 0 ? 0 : round(($terminal / $total) * 100, 1),
                'complete' => $rows->where('status', 'complete')->count(),
                'partial' => $rows->where('status', 'partial')->count(),
                'failed' => $rows->where('status', 'failed')->count(),
                'running' => $rows->where('status', 'running')->count(),
                'pending' => $rows->where('status', 'pending')->count(),
            ];
        }

        $kpis = ['revenue' => 0.0, 'orders' => 0, 'customers' => 0, 'stockouts' => 0];
        foreach ($chunks as $row) {
            $metrics = $this->decodeJson($row->metrics);
            if ((string) $row->domain === 'sales') {
                $kpis['revenue'] += (float) data_get($metrics, 'facts.revenue', 0);
                $kpis['orders'] += (int) data_get($metrics, 'facts.orders', 0);
            } elseif ((string) $row->domain === 'customers') {
                $kpis['customers'] += (int) data_get($metrics, 'facts.unique_customers', 0);
            } elseif ((string) $row->domain === 'inventory') {
                $kpis['stockouts'] += (int) data_get($metrics, 'facts.stockout_skus', 0);
            }
        }

        $opinions = $chunks->filter(fn (object $row): bool => $row->agent_opinion !== null)->sortByDesc('completed_at')->take(12)
            ->map(function (object $row): array {
                $opinion = $this->decodeJson($row->agent_opinion);

                return [
                    'id' => (int) $row->id, 'domain' => (string) $row->domain, 'source' => (string) $row->source_kind,
                    'opinion' => (string) data_get($opinion, 'opinion', ''), 'store_count' => count($this->decodeIds($row->store_ids)),
                    'provider' => $row->provider === null ? null : (string) $row->provider, 'model' => $row->model === null ? null : (string) $row->model,
                    'latency_ms' => (int) ($row->agent_latency_ms ?? 0),
                ];
            })->values()->all();

        $trace = $chunks->sortByDesc('id')->take(30)->map(fn (object $row): array => [
            'id' => (int) $row->id, 'domain' => (string) $row->domain, 'status' => (string) $row->status,
            'source' => (string) $row->source_kind, 'store_count' => count($this->decodeIds($row->store_ids)),
            'provider' => $row->provider === null ? null : (string) $row->provider, 'model' => $row->model === null ? null : (string) $row->model,
            'latency_ms' => (int) ($row->agent_latency_ms ?? 0), 'completed_at' => $this->displayTime($row->completed_at),
        ])->values()->all();

        $history = DB::table('assistant_analysis_runs')->latest('id')->limit(10)->get()->map(fn (object $row): array => [
            'id' => (int) $row->id, 'trigger' => (string) $row->trigger, 'status' => (string) $row->status,
            'coverage_percent' => (float) $row->coverage_percent, 'source' => (string) $row->agent_source,
            'started_at' => $this->displayTime($row->started_at), 'completed_at' => $this->displayTime($row->completed_at),
        ])->all();

        $agent = $this->decodeJson($run->agent_summary);
        if ($agent === []) {
            $agent = ['source' => (string) $run->agent_source];
        }
        $agent['source'] = (string) ($agent['source'] ?? $run->agent_source ?? 'DATABASE');

        return [
            'id' => (int) $run->id, 'status' => (string) $run->status, 'coverage_percent' => (float) $run->coverage_percent,
            'last_progress_at' => $this->displayTime($run->last_progress_at), 'agent' => $agent,
            'provider' => $run->provider === null ? null : (string) $run->provider, 'model' => $run->model === null ? null : (string) $run->model,
            'agent_latency_ms' => $run->agent_latency_ms === null ? null : (int) $run->agent_latency_ms,
            'kpis' => $kpis, 'domains' => $domains, 'opinions' => $opinions, 'trace' => $trace, 'history' => $history,
        ];
    }

    private function recoverStaleChunks(int $runId): void
    {
        $minutes = (int) config('assistant.performance.analysis_stale_chunk_minutes', 10);
        DB::table('assistant_analysis_chunks')->where('run_id', $runId)->where('status', 'running')
            ->where('started_at', '<', now('UTC')->subMinutes($minutes))->update([
                'status' => 'pending', 'progress_percent' => 0, 'failure_code' => 'STALE_CHUNK_RECLAIMED',
                'started_at' => null, 'updated_at' => now('UTC'),
            ]);
        $this->updateRunProgress($runId);
    }

    private function updateRunProgress(int $runId): float
    {
        $total = (int) DB::table('assistant_analysis_chunks')->where('run_id', $runId)->count();
        $complete = (int) DB::table('assistant_analysis_chunks')->where('run_id', $runId)->whereIn('status', self::TERMINAL)->count();
        $failed = (int) DB::table('assistant_analysis_chunks')->where('run_id', $runId)->where('status', 'failed')->count();
        $coverage = $total === 0 ? 100.0 : round(($complete / $total) * 100, 2);
        DB::table('assistant_analysis_runs')->where('id', $runId)->update([
            'completed_chunks' => $complete, 'failed_chunks' => $failed, 'coverage_percent' => $coverage,
            'last_progress_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);

        return $coverage;
    }

    private function finalizeEligibleRun(?int $specificRunId = null): void
    {
        $query = DB::table('assistant_analysis_runs')->whereIn('status', ['queued', 'running'])->orderBy('id');
        if ($specificRunId !== null) {
            $query->where('id', $specificRunId);
        }
        $run = $query->first();
        if ($run === null) {
            return;
        }

        $remaining = DB::table('assistant_analysis_chunks')->where('run_id', $run->id)->whereIn('status', ['pending', 'running'])->count();
        if ($remaining > 0) {
            return;
        }

        $claimed = DB::table('assistant_analysis_runs')->where('id', $run->id)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'finalizing', 'updated_at' => now('UTC')]);
        if ($claimed === 1) {
            $this->finalizeRun((int) $run->id);
        }
    }

    private function finalizeRun(int $runId): void
    {
        $rows = DB::table('assistant_analysis_chunks')->where('run_id', $runId)->orderBy('domain')->orderBy('id')->get();
        $representative = [];
        $domainCoverage = [];
        foreach (self::DOMAINS as $domain) {
            $domainRows = $rows->where('domain', $domain);
            $domainCoverage[$domain] = [
                'chunks' => $domainRows->count(), 'complete' => $domainRows->where('status', 'complete')->count(),
                'partial' => $domainRows->where('status', 'partial')->count(), 'failed' => $domainRows->where('status', 'failed')->count(),
            ];
            foreach ($domainRows->filter(fn (object $row): bool => $row->agent_opinion !== null)->take(5) as $row) {
                $opinion = $this->decodeJson($row->agent_opinion);
                $representative[] = [
                    'domain' => $domain, 'state' => data_get($opinion, 'state'), 'opinion' => data_get($opinion, 'opinion'),
                    'findings' => array_slice((array) data_get($opinion, 'findings', []), 0, 3),
                    'recommendations' => array_slice((array) data_get($opinion, 'recommendations', []), 0, 3),
                ];
            }
        }

        $result = $this->provider->complete(new AssistantProviderRequest(
            systemInstructions: 'You are the FOODEX executive background intelligence agent. Database and deterministic results are authoritative. Never invent numbers, hidden causes or actions. Separate observation from interpretation.',
            taskInstructions: 'Create one concise executive assessment from the supplied completed domain opinions. Return JSON only. Prioritize cross-domain risks and opportunities. Do not claim database access beyond the supplied evidence.',
            userContent: 'Finalize FOODEX background platform analysis run #'.$runId.'.',
            context: ['domain_coverage' => $domainCoverage, 'representative_domain_opinions' => array_slice($representative, 0, 35)],
            responseSchema: [
                'type' => 'object', 'required' => ['overall_state', 'executive_opinion', 'confidence', 'priorities', 'recommendations'],
                'properties' => [
                    'overall_state' => ['type' => 'string', 'enum' => ['healthy', 'watch', 'risk', 'critical']],
                    'executive_opinion' => ['type' => 'string'], 'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                    'priorities' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 8],
                    'recommendations' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 8],
                ], 'additionalProperties' => false,
            ],
            temperature: 0.1, maxTokens: (int) config('assistant.performance.analysis_agent_max_tokens', 768),
            timeoutSeconds: (int) config('assistant.performance.analysis_agent_timeout_seconds', 120),
            correlationId: 'analysis-run-'.$runId.'-final', useCase: 'background_intelligence',
        ));

        $failedChunks = $rows->where('status', 'failed')->count();
        $agentOk = $result->ok && is_array($result->structuredPayload);
        $summary = $agentOk ? [
            'source' => 'HYBRID', 'overall_state' => (string) data_get($result->structuredPayload, 'overall_state', 'watch'),
            'executive_opinion' => (string) data_get($result->structuredPayload, 'executive_opinion', ''),
            'confidence' => (string) data_get($result->structuredPayload, 'confidence', 'low'),
            'priorities' => array_values((array) data_get($result->structuredPayload, 'priorities', [])),
            'recommendations' => array_values((array) data_get($result->structuredPayload, 'recommendations', [])),
            'generated_at' => now('UTC')->toIso8601String(),
        ] : [
            'source' => 'DATABASE', 'overall_state' => $failedChunks > 0 ? 'risk' : 'watch', 'executive_opinion' => '',
            'confidence' => 'low', 'priorities' => [], 'recommendations' => [],
            'agent_failure' => $result->failureReason ?? AssistantProviderResult::FAILURE_UNAVAILABLE,
            'generated_at' => now('UTC')->toIso8601String(),
        ];

        DB::table('assistant_analysis_runs')->where('id', $runId)->update([
            'status' => ($failedChunks > 0 || ! $agentOk) ? 'partial' : 'complete', 'coverage_percent' => 100,
            'agent_source' => $agentOk ? 'HYBRID' : 'DATABASE',
            'agent_summary' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'provider' => $result->provider, 'model' => $result->model, 'agent_latency_ms' => $result->latencyMs,
            'last_progress_at' => now('UTC'), 'completed_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
    }

    private function analyzeWithAgent(string $domain, array $metrics, int $runId, int $chunkId): array
    {
        $result = $this->provider->complete(new AssistantProviderRequest(
            systemInstructions: 'You are the FOODEX background business analyst. The supplied database metrics are authoritative. Do not invent numbers or claim actions. Return only a concise structured interpretation.',
            taskInstructions: 'Analyze this bounded '.$domain.' database snapshot. Identify material risks or opportunities and give practical recommendations. Return JSON only.',
            userContent: 'Analyze FOODEX '.$domain.' chunk '.$chunkId.' in background run '.$runId.'.',
            context: ['database_metrics' => $metrics],
            responseSchema: [
                'type' => 'object', 'required' => ['state', 'opinion', 'confidence', 'findings', 'recommendations'],
                'properties' => [
                    'state' => ['type' => 'string', 'enum' => ['healthy', 'watch', 'risk', 'critical']], 'opinion' => ['type' => 'string'],
                    'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                    'findings' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 5],
                    'recommendations' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 5],
                ], 'additionalProperties' => false,
            ],
            temperature: 0.1, maxTokens: (int) config('assistant.performance.analysis_agent_max_tokens', 768),
            timeoutSeconds: (int) config('assistant.performance.analysis_agent_timeout_seconds', 120),
            correlationId: 'analysis-run-'.$runId.'-chunk-'.$chunkId, useCase: 'background_intelligence',
        ));

        if (! $result->ok || ! is_array($result->structuredPayload)) {
            return ['ok' => false, 'opinion' => null, 'provider' => $result->provider, 'model' => $result->model,
                'latency_ms' => $result->latencyMs, 'failure' => $result->failureReason ?? AssistantProviderResult::FAILURE_UNAVAILABLE];
        }

        return ['ok' => true, 'opinion' => [
            'state' => (string) data_get($result->structuredPayload, 'state', 'watch'),
            'opinion' => (string) data_get($result->structuredPayload, 'opinion', ''),
            'confidence' => (string) data_get($result->structuredPayload, 'confidence', 'low'),
            'findings' => array_values((array) data_get($result->structuredPayload, 'findings', [])),
            'recommendations' => array_values((array) data_get($result->structuredPayload, 'recommendations', [])),
            'source' => 'AGENT AI', 'generated_at' => now('UTC')->toIso8601String(),
        ], 'provider' => $result->provider, 'model' => $result->model, 'latency_ms' => $result->latencyMs, 'failure' => null];
    }

    private function collectMetrics(string $domain, array $storeIds): array
    {
        $to = CarbonImmutable::now('UTC');
        $from = $to->subDays(7);
        $previousFrom = $from->subDays(7);
        $facts = match ($domain) {
            'sales' => $this->salesMetrics($storeIds, $from, $to, $previousFrom),
            'customers' => $this->customerMetrics($storeIds, $from, $to),
            'products' => $this->productMetrics($storeIds),
            'inventory' => $this->inventoryMetrics($storeIds),
            'finance' => $this->financeMetrics($storeIds, $from, $to),
            'operations' => $this->operationsMetrics($storeIds, $from, $to),
            'support' => $this->supportMetrics($storeIds, $from, $to),
            default => throw new \InvalidArgumentException('Unsupported analysis domain ['.$domain.'].'),
        };

        return [
            'source' => 'DATABASE', 'domain' => $domain, 'store_count' => count($storeIds), 'store_ids' => $storeIds,
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'previous_from' => $previousFrom->toIso8601String(), 'previous_to' => $from->toIso8601String()],
            'facts' => $facts, 'generated_at' => now('UTC')->toIso8601String(),
        ];
    }

    private function salesMetrics(array $storeIds, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $previousFrom): array
    {
        $current = $this->orders($storeIds, $from, $to);
        $previous = $this->orders($storeIds, $previousFrom, $from);
        $revenue = round((float) (clone $current)->sum('orders.grand_total'), 3);
        $previousRevenue = round((float) (clone $previous)->sum('orders.grand_total'), 3);
        $orders = (int) (clone $current)->count('orders.id');
        $recognized = (int) (clone $current)->whereNotIn('orders.status', ['cancelled', 'refunded'])->count('orders.id');

        return [
            'orders' => $orders, 'recognized_orders' => $recognized, 'revenue' => $revenue, 'previous_revenue' => $previousRevenue,
            'revenue_change_percent' => $previousRevenue == 0.0 ? null : round((($revenue - $previousRevenue) / abs($previousRevenue)) * 100, 2),
            'average_order_value' => $recognized === 0 ? 0 : round($revenue / $recognized, 3),
            'currencies' => array_values(array_unique((clone $current)->whereNotNull('orders.currency')->distinct()->pluck('orders.currency')->map('strval')->all())),
        ];
    }

    private function customerMetrics(array $storeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasColumn('orders', 'customer_id')) {
            return ['available' => false, 'reason' => 'orders.customer_id_missing'];
        }
        $orders = $this->orders($storeIds, $from, $to)->whereNotNull('orders.customer_id');
        $unique = (int) (clone $orders)->distinct()->count('orders.customer_id');
        $repeat = DB::query()->fromSub((clone $orders)->selectRaw('orders.customer_id, COUNT(*) as order_count')->groupBy('orders.customer_id'), 'customer_orders')
            ->where('order_count', '>=', 2)->count();

        return ['available' => true, 'unique_customers' => $unique, 'repeat_customers' => (int) $repeat,
            'repeat_customer_percent' => $unique === 0 ? 0 : round(((int) $repeat / $unique) * 100, 2)];
    }

    private function productMetrics(array $storeIds): array
    {
        if (! Schema::hasTable('store_products')) {
            return ['available' => false, 'reason' => 'store_products_missing'];
        }
        $query = DB::table('store_products')->whereIn('store_id', $storeIds);
        $total = (int) (clone $query)->count();
        $hasCost = Schema::hasColumn('store_products', 'cost_price');
        $costed = $hasCost ? (int) (clone $query)->whereNotNull('cost_price')->count() : null;

        return ['available' => true, 'store_product_rows' => $total, 'costed_rows' => $costed,
            'cost_coverage_percent' => ! $hasCost || $total === 0 ? null : round(((int) $costed / $total) * 100, 2)];
    }

    private function inventoryMetrics(array $storeIds): array
    {
        if (! Schema::hasTable('inventories') || ! Schema::hasTable('warehouses')) {
            return ['available' => false, 'reason' => 'inventory_tables_missing'];
        }
        $query = DB::table('inventories')->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')->whereIn('warehouses.store_id', $storeIds);
        $onHand = round((float) (clone $query)->sum('inventories.quantity'), 3);
        $hasReserved = Schema::hasColumn('inventories', 'reserved_quantity');
        $reserved = $hasReserved ? round((float) (clone $query)->sum('inventories.reserved_quantity'), 3) : 0.0;
        $stockouts = $hasReserved
            ? (int) (clone $query)->whereRaw('(COALESCE(inventories.quantity,0) - COALESCE(inventories.reserved_quantity,0)) <= 0')->distinct()->count('inventories.product_id')
            : (int) (clone $query)->where('inventories.quantity', '<=', 0)->distinct()->count('inventories.product_id');

        return ['available' => true, 'on_hand_units' => $onHand, 'reserved_units' => $reserved, 'available_units' => round($onHand - $reserved, 3),
            'sku_count' => (int) (clone $query)->distinct()->count('inventories.product_id'), 'stockout_skus' => $stockouts];
    }

    private function financeMetrics(array $storeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasTable('payments')) {
            return ['available' => false, 'reason' => 'payments_missing'];
        }
        $query = DB::table('payments')->join('orders', 'orders.id', '=', 'payments.order_id')->whereIn('orders.store_id', $storeIds)
            ->whereBetween('payments.created_at', [$from->toDateTimeString(), $to->toDateTimeString()]);

        return ['available' => true, 'payments' => (int) (clone $query)->count('payments.id'),
            'collected' => round((float) (clone $query)->whereIn('payments.status', ['paid', 'captured'])->sum('payments.amount'), 3),
            'refunded' => round((float) (clone $query)->where('payments.status', 'refunded')->sum('payments.amount'), 3),
            'pending' => (int) (clone $query)->whereIn('payments.status', ['pending', 'processing'])->count('payments.id')];
    }

    private function operationsMetrics(array $storeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = $this->orders($storeIds, $from, $to);
        $statuses = (clone $query)->selectRaw('orders.status, COUNT(*) as row_count')->groupBy('orders.status')->orderByDesc('row_count')->get()
            ->mapWithKeys(static fn (object $row): array => [(string) $row->status => (int) $row->row_count])->all();

        return ['orders' => (int) (clone $query)->count('orders.id'), 'status_breakdown' => $statuses,
            'cancelled' => (int) ($statuses['cancelled'] ?? 0), 'awaiting_dispatch' => (int) ($statuses['awaiting_dispatch'] ?? 0)];
    }

    private function supportMetrics(array $storeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasTable('customer_service_cases')) {
            return ['available' => false, 'reason' => 'customer_service_cases_missing'];
        }
        $query = DB::table('customer_service_cases')->whereIn('store_id', $storeIds)->whereBetween('created_at', [$from->toDateTimeString(), $to->toDateTimeString()]);
        $overdue = Schema::hasColumn('customer_service_cases', 'sla_target_at')
            ? (int) (clone $query)->whereIn('status', ['open', 'pending'])->whereNotNull('sla_target_at')->where('sla_target_at', '<', now('UTC'))->count() : 0;

        return ['available' => true, 'cases' => (int) (clone $query)->count(),
            'open_or_pending' => (int) (clone $query)->whereIn('status', ['open', 'pending'])->count(), 'overdue_sla' => $overdue];
    }

    private function orders(array $storeIds, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('orders')->whereIn('orders.store_id', $storeIds)
            ->whereBetween('orders.created_at', [$from->toDateTimeString(), $to->toDateTimeString()]);
    }

    private function decodeIds(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)->map(static fn ($id): int => (int) $id)->filter(static fn (int $id): bool => $id > 0)->unique()->values()->all();
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function displayTime(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value, 'UTC')->setTimezone('Asia/Kuwait')->format('Y-m-d H:i');
    }
}
