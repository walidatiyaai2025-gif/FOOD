<?php

namespace Tests\Performance\Support;

use InvalidArgumentException;
use JsonException;

final class EnterprisePerformanceLineage
{
    /**
     * @return array{mode: 'baseline'|'candidate', source_head: string, baseline_head: string|null}
     */
    public static function resolve(string $defaultSourceHead, ?array $githubEvent = null): array
    {
        self::assertSha($defaultSourceHead, 'default source head');

        $mode = trim((string) getenv('FOODEX_PERF_MODE'));
        $source = strtolower(trim((string) getenv('FOODEX_PERF_SOURCE_HEAD')));
        $baseline = strtolower(trim((string) getenv('FOODEX_PERF_BASELINE_HEAD')));

        if ($mode === '' && $source === '' && $baseline === '') {
            $eventLineage = self::githubEventLineage(
                $githubEvent ?? self::githubEvent(),
            );

            if ($eventLineage !== null) {
                return $eventLineage;
            }

            return [
                'mode' => 'baseline',
                'source_head' => strtolower($defaultSourceHead),
                'baseline_head' => null,
            ];
        }

        if (! in_array($mode, ['baseline', 'candidate'], true)) {
            throw new InvalidArgumentException(
                'FOODEX_PERF_MODE must be baseline or candidate when explicit lineage is supplied.'
            );
        }

        self::assertSha($source, 'FOODEX_PERF_SOURCE_HEAD');

        if ($mode === 'baseline') {
            if ($baseline !== '') {
                throw new InvalidArgumentException(
                    'Baseline mode must not declare FOODEX_PERF_BASELINE_HEAD.'
                );
            }

            return [
                'mode' => 'baseline',
                'source_head' => $source,
                'baseline_head' => null,
            ];
        }

        self::assertSha($baseline, 'FOODEX_PERF_BASELINE_HEAD');

        if ($source === $baseline) {
            throw new InvalidArgumentException(
                'Candidate source head must differ from its accepted baseline head.'
            );
        }

        return [
            'mode' => 'candidate',
            'source_head' => $source,
            'baseline_head' => $baseline,
        ];
    }

    /**
     * Pull-request CI must identify the exact child head and exact integration
     * base rather than the synthetic merge SHA. A direct Performance integration
     * push uses the exact before/after pair so post-merge correction evidence is
     * also lineage-safe.
     *
     * @param  array<string, mixed>  $event
     * @return array{mode:'candidate',source_head:string,baseline_head:string}|null
     */
    private static function githubEventLineage(array $event): ?array
    {
        $pullRequest = $event['pull_request'] ?? null;
        if (is_array($pullRequest)) {
            return self::candidatePair(
                (string) ($pullRequest['head']['sha'] ?? ''),
                (string) ($pullRequest['base']['sha'] ?? ''),
                'GitHub pull request',
            );
        }

        $ref = trim((string) ($event['ref'] ?? ''));
        if ($ref === 'refs/heads/feature/enterprise-performance-gates') {
            return self::candidatePair(
                (string) ($event['after'] ?? ''),
                (string) ($event['before'] ?? ''),
                'Performance integration push',
            );
        }

        return null;
    }

    /**
     * @return array{mode:'candidate',source_head:string,baseline_head:string}
     */
    private static function candidatePair(string $source, string $baseline, string $label): array
    {
        $source = strtolower(trim($source));
        $baseline = strtolower(trim($baseline));

        self::assertSha($source, $label.' source SHA');
        self::assertSha($baseline, $label.' baseline SHA');

        if ($source === $baseline) {
            throw new InvalidArgumentException(
                $label.' source head must differ from its baseline head.'
            );
        }

        return [
            'mode' => 'candidate',
            'source_head' => $source,
            'baseline_head' => $baseline,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function githubEvent(): array
    {
        $path = trim((string) getenv('GITHUB_EVENT_PATH'));
        if ($path === '') {
            return [];
        }

        $payload = @file_get_contents($path);
        if (! is_string($payload) || $payload === '') {
            throw new InvalidArgumentException(
                'GITHUB_EVENT_PATH is set but the event payload is unreadable.'
            );
        }

        try {
            $event = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'GitHub event payload is not valid JSON.',
                previous: $exception,
            );
        }

        if (! is_array($event)) {
            throw new InvalidArgumentException('GitHub event payload must decode to an object.');
        }

        return $event;
    }

    private static function assertSha(string $value, string $label): void
    {
        if (preg_match('/^[0-9a-f]{40,64}$/', strtolower($value)) !== 1) {
            throw new InvalidArgumentException($label.' must be a 40-64 character lowercase hex SHA.');
        }
    }
}
