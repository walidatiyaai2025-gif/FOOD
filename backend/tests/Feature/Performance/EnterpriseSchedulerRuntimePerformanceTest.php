<?php

namespace Tests\Feature\Performance;

use App\Services\SchedulerRuntime;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Performance\Support\QueueSchedulerProbe;
use Tests\TestCase;

final class EnterpriseSchedulerRuntimePerformanceTest extends TestCase
{
    private string $heartbeatPath;

    protected function setUp(): void
    {
        parent::setUp();

        $directory = storage_path('app/system');
        File::ensureDirectoryExists($directory);
        $this->heartbeatPath = $directory.'/scheduler-heartbeat.lock';
        File::delete($this->heartbeatPath);

        config([
            'foodex.scheduler_heartbeat' => true,
            'foodex.scheduler_auto_provision' => false,
        ]);
    }

    protected function tearDown(): void
    {
        File::delete($this->heartbeatPath);

        parent::tearDown();
    }

    public function test_missed_heartbeat_recovers_once_and_immediate_repeat_is_suppressed(): void
    {
        File::put($this->heartbeatPath, (string) (time() - 120));

        Artisan::shouldReceive('call')
            ->once()
            ->with('foodex:dispatch-scheduled-notifications')
            ->andReturn(0);
        Artisan::shouldReceive('call')
            ->once()
            ->with('schedule:run')
            ->andReturn(0);

        $runtime = app(SchedulerRuntime::class);

        $slice = QueueSchedulerProbe::measureSlice(
            static fn () => $runtime->tickIfDue(),
        );

        $this->assertSame('PASS', $slice['status']);
        $this->assertLessThanOrEqual(45.0, $slice['runtime_seconds']);

        $recordedRun = (int) trim((string) File::get($this->heartbeatPath));
        $this->assertGreaterThanOrEqual(time() - 5, $recordedRun);

        // A second request inside the heartbeat window must not run the scheduler again.
        $runtime->tickIfDue();
    }

    public function test_disabled_heartbeat_performs_no_scheduler_work(): void
    {
        config(['foodex.scheduler_heartbeat' => false]);

        Artisan::shouldReceive('call')->never();

        app(SchedulerRuntime::class)->tickIfDue();

        $this->assertFileDoesNotExist($this->heartbeatPath);
    }
}
