<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2NavigationSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_is_redacted_deterministic_and_read_only(): void
    {
        DB::table('admin_sidebar_groups')->insert([
            'group_key' => 'overview',
            'label_ar' => 'PRIVATE_AR_LABEL_SHOULD_NEVER_APPEAR',
            'label_en' => 'PRIVATE_EN_LABEL_SHOULD_NEVER_APPEAR',
            'sort_order' => 42,
            'is_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('admin_sidebar_items')->insert([
            'item_key' => 'enterprise_workspaces',
            'group_key' => 'overview',
            'label_ar' => 'PRIVATE_ITEM_LABEL_SHOULD_NEVER_APPEAR',
            'label_en' => 'PRIVATE_ITEM_EN_SHOULD_NEVER_APPEAR',
            'sort_order' => 8,
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = [
            DB::table('admin_sidebar_groups')->count(),
            DB::table('admin_sidebar_items')->count(),
        ];
        [$exit, $report] = $this->snapshot();

        $this->assertSame(0, $exit);
        $this->assertSame(1, $report['schema_version']);
        $this->assertSame(2777, $report['issue']);
        $this->assertTrue($report['read_only']);
        $this->assertFalse($report['production_deployment_verified']);
        $this->assertSame('not_attested', $report['environment_identity']);
        $this->assertSame('not_tested', $report['route_authorization']);
        $this->assertTrue($report['sidebar_tables_present']);
        $this->assertSame('not_compared', $report['comparison']['status']);
        $this->assertSame(false, $report['saved_layout']['groups']['overview']['enabled']);
        $this->assertSame(42, $report['saved_layout']['groups']['overview']['sort_order']);
        $this->assertSame('overview', $report['saved_layout']['items']['enterprise_workspaces']['group_key']);
        $this->assertSame(64, strlen($report['canonical_sha256']));
        $this->assertSame(64, strlen($report['saved_layout_sha256']));

        $serialized = json_encode($report, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE_AR_LABEL', 'PRIVATE_EN_LABEL', 'PRIVATE_ITEM_LABEL', 'PRIVATE_ITEM_EN'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        $this->assertSame($before, [
            DB::table('admin_sidebar_groups')->count(),
            DB::table('admin_sidebar_items')->count(),
        ]);
        [, $again] = $this->snapshot();
        $this->assertSame($report, $again);
    }

    public function test_comparing_the_same_snapshot_never_claims_production_verification(): void
    {
        [, $reference] = $this->snapshot();

        $this->withBaseline($reference, function (string $path): void {
            [$exit, $report] = $this->snapshot($path);

            $this->assertSame(0, $exit);
            $this->assertSame('matching_snapshots_unattested', $report['comparison']['status']);
            $this->assertFalse($report['comparison']['production_verified']);
            foreach ($report['comparison']['differences'] as $counts) {
                $this->assertSame(['removed' => 0, 'added' => 0, 'changed' => 0], $counts);
            }
        });
    }

    public function test_comparison_detects_canonical_and_saved_layout_drift_even_if_hash_is_forged(): void
    {
        [, $reference] = $this->snapshot();
        $reference['canonical']['groups']['overview']['sort_order'] = -999;
        $reference['saved_layout']['items']['enterprise_workspaces']['enabled'] = false;
        // Intentionally keep the original hashes: decisions compare actual data, not labels/hashes.
        $this->withBaseline($reference, function (string $path): void {
            [$exit, $report] = $this->snapshot($path);

            $this->assertSame(1, $exit);
            $this->assertSame('drift_detected', $report['comparison']['status']);
            $this->assertSame(1, $report['comparison']['differences']['canonical_groups']['changed']);
            $this->assertSame(1, $report['comparison']['differences']['saved_items']['changed']);
            $this->assertFalse($report['production_deployment_verified']);
        });
    }

    public function test_comparison_fails_closed_for_missing_runtime_or_invalid_baseline(): void
    {
        [, $reference] = $this->snapshot();
        $reference['saved_layout'] = null;

        $this->withBaseline($reference, function (string $path): void {
            [$exit, $report] = $this->snapshot($path);
            $this->assertSame(1, $exit);
            $this->assertSame('incomplete_runtime_inventory', $report['comparison']['status']);
        });

        $this->withBaseline([
            'schema_version' => 1,
            'issue' => 2777,
            'canonical' => [
                'groups' => ['../sensitive' => ['sort_order' => 1]],
                'items' => [],
            ],
            'saved_layout' => ['groups' => [], 'items' => []],
        ], function (string $path): void {
            [$exit, $report] = $this->snapshot($path);
            $this->assertSame(1, $exit);
            $this->assertSame('invalid_baseline', $report['comparison']['status']);
            $this->assertStringNotContainsString('sensitive', json_encode($report, JSON_THROW_ON_ERROR));
        });
    }

    /** @return array{int,array<string,mixed>} */
    private function snapshot(?string $against = null): array
    {
        $arguments = $against === null ? [] : ['--against' => $against];
        $exit = Artisan::call('foodex:v2-navigation-snapshot', $arguments);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($report);

        return [$exit, $report];
    }

    /** @param array<string,mixed> $baseline */
    private function withBaseline(array $baseline, callable $assertions): void
    {
        $path = tempnam(sys_get_temp_dir(), 'foodex-v2-nav-');
        $this->assertIsString($path);
        try {
            file_put_contents($path, json_encode($baseline, JSON_THROW_ON_ERROR));
            $assertions($path);
        } finally {
            @unlink($path);
        }
    }
}
