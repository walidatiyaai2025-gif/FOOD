<?php

namespace Tests\Unit;

use App\Services\ProductionLaunchPreflight;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class ProductionLaunchPreflightTest extends TestCase
{
    public function test_missing_required_checks_are_fail_closed(): void
    {
        $service = new ProductionLaunchPreflight;

        $report = $service->decide([
            'schema_migrations' => ['passed' => true, 'detail' => 'ok'],
        ]);

        self::assertFalse($report['ready']);
        self::assertContains('exact_head_required_ci', $report['blockers']);
        self::assertCount(count(ProductionLaunchPreflight::REQUIRED_CHECKS), $report['checks']);
    }

    public function test_gate_is_ready_only_when_every_required_check_passes(): void
    {
        $service = new ProductionLaunchPreflight;
        $checks = [];

        foreach (ProductionLaunchPreflight::REQUIRED_CHECKS as $code) {
            $checks[$code] = [
                'passed' => true,
                'detail' => 'passed',
                'source' => 'test',
            ];
        }

        $report = $service->decide($checks);

        self::assertTrue($report['ready']);
        self::assertSame([], $report['blockers']);
        $service->assertLaunchable($report);
    }

    public function test_one_failure_blocks_launch_without_bypass(): void
    {
        $service = new ProductionLaunchPreflight;
        $checks = [];

        foreach (ProductionLaunchPreflight::REQUIRED_CHECKS as $code) {
            $checks[$code] = ['passed' => true, 'detail' => 'passed'];
        }
        $checks['product_image_quality'] = [
            'passed' => false,
            'detail' => 'one low-resolution product image',
        ];

        $report = $service->decide($checks);

        self::assertFalse($report['ready']);
        self::assertSame(['product_image_quality'], $report['blockers']);

        $this->expectException(ValidationException::class);
        $service->assertLaunchable($report);
    }
}
