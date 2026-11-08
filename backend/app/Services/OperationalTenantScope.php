<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OperationalTenantScope
{
    /** @return list<int> */
    public function allowedStoreIds(User $user, string $permission, ?string $channel = null): array
    {
        $channel = $channel === null ? null : strtoupper($channel);

        if ($user->hasRole('SUPER_ADMIN')) {
            return $this->storeIdsForChannels($channel === null ? ['B2B', 'B2C'] : [$channel]);
        }

        $ids = DB::table('user_store_roles')
            ->join('roles', 'roles.id', '=', 'user_store_roles.role_id')
            ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->join('stores', 'stores.id', '=', 'user_store_roles.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('user_store_roles.user_id', $user->getKey())
            ->where('roles.is_active', true)
            ->where('roles.scope', 'store')
            ->where('permissions.code', $permission)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->when($channel !== null, fn ($query) => $query->where('store_types.code', $channel))
            ->pluck('stores.id')
            ->map(static fn ($id): int => (int) $id);

        if ($user->hasPermission($permission)) {
            $globalRoles = $user->roles()
                ->where('roles.is_active', true)
                ->where('roles.scope', 'global')
                ->pluck('roles.code')
                ->all();

            $globalChannels = collect((array) config('admin.channels', []))
                ->filter(function (array $definition, string $code) use ($globalRoles, $channel): bool {
                    if ($channel !== null && strtoupper($code) !== $channel) {
                        return false;
                    }

                    return array_intersect(
                        $globalRoles,
                        array_values((array) ($definition['global_roles'] ?? [])),
                    ) !== [];
                })
                ->keys()
                ->map(static fn (string $code): string => strtoupper($code))
                ->all();

            if ($globalChannels !== []) {
                $ids = $ids->merge($this->storeIdsForChannels($globalChannels));
            }
        }

        return $ids
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function assertStore(
        User $user,
        int $storeId,
        string $permission,
        ?string $expectedChannel = null,
    ): string {
        $channel = $this->storeChannel($storeId);
        if ($expectedChannel !== null) {
            abort_unless($channel === strtolower($expectedChannel), 404);
        }

        abort_unless(
            in_array($storeId, $this->allowedStoreIds($user, $permission, $channel), true),
            404,
        );

        return $channel;
    }

    public function storeChannel(int $storeId): string
    {
        $channel = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->value('store_types.code');

        abort_unless(is_string($channel), 404);

        return strtolower($channel);
    }

    public function assertProductOwnedByStore(int $productId, int $storeId): void
    {
        if (! Product::query()->forStore($storeId)->whereKey($productId)->exists()) {
            throw ValidationException::withMessages([
                'product_id' => ['Product must belong to the same store/catalog as the operational record.'],
            ]);
        }
    }

    /** @param list<string> $channels
     * @return list<int>
     */
    private function storeIdsForChannels(array $channels): array
    {
        return DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.is_active', true)
            ->whereIn('store_types.code', $channels)
            ->pluck('stores.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
