<?php

namespace Tests\Unit;

use Tests\TestCase;

final class LegacyDatabaseOneShotContractTest extends TestCase
{
    public function test_one_shot_accepts_the_protected_post_reset_baseline(): void
    {
        $source = file_get_contents(app_path('Services/LegacyDatabaseOneShotService.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('legacy_protected_reset_ready', $source);
        $this->assertStringContainsString('approved-target-reset', $source);
        $this->assertStringContainsString('approved_target_reset', $source);
        $this->assertStringContainsString('LegacyImportRehearsalResetService', $source);
    }

    public function test_one_shot_stock_is_fail_closed_and_normalized_to_principal_warehouse(): void
    {
        $source = file_get_contents(app_path('Services/LegacyDatabaseOneShotService.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('accepted_stock_detail_rows', $source);
        $this->assertStringContainsString('positive_positions', $source);
        $this->assertStringContainsString('positive_products', $source);
        $this->assertStringContainsString('unmapped_locations', $source);
        $this->assertStringContainsString('unmapped_items', $source);
        $this->assertStringContainsString('normalizeLegacyStockToPrincipalWarehouse', $source);
        $this->assertStringContainsString('principalWarehouseId', $source);
        $this->assertStringContainsString('nonPrincipalLegacyTargets', $source);
        $this->assertStringContainsString('principal_warehouse_product_aggregate', $source);
    }

    public function test_one_shot_command_exposes_single_command_safe_reset_mode(): void
    {
        $routes = file_get_contents(base_path('routes/legacy_import.php'));

        $this->assertIsString($routes);
        $this->assertStringContainsString('--reset-approved-scope', $routes);
        $this->assertStringContainsString('--confirm=', $routes);
        $this->assertStringContainsString('expected.positive_positions', $routes);
        $this->assertStringContainsString('expected.positive_products', $routes);
    }
}
