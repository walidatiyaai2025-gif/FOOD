<?php

namespace Tests\Unit;

use Tests\TestCase;

final class LegacyStockVerificationContractTest extends TestCase
{
    public function test_stock_verify_requires_actual_rows_to_match_planned_positive_positions(): void
    {
        $routes = file_get_contents(base_path('routes/legacy_import.php'));

        $this->assertIsString($routes);
        $this->assertStringContainsString("array_key_exists('positive_positions', \$planned)", $routes);
        $this->assertStringContainsString("\$inventory['total'] === \$expectedPositivePositions", $routes);
        $this->assertStringContainsString("\$baselineMovements === \$expectedPositivePositions", $routes);
        $this->assertStringContainsString("\$mappings === \$expectedPositivePositions", $routes);
        $this->assertStringContainsString("['expected.positive_positions', \$expectedPositivePositions ?? 'n/a']", $routes);
    }
}
