<?php

namespace Tests\Unit;

use App\Services\LegacyImportRehearsalResetService;
use ReflectionMethod;
use Tests\TestCase;

final class LegacyImportRehearsalResetServiceTest extends TestCase
{
    public function test_post_import_reset_includes_imported_dependents(): void
    {
        $source = file_get_contents(app_path('Services/LegacyImportRehearsalResetService.php'));

        $this->assertIsString($source);
        foreach ([
            'legacy_finance_transactions',
            'legacy_bank_transactions',
            'legacy_operational_document_lines',
            'legacy_operational_documents',
            'legacy_procurement_document_lines',
            'legacy_procurement_documents',
            'legacy_fixed_asset_records',
            'product_supplier_brand_sources',
            'van_inventory_history_lines',
            'van_inventory_history_documents',
        ] as $table) {
            $this->assertStringContainsString("'{$table}'", $source);
        }
    }

    public function test_mapping_cleanup_targets_only_reset_tables_plus_legacy_ledger(): void
    {
        $service = new LegacyImportRehearsalResetService;
        $method = new ReflectionMethod($service, 'mappingTargetTables');
        $method->setAccessible(true);

        $targets = $method->invoke($service, ['products', 'orders']);

        $this->assertSame(
            ['products', 'orders', 'customer_account_ledger_entries'],
            $targets,
        );
        $this->assertNotContains('customers', $targets);
        $this->assertNotContains('warehouses', $targets);
    }

    public function test_route_no_longer_refuses_post_import_reset_only_because_mappings_exist(): void
    {
        $routes = file_get_contents(base_path('routes/legacy_import.php'));

        $this->assertIsString($routes);
        $this->assertStringContainsString('Legacy mapping rows to remove:', $routes);
        $this->assertStringNotContainsString(
            'Restore the pre-import target before using this rehearsal reset.',
            $routes,
        );
    }
}
