<?php

namespace App\Domain\Installer;

use RuntimeException;

final class InstallState
{
    public function lockPath(): string
    {
        return $this->resolvePath((string) config('foodex.install_lock', 'storage/app/system/installed.lock'));
    }

    public function progressPath(): string
    {
        return $this->resolvePath((string) config('foodex.install_progress', 'storage/app/system/install-progress.json'));
    }

    public function isInstalled(): bool
    {
        return is_file($this->lockPath());
    }

    /**
     * @return array{completed_step: int, admin: array{name: string, email: string, password_hash: string, locale: string}|null, token: string|null}
     */
    public function progress(): array
    {
        $path = $this->progressPath();

        if (! is_file($path)) {
            return ['completed_step' => 0, 'admin' => null, 'token' => null];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return ['completed_step' => 0, 'admin' => null, 'token' => null];
        }

        $admin = null;
        $candidate = $decoded['admin'] ?? null;

        if (is_array($candidate)
            && isset($candidate['name'], $candidate['email'], $candidate['password_hash'], $candidate['locale'])
            && is_string($candidate['name'])
            && is_string($candidate['email'])
            && is_string($candidate['password_hash'])
            && is_string($candidate['locale'])) {
            $admin = [
                'name' => $candidate['name'],
                'email' => $candidate['email'],
                'password_hash' => $candidate['password_hash'],
                'locale' => $candidate['locale'],
            ];
        }

        $token = $decoded['token'] ?? null;
        $token = is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token) === 1
            ? $token
            : null;

        return [
            'completed_step' => max(0, min(15, (int) ($decoded['completed_step'] ?? 0))),
            'admin' => $admin,
            'token' => $token,
        ];
    }

    public function token(): string
    {
        $progress = $this->progress();

        if ($progress['token'] !== null) {
            return $progress['token'];
        }

        $progress['token'] = bin2hex(random_bytes(32));
        $this->writeJson($this->progressPath(), $progress);

        return $progress['token'];
    }

    /**
     * @param  array{name: string, email: string, password_hash: string, locale: string}|null  $admin
     */
    public function markStepComplete(int $step, ?array $admin = null): void
    {
        $progress = $this->progress();
        $progress['completed_step'] = max($progress['completed_step'], min(15, $step));
        $progress['token'] ??= bin2hex(random_bytes(32));

        if ($admin !== null) {
            $progress['admin'] = $admin;
        }

        $this->writeJson($this->progressPath(), $progress);
    }

    public function writeLock(string $version): void
    {
        $this->writeJson($this->lockPath(), [
            'version' => $version,
            'installed_at' => now()->toIso8601String(),
        ]);
    }

    public function clearProgress(): void
    {
        $path = $this->progressPath();

        if (is_file($path) && ! @unlink($path)) {
            throw new RuntimeException('Installer progress could not be cleared.');
        }
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Installer state directory is not writable.');
        }

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(6));

        if (file_put_contents($temporary, $encoded.PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Installer state could not be persisted.');
        }

        @chmod($temporary, 0640);

        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Installer state could not be finalized.');
        }
    }
}
