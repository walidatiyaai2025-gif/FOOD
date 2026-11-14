<?php

namespace Tests\Unit;

use App\Domain\Updater\UpdateManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class V2UpdaterExceptionSecretRedactionTest extends TestCase
{
    /**
     * @dataProvider sensitiveErrorProvider
     */
    public function test_exception_summary_never_retains_sensitive_values(
        string $input,
        string $secret,
    ): void {
        $summary = $this->summarize(new RuntimeException('SQLSTATE[HY000] '.$input));

        $this->assertStringContainsString('SQLSTATE[HY000]', $summary);
        $this->assertStringContainsString('[REDACTED]', $summary);
        $this->assertStringNotContainsString($secret, $summary);
    }

    /** @return iterable<string, array{string, string}> */
    public static function sensitiveErrorProvider(): iterable
    {
        yield 'database password' => ['DB_PASSWORD=never-store-this-password', 'never-store-this-password'];
        yield 'quoted password with whitespace' => ['password="two words secret"', 'two words secret'];
        yield 'redis password' => ['redis_password:redis-custody-secret', 'redis-custody-secret'];
        yield 'mail password' => ['MAIL_PASSWORD=mail-custody-secret', 'mail-custody-secret'];
        yield 'app key' => ['APP_KEY=base64:TopSecretApplicationKey', 'TopSecretApplicationKey'];
        yield 'api key' => ['API_KEY=api-custody-secret', 'api-custody-secret'];
        yield 'token query' => ['https://provider.example/path?token=very-secret-token', 'very-secret-token'];
        yield 'authorization bearer' => ['Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.jwt.sig', 'eyJhbGciOiJIUzI1NiJ9'];
        yield 'authorization basic' => ['Authorization: Basic dXNlcjpwYXNzd29yZA==', 'dXNlcjpwYXNzd29yZA=='];
        yield 'bare bearer' => ['Invalid Bearer jwt.very-secret-value', 'jwt.very-secret-value'];
        yield 'mysql URL userinfo' => ['mysql://operator:mysql-password@db.example/app', 'mysql-password'];
        yield 'redis URL userinfo' => ['redis://default:redis-password@cache.example/0', 'redis-password'];
        yield 'mixed casing' => ['Db_PaSsWoRd=MixedCaseSecret', 'MixedCaseSecret'];
        yield 'secret label' => ['SECRET:local-dev-credential', 'local-dev-credential'];
    }

    public function test_nested_errors_are_scrubbed_separately_without_losing_stage_context(): void
    {
        $inner = new RuntimeException('SQLSTATE[42000] MAIL_PASSWORD=inner-secret');
        $outer = new RuntimeException('Update extraction failed: Authorization: Bearer outer-secret', 0, $inner);
        $summary = $this->summarize($outer);

        $this->assertStringContainsString('Update extraction failed', $summary);
        $this->assertStringContainsString('SQLSTATE[42000]', $summary);
        $this->assertStringContainsString('Caused by:', $summary);
        $this->assertStringNotContainsString('inner-secret', $summary);
        $this->assertStringNotContainsString('outer-secret', $summary);
        $this->assertSame(2, substr_count($summary, '[REDACTED]'));
    }

    public function test_nonsecret_error_context_is_preserved(): void
    {
        $context = 'SQLSTATE[HY000] table migration failed; password is missing';
        $this->assertSame($context, $this->summarize(new RuntimeException($context)));
    }

    public function test_long_messages_and_missing_detail_keep_existing_bounded_behavior(): void
    {
        $longSummary = $this->summarize(new RuntimeException(str_repeat('x', 1300)));

        $this->assertSame(1201, mb_strlen($longSummary));
        $this->assertStringEndsWith('…', $longSummary);
        $this->assertSame('RuntimeException', $this->summarize(new RuntimeException('')));
    }

    private function summarize(RuntimeException $exception): string
    {
        // Pure diagnostic code: instantiate without app dependencies or real
        // migrations, UpdateHistory, live backups, or filesystem operations.
        $manager = (new ReflectionClass(UpdateManager::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(UpdateManager::class, 'exceptionSummary');

        return (string) $method->invoke($manager, $exception);
    }
}
