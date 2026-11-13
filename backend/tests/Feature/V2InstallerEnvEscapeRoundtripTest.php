<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallerEnvironment;
use Tests\TestCase;

final class V2InstallerEnvEscapeRoundtripTest extends TestCase
{
    private string $directory;

    private string $environmentPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/installer-escape-'.bin2hex(random_bytes(6)));
        $this->assertTrue(mkdir($this->directory, 0750, true));
        $this->environmentPath = $this->directory.'/.env';

        config(['foodex.installer_env_path' => $this->environmentPath]);
    }

    protected function tearDown(): void
    {
        if (isset($this->environmentPath) && is_file($this->environmentPath)) {
            unlink($this->environmentPath);
        }

        if (isset($this->directory) && is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_new_credentials_preserve_literal_backslash_n_r_and_repeated_slashes(): void
    {
        $this->assertNotFalse(file_put_contents($this->environmentPath, "APP_NAME=FOODEX\n"));
        $slash = chr(92);
        $credentials = [
            'DB_PASSWORD' => 'db-'.$slash.'next-'.$slash.'restore',
            'REDIS_PASSWORD' => 'redis-'.$slash.$slash.'n-'.$slash.'r',
            'MAIL_PASSWORD' => 'smtp-'.$slash.'"quoted"'.$slash.'n',
        ];

        $environment = app(InstallerEnvironment::class);
        $environment->write($credentials);

        $this->assertSame($credentials, $environment->read(array_keys($credentials)));
        $contents = (string) file_get_contents($this->environmentPath);
        $this->assertStringContainsString("APP_NAME=FOODEX\n", $contents);
        foreach (array_keys($credentials) as $key) {
            $this->assertSame(1, substr_count($contents, $key.'='));
        }

        clearstatcache(true, $this->environmentPath);
        $this->assertSame(0600, fileperms($this->environmentPath) & 0777);
    }

    public function test_existing_credentials_preserve_backslashes_after_replacement_and_replay(): void
    {
        $this->assertNotFalse(file_put_contents($this->environmentPath, implode(PHP_EOL, [
            'APP_NAME=FOODEX',
            'DB_PASSWORD=old',
            'REDIS_PASSWORD=old',
            'MAIL_PASSWORD=old',
        ]).PHP_EOL));

        $slash = chr(92);
        $credentials = [
            'DB_PASSWORD' => 'changed-'.$slash.'n-'.$slash.$slash.'r',
            'REDIS_PASSWORD' => 'cache-'.$slash.'r-'.$slash.'next',
            'MAIL_PASSWORD' => 'mail-'.$slash.'"quote"'.$slash.'n',
        ];
        $environment = app(InstallerEnvironment::class);
        $environment->write($credentials);
        $environment->write(['DB_PASSWORD' => $credentials['DB_PASSWORD']]);

        $this->assertSame(
            $credentials + ['APP_NAME' => 'FOODEX'],
            $environment->read(['DB_PASSWORD', 'REDIS_PASSWORD', 'MAIL_PASSWORD', 'APP_NAME']),
        );
        $contents = (string) file_get_contents($this->environmentPath);
        foreach (array_keys($credentials) as $key) {
            $this->assertSame(1, substr_count($contents, $key.'='));
        }
        $this->assertSame(1, substr_count($contents, 'APP_NAME='));
    }

    public function test_real_line_breaks_quotes_and_unknown_escapes_still_decode_correctly(): void
    {
        $slash = chr(92);
        $password = "line\nbreak\rquote\"end".$slash.'n'.$slash.'q';
        $environment = app(InstallerEnvironment::class);
        $environment->write(['DB_PASSWORD' => $password]);

        $this->assertSame($password, $environment->read(['DB_PASSWORD'])['DB_PASSWORD']);
        $contents = (string) file_get_contents($this->environmentPath);
        $this->assertSame(1, substr_count($contents, 'DB_PASSWORD='));
        $this->assertStringNotContainsString("line\nbreak", $contents);

        $this->assertNotFalse(file_put_contents(
            $this->environmentPath,
            'DB_PASSWORD="unknown'.$slash.'q"'.PHP_EOL,
        ));
        $this->assertSame('unknown'.$slash.'q', $environment->read(['DB_PASSWORD'])['DB_PASSWORD']);
    }
}
