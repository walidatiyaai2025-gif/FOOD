<?php

namespace Tests\Unit\Performance;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Performance\Support\QueryScalingGate;

final class QueryScalingGateTest extends TestCase
{
    public function test_bounded_list_query_growth_passes(): void
    {
        $result = QueryScalingGate::evaluate(
            smallRows: 25,
            smallQueries: 8,
            largeRows: 100,
            largeQueries: 10,
        );

        $this->assertSame('PASS', $result['status']);
        $this->assertSame(75, $result['extra_rows']);
        $this->assertSame(2, $result['extra_queries']);
        $this->assertLessThanOrEqual(0.10, $result['queries_per_extra_row']);
    }

    public function test_per_row_query_growth_fails(): void
    {
        $result = QueryScalingGate::evaluate(
            smallRows: 25,
            smallQueries: 33,
            largeRows: 100,
            largeQueries: 108,
        );

        $this->assertSame('FAIL', $result['status']);
        $this->assertSame(75, $result['extra_queries']);
        $this->assertEqualsWithDelta(
            1.0,
            (float) $result['queries_per_extra_row'],
            0.000001,
        );
    }

    public function test_assertion_throws_with_numeric_regression_evidence(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('25 -> 100 rows caused 12 -> 87 queries');

        QueryScalingGate::assertBounded(
            smallRows: 25,
            smallQueries: 12,
            largeRows: 100,
            largeQueries: 87,
        );
    }

    public function test_small_fixed_query_delta_is_allowed_even_on_small_cardinality_change(): void
    {
        $result = QueryScalingGate::evaluate(
            smallRows: 5,
            smallQueries: 8,
            largeRows: 10,
            largeQueries: 10,
        );

        $this->assertSame('PASS', $result['status']);
    }

    public function test_invalid_cardinality_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QueryScalingGate::evaluate(
            smallRows: 25,
            smallQueries: 8,
            largeRows: 25,
            largeQueries: 8,
        );
    }
}
