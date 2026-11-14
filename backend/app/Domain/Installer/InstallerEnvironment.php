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
        // The .env rename is atomic, but its read/modify/write sequence is not.
        // Lock a persistent inode outside the replaceable .env file so two
        // setup requests cannot silently discard each other's managed fields.
        $lockPath = (string) config(
            'foodex.installer_env_lock_path',
            storage_path('framework/installer-env-locks/'.hash('sha256', $path).'.lock'),
        );
        $this->rejectSymbolicLink($lockPath);

        $directory = dirname($lockPath);
        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Installer environment write lock directory is not writable.');
        }

        $this->rejectSymbolicLink($lockPath);
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Installer environment write lock could not be opened.');
        }

        try {
            $this->assertSafeWriteLock($lockPath, $lock);
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Installer environment write lock could not be acquired.');
            }

            $this->assertSafeWriteLock($lockPath, $lock);
            @chmod($lockPath, 0640);
            $this->writeWithLock($path, $values);
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param  resource  $lock
     */
    private function assertSafeWriteLock(string $lockPath, $lock): void
    {
        $this->rejectSymbolicLink($lockPath);
        clearstatcache(true, $lockPath);

        $opened = fstat($lock);
        $current = @lstat($lockPath);

        // Refuse symlink swaps, hardlinked external files and replaced inodes.
        // Never change permissions on an unverified lock file.
        if ($opened === false || $current === false
            || ($opened['mode'] & 0170000) !== 0100000
            || ($current['mode'] & 0170000) !== 0100000
            || $opened['nlink'] !== 1
            || $current['nlink'] !== 1
            || $opened['dev'] !== $current['dev']
            || $opened['ino'] !== $current['ino']) {
            throw new RuntimeException('Installer environment write lock path changed unexpectedly.');
        }
    }

    /**
     * @param  array<string, scalar|null>  $values
     */
    private function writeWithLock(string $path, array $values): void
    {
        $this->rejectSymbolicLink($path);
        $this->ensureEnvironmentFile($path);
        $this->rejectSymbolicLink($path);
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Environment configuration could not be read.');
        }

        foreach ($values as $key => $value) {
            // The explicit missing Redis password is the one intentional
            // unquoted dotenv null sentinel. A user-typed string "null" must
            // instead remain a literal credential (and be quoted below).
            $encodedValue = $key === 'REDIS_PASSWORD' && $value === null
                ? 'null'
                : $this->encodeValue($value);
            $line = $key.'='.$encodedValue;
            // Read() uses the last .env assignment: after any installer write,
            // ensure the managed key has only one effective definition.
            $pattern = '/^[ \t]*'.preg_quote($key, '/').'[ \t]*=.*$/m';
            $found = false;
            $content = (string) preg_replace_callback(
                $pattern,
                static function () use ($line, &$found): string {
                    if ($found) {
                        return '';
                    }

                    $found = true;

                    return $line;
                },
                $content,
            );

            if (! $found) {
                $content = rtrim($content).PHP_EOL.$line.PHP_EOL;
            }
        }

        $temporary = $path.'.tmp-'.bin2hex(random_bytes(6));

        if (file_put_contents($temporary, $content, LOCK_EX) === false) {
            throw new RuntimeException('Environment configuration could not be written.');
        }

        @chmod($temporary, 0600);

        try {
            // Recheck just before the final rename: a containing directory
            // might have changed since the initial read and backup write.
            $this->rejectSymbolicLink($path);

            if (! @rename($temporary, $path)) {
                throw new RuntimeException('Environment configuration could not be finalized.');
            }
        } finally {
            if (is_file($temporary) || is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, string|null>
     */
    public function read(array $keys): array
    {
        $path = $this->path();
        $this->rejectSymbolicLink($path);

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
        $this->rejectSymbolicLink($path);

        if (is_file($path)) {
            return;
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Environment directory is not writable.');
        }

        $example = base_path('.env.example');
        $initial = is_file($example) ? (string) file_get_contents($example) : '';

        if (file_put_contents($path, $initial, LOCK_EX) === false) {
            throw new RuntimeException('Environment file could not be initialized.');
        }

        @chmod($path, 0600);
    }

    private function rejectSymbolicLink(string $path): void
    {
        // A regular .env beneath a symlinked directory still follows that link
        // on read, initialization and replacement. Trust the configured
        // application root, but reject links in every descendant path segment.
        $trustedRoot = rtrim(base_path(), '/\\');
        $candidate = $path;

        while ($candidate !== $trustedRoot) {
            clearstatcache(true, $candidate);

            if (is_link($candidate)) {
                throw new RuntimeException('Environment configuration path cannot be a symbolic link.');
            }

            $parent = dirname($candidate);

            if ($parent === $candidate) {
                break;
            }

            $candidate = $parent;
        }
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

        // Laravel env() coerces unquoted true/false/null/empty to non-string
        // values. Credentials and operator-entered names are literals.
        if (in_array(strtolower($string), ['true', 'false', 'null', 'empty'], true)) {
            return '"'.$string.'"';
        }

        if (preg_match('/^[A-Za-z0-9_\.\-:\/@]+$/', $string) === 1) {
            return $string;
        }

        return '"'.str_replace(
            ['\\', '"', "\r", "\n"],
            ['\\\\', '\\"', '\\r', '\\n'],
            $string,
        ).'"';
    }

    private function decodeValue(string $value): string
    {
        if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
            $value = substr($value, 1, -1);

            // Process escapes once: decoding an escaped backslash must not
            // turn a following literal "n" or "r" into a control character.
            $decoded = '';
            for ($index = 0, $length = strlen($value); $index < $length; $index++) {
                if ($value[$index] !== '\\' || $index + 1 >= $length) {
                    $decoded .= $value[$index];

                    continue;
                }

                $escaped = $value[++$index];
                $decoded .= match ($escaped) {
                    'n' => "\n",
                    'r' => "\r",
                    '"' => '"',
                    '\\' => '\\',
                    default => '\\'.$escaped,
                };
            }

            return $decoded;
        }

        if (strlen($value) >= 2 && $value[0] === "'" && $value[strlen($value) - 1] === "'") {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
