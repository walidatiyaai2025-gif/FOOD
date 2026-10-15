<?php

namespace Tests\Unit\Performance;

use PHPUnit\Framework\TestCase;
use Tests\Performance\Support\QueryCountProbe;

final class QueryCountProbeTest extends TestCase
{
    public function test_it_counts_queries_and_groups_normalized_fingerprints(): void
    {
        $probe = new QueryCountProbe;

        $probe->record("select * from orders where store_id = 10 and status = 'pending'");
        $probe->record("select  *  from orders where store_id = 22 and status = 'confirmed'");
        $probe->record('select count(*) from order_items where order_id = 91');

        $snapshot = $probe->snapshot();

        $this->assertSame(3, $snapshot['count']);
        $this->assertSame(2, $snapshot['unique_query_count']);
        $this->assertSame(2, $snapshot['queries'][0]['count']);
        $this->assertSame(
            'select * from orders where store_id = ? and status = ?',
            $snapshot['queries'][0]['sql']
        );
    }

    public function test_reset_clears_all_measurement_state(): void
    {
        $probe = new QueryCountProbe;
        $probe->record('select 1');

        $probe->reset();

        $this->assertSame([
            'count' => 0,
            'unique_query_count' => 0,
            'queries' => [],
        ], $probe->snapshot());
    }

    public function test_normalization_is_deterministic_for_whitespace_and_literals(): void
    {
        $left = QueryCountProbe::normalizeSql("SELECT *\nFROM orders WHERE id=100 AND channel='b2c'");
        $right = QueryCountProbe::normalizeSql(" select * from orders where id=200 and channel='b2b' ");

        $this->assertSame($left, $right);
    }
}
