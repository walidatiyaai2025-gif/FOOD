<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallerEnvironment;
use Tests\TestCase;

final class V2InstallerEnvDuplicateKeyCanonicalizationTest extends TestCase
{
    private string $directory;

    private string $environmentPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/installer-env-duplicate-'.bin2hex(random_bytes(6)));
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

    public function test_duplicate_managed_keys_are_canonicalized_without_stale_values(): void
    {
        file_put_contents($this->environmentPath, implode("\n", [
            'APP_KEY=base64:old-first',
            'UNMANAGED=value',
            'APP_KEY=base64:old-last',
            'DB_PASSWORD=older',
            'DB_PASSWORD=stale',
            'APP_URL=https://old.example.test',
            'APP_URL=https://stale.example.test',
            '',
        ]));

        $environment = app(InstallerEnvironment::class);
        $password = 'literal-$1-$0-$$-#-value';
        $environment->write([
            'APP_KEY' => 'base64:new-safe-value',
            'DB_PASSWORD' => $password,
            'APP_URL' => 'https://setup.example.test',
        ]);

        $this->assertSame([
            'APP_KEY' => 'base64:new-safe-value',
            'DB_PASSWORD' => $password,
            'APP_URL' => 'https://setup.example.test',
            'UNMANAGED' => 'value',
        ], $environment->read(['APP_KEY', 'DB_PASSWORD', 'APP_URL', 'UNMANAGED']));

        $contents = (string) file_get_contents($this->environmentPath);
        foreach (['APP_KEY', 'DB_PASSWORD', 'APP_URL'] as $key) {
            $this->assertSame(1, preg_match_all('/^'.$key.'=/m', $contents));
        }

        $this->assertStringNotContainsString('old-last', $contents);
        $this->assertStringNotContainsString('stale.example.test', $contents);
        $this->assertStringContainsString("UNMANAGED=value\n", $contents);

        clearstatcache(true, $this->environmentPath);
        $this->assertSame(0600, fileperms($this->environmentPath) & 0777);
    }

    public function test_repeat_write_preserves_other_keys_and_does_not_remove_unmanaged_duplicates(): void
    {
        file_put_contents($this->environmentPath, implode("\n", [
            'DB_PASSWORD=first',
            'OTHER=first',
            'DB_PASSWORD=second',
            'OTHER=second',
            '',
        ]));

        $environment = app(InstallerEnvironment::class);
        $environment->write(['DB_PASSWORD' => 'safe-retry']);
        $firstContents = (string) file_get_contents($this->environmentPath);
        $environment->write(['DB_PASSWORD' => 'safe-retry']);

        $this->assertSame($firstContents, (string) file_get_contents($this->environmentPath));
        $this->assertSame('safe-retry', $environment->read(['DB_PASSWORD'])['DB_PASSWORD']);
        $this->assertSame(1, preg_match_all('/^DB_PASSWORD=/m', $firstContents));
        $this->assertSame(2, preg_match_all('/^OTHER=/m', $firstContents));
    }

    public function test_key_without_equals_never_consumes_next_line_during_update(): void
    {
        file_put_contents($this->environmentPath, "APP_URL\nAPP_NAME=Keep Name\n");

        $environment = app(InstallerEnvironment::class);
        $environment->write(['APP_URL' => 'https://setup.example.test']);

        $contents = (string) file_get_contents($this->environmentPath);
        $this->assertStringContainsString("APP_NAME=Keep Name\n", $contents);
        $this->assertSame('Keep Name', $environment->read(['APP_NAME'])['APP_NAME']);
        $this->assertSame('https://setup.example.test', $environment->read(['APP_URL'])['APP_URL']);
        $this->assertSame(1, preg_match_all('/^APP_URL=/m', $contents));
    }
}
