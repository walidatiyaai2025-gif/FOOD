<?php

namespace Tests\Unit\Performance;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Performance\Support\EnterprisePerformanceLineage;

final class EnterprisePerformanceLineageTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach ([
            'FOODEX_PERF_MODE',
            'FOODEX_PERF_SOURCE_HEAD',
            'FOODEX_PERF_BASELINE_HEAD',
        ] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    public function test_default_mode_preserves_accepted_baseline_behavior(): void
    {
        $lineage = EnterprisePerformanceLineage::resolve(str_repeat('a', 40), []);

        $this->assertSame('baseline', $lineage['mode']);
        $this->assertSame(str_repeat('a', 40), $lineage['source_head']);
        $this->assertNull($lineage['baseline_head']);
    }

    public function test_pull_request_event_uses_exact_candidate_and_integration_heads(): void
    {
        $lineage = EnterprisePerformanceLineage::resolve(str_repeat('a', 40), [
            'pull_request' => [
                'head' => ['sha' => str_repeat('c', 40)],
                'base' => ['sha' => str_repeat('b', 40)],
            ],
        ]);

        $this->assertSame('candidate', $lineage['mode']);
        $this->assertSame(str_repeat('c', 40), $lineage['source_head']);
        $this->assertSame(str_repeat('b', 40), $lineage['baseline_head']);
    }

    public function test_performance_integration_push_uses_exact_before_after_lineage(): void
    {
        $lineage = EnterprisePerformanceLineage::resolve(str_repeat('a', 40), [
            'ref' => 'refs/heads/feature/enterprise-performance-gates',
            'before' => str_repeat('b', 40),
            'after' => str_repeat('c', 40),
        ]);

        $this->assertSame('candidate', $lineage['mode']);
        $this->assertSame(str_repeat('c', 40), $lineage['source_head']);
        $this->assertSame(str_repeat('b', 40), $lineage['baseline_head']);
    }

    public function test_unrelated_push_preserves_baseline_fallback(): void
    {
        $lineage = EnterprisePerformanceLineage::resolve(str_repeat('a', 40), [
            'ref' => 'refs/heads/main',
            'before' => str_repeat('b', 40),
            'after' => str_repeat('c', 40),
        ]);

        $this->assertSame('baseline', $lineage['mode']);
        $this->assertSame(str_repeat('a', 40), $lineage['source_head']);
        $this->assertNull($lineage['baseline_head']);
    }

    public function test_pull_request_lineage_fails_closed_when_head_equals_base(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EnterprisePerformanceLineage::resolve(str_repeat('a', 40), [
            'pull_request' => [
                'head' => ['sha' => str_repeat('d', 40)],
                'base' => ['sha' => str_repeat('d', 40)],
            ],
        ]);
    }

    public function test_explicit_baseline_mode_accepts_current_main_source(): void
    {
        $this->environment('FOODEX_PERF_MODE', 'baseline');
        $this->environment('FOODEX_PERF_SOURCE_HEAD', str_repeat('b', 40));

        $lineage = EnterprisePerformanceLineage::resolve(str_repeat('a', 40));

        $this->assertSame('baseline', $lineage['mode']);
        $this->assertSame(str_repeat('b', 40), $lineage['source_head']);
        $this->assertNull($lineage['baseline_head']);
    }

    public function test_candidate_mode_requires_exact_source_and_baseline_heads(): void
    {
        $this->environment('FOODEX_PERF_MODE', 'candidate');
        $this->environment('FOODEX_PERF_SOURCE_HEAD', str_repeat('c', 40));
        $this->environment('FOODEX_PERF_BASELINE_HEAD', str_repeat('a', 40));

        $lineage = EnterprisePerformanceLineage::resolve(str_repeat('b', 40));

        $this->assertSame('candidate', $lineage['mode']);
        $this->assertSame(str_repeat('c', 40), $lineage['source_head']);
        $this->assertSame(str_repeat('a', 40), $lineage['baseline_head']);
    }

    public function test_partial_explicit_lineage_fails_closed(): void
    {
        $this->environment('FOODEX_PERF_SOURCE_HEAD', str_repeat('c', 40));

        $this->expectException(InvalidArgumentException::class);

        EnterprisePerformanceLineage::resolve(str_repeat('a', 40));
    }

    public function test_candidate_cannot_compare_a_head_to_itself(): void
    {
        $this->environment('FOODEX_PERF_MODE', 'candidate');
        $this->environment('FOODEX_PERF_SOURCE_HEAD', str_repeat('c', 40));
        $this->environment('FOODEX_PERF_BASELINE_HEAD', str_repeat('c', 40));

        $this->expectException(InvalidArgumentException::class);

        EnterprisePerformanceLineage::resolve(str_repeat('a', 40));
    }

    private function environment(string $key, string $value): void
    {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
