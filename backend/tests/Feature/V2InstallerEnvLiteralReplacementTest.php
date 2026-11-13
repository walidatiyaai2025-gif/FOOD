<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallerEnvironment;
use Tests\TestCase;

class V2InstallerEnvLiteralReplacementTest extends TestCase
{
    private string $directory;

    private string $environmentPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/installer-literal-'.bin2hex(random_bytes(6)));
        mkdir($this->directory, 0750, true);
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

    public function test_existing_database_password_is_replaced_literally_without_regex_capture_expansion(): void
    {
        file_put_contents($this->environmentPath, "DB_PASSWORD=previous\nAPP_NAME=FOODEX\n");
        $password = 'test-only-$1-$2-$0-$$-end';

        $environment = app(InstallerEnvironment::class);
        $environment->write(['DB_PASSWORD' => $password]);

        $this->assertSame($password, $environment->read(['DB_PASSWORD'])['DB_PASSWORD']);

        $contents = (string) file_get_contents($this->environmentPath);
        $this->assertStringContainsString('DB_PASSWORD="'.$password.'"', $contents);
        $this->assertStringContainsString("APP_NAME=FOODEX\n", $contents);
        $this->assertSame(1, substr_count($contents, 'DB_PASSWORD='));
    }

    public function test_multiple_existing_redis_and_mail_credentials_remain_exact(): void
    {
        file_put_contents($this->environmentPath, "REDIS_PASSWORD=old\nMAIL_PASSWORD=previous\nAPP_NAME=FOODEX\n");

        $redis = 'redis-$2-!#@=+';
        $mail = 'mail-$1-$0-"quoted"';
        $environment = app(InstallerEnvironment::class);
        $environment->write([
            'REDIS_PASSWORD' => $redis,
            'MAIL_PASSWORD' => $mail,
        ]);

        $this->assertSame([
            'REDIS_PASSWORD' => $redis,
            'MAIL_PASSWORD' => $mail,
            'APP_NAME' => 'FOODEX',
        ], $environment->read(['REDIS_PASSWORD', 'MAIL_PASSWORD', 'APP_NAME']));

        $contents = (string) file_get_contents($this->environmentPath);
        $this->assertSame(1, substr_count($contents, 'REDIS_PASSWORD='));
        $this->assertSame(1, substr_count($contents, 'MAIL_PASSWORD='));
    }

    public function test_new_credential_append_then_replacement_preserves_permissions_and_other_keys(): void
    {
        file_put_contents($this->environmentPath, "APP_NAME=FOODEX\n");

        $environment = app(InstallerEnvironment::class);
        $environment->write(['DB_PASSWORD' => 'initial-$1']);
        $this->assertSame('initial-$1', $environment->read(['DB_PASSWORD'])['DB_PASSWORD']);

        $environment->write(['DB_PASSWORD' => 'updated-$2-$0']);

        $this->assertSame([
            'DB_PASSWORD' => 'updated-$2-$0',
            'APP_NAME' => 'FOODEX',
        ], $environment->read(['DB_PASSWORD', 'APP_NAME']));

        clearstatcache(true, $this->environmentPath);
        $this->assertSame(0600, fileperms($this->environmentPath) & 0777);
        $this->assertSame(1, substr_count((string) file_get_contents($this->environmentPath), 'DB_PASSWORD='));
    }
}
