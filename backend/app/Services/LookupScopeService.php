<?php

namespace App\Services;

use App\Models\User;
use App\Support\TenantContextResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class LookupScopeService
{
    public const GLOBAL = 'global';

    public const B2B = 'b2b';

    public const STORE = 'store';

    public function __construct(private readonly TenantContextResolver $tenants) {}

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function visible(Builder $query, User $user, string $table): Builder
    {
        if ($user->hasRole('SUPER_ADMIN')) {
            return $query;
        }

        $retailStoreIds = $this->tenants->retailStoreIds($user);
        $canSeeB2b = $user->hasRole('B2B_ADMIN');

        return $query->where(function (Builder $scope) use ($table, $retailStoreIds, $canSeeB2b): void {
            $scope->where($table.'.scope', self::GLOBAL);

            if ($canSeeB2b) {
                $scope->orWhere($table.'.scope', self::B2B);
            }

            if ($retailStoreIds !== []) {
                $scope->orWhere(function (Builder $retail) use ($table, $retailStoreIds): void {
                    $retail
                        ->where($table.'.scope', self::STORE)
                        ->whereIn($table.'.store_id', $retailStoreIds);
                });
            }
        });
    }

    public function authorizeMutation(
        User $user,
        string $scope,
        ?int $storeId,
        bool $supportAccess,
        Request $request,
    ): string {
        if ($scope === self::GLOBAL) {
            abort_unless($user->hasRole('SUPER_ADMIN'), 403);

            return self::GLOBAL;
        }

        if ($scope === self::B2B) {
            $this->tenants->wholesale($user);

            return self::B2B;
        }

        abort_unless($scope === self::STORE && $storeId !== null, 422);
        $this->tenants->retail($user, $storeId, $supportAccess, $request);

        return self::STORE.':'.$storeId;
    }

    public function canManage(User $user, string $scope, ?int $storeId): bool
    {
        if ($user->hasRole('SUPER_ADMIN')) {
            return true;
        }

        if ($scope === self::GLOBAL) {
            return false;
        }

        if ($scope === self::B2B) {
            return $user->hasRole('B2B_ADMIN');
        }

        if ($scope !== self::STORE || $storeId === null) {
            return false;
        }

        return in_array($storeId, $this->tenants->retailStoreIds($user), true);
    }

    /** @return list<string> */
    public function manageableScopes(User $user): array
    {
        if ($user->hasRole('SUPER_ADMIN')) {
            return [self::GLOBAL, self::B2B, self::STORE];
        }

        $scopes = [];
        if ($user->hasRole('B2B_ADMIN')) {
            $scopes[] = self::B2B;
        }
        if ($this->tenants->retailStoreIds($user) !== []) {
            $scopes[] = self::STORE;
        }

        return $scopes;
    }

    public function assertAssignableToStore(string $type, int $lookupId, int $storeId): void
    {
        abort_unless(in_array($type, ['brands', 'units'], true), 404);

        $row = DB::table($type)
            ->where('id', $lookupId)
            ->where('is_active', true)
            ->first(['scope', 'store_id']);
        abort_if($row === null, 404);

        if ((string) $row->scope === self::GLOBAL) {
            return;
        }

        $channel = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->value('store_types.code');
        abort_if($channel === null, 404);

        if ((string) $row->scope === self::B2B) {
            abort_unless($channel === 'B2B', 422, 'Wholesale lookup cannot be assigned to a retail product.');

            return;
        }

        abort_unless(
            (string) $row->scope === self::STORE
                && $channel === 'B2C'
                && (int) $row->store_id === $storeId,
            422,
            'Store-scoped lookup belongs to a different retail store.',
        );
    }

    /** @return Collection<int, object> */
    public function visibleRetailStores(User $user): Collection
    {
        $storeIds = $this->tenants->retailStoreIds($user);

        if ($storeIds === []) {
            return collect();
        }

        return DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->whereIn('stores.id', $storeIds)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->orderBy('stores.name')
            ->get(['stores.id', 'stores.code', 'stores.name']);
    }
}
