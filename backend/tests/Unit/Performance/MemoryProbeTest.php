<?php

namespace Tests\Unit\Performance;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Performance\Support\MemoryProbe;

final class MemoryProbeTest extends TestCase
{
    #[DataProvider('memoryLimitProvider')]
    public function test_it_parses_php_memory_limits(string $value, ?int $expected): void
    {
        $this->assertSame($expected, MemoryProbe::parseMemoryLimit($value));
    }

    /**
     * @return array<string, array{0: string, 1: int|null}>
     */
    public static function memoryLimitProvider(): array
    {
        return [
            'unlimited' => ['-1', null],
            'empty' => ['', null],
            'bytes' => ['1024', 1024],
            'kilobytes' => ['512K', 512 * 1024],
            'megabytes' => ['128M', 128 * 1024 * 1024],
            'gigabytes' => ['1G', 1024 * 1024 * 1024],
            'decimal' => ['1.5G', (int) (1.5 * 1024 * 1024 * 1024)],
        ];
    }

    public function test_request_budget_enforces_target_and_75_percent_limit(): void
    {
        $pass = MemoryProbe::evaluate(
            90 * 1024 * 1024,
            128 * 1024 * 1024
        );

        $this->assertTrue($pass['target_pass']);
        $this->assertTrue($pass['configured_limit_fraction_pass']);

        $fail = MemoryProbe::evaluate(
            100 * 1024 * 1024,
            128 * 1024 * 1024
        );

        $this->assertFalse($fail['target_pass']);
        $this->assertFalse($fail['configured_limit_fraction_pass']);
    }

    public function test_background_job_uses_128_mib_target(): void
    {
        $result = MemoryProbe::evaluate(
            120 * 1024 * 1024,
            256 * 1024 * 1024,
            backgroundJob: true
        );

        $this->assertSame(128, $result['target_mib']);
        $this->assertTrue($result['target_pass']);
        $this->assertTrue($result['configured_limit_fraction_pass']);
    }

    public function test_invalid_memory_limit_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MemoryProbe::parseMemoryLimit('lots');
    }

    public function test_runtime_probe_returns_peak_measurement(): void
    {
        $probe = new MemoryProbe;
        $probe->start();

        $payload = str_repeat('x', 1024 * 1024);
        $snapshot = $probe->snapshot();

        $this->assertNotSame('', $payload);
        $this->assertGreaterThanOrEqual($snapshot['start_bytes'], $snapshot['peak_bytes']);
        $this->assertGreaterThan(0.0, $snapshot['peak_mib']);
    }
}
