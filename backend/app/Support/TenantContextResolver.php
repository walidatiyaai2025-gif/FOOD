<?php

namespace App\Support;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TenantContextResolver
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OrganizationHierarchyService $organizations,
    ) {}

    public function wholesale(User $user, ?int $storeId = null): ResolvedTenantContext
    {
        $allowedRoles = array_values((array) config('admin.channels.b2b.global_roles', []));
        $canAccessWholesale = $user->roles()
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global')
            ->whereIn('roles.code', $allowedRoles)
            ->exists();

        abort_unless($canAccessWholesale, 403);

        if ($storeId !== null) {
            $this->assertStoreChannel($storeId, 'B2B');
        }

        return new ResolvedTenantContext(
            userId: (int) $user->getKey(),
            channel: 'b2b',
            storeId: $storeId,
            supportAccess: $user->hasRole('SUPER_ADMIN'),
            enterpriseScope: $storeId === null ? null : $this->enterpriseScopeForStore($storeId),
        );
    }

    public function retail(
        User $user,
        int $storeId,
        bool $supportAccess = false,
        ?Request $request = null,
    ): ResolvedTenantContext {
        $store = $this->storeForChannel($storeId, 'B2C');

        if ($user->hasRole('SUPER_ADMIN')) {
            abort_unless($supportAccess, 403, 'SUPER_ADMIN retail access must be explicitly entered as support access.');

            $this->audit->record(
                'tenant.support_access.entered',
                $user,
                $store,
                null,
                ['channel' => 'b2c', 'store_id' => $storeId],
                $request,
            );

            return new ResolvedTenantContext(
                userId: (int) $user->getKey(),
                channel: 'b2c',
                storeId: $storeId,
                supportAccess: true,
                enterpriseScope: $this->enterpriseScopeForStore($storeId),
            );
        }

        abort_unless($this->assignedRetailStoresQuery($user)->where('stores.id', $storeId)->exists(), 403);

        return new ResolvedTenantContext(
            userId: (int) $user->getKey(),
            channel: 'b2c',
            storeId: $storeId,
            enterpriseScope: $this->enterpriseScopeForStore($storeId),
        );
    }

    /**
     * @return list<int>
     */
    public function retailStoreIds(User $user): array
    {
        if ($user->hasRole('SUPER_ADMIN')) {
            return $this->storesForChannelQuery('B2C')
                ->pluck('stores.id')
                ->map(static fn ($id): int => (int) $id)
                ->values()
                ->all();
        }

        return $this->assignedRetailStoresQuery($user)
            ->pluck('stores.id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /** @return list<int> */
    public function retailStoreIdsByProfile(User $user, string $profile): array
    {
        abort_unless(in_array($profile, Store::businessProfiles(), true), 404);

        $ids = $this->retailStoreIds($user);
        if ($ids === []) {
            return [];
        }

        return Store::query()
            ->whereIn('id', $ids)
            ->where(function ($query) use ($profile): void {
                $query->where('business_profile', $profile);

                if ($profile === Store::BUSINESS_PROFILE_RETAIL_STORE) {
                    $query->orWhereNull('business_profile');
                }
            })
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public function businessProfileForStore(int $storeId): string
    {
        $store = $this->storeForChannel($storeId, 'B2C');
        $profile = Store::effectiveB2cBusinessProfile($store->business_profile);
        abort_unless($profile !== null, 404);

        return $profile;
    }

    public function assertBusinessProfile(int $storeId, string $profile): Store
    {
        abort_unless(in_array($profile, Store::businessProfiles(), true), 404);
        $store = $this->storeForChannel($storeId, 'B2C');
        abort_unless(Store::effectiveB2cBusinessProfile($store->business_profile) === $profile, 404);

        return $store;
    }

    public function defaultRetailStoreId(User $user): ?int
    {
        $ids = $this->retailStoreIds($user);

        if (count($ids) !== 1) {
            return null;
        }

        return $ids[0];
    }

    public function assertOwnedStore(ResolvedTenantContext $context, int $storeId): void
    {
        if ($context->isRetail()) {
            abort_unless($context->ownsStore($storeId), 404);

            return;
        }

        $this->assertStoreChannel($storeId, 'B2B');
    }

    public function assertStoreChannel(int $storeId, string $channel): void
    {
        $this->storeForChannel($storeId, strtoupper($channel));
    }

    private function enterpriseScopeForStore(int $storeId): ?EnterpriseDataScope
    {
        $scope = $this->organizations->scopeForStore($storeId);

        return $scope === null ? null : EnterpriseDataScope::fromHierarchy($scope);
    }

    private function storeForChannel(int $storeId, string $channel): Store
    {
        $store = Store::query()
            ->select('stores.*')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->where('store_types.code', $channel)
            ->first();

        abort_unless($store instanceof Store, 404);

        return $store;
    }

    private function storesForChannelQuery(string $channel): Builder
    {
        return DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.is_active', true)
            ->where('store_types.code', $channel)
            ->orderBy('stores.id');
    }

    private function assignedRetailStoresQuery(User $user): Builder
    {
        return DB::table('user_store_roles')
            ->join('roles', 'roles.id', '=', 'user_store_roles.role_id')
            ->join('stores', 'stores.id', '=', 'user_store_roles.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('user_store_roles.user_id', $user->getKey())
            ->where('roles.is_active', true)
            ->where('roles.scope', 'store')
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->select('stores.id')
            ->distinct()
            ->orderBy('stores.id');
    }
}
