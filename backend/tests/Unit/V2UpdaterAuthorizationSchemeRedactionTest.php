<?php

namespace Tests\Unit;

use App\Domain\Updater\UpdateManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class V2UpdaterAuthorizationSchemeRedactionTest extends TestCase
{
    #[DataProvider('authorizationSchemeCases')]
    public function test_authorization_tail_is_never_persisted_regardless_of_scheme(
        string $message,
        string $secret,
    ): void {
        $summary = $this->summarize(new RuntimeException('SQLSTATE[HY000] '.$message));

        $this->assertStringContainsString('SQLSTATE[HY000]', $summary);
        $this->assertStringContainsString('[REDACTED]', $summary);
        $this->assertStringNotContainsString($secret, $summary);
    }

    /** @return iterable<string, array{string, string}> */
    public static function authorizationSchemeCases(): iterable
    {
        yield 'token scheme with whitespace-separated credential' => [
            'Authorization: Token token-value-3524 rejected', 'token-value-3524',
        ];
        yield 'digest with comma-separated challenge fields' => [
            'Authorization: Digest username="test",realm="service",response="digest-value-3524" rejected',
            'digest-value-3524',
        ];
        yield 'custom scheme and multiple fields' => [
            'Authorization: Custom user=test signature=custom-value-3524 request failed',
            'custom-value-3524',
        ];
        yield 'api key scheme using equals separator' => [
            'Authorization=ApiKey key-value-3524 rejected', 'key-value-3524',
        ];
        yield 'bearer regression' => [
            'Authorization: Bearer jwt-value-3524', 'jwt-value-3524',
        ];
        yield 'basic regression' => [
            'Authorization: Basic encoded-value-3524', 'encoded-value-3524',
        ];
        yield 'mixed case and spacing' => [
            'aUtHoRiZaTiOn : Token space-value-3524', 'space-value-3524',
        ];
    }

    public function test_nested_causes_keep_pre_header_diagnostics_and_hide_both_credentials(): void
    {
        $inner = new RuntimeException('SQLSTATE[42000] DB_PASSWORD=inner-password-3524');
        $outer = new RuntimeException(
            'Package validation failed: Authorization: Digest response=digest-value-3524,nonce=nonce-3524',
            0,
            $inner,
        );

        $summary = $this->summarize($outer);

        $this->assertStringContainsString('Package validation failed', $summary);
        $this->assertStringContainsString('SQLSTATE[42000]', $summary);
        $this->assertStringContainsString('Caused by:', $summary);
        $this->assertStringNotContainsString('digest-value-3524', $summary);
        $this->assertStringNotContainsString('nonce-3524', $summary);
        $this->assertStringNotContainsString('inner-password-3524', $summary);
    }

    public function test_nonsecret_sqlstate_remains_unchanged(): void
    {
        $message = 'SQLSTATE[HY000] package migration failed; authorization was denied';

        $this->assertSame($message, $this->summarize(new RuntimeException($message)));
    }

    private function summarize(RuntimeException $exception): string
    {
        // Exercise the real diagnostic sanitizer without running an Update,
        // touching the database, storing audit events, or accessing credentials.
        $manager = (new ReflectionClass(UpdateManager::class))->newInstanceWithoutConstructor();

        return (string) (new ReflectionMethod(UpdateManager::class, 'exceptionSummary'))
            ->invoke($manager, $exception);
    }
}
