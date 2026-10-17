<?php

namespace App\Http\Controllers\Admin;

use App\Domain\RestaurantSaaS\RestaurantOperatorService;
use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\SystemInspectorEvent;
use App\Models\User;
use App\Services\EnterpriseSystemInspector;
use App\Services\SystemAdministrationRuntime;
use App\Services\SystemInspectorRecorder;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class SystemInspectorController extends Controller
{
    public function index(
        Request $request,
        EnterpriseSystemInspector $enterpriseInspector,
        RestaurantOperatorService $restaurantOperator,
        SystemAdministrationRuntime $systemRuntime,
    ): View {
        $actor = $this->platformAdmin($request);
        $source = trim((string) $request->query('source', ''));
        $severity = trim((string) $request->query('severity', ''));
        $search = trim((string) $request->query('q', ''));
        $appVersion = trim((string) $request->query('app_version', ''));
        $appBuild = trim((string) $request->query('app_build', ''));
        $channel = trim((string) $request->query('channel', ''));
        $storeId = $request->integer('store_id');
        $restaurantStoreId = $request->integer('restaurant_store_id');
        $restaurantStore = $restaurantStoreId > 0
            ? $restaurantOperator->store($restaurantStoreId)
            : null;
        abort_if($restaurantStoreId > 0 && $restaurantStore === null, 404);

        $query = SystemInspectorEvent::query()
            ->with(['user:id,name,email', 'store:id,name,code'])
            ->when($source !== '', fn ($builder) => $builder->where('source', $source))
            ->when($severity !== '', fn ($builder) => $builder->where('severity', $severity))
            ->when($appVersion !== '', fn ($builder) => $builder->where('context->app_version', $appVersion))
            ->when($appBuild !== '', fn ($builder) => $builder->where('context->app_build', $appBuild))
            ->when(in_array($channel, ['b2b', 'b2c'], true), fn ($builder) => $builder->where('context->channel', $channel))
            ->when($storeId > 0, fn ($builder) => $builder->where('store_id', $storeId))
            ->when($search !== '', fn ($builder) => $builder->where(function ($builder) use ($search): void {
                $builder->where('message', 'like', "%{$search}%")
                    ->orWhere('route_name', 'like', "%{$search}%")
                    ->orWhere('url', 'like', "%{$search}%")
                    ->orWhere('correlation_id', 'like', "%{$search}%");
            }))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        $since = now()->subDay();
        $trendStart = now()->startOfDay()->subDays(6);
        $incidentCounts = SystemInspectorEvent::query()
            ->where('occurred_at', '>=', $trendStart)
            ->selectRaw('DATE(occurred_at) as day, COUNT(*) as aggregate')
            ->groupByRaw('DATE(occurred_at)')
            ->pluck('aggregate', 'day')
            ->map(static fn ($count): int => (int) $count);

        $incidentTrend = collect(range(6, 0))
            ->map(function (int $daysAgo) use ($incidentCounts): array {
                $date = now()->startOfDay()->subDays($daysAgo);
                $key = $date->toDateString();

                return [
                    'day' => $key,
                    'label' => $date->format('m-d'),
                    'count' => (int) ($incidentCounts[$key] ?? 0),
                ];
            })
            ->all();

        $runtime = $systemRuntime->healthSnapshot();
        $runtimeCode = (string) ($runtime['state'] ?? 'unknown');
        $runtimeStateLabel = match ($runtimeCode) {
            'healthy' => __('admin.system_update_surface.healthy'),
            'warning' => __('admin.system_update_surface.warning'),
            'error' => __('admin.system_update_surface.error'),
            default => __('admin.system_update_surface.unknown'),
        };
        $runtimeBadgeState = match ($runtimeCode) {
            'healthy' => 'success',
            'warning' => 'warning',
            default => 'error',
        };
        $runtimeMetrics = [
            [
                'label' => __('admin.system_update_surface.application_health'),
                'value' => $runtimeStateLabel,
            ],
            [
                'label' => __('admin.system_update_surface.database_health'),
                'value' => match ((string) ($runtime['database']['state'] ?? 'unknown')) {
                    'healthy' => __('admin.system_update_surface.healthy'),
                    'error' => __('admin.system_update_surface.error'),
                    default => __('admin.system_update_surface.unknown'),
                },
            ],
            [
                'label' => __('admin.system_update_surface.cache_health'),
                'value' => match ((string) ($runtime['cache']['state'] ?? 'unknown')) {
                    'healthy' => __('admin.system_update_surface.healthy'),
                    'error' => __('admin.system_update_surface.error'),
                    default => __('admin.system_update_surface.unknown'),
                },
            ],
            [
                'label' => __('admin.system_update_surface.failed_jobs'),
                'value' => $runtime['queue']['failed_jobs'] ?? '—',
            ],
            [
                'label' => __('admin.system_update_surface.scheduler_health'),
                'value' => match ((string) ($runtime['scheduler']['state'] ?? 'unknown')) {
                    'healthy' => __('admin.system_update_surface.healthy'),
                    'warning' => __('admin.system_update_surface.warning'),
                    'disabled' => __('admin.system_update_surface.disabled'),
                    default => __('admin.system_update_surface.unknown'),
                },
            ],
            [
                'label' => __('admin.system_update_surface.pending_migrations'),
                'value' => $runtime['pending_migrations'] ?? '—',
            ],
            [
                'label' => __('admin.system_update_surface.storage_health'),
                'value' => match ((string) ($runtime['storage']['state'] ?? 'unknown')) {
                    'healthy' => __('admin.system_update_surface.healthy'),
                    'error' => __('admin.system_update_surface.error'),
                    default => __('admin.system_update_surface.unknown'),
                },
            ],
            [
                'label' => __('admin.system_update_surface.config_warnings'),
                'value' => count($runtime['configuration_warnings'] ?? []),
            ],
        ];

        return view('admin.system-inspector', [
            'user' => $actor,
            'navGroups' => app(AdminNavigation::class)->groupsFor($actor),
            'navContext' => 'system_inspector',
            'events' => $query->paginate(100)->withQueryString(),
            'source' => $source,
            'severity' => $severity,
            'search' => $search,
            'appVersion' => $appVersion,
            'appBuild' => $appBuild,
            'channel' => $channel,
            'storeId' => $storeId,
            'stores' => Store::query()->select(['id', 'name', 'code'])->orderBy('name')->orderBy('id')->get(),
            'restaurantStores' => $restaurantOperator->stores(),
            'restaurantStoreId' => $restaurantStoreId,
            'restaurantOperator' => $restaurantStore instanceof Store
                ? $restaurantOperator->summary($restaurantStore)
                : null,
            'stats' => [
                'total' => SystemInspectorEvent::query()->count(),
                'errors_24h' => SystemInspectorEvent::query()->where('occurred_at', '>=', $since)->where('severity', 'error')->count(),
                'javascript_24h' => SystemInspectorEvent::query()->where('occurred_at', '>=', $since)->whereIn('source', ['javascript', 'fetch'])->count(),
                'mobile_24h' => SystemInspectorEvent::query()->where('occurred_at', '>=', $since)->whereIn('source', ['customer_app', 'driver_app', 'van_app'])->count(),
                'routes_24h' => SystemInspectorEvent::query()->where('occurred_at', '>=', $since)->where('source', 'route')->count(),
            ],
            'diagnostics' => $this->diagnostics(),
            'runtime' => $runtime,
            'runtimeStateLabel' => $runtimeStateLabel,
            'runtimeBadgeState' => $runtimeBadgeState,
            'runtimeMetrics' => $runtimeMetrics,
            'incidentTrend' => $incidentTrend,
            'enterprise' => $this->enterpriseDiagnostics($enterpriseInspector),
        ]);
    }

    public function grantRestaurantSupport(
        Request $request,
        Store $store,
        RestaurantOperatorService $operator,
    ): RedirectResponse {
        $actor = $this->platformAdmin($request);
        $data = $request->validate([
            'case_reference' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\\/-]{3,120}$/'],
            'reason_code' => ['required', Rule::in(RestaurantOperatorService::SUPPORT_REASON_CODES)],
            'ttl_minutes' => ['required', 'integer', Rule::in(RestaurantOperatorService::SUPPORT_TTL_MINUTES)],
        ]);

        $operator->grantSupportAccess(
            $store,
            $actor,
            (string) $data['case_reference'],
            (string) $data['reason_code'],
            (int) $data['ttl_minutes'],
            $request,
        );

        return redirect()
            ->route('admin.inspector.index', ['restaurant_store_id' => (int) $store->getKey()])
            ->with('status', __('restaurant_operator.flash.support_granted'));
    }

    public function revokeRestaurantSupport(
        Request $request,
        Store $store,
        RestaurantOperatorService $operator,
    ): RedirectResponse {
        $actor = $this->platformAdmin($request);
        $operator->revokeSupportAccess($store, $actor, $request);

        return redirect()
            ->route('admin.inspector.index', ['restaurant_store_id' => (int) $store->getKey()])
            ->with('status', __('restaurant_operator.flash.support_revoked'));
    }

    public function revokeRestaurantSupportSessions(
        Request $request,
        Store $store,
        RestaurantOperatorService $operator,
    ): RedirectResponse {
        $actor = $this->platformAdmin($request);
        $operator->revokeSupportSessions($store, $actor, $request);

        return redirect()
            ->route('admin.inspector.index', ['restaurant_store_id' => (int) $store->getKey()])
            ->with('status', __('restaurant_operator.flash.sessions_revoked'));
    }

    public function transitionRestaurantLifecycle(
        Request $request,
        Store $store,
        RestaurantOperatorService $operator,
    ): RedirectResponse {
        $actor = $this->platformAdmin($request);
        $data = $request->validate([
            'target_state' => ['required', Rule::in(['CANCELED', 'OFFBOARDED'])],
        ]);

        $operator->transitionLifecycle(
            $store,
            $actor,
            (string) $data['target_state'],
            $request,
        );

        return redirect()
            ->route('admin.inspector.index', ['restaurant_store_id' => (int) $store->getKey()])
            ->with('status', __('restaurant_operator.flash.lifecycle_updated'));
    }

    public function clientEvent(Request $request, SystemInspectorRecorder $recorder): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $data = $request->validate([
            'source' => ['required', Rule::in(['javascript', 'fetch'])],
            'severity' => ['nullable', Rule::in(['warning', 'error'])],
            'category' => ['nullable', Rule::in([
                'server_failure',
                'network_failure',
                'timeout',
                'intentional_abort',
                'validation_rejection',
                'domain_rejection',
                'authorization_rejection',
                'maintenance',
                'external_service_failure',
                'http_rejection',
            ])],
            'message' => ['required', 'string', 'max:2000'],
            'url' => ['nullable', 'string', 'max:4096'],
            'response_url' => ['nullable', 'string', 'max:4096'],
            'method' => ['nullable', 'string', 'max:12'],
            'status' => ['nullable', 'integer', 'between:400,599'],
            'filename' => ['nullable', 'string', 'max:2048'],
            'line' => ['nullable', 'integer', 'min:0'],
            'column' => ['nullable', 'integer', 'min:0'],
            'stack' => ['nullable', 'string', 'max:10000'],
        ]);

        $recorder->recordClient($data, $request);

        return response()->json([], 202);
    }

    public function export(
        Request $request,
        EnterpriseSystemInspector $enterpriseInspector,
        SystemAdministrationRuntime $systemRuntime,
    ): StreamedResponse {
        $actor = $this->platformAdmin($request);
        $events = SystemInspectorEvent::query()
            ->with(['user:id,name,email', 'store:id,name,code'])
            ->orderByDesc('occurred_at')
            ->limit(1500)
            ->get();

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'generated_by' => ['id' => $actor->id, 'name' => $actor->name],
            'diagnostics' => $this->diagnostics(),
            'runtime' => $systemRuntime->healthSnapshot(),
            'recovery' => [
                'backups' => $systemRuntime->backups(100),
                'operations' => $systemRuntime->recentOperations(100),
            ],
            'enterprise' => $this->enterpriseDiagnostics($enterpriseInspector, snapshot: true),
            'events' => $events->map(static fn (SystemInspectorEvent $event): array => [
                'id' => $event->id,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'source' => $event->source,
                'severity' => $event->severity,
                'status_code' => $event->status_code,
                'method' => $event->method,
                'route_name' => $event->route_name,
                'url' => $event->url,
                'message' => $event->message,
                'exception_class' => $event->exception_class,
                'correlation_id' => $event->correlation_id,
                'store' => $event->store ? ['id' => $event->store->id, 'code' => $event->store->code, 'name' => $event->store->name] : null,
                'user' => $event->user ? ['id' => $event->user->id, 'name' => $event->user->name, 'email' => $event->user->email] : null,
                'context' => $event->context,
            ])->all(),
        ];

        $filename = 'foodex-system-inspector-'.now()->format('Ymd-His').'.json';

        return response()->streamDownload(
            static function () use ($payload): void {
                echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            },
            $filename,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    public function repairStorageLink(Request $request): RedirectResponse
    {
        $this->platformAdmin($request);

        $root = (string) config('filesystems.disks.public.root');
        if (is_dir($root) === false) {
            Storage::disk('public')->makeDirectory('.');
        }

        if (is_dir($root) === false) {
            return back()->withErrors(['storage' => $this->msg(
                'تعذر إنشاء مجلد التخزين العام.',
                'The public storage directory could not be created.',
            )]);
        }

        Artisan::call('storage:link');

        $diagnostics = $this->diagnostics();
        if ($diagnostics['public_link_exists'] === false) {
            return back()->withErrors(['storage' => $this->msg(
                'تعذر إنشاء رابط public/storage. راجع صلاحيات الاستضافة.',
                'public/storage could not be linked. Check hosting permissions.',
            )]);
        }

        return back()->with('status', $this->msg(
            'تم التحقق من رابط الصور العام وإصلاحه.',
            'The public image storage link was verified and repaired.',
        ));
    }

    /**
     * @return array{available:bool,migration_pending:bool,missing_tables?:list<string>,data?:array<string,mixed>}
     */
    private function enterpriseDiagnostics(
        EnterpriseSystemInspector $inspector,
        bool $snapshot = false,
    ): array {
        try {
            $requiredTables = [
                'enterprise_modules',
                'enterprise_module_entitlements',
                'enterprise_outbox_messages',
                'enterprise_workflow_requests',
                'enterprise_workflow_steps',
                'enterprise_organizations',
                'enterprise_cashboxes',
                'restaurant_location_profiles',
            ];
            $missingTables = [];

            foreach ($requiredTables as $table) {
                if (! Schema::hasTable($table)) {
                    $missingTables[] = $table;
                }
            }

            if ($missingTables !== []) {
                return [
                    'available' => false,
                    'migration_pending' => true,
                    'missing_tables' => $missingTables,
                ];
            }

            $data = $snapshot ? $inspector->snapshot() : $inspector->page();

            return array_merge([
                'available' => true,
                'migration_pending' => false,
                'missing_tables' => [],
            ], $data);
        } catch (Throwable) {
            return [
                'available' => false,
                'migration_pending' => false,
            ];
        }
    }

    /** @return array<string,mixed> */
    private function diagnostics(): array
    {
        $publicRoot = (string) config('filesystems.disks.public.root');
        $publicLink = public_path('storage');
        $versionPath = base_path('../VERSION');
        $version = is_readable($versionPath)
            ? trim((string) file_get_contents($versionPath))
            : '';

        return [
            'version' => $version !== '' ? $version : 'unknown',
            'app_url' => (string) config('app.url'),
            'environment' => (string) app()->environment(),
            'public_disk_root' => $publicRoot,
            'public_disk_writable' => is_dir($publicRoot) && is_writable($publicRoot),
            'public_link_path' => $publicLink,
            'public_link_exists' => is_link($publicLink) || is_dir($publicLink),
            'public_link_target' => is_link($publicLink) ? (readlink($publicLink) ?: null) : null,
            'public_disk_driver' => (string) config('filesystems.disks.public.driver'),
            'public_disk_url' => (string) config('filesystems.disks.public.url'),
            'public_disk_available' => Storage::disk('public')->exists('.') || is_dir($publicRoot),
            'named_routes' => collect(Route::getRoutes()->getRoutes())->filter(static fn ($route): bool => $route->getName() !== null)->count(),
        ];
    }

    private function platformAdmin(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('platform.manage');

        return $actor;
    }

    private function msg(string $ar, string $en): string
    {
        return app()->getLocale() === 'ar' ? $ar : $en;
    }
}
