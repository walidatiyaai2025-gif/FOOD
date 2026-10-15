<?php

namespace Tests\Unit\Performance;

use PHPUnit\Framework\TestCase;
use Tests\Performance\Support\SlowQueryProbe;

final class SlowQueryProbeTest extends TestCase
{
    public function test_it_reports_slowest_queries_and_flags_250ms_threshold(): void
    {
        $probe = new SlowQueryProbe;

        $probe->record('select * from orders where store_id = 10', 100.0);
        $probe->record('select * from orders where store_id = 20', 280.0);
        $probe->record('select count(*) from order_items where order_id = 1', 320.0);

        $snapshot = $probe->snapshot();

        $this->assertSame(2, $snapshot['flagged_query_count']);
        $this->assertSame(320.0, $snapshot['slowest'][0]['max_ms']);
        $this->assertTrue($snapshot['slowest'][0]['investigation_required']);
        $this->assertSame(2, $snapshot['slowest'][1]['count']);
        $this->assertSame(190.0, $snapshot['slowest'][1]['avg_ms']);
        $this->assertTrue($snapshot['slowest'][1]['investigation_required']);
    }

    public function test_snapshot_never_contains_raw_bindings(): void
    {
        $probe = new SlowQueryProbe;
        $probe->record(
            "select * from customers where email = 'secret@example.test' and id = 999",
            10.0,
            'mysql'
        );

        $snapshot = $probe->snapshot();
        $serialized = json_encode($snapshot, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('secret@example.test', $serialized);
        $this->assertStringNotContainsString('999', $serialized);
        $this->assertStringContainsString('where email = ? and id = ?', $serialized);
    }

    public function test_limit_and_reset_are_deterministic(): void
    {
        $probe = new SlowQueryProbe(50.0);
        $probe->record('select 1', 10.0);
        $probe->record('select 2', 20.0);

        $this->assertCount(1, $probe->snapshot(1)['slowest']);

        $probe->reset();

        $this->assertSame([], $probe->snapshot()['slowest']);
    }
}
