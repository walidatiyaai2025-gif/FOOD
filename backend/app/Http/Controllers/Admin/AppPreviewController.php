<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppPreviewSession;
use App\Models\Store;
use App\Models\User;
use App\Services\AppPreviewSessionService;
use App\Services\AppPreviewTargetService;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use App\Support\TenantContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class AppPreviewController extends Controller
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly TenantContextResolver $tenantResolver,
        private readonly WholesalePrincipal $wholesalePrincipal,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $retailStores = Store::query()
            ->whereIn('id', $this->tenantResolver->retailStoreIds($user))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->filter(fn (Store $store): bool => $user->hasPermission('app_preview.view', (int) $store->getKey()))
            ->values();

        $wholesaleAvailable = $this->navigation->canAccess($user, 'b2b')
            && $user->hasPermission('app_preview.view');
        $retailAvailable = $retailStores->isNotEmpty();

        abort_unless($wholesaleAvailable || $retailAvailable, 403);

        $runtimeConfig = (array) config('app_preview.runtimes', []);
        $wholesaleStoreId = $wholesaleAvailable ? $this->wholesalePrincipal->storeId() : null;
        $manageableRetailDriverStoreIds = $retailStores
            ->filter(fn (Store $store): bool => $user->hasPermission('drivers.b2c.manage', (int) $store->getKey()))
            ->map(fn (Store $store): int => (int) $store->getKey())
            ->values()
            ->all();

        return view('admin.app-preview', [
            'user' => $user,
            'navGroups' => $this->navigation->groupsFor($user),
            'navContext' => 'app_preview',
            'retailStores' => $retailStores,
            'wholesaleAvailable' => $wholesaleAvailable,
            'wholesaleStoreId' => $wholesaleStoreId,
            'retailAvailable' => $retailAvailable,
            'supportAccessRequired' => $user->hasRole('SUPER_ADMIN'),
            'canImpersonateCustomer' => $this->canImpersonate($user, 'customer', $retailStores->pluck('id')->all()),
            'canImpersonateDriver' => $this->canImpersonate($user, 'driver', $retailStores->pluck('id')->all()),
            'canManageWholesaleDrivers' => $wholesaleStoreId !== null
                && $user->hasPermission('drivers.b2b.manage', $wholesaleStoreId),
            'manageableRetailDriverStoreIds' => $manageableRetailDriverStoreIds,
            'runtimeConfig' => [
                'customer' => $this->runtime((array) ($runtimeConfig['customer'] ?? [])),
                'driver' => $this->runtime((array) ($runtimeConfig['driver'] ?? [])),
                'van' => $this->runtime((array) ($runtimeConfig['van'] ?? [])),
            ],
            'deviceProfiles' => (array) config('app_preview.device_profiles', []),
            'platformVersion' => trim((string) @file_get_contents(base_path('../VERSION'))),
        ]);
    }

    public function targets(Request $request, AppPreviewTargetService $targets): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $data = $request->validate([
            'target_type' => ['required', Rule::in(['customer', 'driver'])],
            'channel' => ['required', Rule::in(['b2b', 'b2c'])],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'support_access' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:80'],
        ]);

        return response()->json([
            'data' => $targets->discover(
                $user,
                (string) $data['target_type'],
                (string) $data['channel'],
                isset($data['store_id']) ? (int) $data['store_id'] : null,
                (bool) ($data['support_access'] ?? false),
                $request,
                isset($data['q']) ? (string) $data['q'] : null,
            ),
        ]);
    }

    public function storeSession(Request $request, AppPreviewSessionService $sessions): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $data = $request->validate([
            'target_user_id' => ['required', 'integer', 'exists:users,id'],
            'target_type' => ['required', Rule::in(['customer', 'driver'])],
            'channel' => ['required', Rule::in(['b2b', 'b2c'])],
            'store_id' => ['nullable', 'integer', 'min:1'],
            'support_access' => ['nullable', 'boolean'],
            'ttl_minutes' => ['nullable', 'integer', 'min:5', 'max:30'],
        ]);

        $created = $sessions->create($user, $data, $request);

        return response()->json([
            'data' => $sessions->context($created['session']),
            'credential' => $created['token'],
            'credential_type' => 'Preview',
        ], 201);
    }

    public function destroySession(
        Request $request,
        string $sessionId,
        AppPreviewSessionService $sessions,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $session = AppPreviewSession::query()
            ->where('public_id', $sessionId)
            ->firstOrFail();
        $reason = $request->input('reason');

        $sessions->revoke(
            $user,
            $session,
            $request,
            is_string($reason) ? $reason : null,
        );

        return response()->noContent();
    }

    /**
     * @param  list<int|string>  $retailStoreIds
     */
    private function canImpersonate(User $user, string $targetType, array $retailStoreIds): bool
    {
        $permission = "app_preview.impersonate_{$targetType}";

        if ($user->hasPermission($permission)) {
            return true;
        }

        foreach ($retailStoreIds as $storeId) {
            if ($user->hasPermission($permission, (int) $storeId)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $runtime */
    private function runtime(array $runtime): array
    {
        $url = trim((string) ($runtime['url'] ?? ''));
        $contractVersion = trim((string) ($runtime['contract_version'] ?? ''));
        $origin = $this->runtimeOrigin($url);
        $allowedOrigin = $this->runtimeOrigin(
            trim((string) ($runtime['allowed_origin'] ?? '')),
        );
        $available = $url !== ''
            && $origin !== ''
            && $allowedOrigin !== ''
            && hash_equals($allowedOrigin, $origin)
            && $contractVersion !== '';

        return [
            'available' => $available,
            'url' => $url,
            'origin' => $available ? $origin : '',
            'contract_version' => $contractVersion,
        ];
    }

    private function runtimeOrigin(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        $localHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && ! $localHost) {
            return '';
        }

        return $scheme.'://'.$host.($port === null ? '' : ':'.$port);
    }
}
