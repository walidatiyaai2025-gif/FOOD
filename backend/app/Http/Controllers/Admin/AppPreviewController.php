<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminNavigation;
use App\Support\TenantContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class AppPreviewController extends Controller
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly TenantContextResolver $tenantResolver,
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

        return view('admin.app-preview', [
            'user' => $user,
            'navGroups' => $this->navigation->groupsFor($user),
            'navContext' => 'app_preview',
            'retailStores' => $retailStores,
            'wholesaleAvailable' => $wholesaleAvailable,
            'retailAvailable' => $retailAvailable,
            'supportAccessRequired' => $user->hasRole('SUPER_ADMIN'),
            'canImpersonateCustomer' => $this->canImpersonate($user, 'customer', $retailStores->pluck('id')->all()),
            'canImpersonateDriver' => $this->canImpersonate($user, 'driver', $retailStores->pluck('id')->all()),
            'runtimeConfig' => [
                'customer' => $this->runtime((array) ($runtimeConfig['customer'] ?? [])),
                'driver' => $this->runtime((array) ($runtimeConfig['driver'] ?? [])),
            ],
            'deviceProfiles' => (array) config('app_preview.device_profiles', []),
            'platformVersion' => trim((string) @file_get_contents(base_path('../VERSION'))),
        ]);
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

        return [
            'available' => $url !== '',
            'url' => $url,
            'contract_version' => trim((string) ($runtime['contract_version'] ?? '')),
        ];
    }
}
