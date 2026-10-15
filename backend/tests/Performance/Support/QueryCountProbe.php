<?php

namespace Tests\Performance\Support;

final class QueryCountProbe
{
    private int $count = 0;

    /**
     * @var array<string, array{fingerprint: string, sql: string, count: int}>
     */
    private array $queries = [];

    public function record(string $sql): void
    {
        $this->count++;

        $normalized = self::normalizeSql($sql);
        $fingerprint = hash('sha256', $normalized);

        if (! isset($this->queries[$fingerprint])) {
            $this->queries[$fingerprint] = [
                'fingerprint' => $fingerprint,
                'sql' => $normalized,
                'count' => 0,
            ];
        }

        $this->queries[$fingerprint]['count']++;
    }

    /**
     * @return array{
     *     count: int,
     *     unique_query_count: int,
     *     queries: array<int, array{fingerprint: string, sql: string, count: int}>
     * }
     */
    public function snapshot(): array
    {
        $queries = array_values($this->queries);

        usort(
            $queries,
            static fn (array $left, array $right): int => [$right['count'], $left['fingerprint']]
                <=> [$left['count'], $right['fingerprint']]
        );

        return [
            'count' => $this->count,
            'unique_query_count' => count($queries),
            'queries' => $queries,
        ];
    }

    public function reset(): void
    {
        $this->count = 0;
        $this->queries = [];
    }

    public static function normalizeSql(string $sql): string
    {
        $normalized = preg_replace("/'(?:''|[^'])*'/", '?', $sql) ?? $sql;
        $normalized = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', trim($normalized)) ?? trim($normalized);

        return strtolower($normalized);
    }
}
