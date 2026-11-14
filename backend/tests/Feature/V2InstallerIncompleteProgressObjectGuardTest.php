<?php

namespace Tests\Feature;

use App\Domain\Installer\InstallState;
use RuntimeException;
use Tests\TestCase;

final class V2InstallerIncompleteProgressObjectGuardTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/v2-installer-progress-required-'.bin2hex(random_bytes(6)));
        $this->assertTrue(mkdir($this->directory, 0750, true));
        $this->assertNotFalse(file_put_contents(
            $this->directory.'/.env',
            "APP_NAME=FOODEX\nAPP_ENV=testing\nAPP_KEY=\nAPP_URL=http://localhost\n",
        ));

        config([
            'foodex.installer_env_path' => $this->directory.'/.env',
            'foodex.install_lock' => $this->directory.'/installed.lock',
            'foodex.install_progress' => $this->directory.'/install-progress.json',
            'foodex.installer_token_lock_path' => $this->directory.'/installer-token.lock',
            'foodex.scheduler_heartbeat' => false,
            'foodex.scheduler_auto_provision' => false,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            foreach (['.env', 'installed.lock', 'install-progress.json', 'installer-token.lock'] as $name) {
                $path = $this->directory.'/'.$name;
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            @rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_existing_partial_json_objects_fail_closed_without_overwriting_progress(): void
    {
        $state = app(InstallState::class);

        foreach ([
            '{}',
            '{"completed_step":12,"admin":null}',
            '{"completed_step":12,"token":"'.str_repeat('a', 64).'"}',
            '{"token":"'.str_repeat('a', 64).'","admin":null}',
        ] as $before) {
            $this->assertNotFalse(file_put_contents($this->progressFile(), $before));
            $environment = (string) file_get_contents($this->directory.'/.env');

            foreach ([
                static fn () => $state->progress(),
                static fn () => $state->token(),
                static fn () => $state->markStepComplete(13),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('Partial progress object must fail closed without overwriting the snapshot.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('Installer progress state is invalid.', $exception->getMessage());
                }
            }

            $this->assertSame($before, file_get_contents($this->progressFile()));
            $this->assertSame($environment, file_get_contents($this->directory.'/.env'));
            $this->assertFileDoesNotExist($this->directory.'/installer-token.lock');
            $this->assertFileDoesNotExist($this->directory.'/installed.lock');
        }
    }

    public function test_partial_object_blocks_web_installer_without_mutation(): void
    {
        $before = '{"completed_step":12,"admin":null}';
        $this->assertNotFalse(file_put_contents($this->progressFile(), $before));
        $environment = (string) file_get_contents($this->directory.'/.env');

        $this->get('/install')->assertStatus(500);

        $this->assertSame($before, file_get_contents($this->progressFile()));
        $this->assertSame($environment, file_get_contents($this->directory.'/.env'));
        $this->assertFileDoesNotExist($this->directory.'/installer-token.lock');
        $this->assertFileDoesNotExist($this->directory.'/installed.lock');
    }

    public function test_complete_progress_object_remains_usable_and_does_not_erase_saved_admin(): void
    {
        $state = app(InstallState::class);
        $token = str_repeat('b', 64);
        $admin = [
            'name' => 'Fixture Admin',
            'email' => 'fixture@example.test',
            'password_hash' => 'fixture-hash',
            'locale' => 'ar',
        ];
        $before = json_encode([
            'completed_step' => 12,
            'admin' => $admin,
            'token' => $token,
            'forward_compatible' => true,
        ], JSON_THROW_ON_ERROR);

        $this->assertNotFalse(file_put_contents($this->progressFile(), $before));
        $this->assertSame(['completed_step' => 12, 'admin' => $admin, 'token' => $token], $state->progress());
        $this->assertSame($token, $state->token());
        $this->assertSame($before, file_get_contents($this->progressFile()));

        $state->markStepComplete(13);
        $after = $state->progress();

        $this->assertSame(13, $after['completed_step']);
        $this->assertSame($admin, $after['admin']);
        $this->assertSame($token, $after['token']);
    }

    public function test_absent_progress_still_creates_one_initial_token_and_all_three_keys(): void
    {
        $state = app(InstallState::class);
        $this->assertFileDoesNotExist($this->progressFile());
        $this->assertSame(['completed_step' => 0, 'admin' => null, 'token' => null], $state->progress());

        $token = $state->token();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame($token, $state->token());
        $document = json_decode((string) file_get_contents($this->progressFile()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $document['completed_step']);
        $this->assertArrayHasKey('admin', $document);
        $this->assertNull($document['admin']);
        $this->assertSame($token, $document['token']);
    }

    public function test_malformed_nested_admin_progress_fails_closed_before_any_state_or_token_write(): void
    {
        $state = app(InstallState::class);
        $token = str_repeat('c', 64);

        foreach ([
            (object) [],
            (object) ['name' => 'Admin', 'email' => 'admin@example.test', 'locale' => 'ar'],
            (object) [
                'name' => 'Admin',
                'email' => 'admin@example.test',
                'password_hash' => false,
                'locale' => 'ar',
            ],
            ['name' => 'Admin', 'email' => 'admin@example.test', 'password_hash' => 'hash', 'locale' => 'ar'],
            'unexpected-admin',
            true,
        ] as $invalidAdmin) {
            $before = json_encode([
                'completed_step' => 12,
                'admin' => $invalidAdmin,
                'token' => $token,
            ], JSON_THROW_ON_ERROR);

            $this->assertNotFalse(file_put_contents($this->progressFile(), $before));
            $environment = (string) file_get_contents($this->directory.'/.env');

            foreach ([
                'progress' => static fn () => $state->progress(),
                'token' => static fn () => $state->token(),
                'markStepComplete' => static fn () => $state->markStepComplete(13),
            ] as $operationName => $operation) {
                $actualError = null;
                try {
                    $operation();
                } catch (RuntimeException $exception) {
                    $actualError = $exception->getMessage();
                }

                // Assert outside catch: PHPUnit's fail() can itself be a
                // RuntimeException, which would mask a non-throwing operation.
                $this->assertSame('Installer progress state is invalid.', $actualError, $operationName);
            }

            $this->get('/install')->assertStatus(500);
            $this->assertSame($before, file_get_contents($this->progressFile()));
            $this->assertSame($environment, file_get_contents($this->directory.'/.env'));
            $this->assertFileDoesNotExist($this->directory.'/installer-token.lock');
            $this->assertFileDoesNotExist($this->directory.'/installed.lock');
        }
    }

    public function test_existing_progress_with_null_admin_is_valid_and_keeps_token(): void
    {
        $state = app(InstallState::class);
        $token = str_repeat('d', 64);
        $before = json_encode([
            'completed_step' => 0,
            'admin' => null,
            'token' => $token,
        ], JSON_THROW_ON_ERROR);
        $this->assertNotFalse(file_put_contents($this->progressFile(), $before));

        $this->assertSame(['completed_step' => 0, 'admin' => null, 'token' => $token], $state->progress());
        $this->assertSame($token, $state->token());
        $this->assertSame($before, file_get_contents($this->progressFile()));

        $state->markStepComplete(1);
        $this->assertSame(['completed_step' => 1, 'admin' => null, 'token' => $token], $state->progress());
    }

    private function progressFile(): string
    {
        return $this->directory.'/install-progress.json';
    }
}
