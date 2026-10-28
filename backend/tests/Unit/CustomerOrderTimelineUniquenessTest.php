<?php

namespace Tests\Unit;

use App\Services\CustomerOrderTimelineService;
use PHPUnit\Framework\TestCase;

final class CustomerOrderTimelineUniquenessTest extends TestCase
{
    public function test_status_history_and_delivery_proof_show_one_customer_milestone(): void
    {
        $events = [
            ['stage' => 'placed', 'source' => 'order', 'occurred_at' => '2026-10-28T09:00:00Z'],
            ['stage' => 'out_for_delivery', 'source' => 'driver', 'occurred_at' => '2026-10-28T10:00:00Z'],
            ['stage' => 'out_for_delivery', 'source' => 'order_status', 'occurred_at' => '2026-10-28T10:01:00Z'],
            ['stage' => 'delivered', 'source' => 'driver', 'occurred_at' => '2026-10-28T11:00:00Z'],
        ];

        $timeline = (new CustomerOrderTimelineService())->uniqueStages($events);

        self::assertSame(['placed', 'out_for_delivery', 'delivered'], array_column($timeline, 'stage'));
        self::assertSame('driver', $timeline[1]['source']);
        self::assertCount(3, $timeline);
    }

    public function test_distinct_milestones_remain_in_order(): void
    {
        $events = [
            ['stage' => 'placed'],
            ['stage' => 'accepted'],
            ['stage' => 'picked_up'],
            ['stage' => 'picked_up'],
            ['stage' => 'out_for_delivery'],
        ];

        self::assertSame(
            ['placed', 'accepted', 'picked_up', 'out_for_delivery'],
            array_column((new CustomerOrderTimelineService())->uniqueStages($events), 'stage')
        );
    }
}
