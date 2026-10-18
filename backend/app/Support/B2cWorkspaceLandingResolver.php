<?php

namespace App\Support;

use App\Enums\StoreBusinessProfile;
use App\Models\User;
use App\Services\StoreBusinessProfileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

final class B2cWorkspaceLandingResolver
{
    private const LAST_STORE_SESSION_KEY = 'b2c_last_store_id';

    public function __construct(
        private readonly TenantContextResolver $tenants,
        private readonly StoreBusinessProfileService $profiles,
        private readonly AdminNavigation $navigation,
    ) {}

    public function urlFor(User $user, ?Request $request = null): ?string
    {
        if (! $user->is_active || $user->hasRole('SUPER_ADMIN')) {
            return null;
        }

        $storeIds = $this->tenants->retailStoreIds($user);
        if ($storeIds === []) {
            return null;
        }

        $storeId = $this->requestedStoreId($request, $storeIds)
            ?? $this->lastStoreId($request, $storeIds)
            ?? $this->primaryStoreId($user, $storeIds);

        if ($storeId === null) {
            return null;
        }

        $this->rememberStore($request, $storeId);

        return $this->destinationForStore($user, $storeId, count($storeIds) > 1);
    }

    public function allowsIntendedUrl(User $user, Request $request, string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        $requiredProfile = $this->profileForPath((string) ($parts['path'] ?? ''));
        if ($requiredProfile === null) {
            return true;
        }

        $storeIds = $this->tenants->retailStoreIds($user);
        if ($storeIds === []) {
            return false;
        }

        $storeId = $this->storeIdFromUrl($parts)
            ?? $this->lastStoreId($request, $storeIds)
            ?? (count($storeIds) === 1 ? $storeIds[0] : null);

        if ($storeId === null || ! in_array($storeId, $storeIds, true)) {
            return false;
        }

        if ($this->profileForStore($storeId) !== $requiredProfile) {
            return false;
        }

        $this->rememberStore($request, $storeId);

        return true;
    }

    /** @param  list<int>  $storeIds */
    private function requestedStoreId(?Request $request, array $storeIds): ?int
    {
        if ($request === null) {
            return null;
        }

        $storeId = $request->integer('store_id') ?: $request->integer('store');

        return $storeId > 0 && in_array($storeId, $storeIds, true) ? $storeId : null;
    }

    /** @param  list<int>  $storeIds */
    private function lastStoreId(?Request $request, array $storeIds): ?int
    {
        if ($request === null || ! $request->hasSession()) {
            return null;
        }

        $storeId = (int) $request->session()->get(self::LAST_STORE_SESSION_KEY, 0);

        return $storeId > 0 && in_array($storeId, $storeIds, true) ? $storeId : null;
    }

    /** @param  list<int>  $storeIds */
    private function primaryStoreId(User $user, array $storeIds): ?int
    {
        $storeId = DB::table('user_store_roles')
            ->where('user_id', $user->getKey())
            ->whereIn('store_id', $storeIds)
            ->orderBy('id')
            ->value('store_id');

        return $storeId !== null ? (int) $storeId : ($storeIds[0] ?? null);
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function storeIdFromUrl(array $parts): ?int
    {
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        foreach (['store_id', 'store'] as $key) {
            $value = $query[$key] ?? null;
            if (is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    private function profileForPath(string $path): ?StoreBusinessProfile
    {
        if ($path === '/admin/smart-ordering' || str_starts_with($path, '/admin/smart-ordering/')) {
            return StoreBusinessProfile::RESTAURANT;
        }

        if ($path === '/admin/b2c' || str_starts_with($path, '/admin/b2c/')) {
            return StoreBusinessProfile::RETAIL_STORE;
        }

        return null;
    }

    private function profileForStore(int $storeId): StoreBusinessProfile
    {
        return $this->profiles->resolve($storeId) ?? StoreBusinessProfile::RETAIL_STORE;
    }

    private function destinationForStore(User $user, int $storeId, bool $multiStore): string
    {
        $profile = $this->profileForStore($storeId);
        $roleAwareUrl = $this->navigation->defaultWorkspaceUrl($user, 'b2c', $storeId);
        $roleAwarePath = (string) (parse_url($roleAwareUrl, PHP_URL_PATH) ?? '');

        if ($this->profileForPath($roleAwarePath) === $profile) {
            return $profile === StoreBusinessProfile::RESTAURANT || $multiStore
                ? $this->withStoreId($roleAwareUrl, $storeId)
                : $roleAwareUrl;
        }

        if ($profile === StoreBusinessProfile::RETAIL_STORE) {
            return route(
                'admin.b2c.dashboard',
                $multiStore ? ['store_id' => $storeId] : [],
            );
        }

        return $this->restaurantUrl($user, $storeId);
    }

    private function restaurantUrl(User $user, int $storeId): string
    {
        $destinations = [
            ['route' => 'admin.smart-ordering.index', 'permission' => 'smart_ordering.view'],
            ['route' => 'admin.smart-ordering.menu.index', 'permission' => 'smart_ordering.manage'],
            ['route' => 'admin.smart-ordering.operations.index', 'permission' => 'orders.view'],
            ['route' => 'admin.smart-ordering.qr.index', 'permission' => 'smart_ordering.qr.view'],
            ['route' => 'admin.smart-ordering.analytics.index', 'permission' => 'smart_ordering.analytics.view'],
        ];

        foreach ($destinations as $destination) {
            if (! Route::has($destination['route'])) {
                continue;
            }

            if ($user->hasPermission($destination['permission'], $storeId)
                || $user->hasPermission($destination['permission'])) {
                return route($destination['route'], ['store_id' => $storeId]);
            }
        }

        return route('admin.smart-ordering.index', ['store_id' => $storeId]);
    }

    private function withStoreId(string $url, int $storeId): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $query['store_id'] = $storeId;

        $baseUrl = explode('?', $url, 2)[0];

        return $baseUrl.'?'.http_build_query($query);
    }

    private function rememberStore(?Request $request, int $storeId): void
    {
        if ($request !== null && $request->hasSession()) {
            $request->session()->put(self::LAST_STORE_SESSION_KEY, $storeId);
        }
    }
}
