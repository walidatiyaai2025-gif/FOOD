<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SchedulerRuntime
{
    private const CRON_MARKER = '# FOODEX_MANAGED_SCHEDULER';

    public function provisionCron(): bool
    {
        if (app()->environment('testing') || (bool) config('foodex.scheduler_auto_provision', true) === false) {
            return false;
        }

        $crontab = (string) config('foodex.crontab_binary', 'crontab');
        $existing = $this->readCrontab($crontab);
        if ($existing === null) {
            return false;
        }

        $lines = array_values(array_filter(
            preg_split('/\R/', $existing) ?: [],
            static fn (string $line): bool => str_contains($line, self::CRON_MARKER) === false,
        ));
        $lines[] = $this->cronLine();
        $payload = implode(PHP_EOL, $lines).PHP_EOL;

        $directory = storage_path('app/system');
        if (is_dir($directory) === false && @mkdir($directory, 0750, true) === false && is_dir($directory) === false) {
            return false;
        }

        $temporary = $directory.'/foodex-crontab-'.bin2hex(random_bytes(5));
        try {
            if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
                return false;
            }

            return $this->runProcess([$crontab, $temporary]) === 0;
        } catch (Throwable $exception) {
            Log::warning('FOODEX scheduler cron auto-provisioning failed; runtime heartbeat remains active.', [
                'exception' => $exception::class,
            ]);

            return false;
        } finally {
            @unlink($temporary);
        }
    }

    public function tickIfDue(): void
    {
        if ((bool) config('foodex.scheduler_heartbeat', true) === false) {
            return;
        }

        $directory = storage_path('app/system');
        if (is_dir($directory) === false && @mkdir($directory, 0750, true) === false && is_dir($directory) === false) {
            return;
        }

        $path = $directory.'/scheduler-heartbeat.lock';
        $handle = @fopen($path, 'c+');
        if (is_resource($handle) === false) {
            return;
        }

        try {
            if (@flock($handle, LOCK_EX | LOCK_NB) === false) {
                return;
            }

            $lastRun = (int) trim((string) stream_get_contents($handle));
            if ($lastRun > 0 && (time() - $lastRun) < 55) {
                return;
            }

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, (string) time());
            fflush($handle);

            // Keep the currently production-critical campaign dispatcher guaranteed even if a host/CLI
            // has an unusual scheduler bootstrap, then run the full Laravel schedule for all features.
            Artisan::call('foodex:dispatch-scheduled-notifications');
            Artisan::call('schedule:run');
        } catch (Throwable $exception) {
            Log::warning('FOODEX scheduler heartbeat failed.', [
                'exception' => $exception::class,
            ]);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function cronLine(): string
    {
        $php = (string) config('foodex.scheduler_php_binary', PHP_BINDIR.'/php');
        $backend = base_path();
        $artisan = base_path('artisan');
        $log = storage_path('logs/scheduler.log');

        return '* * * * * cd '.escapeshellarg($backend)
            .' && '.escapeshellarg($php).' '.escapeshellarg($artisan)
            .' schedule:run >> '.escapeshellarg($log).' 2>&1 '.self::CRON_MARKER;
    }

    private function readCrontab(string $binary): ?string
    {
        try {
            [$exitCode, $stdout, $stderr] = $this->runProcessWithOutput([$binary, '-l']);

            if ($exitCode === 0) {
                return $stdout;
            }

            if ($exitCode === 1 && (
                str_contains(strtolower($stderr), 'no crontab')
                || trim($stderr) === ''
            )) {
                return '';
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /** @param  array<int, string>  $command */
    private function runProcess(array $command): int
    {
        [$exitCode] = $this->runProcessWithOutput($command);

        return $exitCode;
    }

    /**
     * @param  array<int, string>  $command
     * @return array{0: int, 1: string, 2: string}
     */
    private function runProcessWithOutput(array $command): array
    {
        $process = @proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (is_resource($process) === false) {
            return [127, '', 'process unavailable'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
