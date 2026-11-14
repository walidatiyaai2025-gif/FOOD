<?php

namespace Tests\Feature;

use App\Services\SchedulerRuntime;
use Tests\TestCase;

class V2SchedulerHeartbeatLockSafetyTest extends TestCase
{
    private string $originalStoragePath;

    private string $isolatedStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'foodex.scheduler_heartbeat' => true,
            'foodex.scheduler_auto_provision' => false,
        ]);

        $this->originalStoragePath = app()->storagePath();
        $this->isolatedStoragePath = sys_get_temp_dir().'/foodex-scheduler-lock-'.bin2hex(random_bytes(8));
        mkdir($this->isolatedStoragePath.'/app/system', 0750, true);
        app()->useStoragePath($this->isolatedStoragePath);
    }

    protected function tearDown(): void
    {
        app()->useStoragePath($this->originalStoragePath);
        $this->removeFixture($this->isolatedStoragePath);

        parent::tearDown();
    }

    public function test_heartbeat_rejects_symlinked_lock_without_changing_target(): void
    {
        $foreign = $this->isolatedStoragePath.'/external.txt';
        file_put_contents($foreign, 'preserve external data');
        $this->assertTrue(symlink($foreign, $this->heartbeatPath()));

        app(SchedulerRuntime::class)->tickIfDue();

        $this->assertSame('preserve external data', file_get_contents($foreign));
        $this->assertTrue(is_link($this->heartbeatPath()));
    }

    public function test_heartbeat_rejects_hardlinked_lock_without_changing_target(): void
    {
        $foreign = $this->isolatedStoragePath.'/external.txt';
        file_put_contents($foreign, 'preserve linked data');
        if (! @link($foreign, $this->heartbeatPath())) {
            $this->markTestSkipped('Hardlink support is required.');
        }

        app(SchedulerRuntime::class)->tickIfDue();

        $this->assertSame('preserve linked data', file_get_contents($foreign));
        $this->assertSame('preserve linked data', file_get_contents($this->heartbeatPath()));
    }

    public function test_heartbeat_rejects_linked_system_directory_without_writing_target(): void
    {
        $system = $this->isolatedStoragePath.'/app/system';
        $foreign = $this->isolatedStoragePath.'/outside-system';
        $this->assertTrue(rmdir($system));
        $this->assertTrue(mkdir($foreign, 0750));
        $this->assertTrue(symlink($foreign, $system));

        app(SchedulerRuntime::class)->tickIfDue();

        $this->assertFileDoesNotExist($foreign.'/scheduler-heartbeat.lock');
    }

    public function test_regular_persistent_lock_still_throttles_without_rewriting(): void
    {
        file_put_contents($this->heartbeatPath(), (string) time());
        app(SchedulerRuntime::class)->tickIfDue();

        $this->assertSame((string) time(), trim((string) file_get_contents($this->heartbeatPath())));
    }

    private function heartbeatPath(): string
    {
        return storage_path('app/system/scheduler-heartbeat.lock');
    }

    private function removeFixture(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeFixture($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }
}
