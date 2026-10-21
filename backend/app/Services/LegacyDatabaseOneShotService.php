<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class LegacyDatabaseOneShotService
{
    private const SOURCE_SYSTEM = 'foodex_sqlserver';

    public function __construct(
        private readonly LegacyDatabaseSnapshotService $snapshots,
        private readonly LegacyFoodexImportService $importer,
        private readonly LegacyFinanceAssetMaterializerService $assets,
    ) {}

    public function run(bool $apply, ?callable $progress = null): array
    {
        $this->progress($progress, 'preflight', 'start');
        $preflight = $this->importer->preflight();
        $this->progress($progress, 'preflight', 'done');

        if (! $apply) {
            return $this->dryRun($preflight, $progress);
        }

        $runId = (string) Str::uuid();
        DB::table('legacy_import_runs')->insert([
            'id' => $runId,
            'source_system' => self::SOURCE_SYSTEM,
            'phase' => 'database-one-shot',
            'mode' => 'apply',
            'status' => 'running',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->progress($progress, 'full-source-snapshot', 'start');
            $snapshot = $this->snapshots->snapshot();
            $this->progress($progress, 'full-source-snapshot', 'done');

            $this->assertSemanticTargetReady($preflight);

            $phases = [];
            $phases['masters'] = $this->runUnverifiedPhase('masters', $progress);
            $phases['extended-masters'] = $this->runUnverifiedPhase('extended-masters', $progress);
            $phases['stock'] = $this->runVerifiedPhase('stock', 'foodex:legacy-stock-verify', $progress);
            $phases['sales-history'] = $this->runVerifiedPhase('sales-history', 'foodex:legacy-sales-history-verify', $progress);
            $phases['purchase-history'] = $this->runVerifiedPhase('purchase-history', 'foodex:legacy-purchase-history-verify', $progress);
            $phases['operational-history'] = $this->runVerifiedPhase('operational-history', 'foodex:legacy-operational-history-verify', $progress);
            $phases['finance-history'] = $this->runVerifiedPhase('finance-history', 'foodex:legacy-finance-history-verify', $progress);
            $phases['finance-assets'] = $this->runAssets($progress);
            $phases['customer360'] = $this->runCustomer360($progress);

            $this->progress($progress, 'final-verification', 'start');
            $finalVerification = $this->finalVerification();
            $this->progress($progress, 'final-verification', 'done');

            $coverage = $this->coverage((string) $snapshot['snapshot_id']);
            $assetPreflight = $phases['finance-assets']['native_preflight'];
            $semanticHealthy = collect($finalVerification)->every(
                static fn (array $row): bool => (bool) ($row['healthy'] ?? false),
            );

            $summary = [
                'run_id' => $runId,
                'snapshot_id' => $snapshot['snapshot_id'],
                'source_database' => $snapshot['source_database'],
                'source_table_count' => $snapshot['table_count'],
                'source_row_count' => $snapshot['source_row_count'],
                'archived_row_count' => $snapshot['archived_row_count'],
                'all_source_data_preserved' => (bool) $snapshot['verification']['healthy'],
                'semantic_verifications_healthy' => $semanticHealthy,
                'native_fixed_asset_ready' => (bool) $assetPreflight['ready_for_native_promotion'],
                'coverage' => $coverage,
                'phases' => $phases,
                'final_verification' => $finalVerification,
            ];
            $summary['status'] = $summary['all_source_data_preserved'] && $semanticHealthy
                ? ($coverage['raw_only_source_tables'] === [] && $summary['native_fixed_asset_ready']
                    ? 'complete_native'
                    : 'complete_preserved_with_native_gaps')
                : 'failed';

            DB::table('legacy_import_runs')->where('id', $runId)->update([
                'status' => $summary['status'] === 'failed' ? 'failed' : 'completed',
                'summary' => $this->json($summary),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            if ($summary['status'] === 'failed') {
                throw new RuntimeException('Full legacy database one-shot verification failed.');
            }

            return $summary;
        } catch (Throwable $exception) {
            DB::table('legacy_import_runs')->where('id', $runId)->update([
                'status' => 'failed',
                'error' => mb_substr($exception->getMessage(), 0, 60000),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            throw $exception;
        }
    }

    private function dryRun(array $preflight, ?callable $progress): array
    {
        $this->progress($progress, 'full-source-inventory', 'start');
        $snapshotPreview = $this->snapshots->preview();
        $this->progress($progress, 'full-source-inventory', 'done');

        $phasePreviews = [];
        foreach ([
            'masters',
            'extended-masters',
            'stock',
            'sales-history',
            'purchase-history',
            'operational-history',
            'finance-history',
        ] as $phase) {
            $this->progress($progress, $phase.'-dry-run', 'start');
            $phasePreviews[$phase] = $this->importPhase($phase, false);
            $this->progress($progress, $phase.'-dry-run', 'done');
        }

        $phasePreviews['finance-assets'] = $this->assets->preview();
        $phasePreviews['customer360'] = $this->importer->backfillCustomer360(false);

        return [
            'mode' => 'dry-run',
            'preflight' => $preflight,
            'full_source_inventory' => $snapshotPreview,
            'phase_previews' => $phasePreviews,
            'rules' => [
                'every_source_base_table_is_inventoried' => true,
                'apply_snapshots_every_source_row_before_semantic_writes' => true,
                'verified_completed_phases_are_resumed_not_reapplied' => true,
                'unsupported_source_data_is_preserved_raw' => true,
                'unsafe_semantic_writes_fail_fast' => true,
                'accounting_data_is_never_fabricated' => true,
            ],
        ];
    }

    private function runUnverifiedPhase(string $phase, ?callable $progress): array
    {
        $this->progress($progress, $phase, 'start');
        $dryRun = $this->importPhase($phase, false);
        $apply = $this->importPhase($phase, true);
        $this->progress($progress, $phase, 'done');

        return [
            'status' => 'applied',
            'dry_run' => $dryRun,
            'apply' => $apply,
        ];
    }

    private function runVerifiedPhase(string $phase, string $verifyCommand, ?callable $progress): array
    {
        $this->progress($progress, $phase, 'start');
        $before = $this->artisanJson($verifyCommand);
        if ($before['exit_code'] === 0 && (bool) ($before['report']['healthy'] ?? false)) {
            $this->progress($progress, $phase, 'done');

            return [
                'status' => 'already_verified',
                'verification' => $before['report'],
            ];
        }

        $dryRun = $this->importPhase($phase, false);
        $apply = $this->importPhase($phase, true);
        $after = $this->artisanJson($verifyCommand);

        if ($after['exit_code'] !== 0 || ! (bool) ($after['report']['healthy'] ?? false)) {
            throw new RuntimeException("{$phase} verification failed after apply.");
        }

        $this->progress($progress, $phase, 'done');

        return [
            'status' => 'applied_and_verified',
            'dry_run' => $dryRun,
            'apply' => $apply,
            'verification' => $after['report'],
        ];
    }

    private function runAssets(?callable $progress): array
    {
        $this->progress($progress, 'finance-assets', 'start');
        $before = $this->assets->verify();

        if (! $before['healthy']) {
            $dryRun = $this->assets->preview();
            $apply = $this->assets->materialize(true);
        } else {
            $dryRun = null;
            $apply = null;
        }

        $verification = $this->assets->verify();
        if (! $verification['healthy']) {
            throw new RuntimeException('Fixed-asset preservation verification failed.');
        }

        $nativePreflight = $this->assets->nativePromotionPreflight();
        $this->progress($progress, 'finance-assets', 'done');

        return [
            'status' => $apply === null ? 'already_verified' : 'materialized_and_verified',
            'dry_run' => $dryRun,
            'apply' => $apply,
            'verification' => $verification,
            'native_preflight' => $nativePreflight,
        ];
    }

    private function runCustomer360(?callable $progress): array
    {
        $this->progress($progress, 'customer360', 'start');
        $dryRun = $this->importer->backfillCustomer360(false);
        $apply = $this->importer->backfillCustomer360(true);
        $idempotency = $this->importer->backfillCustomer360(false);
        $this->progress($progress, 'customer360', 'done');

        return [
            'status' => 'applied',
            'dry_run' => $dryRun,
            'apply' => $apply,
            'idempotency_check' => $idempotency,
        ];
    }

    private function finalVerification(): array
    {
        $commands = [
            'stock' => 'foodex:legacy-stock-verify',
            'sales-history' => 'foodex:legacy-sales-history-verify',
            'purchase-history' => 'foodex:legacy-purchase-history-verify',
            'operational-history' => 'foodex:legacy-operational-history-verify',
            'finance-history' => 'foodex:legacy-finance-history-verify',
            'finance-assets' => 'foodex:legacy-finance-assets-verify',
        ];
        $reports = [];

        foreach ($commands as $name => $command) {
            $result = $this->artisanJson($command);
            $reports[$name] = [
                'healthy' => $result['exit_code'] === 0 && (bool) ($result['report']['healthy'] ?? false),
                'report' => $result['report'],
            ];
        }

        return $reports;
    }

    private function coverage(string $snapshotId): array
    {
        $archived = $this->snapshots->archivedTables($snapshotId);
        $mapped = DB::table('legacy_import_mappings')
            ->where('source_system', self::SOURCE_SYSTEM)
            ->distinct()
            ->pluck('source_table')
            ->map(static fn ($name): string => (string) $name)
            ->all();
        $mappedSet = array_fill_keys($mapped, true);
        $semantic = [];
        $rawOnly = [];

        foreach ($archived as $table) {
            $qualified = $table['schema'].'.'.$table['table'];
            if (isset($mappedSet[$table['table']]) || isset($mappedSet[$qualified])) {
                $semantic[] = $qualified;
            } else {
                $rawOnly[] = $qualified;
            }
        }

        return [
            'archived_source_tables' => array_values(array_map(
                static fn (array $table): string => $table['schema'].'.'.$table['table'],
                $archived,
            )),
            'tables_with_semantic_mappings' => $semantic,
            'raw_only_source_tables' => $rawOnly,
            'semantic_mapping_rows' => (int) DB::table('legacy_import_mappings')
                ->where('source_system', self::SOURCE_SYSTEM)
                ->count(),
            'raw_archive_is_lossless_fallback' => true,
        ];
    }

    private function importPhase(string $phase, bool $apply): array
    {
        return match ($phase) {
            'masters' => $this->importer->importMasters($apply),
            'extended-masters' => $this->importer->importExtendedMasters($apply),
            'stock' => $this->importer->importStock($apply),
            'sales-history' => $this->importer->importSalesHistory($apply),
            'purchase-history' => $this->importer->importPurchaseHistory($apply),
            'operational-history' => $this->importer->importOperationalHistory($apply),
            'finance-history' => $this->importer->importFinanceHistory($apply),
            default => throw new RuntimeException("Unsupported one-shot phase: {$phase}"),
        };
    }

    private function artisanJson(string $command): array
    {
        $exitCode = Artisan::call($command, ['--json' => true]);
        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);

        return [
            'exit_code' => $exitCode,
            'report' => is_array($decoded) ? $decoded : ['raw_output' => $output],
        ];
    }

    private function assertSemanticTargetReady(array $preflight): void
    {
        $target = (array) ($preflight['target'] ?? []);
        $ready = (bool) ($target['clean_baseline_ready'] ?? false)
            || (bool) ($target['legacy_upgrade_ready'] ?? false)
            || (bool) ($target['legacy_masters_rerun_ready'] ?? false);

        if (! $ready) {
            throw new RuntimeException(
                'Target is not safe for semantic legacy import. Full raw source snapshot was preserved, but native writes were refused.'
            );
        }
    }

    private function progress(?callable $progress, string $stage, string $state): void
    {
        if ($progress !== null) {
            $progress($stage, $state);
        }
    }

    private function json(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if (! is_string($json)) {
            throw new RuntimeException('Unable to encode full legacy one-shot summary.');
        }

        return $json;
    }
}
