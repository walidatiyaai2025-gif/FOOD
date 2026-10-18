<?php

namespace App\Http\Controllers\Admin;

use App\Enterprise\Health\ModuleHealthService;
use App\Enterprise\Modules\ModuleManifestCatalog;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Http\Controllers\Controller;
use App\Models\EnterpriseOutboxMessage;
use App\Models\EnterpriseWorkflowRequest;
use App\Models\SystemInspectorEvent;
use App\Models\SystemVersion;
use App\Models\UpdateHistory;
use App\Models\User;
use App\Services\ProductionDatasetSnapshot;
use App\Support\AdminNavigation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class EnterpriseSystemHealthController extends Controller
{
    public function __construct(
        private readonly ModuleManifestCatalog $catalog,
        private readonly ModuleRegistry $registry,
        private readonly ModuleHealthService $health,
        private readonly AdminNavigation $navigation,
        private readonly ProductionDatasetSnapshot $snapshot,
    ) {}

    public function __invoke(Request $request): View
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('platform.manage');

        [$from, $to] = $this->period($request);
        $reports = $this->health->all();
        $modules = $this->catalog->all()->map(function ($manifest) use ($reports): array {
            $report = $reports[$manifest->code];
            $state = $this->safeState($manifest->code);

            $dependencies = collect($manifest->hardDependencies)->map(function (string $dependency): array {
                return [
                    'name' => Str::headline($dependency),
                    'state' => $this->safeState($dependency)->value,
                ];
            })->values()->all();

            return [
                'name' => Str::headline($manifest->code),
                'category' => Str::headline($manifest->category),
                'lifecycle' => $state->value,
                'health' => $report->status->value,
                'checked_at' => $report->checkedAt->toIso8601String(),
                'check_count' => count($report->checks),
                'stale_count' => collect($report->checks)->filter(
                    static fn ($check): bool => $check->isStale(),
                )->count(),
                'dependencies' => $dependencies,
            ];
        })->values();

        $database = $this->databaseHealth();
        $cache = $this->cacheHealth();
        $queue = $this->queueHealth();
        $storage = $this->storageHealth();
        $failedJobs = $this->failedJobs($from, $to);
        $scheduler = $this->schedulerHealth();
        $pendingMigrations = $this->pendingMigrationCount();
        $snapshot = $this->safeSnapshotStatus();
        $updates = UpdateHistory::query()
            ->with('actor:id,name')
            ->latest('id')
            ->limit(24)
            ->get();
        $backupRows = $this->backupRows($updates, $snapshot);
        $lastBackup = collect($backupRows)
            ->filter(static fn (array $row): bool => ($row['successful'] ?? false) === true
                && is_string($row['created_at'] ?? null)
                && $row['created_at'] !== '')
            ->sortByDesc('created_at')
            ->first();
        $backupAge = $lastBackup !== null
            ? CarbonImmutable::parse((string) $lastBackup['created_at'])->diffForHumans()
            : '—';
        $latestUpdate = $updates->first();
        $warnings = $this->configurationWarnings($database, $cache, $queue, $storage, $scheduler);
        $applicationState = $this->applicationState($modules->all(), $database, $cache, $storage);
        $currentVersion = $this->currentVersion();
        $incidentTrend = $this->incidentTrend($from, $to);
        $updateTrend = $this->updateTrend($from, $to);
        $now = now()->toIso8601String();

        $queueState = ($failedJobs['count'] > 0 || $scheduler['state'] !== 'healthy' || $queue['state'] !== 'healthy')
            ? 'degraded'
            : 'healthy';

        $kpis = [
            [
                'label' => __('system_admin.kpis.application'),
                'value' => __('system_admin.states.'.$applicationState),
                'status' => $this->kpiStatus($applicationState),
                'last_refresh' => $now,
                'drilldown_url' => '#module-health',
            ],
            [
                'label' => __('system_admin.kpis.database'),
                'value' => __('system_admin.states.'.$database['state']),
                'status' => $this->kpiStatus($database['state']),
                'last_refresh' => $now,
                'drilldown_url' => route('admin.inspector.index'),
            ],
            [
                'label' => __('system_admin.kpis.queue_scheduler'),
                'value' => __('system_admin.states.'.$queueState),
                'status' => $this->kpiStatus($queueState),
                'last_refresh' => $scheduler['last_run'] ?? $now,
                'drilldown_url' => '#failed-jobs',
            ],
            [
                'label' => __('system_admin.kpis.failed_jobs'),
                'value' => number_format($failedJobs['count']),
                'status' => [
                    'state' => $failedJobs['count'] > 0 ? 'danger' : 'success',
                    'label' => $failedJobs['count'] > 0 ? __('system_admin.states.degraded') : __('system_admin.states.healthy'),
                ],
                'last_refresh' => $now,
                'drilldown_url' => '#failed-jobs',
            ],
            [
                'label' => __('system_admin.kpis.last_backup'),
                'value' => $lastBackup['created_at'] ?? '—',
                'status' => [
                    'state' => $lastBackup === null ? 'warning' : 'success',
                    'label' => $lastBackup === null ? __('system_admin.states.not_available') : __('system_admin.states.available'),
                ],
                'last_refresh' => $now,
                'drilldown_url' => '#backup-history',
            ],
            [
                'label' => __('system_admin.kpis.backup_age'),
                'value' => $backupAge,
                'status' => [
                    'state' => $lastBackup === null ? 'warning' : 'success',
                    'label' => $lastBackup === null ? __('system_admin.states.not_available') : __('system_admin.states.available'),
                ],
                'last_refresh' => $now,
                'drilldown_url' => '#backup-history',
            ],
            [
                'label' => __('system_admin.kpis.current_version'),
                'value' => $currentVersion,
                'status' => [
                    'state' => $latestUpdate?->status === 'failed' ? 'danger' : 'current',
                    'label' => $latestUpdate?->status === 'failed'
                        ? __('system_admin.evidence.failed')
                        : __('system_admin.states.current'),
                ],
                'last_refresh' => optional($latestUpdate?->finished_at)->toIso8601String() ?: $now,
                'drilldown_url' => route('admin.system-update.index'),
            ],
            [
                'label' => __('system_admin.kpis.pending_migrations'),
                'value' => $pendingMigrations === null ? '—' : number_format($pendingMigrations),
                'status' => [
                    'state' => $pendingMigrations === null ? 'warning' : ($pendingMigrations > 0 ? 'danger' : 'success'),
                    'label' => $pendingMigrations === null
                        ? __('system_admin.states.unknown')
                        : ($pendingMigrations > 0 ? __('system_admin.states.degraded') : __('system_admin.states.healthy')),
                ],
                'last_refresh' => $now,
                'drilldown_url' => route('admin.system-update.index'),
            ],
            [
                'label' => __('system_admin.kpis.storage'),
                'value' => __('system_admin.states.'.$storage['state']),
                'status' => $this->kpiStatus($storage['state'] === 'writable' ? 'healthy' : 'error'),
                'last_refresh' => $now,
                'drilldown_url' => route('admin.inspector.index'),
            ],
            [
                'label' => __('system_admin.kpis.warnings'),
                'value' => number_format(count($warnings)),
                'status' => [
                    'state' => $warnings === [] ? 'success' : 'warning',
                    'label' => $warnings === [] ? __('system_admin.states.healthy') : __('system_admin.states.degraded'),
                ],
                'last_refresh' => $now,
                'drilldown_url' => '#configuration-warnings',
            ],
        ];

        return view('admin.enterprise-system-health', [
            'user' => $actor,
            'navGroups' => $this->navigation->groupsFor($actor),
            'navContext' => 'administration_hub',
            'modules' => $modules,
            'outboxCounts' => $this->statusCounts(EnterpriseOutboxMessage::query(), 'status'),
            'workflowCounts' => $this->statusCounts(EnterpriseWorkflowRequest::query(), 'status'),
            'queueDriver' => (string) config('queue.default', ''),
            'databaseHealth' => $database,
            'cacheHealth' => $cache,
            'queueHealth' => $queue,
            'storageHealth' => $storage,
            'schedulerHealth' => $scheduler,
            'failedJobs' => $failedJobs,
            'pendingMigrations' => $pendingMigrations,
            'snapshotStatus' => $snapshot,
            'backupRows' => $backupRows,
            'updateRows' => $updates,
            'warnings' => $warnings,
            'incidentTrend' => $incidentTrend,
            'updateTrend' => $updateTrend,
            'kpis' => $kpis,
            'selectedFrom' => $from->toDateString(),
            'selectedTo' => $to->toDateString(),
        ]);
    }

    private function safeState(string $module): ModuleState
    {
        try {
            return $this->registry->state($module);
        } catch (Throwable) {
            return ModuleState::ERROR;
        }
    }

    private function statusCounts($query, string $column): array
    {
        return $query
            ->selectRaw($column.', COUNT(*) AS aggregate')
            ->groupBy($column)
            ->orderBy($column)
            ->pluck('aggregate', $column)
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $to = isset($validated['to'])
            ? CarbonImmutable::createFromFormat('Y-m-d', (string) $validated['to'], 'UTC')->endOfDay()
            : CarbonImmutable::now('UTC')->endOfDay();
        $from = isset($validated['from'])
            ? CarbonImmutable::createFromFormat('Y-m-d', (string) $validated['from'], 'UTC')->startOfDay()
            : $to->subDays(13)->startOfDay();

        if ($from->diffInDays($to) > 90) {
            throw ValidationException::withMessages([
                'from' => __('validation.max.numeric', ['attribute' => 'period', 'max' => 90]),
            ]);
        }

        return [$from, $to];
    }

    /** @return array{state:string} */
    private function databaseHealth(): array
    {
        try {
            DB::select('select 1');

            return ['state' => 'healthy'];
        } catch (Throwable) {
            return ['state' => 'error'];
        }
    }

    /** @return array{state:string} */
    private function cacheHealth(): array
    {
        $key = 'foodex:system-health:'.bin2hex(random_bytes(6));

        try {
            Cache::put($key, 'ok', 5);
            $healthy = Cache::get($key) === 'ok';
            Cache::forget($key);

            return ['state' => $healthy ? 'healthy' : 'error'];
        } catch (Throwable) {
            return ['state' => 'error'];
        }
    }

    /** @return array{state:string} */
    private function queueHealth(): array
    {
        $driver = (string) config('queue.default');

        if ($driver === 'sync') {
            return ['state' => app()->environment('production') ? 'degraded' : 'healthy'];
        }

        if ($driver !== 'redis') {
            return ['state' => 'degraded'];
        }

        try {
            $connection = (string) config('queue.connections.redis.connection', 'default');
            $reply = Redis::connection($connection)->ping();

            return ['state' => $reply === false ? 'error' : 'healthy'];
        } catch (Throwable) {
            return ['state' => 'error'];
        }
    }

    /** @return array{state:string} */
    private function storageHealth(): array
    {
        $storage = storage_path();

        return [
            'state' => is_dir($storage) && is_writable($storage) ? 'writable' : 'not_writable',
        ];
    }

    /** @return array{available:bool,count:int,rows:list<array<string,mixed>>,trend:list<array{date:string,count:int}>} */
    private function failedJobs(CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ['available' => false, 'count' => 0, 'rows' => [], 'trend' => []];
        }

        try {
            $idColumn = Schema::hasColumn('failed_jobs', 'uuid') ? 'uuid' : 'id';
            $columns = [$idColumn];
            foreach (['connection', 'queue', 'failed_at'] as $column) {
                if (Schema::hasColumn('failed_jobs', $column)) {
                    $columns[] = $column;
                }
            }

            $base = DB::table('failed_jobs');
            $count = (clone $base)->count();
            $rows = (clone $base)
                ->orderByDesc(Schema::hasColumn('failed_jobs', 'failed_at') ? 'failed_at' : $idColumn)
                ->limit(20)
                ->get($columns)
                ->map(static fn (object $row): array => [
                    'reference' => (string) ($row->{$idColumn} ?? ''),
                    'connection' => (string) ($row->connection ?? ''),
                    'queue' => (string) ($row->queue ?? ''),
                    'failed_at' => isset($row->failed_at) ? (string) $row->failed_at : null,
                ])
                ->values()
                ->all();

            $trend = [];
            if (Schema::hasColumn('failed_jobs', 'failed_at')) {
                $trend = DB::table('failed_jobs')
                    ->whereBetween('failed_at', [$from, $to])
                    ->selectRaw('DATE(failed_at) AS day, COUNT(*) AS aggregate')
                    ->groupBy('day')
                    ->orderBy('day')
                    ->get()
                    ->map(static fn (object $row): array => [
                        'date' => (string) $row->day,
                        'count' => (int) $row->aggregate,
                    ])
                    ->all();
            }

            return ['available' => true, 'count' => (int) $count, 'rows' => $rows, 'trend' => $trend];
        } catch (Throwable) {
            return ['available' => false, 'count' => 0, 'rows' => [], 'trend' => []];
        }
    }

    /** @return array{state:string,last_run:?string,age_seconds:?int} */
    private function schedulerHealth(): array
    {
        if ((bool) config('foodex.scheduler_heartbeat', true) === false) {
            return ['state' => 'degraded', 'last_run' => null, 'age_seconds' => null];
        }

        $path = storage_path('app/system/scheduler-heartbeat.lock');
        if (! is_file($path)) {
            return ['state' => app()->environment('testing') ? 'healthy' : 'degraded', 'last_run' => null, 'age_seconds' => null];
        }

        $timestamp = (int) trim((string) @file_get_contents($path));
        if ($timestamp <= 0) {
            return ['state' => 'degraded', 'last_run' => null, 'age_seconds' => null];
        }

        $age = max(0, time() - $timestamp);

        return [
            'state' => $age <= 180 ? 'healthy' : 'degraded',
            'last_run' => CarbonImmutable::createFromTimestampUTC($timestamp)->toIso8601String(),
            'age_seconds' => $age,
        ];
    }

    private function pendingMigrationCount(): ?int
    {
        try {
            if (! Schema::hasTable('migrations')) {
                return null;
            }

            $ran = DB::table('migrations')->pluck('migration')->map(static fn (mixed $value): string => (string) $value)->all();
            $files = glob(database_path('migrations/*.php')) ?: [];
            $available = array_map(
                static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
                $files,
            );

            return count(array_diff($available, $ran));
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function safeSnapshotStatus(): array
    {
        try {
            return $this->snapshot->status();
        } catch (Throwable) {
            return ['state' => 'failed', 'verified' => false];
        }
    }

    /**
     * @param  Collection<int,UpdateHistory>  $updates
     * @param  array<string,mixed>  $snapshot
     * @return list<array<string,mixed>>
     */
    private function backupRows(Collection $updates, array $snapshot): array
    {
        $rows = [];

        if (is_string($snapshot['created_at'] ?? null) && $snapshot['created_at'] !== '') {
            $rows[] = [
                'type' => __('system_admin.labels.snapshot'),
                'created_at' => $snapshot['created_at'],
                'size' => $this->formatBytes(is_int($snapshot['size'] ?? null) ? $snapshot['size'] : null),
                'reference' => is_string($snapshot['checksum_sha256'] ?? null)
                    ? substr((string) $snapshot['checksum_sha256'], 0, 16)
                    : (string) ($snapshot['snapshot_id'] ?? '—'),
                'status' => ($snapshot['verified'] ?? false) === true
                    ? __('system_admin.states.verified')
                    : __('system_admin.states.not_verified'),
                'successful' => ($snapshot['verified'] ?? false) === true,
                'scope' => __('system_admin.labels.database_scope'),
                'retention' => __('system_admin.retention.snapshot'),
                'reason' => (string) ($snapshot['restore_reason'] ?? $snapshot['snapshot_reason'] ?? 'prelaunch_protection'),
                'origin' => trim(((string) ($snapshot['source_release'] ?? '')).' → '.((string) ($snapshot['target_release'] ?? ''))) ?: __('system_admin.labels.snapshot'),
                'url' => route('admin.production-dataset-switch.index'),
            ];
        }

        foreach ($updates as $update) {
            if ($update->file_backup_reference === null && $update->database_backup_reference === null) {
                continue;
            }

            $rawEvidence = $update->getAttribute('execution_evidence');
            /** @var array<string,mixed> $evidence */
            $evidence = is_array($rawEvidence) ? $rawEvidence : [];
            $rawFileEvidence = $evidence['file_backup'] ?? null;
            /** @var array<string,mixed> $fileEvidence */
            $fileEvidence = is_array($rawFileEvidence) ? $rawFileEvidence : [];
            $rawDatabaseEvidence = $evidence['database_backup'] ?? null;
            /** @var array<string,mixed> $databaseEvidence */
            $databaseEvidence = is_array($rawDatabaseEvidence) ? $rawDatabaseEvidence : [];
            $size = ((int) ($fileEvidence['size_bytes'] ?? 0)) + ((int) ($databaseEvidence['size_bytes'] ?? 0));
            $hash = (string) ($databaseEvidence['sha256'] ?? $fileEvidence['sha256'] ?? $update->package_hash ?? '');

            $rows[] = [
                'type' => __('system_admin.labels.update_backup'),
                'created_at' => optional($update->started_at)->toIso8601String() ?? optional($update->created_at)->toIso8601String(),
                'size' => $this->formatBytes($size > 0 ? $size : null),
                'reference' => $hash !== '' ? substr($hash, 0, 16) : '#'.$update->id,
                'status' => __('system_admin.evidence.'.($update->backup_status ?: 'unknown')),
                'successful' => $update->backup_status === 'available',
                'scope' => $update->database_backup_reference !== null
                    ? __('system_admin.labels.files_database_scope')
                    : __('system_admin.labels.files_scope'),
                'retention' => __('system_admin.retention.update'),
                'reason' => $update->operation_reason ?: 'system_update',
                'origin' => ($update->from_version ?: '—').' → '.$update->to_version,
                'url' => route('admin.system-update.index', ['page' => 1]),
            ];
        }

        usort($rows, static fn (array $left, array $right): int => strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? '')));

        return array_slice($rows, 0, 24);
    }

    /** @return list<string> */
    private function configurationWarnings(
        array $database,
        array $cache,
        array $queue,
        array $storage,
        array $scheduler,
    ): array {
        $warnings = [];

        if (app()->environment('production') && (bool) config('app.debug')) {
            $warnings[] = __('system_admin.messages.debug_production');
        }
        if (trim((string) config('app.key')) === '') {
            $warnings[] = __('system_admin.messages.missing_app_key');
        }
        if (($storage['state'] ?? null) !== 'writable') {
            $warnings[] = __('system_admin.messages.storage_unwritable');
        }
        if ((bool) config('foodex.scheduler_heartbeat', true) === false) {
            $warnings[] = __('system_admin.messages.scheduler_disabled');
        } elseif (($scheduler['state'] ?? null) !== 'healthy') {
            $warnings[] = __('system_admin.messages.scheduler_stale');
        }
        if (app()->environment('production') && (string) config('queue.default') === 'sync') {
            $warnings[] = __('system_admin.messages.queue_sync_production');
        }
        if (($database['state'] ?? null) === 'error') {
            $warnings[] = __('system_admin.messages.database_unavailable');
        }
        if (($cache['state'] ?? null) === 'error') {
            $warnings[] = __('system_admin.messages.cache_unavailable');
        }
        if (($queue['state'] ?? null) === 'error') {
            $warnings[] = __('system_admin.messages.queue_unavailable');
        }

        return $warnings;
    }

    /** @param list<array{health:string,lifecycle:string,stale_count:int}> $modules */
    private function applicationState(
        array $modules,
        array $database,
        array $cache,
        array $storage,
    ): string {
        $moduleRows = collect($modules);

        if (($database['state'] ?? null) === 'error'
            || ($cache['state'] ?? null) === 'error'
            || ($storage['state'] ?? null) !== 'writable'
            || $moduleRows->contains(static fn (array $module): bool => in_array($module['health'], ['ERROR'], true) || $module['lifecycle'] === 'ERROR')) {
            return 'error';
        }

        if ($moduleRows->contains(static fn (array $module): bool => $module['health'] === 'DEGRADED' || $module['stale_count'] > 0)) {
            return 'degraded';
        }

        return 'healthy';
    }

    /** @return list<array{date:string,total:int,errors:int}> */
    private function incidentTrend(CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! Schema::hasTable('system_inspector_events')) {
            return [];
        }

        try {
            return SystemInspectorEvent::query()
                ->whereBetween('occurred_at', [$from, $to])
                ->selectRaw("DATE(occurred_at) AS day, COUNT(*) AS aggregate, SUM(CASE WHEN severity = 'error' THEN 1 ELSE 0 END) AS errors")
                ->groupBy('day')
                ->orderBy('day')
                ->get()
                ->map(static fn (SystemInspectorEvent $row): array => [
                    'date' => (string) $row->getAttribute('day'),
                    'total' => (int) $row->getAttribute('aggregate'),
                    'errors' => (int) $row->getAttribute('errors'),
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array{date:string,success:int,failed:int}> */
    private function updateTrend(CarbonImmutable $from, CarbonImmutable $to): array
    {
        try {
            return UpdateHistory::query()
                ->whereBetween('created_at', [$from, $to])
                ->get(['status', 'created_at'])
                ->groupBy(static fn (UpdateHistory $row): string => $row->created_at?->toDateString() ?? '')
                ->filter(static fn (Collection $rows, string $day): bool => $day !== '')
                ->map(static fn (Collection $rows, string $day): array => [
                    'date' => $day,
                    'success' => $rows->where('status', 'success')->count(),
                    'failed' => $rows->where('status', 'failed')->count(),
                ])
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function currentVersion(): string
    {
        $version = SystemVersion::query()
            ->orderByDesc('installed_at')
            ->orderByDesc('id')
            ->value('version');

        if (is_string($version) && $version !== '') {
            return $version;
        }

        $file = trim((string) @file_get_contents(base_path('../VERSION')));

        return $file !== '' ? $file : '—';
    }

    /** @return array{state:string,label:string} */
    private function kpiStatus(string $state): array
    {
        return match ($state) {
            'healthy' => ['state' => 'success', 'label' => __('system_admin.states.healthy')],
            'degraded' => ['state' => 'warning', 'label' => __('system_admin.states.degraded')],
            'error' => ['state' => 'danger', 'label' => __('system_admin.states.error')],
            default => ['state' => 'current', 'label' => __('system_admin.states.unknown')],
        };
    }

    private function formatBytes(?int $bytes): string
    {
        if ($bytes === null || $bytes < 0) {
            return '—';
        }

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'TB') {
                return number_format($value, $value >= 10 ? 0 : 1).' '.$unit;
            }
            $value /= 1024;
        }

        return '—';
    }
}
