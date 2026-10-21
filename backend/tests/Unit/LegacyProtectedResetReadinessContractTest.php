<?php

namespace Tests\Unit;

use Tests\TestCase;

final class LegacyProtectedResetReadinessContractTest extends TestCase
{
    public function test_importer_accepts_only_verified_protected_post_reset_baseline(): void
    {
        $source = file_get_contents(app_path('Services/LegacyFoodexImportService.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("'legacy_protected_reset_ready' => $legacyProtectedResetReady", $source);
        $this->assertStringContainsString("&& ! ($preflight['target']['legacy_protected_reset_ready'] ?? false)", $source);
        $this->assertStringContainsString('private function legacyProtectedResetReady(array $sourceCounts): bool', $source);

        foreach ([
            'warehouses',
            'customers',
            'b2b_customers',
            'b2b_accounts',
            'platform_customers',
            'b2b_price_tiers',
        ] as $protectedTarget) {
            $this->assertStringContainsString("'{$protectedTarget}'", $source);
        }

        foreach ([
            'products',
            'categories',
            'inventories',
            'stock_movements',
            'orders',
            'invoices',
            'payments',
            'vans',
        ] as $resetTarget) {
            $this->assertStringContainsString("'{$resetTarget}'", $source);
        }
    }

    public function test_preflight_output_and_rehearsal_script_recognize_protected_reset_state(): void
    {
        $routes = file_get_contents(base_path('routes/legacy_import.php'));
        $script = file_get_contents(base_path('../scripts/run-legacy-rehearsal.ps1'));

        $this->assertIsString($routes);
        $this->assertIsString($script);
        $this->assertStringContainsString('Protected reset ready:', $routes);
        $this->assertStringContainsString('legacy_protected_reset_ready', $script);
    }
}
