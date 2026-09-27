<?php

namespace App\Support;

use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TenantContextResolver
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function wholesale(User $user, ?int $storeId = null): ResolvedTenantContext
    {
        abort_unless($user->hasRole('SUPER_ADMIN') || $user->hasRole('B2B_ADMIN'), 403);

        if ($storeId !== null) {
            $this->assertStoreChannel($storeId, 'B2B');
        }

        return new ResolvedTenantContext(
            userId: (int) $user->getKey(),
            channel: 'b2b',
            storeId: $storeId,
            supportAccess: $user->hasRole('SUPER_ADMIN'),
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
            );
        }

        abort_unless($this->assignedRetailStoresQuery($user)->where('stores.id', $storeId)->exists(), 403);

        return new ResolvedTenantContext(
            userId: (int) $user->getKey(),
            channel: 'b2c',
            storeId: $storeId,
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
