<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\SystemInspectorEvent;
use App\Models\User;
use App\Services\SystemInspectorRecorder;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SystemInspectorController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $this->platformAdmin($request);
        $source = trim((string) $request->query('source', ''));
        $severity = trim((string) $request->query('severity', ''));
        $search = trim((string) $request->query('q', ''));
        $appVersion = trim((string) $request->query('app_version', ''));
        $appBuild = trim((string) $request->query('app_build', ''));
        $channel = trim((string) $request->query('channel', ''));
        $storeId = $request->integer('store_id');

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
            'stats' => [
                'total' => SystemInspectorEvent::query()->count(),
                'errors_24h' => SystemInspectorEvent::query()->where('occurred_at', '>=', $since)->where('severity', 'error')->count(),
                'javascript_24h' => SystemInspectorEvent::query()->where('occurred_at', '>=', $since)->whereIn('source', ['javascript', 'fetch'])->count(),
                'mobile_24h' => SystemInspectorEvent::query()->where('occurred_at', '>=', $since)->whereIn('source', ['customer_app', 'driver_app', 'van_app'])->count(),
                'routes_24h' => SystemInspectorEvent::query()->where('occurred_at', '>=', $since)->where('source', 'route')->count(),
            ],
            'diagnostics' => $this->diagnostics(),
        ]);
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

    public function export(Request $request): StreamedResponse
    {
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
        if (! is_dir($root) && ! @mkdir($root, 0750, true) && ! is_dir($root)) {
            return back()->withErrors(['storage' => $this->msg(
                'تعذر إنشاء مجلد التخزين العام.',
                'The public storage directory could not be created.',
            )]);
        }

        Artisan::call('storage:link');

        $diagnostics = $this->diagnostics();
        if (! $diagnostics['public_link_exists']) {
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

    /** @return array<string,mixed> */
    private function diagnostics(): array
    {
        $publicRoot = (string) config('filesystems.disks.public.root');
        $publicLink = public_path('storage');
        $version = trim((string) @file_get_contents(base_path('../VERSION')));

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
