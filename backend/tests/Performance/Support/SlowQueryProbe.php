<?php

namespace Tests\Performance\Support;

final class SlowQueryProbe
{
    /**
     * @var array<string, array{
     *     fingerprint: string,
     *     sql: string,
     *     connection: string,
     *     count: int,
     *     total_ms: float,
     *     max_ms: float
     * }>
     */
    private array $queries = [];

    public function __construct(private readonly float $investigationThresholdMs = 250.0) {}

    public function record(string $sql, float $timeMs, string $connection = 'default'): void
    {
        $normalized = QueryCountProbe::normalizeSql($sql);
        $fingerprint = hash('sha256', $connection.'|'.$normalized);

        if (! isset($this->queries[$fingerprint])) {
            $this->queries[$fingerprint] = [
                'fingerprint' => $fingerprint,
                'sql' => $normalized,
                'connection' => $connection,
                'count' => 0,
                'total_ms' => 0.0,
                'max_ms' => 0.0,
            ];
        }

        $this->queries[$fingerprint]['count']++;
        $this->queries[$fingerprint]['total_ms'] += $timeMs;
        $this->queries[$fingerprint]['max_ms'] = max(
            $this->queries[$fingerprint]['max_ms'],
            $timeMs
        );
    }

    /**
     * @return array{
     *     investigation_threshold_ms: float,
     *     flagged_query_count: int,
     *     slowest: array<int, array{
     *         fingerprint: string,
     *         sql: string,
     *         connection: string,
     *         count: int,
     *         total_ms: float,
     *         max_ms: float,
     *         avg_ms: float,
     *         investigation_required: bool
     *     }>
     * }
     */
    public function snapshot(int $limit = 10): array
    {
        $rows = [];

        foreach ($this->queries as $query) {
            $rows[] = [
                ...$query,
                'avg_ms' => $query['count'] === 0 ? 0.0 : $query['total_ms'] / $query['count'],
                'investigation_required' => $query['max_ms'] >= $this->investigationThresholdMs,
            ];
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => [$right['max_ms'], $left['fingerprint']]
                <=> [$left['max_ms'], $right['fingerprint']]
        );

        return [
            'investigation_threshold_ms' => $this->investigationThresholdMs,
            'flagged_query_count' => count(array_filter(
                $rows,
                static fn (array $row): bool => $row['investigation_required']
            )),
            'slowest' => array_slice($rows, 0, max(0, $limit)),
        ];
    }

    public function reset(): void
    {
        $this->queries = [];
    }
}
