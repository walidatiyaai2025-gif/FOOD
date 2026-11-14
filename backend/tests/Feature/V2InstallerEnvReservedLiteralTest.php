<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallerEnvironment;
use App\Domain\Installer\InstallerWorkflow;
use Tests\TestCase;

final class V2InstallerEnvReservedLiteralTest extends TestCase
{
    private string $directory;

    private string $environmentPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/v2-installer-env-scalars-'.bin2hex(random_bytes(6)));
        $this->assertTrue(mkdir($this->directory, 0750, true));
        $this->environmentPath = $this->directory.'/.env';
        $this->assertNotFalse(file_put_contents($this->environmentPath, "APP_NAME=FOODEX\n"));

        config([
            'foodex.installer_env_path' => $this->environmentPath,
            'foodex.installer_env_lock_path' => $this->directory.'/env-write.lock',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            foreach (['.env', 'env-write.lock'] as $filename) {
                $path = $this->directory.'/'.$filename;
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            @rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_laravel_reserved_scalar_strings_are_quoted_and_round_trip_for_setup_credentials(): void
    {
        $environment = app(InstallerEnvironment::class);

        foreach (['null', 'TRUE', 'False', 'empty', 'EMPTY'] as $literal) {
            $values = [
                'DB_PASSWORD' => $literal,
                'MAIL_PASSWORD' => $literal,
                'APP_NAME' => $literal,
            ];

            $environment->write($values);
            $this->assertSame($values, $environment->read(array_keys($values)));

            $contents = (string) file_get_contents($this->environmentPath);
            foreach ($values as $key => $value) {
                // Without quotes, Laravel env() converts these strings into
                // null, booleans or empty and changes runtime credentials.
                $this->assertStringContainsString($key.'="'.$value.'"', $contents);
                $this->assertSame(1, preg_match_all('/^'.$key.'=/m', $contents));
            }

            clearstatcache(true, $this->environmentPath);
            $this->assertSame(0600, fileperms($this->environmentPath) & 0777);
        }
    }

    public function test_missing_redis_password_retains_null_sentinel_but_literal_null_is_quoted(): void
    {
        $workflow = app(InstallerWorkflow::class);
        $environment = app(InstallerEnvironment::class);

        $workflow->saveCacheQueueConfiguration([
            'cache' => 'redis',
            'queue' => 'sync',
            'redis_host' => '127.0.0.1',
        ]);
        $contents = (string) file_get_contents($this->environmentPath);
        $this->assertStringContainsString("REDIS_PASSWORD=null\n", $contents);
        $this->assertStringNotContainsString('REDIS_PASSWORD="null"', $contents);

        $workflow->saveCacheQueueConfiguration([
            'cache' => 'redis',
            'queue' => 'sync',
            'redis_password' => 'null',
        ]);
        $contents = (string) file_get_contents($this->environmentPath);
        $this->assertStringContainsString('REDIS_PASSWORD="null"', $contents);
        $this->assertSame('null', $environment->read(['REDIS_PASSWORD'])['REDIS_PASSWORD']);
        $this->assertSame(1, preg_match_all('/^REDIS_PASSWORD=/m', $contents));

        $workflow->saveCacheQueueConfiguration([
            'cache' => 'redis',
            'queue' => 'sync',
            'redis_password' => 'FALSE',
        ]);
        $this->assertStringContainsString(
            'REDIS_PASSWORD="FALSE"',
            (string) file_get_contents($this->environmentPath),
        );

        $workflow->saveCacheQueueConfiguration([
            'cache' => 'redis',
            'queue' => 'sync',
        ]);
        $contents = (string) file_get_contents($this->environmentPath);
        $this->assertStringContainsString("REDIS_PASSWORD=null\n", $contents);
        $this->assertSame(1, preg_match_all('/^REDIS_PASSWORD=/m', $contents));
    }

    public function test_app_debug_keeps_laravel_boolean_semantics_in_production_configuration(): void
    {
        $environment = app(InstallerEnvironment::class);

        foreach (['false', 'true', 'FALSE', 'TRUE'] as $flag) {
            $environment->write(['APP_DEBUG' => $flag]);

            $contents = (string) file_get_contents($this->environmentPath);
            // A quoted "false" is truthy when a boolean config is cast from
            // the raw value; the installer must emit APP_DEBUG=false.
            $this->assertStringContainsString("APP_DEBUG={$flag}\n", $contents);
            $this->assertStringNotContainsString('APP_DEBUG="', $contents);
            $this->assertSame($flag, $environment->read(['APP_DEBUG'])['APP_DEBUG']);
            $this->assertSame(1, preg_match_all('/^APP_DEBUG=/m', $contents));
        }
    }

    public function test_ordinary_and_blank_values_keep_existing_serialization_behavior(): void
    {
        $environment = app(InstallerEnvironment::class);
        $values = [
            'DB_PASSWORD' => 'safe-p4ss',
            'MAIL_PASSWORD' => '',
            'APP_NAME' => 'FOODEX',
            'DB_HOST' => 'db.example.test',
        ];

        $environment->write($values);
        $this->assertSame($values, $environment->read(array_keys($values)));

        $contents = (string) file_get_contents($this->environmentPath);
        $this->assertStringContainsString("DB_PASSWORD=safe-p4ss\n", $contents);
        $this->assertStringContainsString('MAIL_PASSWORD=""', $contents);
        $this->assertStringContainsString("APP_NAME=FOODEX\n", $contents);
        $this->assertStringContainsString("DB_HOST=db.example.test\n", $contents);

        // Generic non-Redis null values retain their existing blank form.
        $environment->write(['MAIL_PASSWORD' => null]);
        $contents = (string) file_get_contents($this->environmentPath);
        $this->assertStringContainsString("MAIL_PASSWORD=\n", $contents);
        $this->assertSame(1, preg_match_all('/^MAIL_PASSWORD=/m', $contents));
    }
}
