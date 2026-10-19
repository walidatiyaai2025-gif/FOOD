<?php

use App\Services\LegacyFoodexImportService;
use Illuminate\Support\Facades\Artisan;

Artisan::command(
    'foodex:legacy-preflight {--json : Output machine-readable JSON}',
    function (LegacyFoodexImportService $importer): int {
        try {
            $report = $importer->preflight();
        } catch (Throwable $exception) {
            $this->error('Legacy Foodex preflight failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->info('Legacy Foodex SQL Server preflight');
        $this->line('Source DB: '.$report['source_database']);
        $this->line('Target driver: '.$report['target']['database_driver']);
        $this->line('Transaction range: '.($report['transaction_date_range']['first'] ?? 'n/a').' -> '.($report['transaction_date_range']['last'] ?? 'n/a'));
        $this->line(
            'Stock snapshot: '.
            ($report['stock_snapshot']['first_date'] ?? 'n/a').' -> '.
            ($report['stock_snapshot']['last_date'] ?? 'n/a').
            ' | rows='.$report['stock_snapshot']['rows'].
            ' | stores='.$report['stock_snapshot']['stores'].
            ' | items='.$report['stock_snapshot']['items']
        );
        $this->line('Tracking schema ready: '.($report['target']['tracking_ready'] ? 'yes' : 'no'));

        $this->table(
            ['Price matrix check', 'Candidates', 'Non-linear'],
            collect($report['price_matrix_consistency'])
                ->map(fn (array $row, string $key): array => [
                    $key,
                    (int) $row['candidate_rows'],
                    (int) $row['mismatch_rows'],
                ])
                ->values()
                ->all(),
        );

        $this->table(
            ['Source table', 'Rows'],
            collect($report['source_counts'])
                ->map(fn ($count, $table): array => [(string) $table, (int) $count])
                ->values()
                ->all(),
        );

        if ($report['target']['b2b_stores'] !== []) {
            $this->table(
                ['B2B store ID', 'Code', 'Name'],
                collect($report['target']['b2b_stores'])
                    ->map(fn (array $store): array => [$store['id'], $store['code'], $store['name']])
                    ->all(),
            );
        } else {
            $this->warn('No active B2B store exists yet. Apply mode will resolve/create the canonical wholesale principal.');
        }

        return 0;
    },
)->purpose('Read-only validation of the legacy Foodex SQL Server source and the MySQL migration target.');

Artisan::command(
    'foodex:legacy-import {phase=masters : Import phase} {--apply : Persist changes; omitted means dry-run} {--json : Output machine-readable JSON}',
    function (LegacyFoodexImportService $importer): int {
        $phase = strtolower(trim((string) $this->argument('phase')));
        if ($phase !== 'masters') {
            $this->error('Supported phase right now: masters.');

            return 2;
        }

        try {
            $result = $importer->importMasters((bool) $this->option('apply'));
        } catch (Throwable $exception) {
            $this->error('Legacy Foodex import failed: '.$exception->getMessage());

            return 1;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        if (! (bool) $this->option('apply')) {
            $this->info('Legacy Foodex masters dry-run');
            $this->table(
                ['Planned entity', 'Rows'],
                collect($result['planned'])
                    ->map(fn ($count, $entity): array => [(string) $entity, (int) $count])
                    ->values()
                    ->all(),
            );
            $this->warn('Dry-run only. Re-run with --apply after reviewing these counts.');

            return 0;
        }

        $this->info('Legacy Foodex masters import completed.');
        $this->line('Run ID: '.$result['summary']['run_id']);
        $this->line('Wholesale store ID: '.$result['summary']['store_id']);

        foreach ($result['summary'] as $section => $summary) {
            if (! is_array($summary)) {
                continue;
            }

            $this->line($section.': '.json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return 0;
    },
)->purpose('Idempotently migrate legacy Foodex master data into canonical FOODEX tables with provenance and quarantine.');
