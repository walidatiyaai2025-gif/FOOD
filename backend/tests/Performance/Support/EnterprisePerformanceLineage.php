<?php

namespace Tests\Performance\Support;

use InvalidArgumentException;

final class EnterprisePerformanceLineage
{
    /**
     * @return array{mode: 'baseline'|'candidate', source_head: string, baseline_head: string|null}
     */
    public static function resolve(string $defaultSourceHead): array
    {
        self::assertSha($defaultSourceHead, 'default source head');

        $mode = trim((string) getenv('FOODEX_PERF_MODE'));
        $source = strtolower(trim((string) getenv('FOODEX_PERF_SOURCE_HEAD')));
        $baseline = strtolower(trim((string) getenv('FOODEX_PERF_BASELINE_HEAD')));

        if ($mode === '' && $source === '' && $baseline === '') {
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

    private static function assertSha(string $value, string $label): void
    {
        if (preg_match('/^[0-9a-f]{40,64}$/', strtolower($value)) !== 1) {
            throw new InvalidArgumentException($label.' must be a 40-64 character lowercase hex SHA.');
        }
    }
}
