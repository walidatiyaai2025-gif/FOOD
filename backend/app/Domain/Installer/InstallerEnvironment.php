<?php

namespace App\Domain\Installer;

use InvalidArgumentException;
use RuntimeException;

final class InstallerEnvironment
{
    /**
     * Only these keys can be written by the web installer.
     *
     * @var array<int, string>
     */
    private const ALLOWED_KEYS = [
        'APP_NAME', 'APP_ENV', 'APP_KEY', 'APP_DEBUG', 'APP_URL', 'APP_LOCALE',
        'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        'FILESYSTEM_DISK', 'CACHE_STORE', 'QUEUE_CONNECTION',
        'REDIS_HOST', 'REDIS_PASSWORD', 'REDIS_PORT',
        'MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD',
        'MAIL_SCHEME', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
    ];

    public function path(): string
    {
        return (string) config('foodex.installer_env_path', base_path('.env'));
    }

    /**
     * @param  array<string, scalar|null>  $values
     */
    public function write(array $values): void
    {
        foreach (array_keys($values) as $key) {
            if (! in_array($key, self::ALLOWED_KEYS, true)) {
                throw new InvalidArgumentException('Environment key ['.$key.'] is not installer-writable.');
            }
        }

        $path = $this->path();
        $this->ensureEnvironmentFile($path);
        $content = (string) file_get_contents($path);

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->encodeValue($value);
            $pattern = '/^'.preg_quote($key, '/').'\s*=.*$/m';

            if (preg_match($pattern, $content) === 1) {
                $content = (string) preg_replace($pattern, $line, $content, 1);
            } else {
                $content = rtrim($content).PHP_EOL.$line.PHP_EOL;
            }
        }

        $temporary = $path.'.tmp-'.bin2hex(random_bytes(6));

        if (file_put_contents($temporary, $content, LOCK_EX) === false) {
            throw new RuntimeException('Environment configuration could not be written.');
        }

        @chmod($temporary, 0600);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Environment configuration could not be finalized.');
        }
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, string|null>
     */
    public function read(array $keys): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return array_fill_keys($keys, null);
        }

        $wanted = array_fill_keys($keys, true);
        $result = array_fill_keys($keys, null);
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new RuntimeException('Environment configuration could not be read.');
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);

            if (! isset($wanted[$key])) {
                continue;
            }

            $result[$key] = $this->decodeValue(trim($value));
        }

        return $result;
    }

    private function ensureEnvironmentFile(string $path): void
    {
        if (is_file($path)) {
            return;
        }

        $directory = dirname($path);

        if (! is_dir($directory) && !@mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Environment directory is not writable.');
        }

        $example = base_path('.env.example');
        $initial = is_file($example) ? (string) file_get_contents($example) : '';

        if (file_put_contents($path, $initial, LOCK_EX) === false) {
            throw new RuntimeException('Environment file could not be initialized.');
        }

        @chmod($path, 0600);
    }

    private function encodeValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $string = (string) $value;

        if ($string === '') {
            return '""';
        }

        if (preg_match('/^[A-Za-z0-9_\.\-:\/@]+$/', $string) === 1) {
            return $string;
        }

        return '"'.str_replace(
            ["\\", '"', "\r", "\n"],
            ["\\\\", '\\"', '\\r', '\\n'],
            $string,
        ).'"';
    }

    private function decodeValue(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
            $value = substr($value, 1, -1);

            return str_replace(
                ['\\n', '\\r', '\\"', '\\\\'],
                ["\n", "\r", '"', '\\'],
                $value,
            );
        }

        if (strlen($value) >= 2 && $value[0] === "'" && $value[strlen($value) - 1] === "'") {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
