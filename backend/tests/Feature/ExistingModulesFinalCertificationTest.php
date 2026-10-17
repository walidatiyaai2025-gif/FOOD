<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ExistingModulesFinalCertificationTest extends TestCase
{
    public function test_navigation_inventory_has_no_unresolved_discoverability_gaps(): void
    {
        $path = base_path('../docs/execution/DASHBOARD_NAVIGATION_CAPABILITY_INVENTORY.json');
        $this->assertFileExists($path);

        $inventory = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $summary = $inventory['coverage_summary'] ?? [];

        $this->assertSame(0, (int) ($summary['unresolved_nav_missing'] ?? -1));
        $this->assertSame(0, (int) ($summary['unresolved_nav_weak'] ?? -1));
        $this->assertSame(0, (int) ($summary['unresolved_duplicate_destinations'] ?? -1));
        $this->assertNotEmpty($inventory['capabilities'] ?? []);

        foreach ($inventory['capabilities'] as $capability) {
            $status = (string) ($capability['status'] ?? '');
            $this->assertNotContains($status, ['NAV_MISSING', 'NAV_WEAK']);
            $this->assertNotSame('', trim((string) ($capability['ar_label'] ?? '')));
            $this->assertNotSame('', trim((string) ($capability['en_label'] ?? '')));
            $this->assertNotEmpty($capability['evidence'] ?? []);
        }
    }

    public function test_certification_authorities_exist_in_repository(): void
    {
        foreach ([
            '../docs/execution/EXISTING_MODULES_FINAL_CERTIFICATION.md',
            '../docs/execution/DASHBOARD_NAVIGATION_DISCOVERABILITY_AUDIT.md',
            'tests/Feature/NavigationCapabilityInventoryTest.php',
        ] as $relativePath) {
            $this->assertFileExists(base_path($relativePath));
        }
    }
}
