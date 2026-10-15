<?php

namespace Tests\Performance\Support;

use InvalidArgumentException;
use RuntimeException;

final class QueryScalingGate
{
    /**
     * @return array{
     *     small_rows:int,
     *     large_rows:int,
     *     small_queries:int,
     *     large_queries:int,
     *     extra_rows:int,
     *     extra_queries:int,
     *     queries_per_extra_row:float,
     *     max_queries_per_extra_row:float,
     *     fixed_query_slack:int,
     *     status:string
     * }
     */
    public static function evaluate(
        int $smallRows,
        int $smallQueries,
        int $largeRows,
        int $largeQueries,
        float $maxQueriesPerExtraRow = 0.10,
        int $fixedQuerySlack = 2,
    ): array {
        if ($smallRows < 1 || $largeRows <= $smallRows) {
            throw new InvalidArgumentException('Large row cardinality must be greater than a positive small cardinality.');
        }

        if ($smallQueries < 0 || $largeQueries < 0) {
            throw new InvalidArgumentException('Query counts must be non-negative.');
        }

        if ($maxQueriesPerExtraRow < 0.0 || $fixedQuerySlack < 0) {
            throw new InvalidArgumentException('N+1 thresholds must be non-negative.');
        }

        $extraRows = $largeRows - $smallRows;
        $extraQueries = max(0, $largeQueries - $smallQueries);
        $slope = $extraQueries / $extraRows;
        $fail = $extraQueries > $fixedQuerySlack && $slope > $maxQueriesPerExtraRow;

        return [
            'small_rows' => $smallRows,
            'large_rows' => $largeRows,
            'small_queries' => $smallQueries,
            'large_queries' => $largeQueries,
            'extra_rows' => $extraRows,
            'extra_queries' => $extraQueries,
            'queries_per_extra_row' => $slope,
            'max_queries_per_extra_row' => $maxQueriesPerExtraRow,
            'fixed_query_slack' => $fixedQuerySlack,
            'status' => $fail ? 'FAIL' : 'PASS',
        ];
    }

    public static function assertBounded(
        int $smallRows,
        int $smallQueries,
        int $largeRows,
        int $largeQueries,
        float $maxQueriesPerExtraRow = 0.10,
        int $fixedQuerySlack = 2,
    ): void {
        $result = self::evaluate(
            $smallRows,
            $smallQueries,
            $largeRows,
            $largeQueries,
            $maxQueriesPerExtraRow,
            $fixedQuerySlack,
        );

        if ($result['status'] === 'FAIL') {
            throw new RuntimeException(sprintf(
                'N+1/query-scaling regression: %d -> %d rows caused %d -> %d queries (%.4f extra queries per added row; max %.4f).',
                $smallRows,
                $largeRows,
                $smallQueries,
                $largeQueries,
                $result['queries_per_extra_row'],
                $maxQueriesPerExtraRow,
            ));
        }
    }
}
