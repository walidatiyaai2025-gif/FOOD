<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class AssistantObservability
{
    private const TABLE = 'assistant_observability_events';

    /** @var list<string> */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'authorization',
        'api_key',
        'apikey',
        'token',
        'secret',
        'password',
        'cookie',
        'credential',
        'prompt',
        'message',
        'content',
        'argument',
        'payload',
        'customer',
        'email',
        'phone',
        'address',
        'name',
    ];

    /**
     * Record only bounded operational metadata. Provider prompts, tool arguments,
     * tool results, customer payloads and credentials are intentionally excluded.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function record(?string $traceId, string $eventName, array $attributes = []): string
    {
        $traceId = $this->traceId($traceId);

        if (! $this->enabled() || ! $this->tableExists()) {
            return $traceId;
        }

        try {
            $now = now();

            DB::table(self::TABLE)->insert([
                'trace_id' => $traceId,
                'actor_user_id' => $this->positiveInt($attributes['actor_user_id'] ?? null),
                'store_id' => $this->positiveInt($attributes['store_id'] ?? null),
                'event_name' => $this->boundedString($eventName, 80) ?? 'unknown',
                'phase' => $this->boundedString($attributes['phase'] ?? null, 32),
                'provider' => $this->boundedString($attributes['provider'] ?? null, 64),
                'model' => $this->boundedString($attributes['model'] ?? null, 160),
                'tool_key' => $this->boundedString($attributes['tool_key'] ?? null, 160),
                'outcome' => $this->boundedString($attributes['outcome'] ?? null, 80),
                'status_code' => $this->boundedInt($attributes['status_code'] ?? null, 100, 599),
                'latency_ms' => $this->boundedInt($attributes['latency_ms'] ?? null, 0, 86_400_000),
                'fallback' => (bool) ($attributes['fallback'] ?? false),
                'prompt_tokens' => $this->boundedInt($attributes['prompt_tokens'] ?? null, 0, 100_000_000),
                'completion_tokens' => $this->boundedInt($attributes['completion_tokens'] ?? null, 0, 100_000_000),
                'total_tokens' => $this->boundedInt($attributes['total_tokens'] ?? null, 0, 100_000_000),
                'metadata' => $this->encodedMetadata($attributes['metadata'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->pruneIfDue();
        } catch (Throwable $exception) {
            Log::warning('assistant.observability.write_failed', [
                'exception' => $exception::class,
            ]);
        }

        return $traceId;
    }

    /** @return array<string,mixed> */
    public function summary(?int $hours = null): array
    {
        $hours = $this->windowHours($hours);
        $empty = [
            'available' => false,
            'window_hours' => $hours,
            'retention_days' => $this->retentionDays(),
            'request_count' => 0,
            'provider_requests' => 0,
            'provider_errors' => 0,
            'avg_provider_latency_ms' => 0,
            'tool_calls' => 0,
            'tool_errors' => 0,
            'fallback_rate_percent' => 0.0,
            'total_tokens' => 0,
            'last_provider' => null,
        ];

        if (! $this->enabled() || ! $this->tableExists()) {
            return $empty;
        }

        try {
            $since = now()->subHours($hours);
            $base = DB::table(self::TABLE)->where('created_at', '>=', $since);
            $provider = (clone $base)
                ->where('phase', 'provider')
                ->where('event_name', 'provider.transport');
            $tool = (clone $base)->where('phase', 'tool');

            $providerRequests = (clone $provider)->count();
            $providerFallbacks = (clone $provider)->where('fallback', true)->count();
            $lastProvider = (clone $base)
                ->where('phase', 'provider')
                ->latest('id')
                ->first();

            return [
                ...$empty,
                'available' => true,
                'request_count' => (clone $base)
                    ->where('event_name', 'turn.started')
                    ->distinct('trace_id')
                    ->count('trace_id'),
                'provider_requests' => $providerRequests,
                'provider_errors' => (clone $provider)
                    ->whereNotIn('outcome', ['success', 'health_ok'])
                    ->count(),
                'avg_provider_latency_ms' => (int) round((float) ((clone $provider)->avg('latency_ms') ?? 0)),
                'tool_calls' => (clone $tool)
                    ->whereIn('event_name', ['tool.completed', 'tool.failed'])
                    ->count(),
                'tool_errors' => (clone $tool)->where('event_name', 'tool.failed')->count(),
                'fallback_rate_percent' => $providerRequests === 0
                    ? 0.0
                    : round(($providerFallbacks / $providerRequests) * 100, 1),
                'total_tokens' => (int) ((clone $base)
                    ->where('event_name', 'provider.result')
                    ->sum('total_tokens') ?? 0),
                'last_provider' => $lastProvider === null ? null : $this->safeRow($lastProvider),
            ];
        } catch (Throwable $exception) {
            Log::warning('assistant.observability.read_failed', ['exception' => $exception::class]);

            return $empty;
        }
    }

    /** @return list<array<string,mixed>> */
    public function recentTraces(int $limit = 25, ?int $hours = null): array
    {
        if (! $this->enabled() || ! $this->tableExists()) {
            return [];
        }

        $limit = min(50, max(1, $limit));
        $hours = $this->windowHours($hours);

        try {
            $rows = DB::table(self::TABLE)
                ->where('created_at', '>=', now()->subHours($hours))
                ->latest('id')
                ->limit(min(2000, $limit * 40))
                ->get();

            $traces = [];

            foreach ($rows as $row) {
                $traceId = (string) $row->trace_id;
                if (! isset($traces[$traceId])) {
                    if (count($traces) >= $limit) {
                        continue;
                    }

                    $traces[$traceId] = [
                        'trace_id' => $traceId,
                        'actor_user_id' => $row->actor_user_id === null ? null : (int) $row->actor_user_id,
                        'store_id' => $row->store_id === null ? null : (int) $row->store_id,
                        'started_at' => $row->created_at,
                        'last_event_at' => $row->created_at,
                        'provider' => $row->provider,
                        'model' => $row->model,
                        'outcome' => null,
                        'latency_ms' => null,
                        'fallback' => false,
                        'total_tokens' => 0,
                        'tools' => [],
                        'event_count' => 0,
                    ];
                }

                $trace = &$traces[$traceId];
                $trace['event_count']++;
                $trace['started_at'] = min((string) $trace['started_at'], (string) $row->created_at);
                $trace['last_event_at'] = max((string) $trace['last_event_at'], (string) $row->created_at);
                $trace['provider'] ??= $row->provider;
                $trace['model'] ??= $row->model;
                $trace['fallback'] = $trace['fallback'] || (bool) $row->fallback;
                $trace['total_tokens'] += $row->event_name === 'provider.result' ? (int) ($row->total_tokens ?? 0) : 0;

                if ($row->tool_key !== null && ! in_array($row->tool_key, $trace['tools'], true)) {
                    $trace['tools'][] = (string) $row->tool_key;
                }

                if (in_array($row->event_name, ['turn.completed', 'turn.failed'], true)) {
                    $trace['outcome'] ??= $row->outcome;
                    $trace['latency_ms'] ??= $row->latency_ms === null ? null : (int) $row->latency_ms;
                } elseif ($trace['latency_ms'] === null && $row->latency_ms !== null) {
                    $trace['latency_ms'] = (int) $row->latency_ms;
                }

                unset($trace);
            }

            return array_values($traces);
        } catch (Throwable $exception) {
            Log::warning('assistant.observability.read_failed', ['exception' => $exception::class]);

            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    public function trace(string $traceId): array
    {
        $traceId = $this->traceId($traceId, generateWhenInvalid: false);
        if ($traceId === '' || ! $this->enabled() || ! $this->tableExists()) {
            return [];
        }

        try {
            return DB::table(self::TABLE)
                ->where('trace_id', $traceId)
                ->oldest('id')
                ->limit(100)
                ->get()
                ->map(fn (object $row): array => $this->safeRow($row))
                ->all();
        } catch (Throwable $exception) {
            Log::warning('assistant.observability.read_failed', ['exception' => $exception::class]);

            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    public function exportRows(?int $hours = null): array
    {
        if (! $this->enabled() || ! $this->tableExists()) {
            return [];
        }

        $hours = $this->windowHours($hours);
        $max = min(10_000, max(100, (int) config('assistant.observability.export_max_rows', 5000)));

        try {
            return DB::table(self::TABLE)
                ->where('created_at', '>=', now()->subHours($hours))
                ->latest('id')
                ->limit($max)
                ->get()
                ->map(fn (object $row): array => $this->safeRow($row))
                ->all();
        } catch (Throwable $exception) {
            Log::warning('assistant.observability.read_failed', ['exception' => $exception::class]);

            return [];
        }
    }

    public function prune(): int
    {
        if (! $this->tableExists()) {
            return 0;
        }

        return DB::table(self::TABLE)
            ->where('created_at', '<', now()->subDays($this->retentionDays()))
            ->delete();
    }

    private function pruneIfDue(): void
    {
        $key = 'assistant:observability:prune:'.now()->toDateString();

        if (Cache::add($key, true, now()->addDay())) {
            $this->prune();
        }
    }

    private function enabled(): bool
    {
        return (bool) config('assistant.observability.enabled', true);
    }

    private function tableExists(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    private function retentionDays(): int
    {
        return min(90, max(1, (int) config('assistant.observability.retention_days', 14)));
    }

    private function windowHours(?int $hours): int
    {
        return min(168, max(1, $hours ?? (int) config('assistant.observability.admin_window_hours', 24)));
    }

    private function traceId(?string $traceId, bool $generateWhenInvalid = true): string
    {
        $traceId = trim((string) $traceId);

        if ($traceId !== '' && preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $traceId) === 1) {
            return $traceId;
        }

        return $generateWhenInvalid ? (string) Str::uuid() : '';
    }

    private function positiveInt(mixed $value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($value) && $value > 0 ? $value : null;
    }

    private function boundedInt(mixed $value, int $min, int $max): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value >= $min && $value <= $max ? $value : null;
    }

    private function boundedString(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function encodedMetadata(mixed $metadata): ?string
    {
        if (! is_array($metadata) || $metadata === []) {
            return null;
        }

        $safe = $this->sanitizeMetadata($metadata);

        return $safe === [] ? null : json_encode(
            $safe,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string,mixed> */
    private function sanitizeMetadata(array $metadata, int $depth = 0): array
    {
        if ($depth >= 3) {
            return [];
        }

        $safe = [];

        foreach (array_slice($metadata, 0, 20, true) as $key => $value) {
            $key = mb_substr((string) $key, 0, 80);
            $normalized = strtolower(str_replace(['-', '.'], '_', $key));

            if ($this->sensitiveKey($normalized)) {
                $safe[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $safe[$key] = array_is_list($value)
                    ? array_values(array_slice(array_map(
                        fn (mixed $item): mixed => is_array($item)
                            ? $this->sanitizeMetadata($item, $depth + 1)
                            : $this->safeScalar($item),
                        $value,
                    ), 0, 20))
                    : $this->sanitizeMetadata($value, $depth + 1);

                continue;
            }

            $safe[$key] = $this->safeScalar($value);
        }

        return $safe;
    }

    private function sensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function safeScalar(mixed $value): mixed
    {
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return is_string($value) ? mb_substr($value, 0, 160) : '[UNSUPPORTED]';
    }

    /** @return array<string,mixed> */
    private function safeRow(object $row): array
    {
        return [
            'trace_id' => (string) $row->trace_id,
            'actor_user_id' => $row->actor_user_id === null ? null : (int) $row->actor_user_id,
            'store_id' => $row->store_id === null ? null : (int) $row->store_id,
            'event_name' => (string) $row->event_name,
            'phase' => $row->phase,
            'provider' => $row->provider,
            'model' => $row->model,
            'tool_key' => $row->tool_key,
            'outcome' => $row->outcome,
            'status_code' => $row->status_code === null ? null : (int) $row->status_code,
            'latency_ms' => $row->latency_ms === null ? null : (int) $row->latency_ms,
            'fallback' => (bool) $row->fallback,
            'prompt_tokens' => $row->prompt_tokens === null ? null : (int) $row->prompt_tokens,
            'completion_tokens' => $row->completion_tokens === null ? null : (int) $row->completion_tokens,
            'total_tokens' => $row->total_tokens === null ? null : (int) $row->total_tokens,
            'created_at' => (string) $row->created_at,
        ];
    }
}
