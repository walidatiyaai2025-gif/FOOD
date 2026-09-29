<?php

namespace Tests\Feature;

use App\Models\NotificationCampaign;
use App\Services\SchedulerRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulerRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private string $heartbeatPath;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'foodex.scheduler_heartbeat' => true,
            'foodex.scheduler_auto_provision' => false,
        ]);

        $this->heartbeatPath = storage_path('app/system/scheduler-heartbeat.lock');
        @unlink($this->heartbeatPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->heartbeatPath);

        parent::tearDown();
    }

    public function test_heartbeat_runs_laravel_schedule_and_dispatches_due_campaign(): void
    {
        $campaign = NotificationCampaign::query()->create([
            'name' => 'Heartbeat campaign',
            'type' => 'promotion',
            'title_ar' => 'عرض',
            'title_en' => 'Offer',
            'body_ar' => 'خصم',
            'body_en' => 'Discount',
            'audience' => 'all',
            'app' => 'all',
            'target_channel' => 'b2b',
            'delivery_channel' => 'in_app',
            'schedule_kind' => 'once',
            'timezone' => 'Asia/Kuwait',
            'starts_at' => now()->subMinute(),
            'next_run_at' => now()->subMinute(),
            'run_count' => 0,
            'status' => 'active',
        ]);

        app(SchedulerRuntime::class)->tickIfDue();

        $campaign->refresh();

        $this->assertSame('completed', $campaign->status);
        $this->assertSame(1, (int) $campaign->run_count);
        $this->assertNull($campaign->next_run_at);
        $this->assertDatabaseHas('notification_campaign_runs', [
            'campaign_id' => $campaign->id,
            'status' => 'dispatched',
        ]);
        $this->assertDatabaseHas('notifications', [
            'id' => $campaign->last_notification_id,
            'type' => 'promotion',
            'status' => 'published',
        ]);
    }

    public function test_heartbeat_is_throttled_to_one_scheduler_tick_per_minute(): void
    {
        $scheduler = app(SchedulerRuntime::class);

        $scheduler->tickIfDue();
        $first = trim((string) @file_get_contents($this->heartbeatPath));

        $scheduler->tickIfDue();
        $second = trim((string) @file_get_contents($this->heartbeatPath));

        $this->assertNotSame('', $first);
        $this->assertSame($first, $second);
    }
}
