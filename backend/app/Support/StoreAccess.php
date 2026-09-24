<?php

namespace App\Support;

use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class StoreAccess
{
    public function hasGlobalStoreAccess(User $user): bool
    {
        return $user->roles()
            ->where('roles.code', 'SUPER_ADMIN')
            ->exists();
    }

    public function canAccess(User $user, Store|int $store): bool
    {
        if ($this->hasGlobalStoreAccess($user)) {
            return true;
        }

        $storeId = $store instanceof Store ? $store->getKey() : $store;

        return $user->storeRoleAssignments()
            ->where('store_id', $storeId)
            ->exists();
    }

    /**
     * @template TModel of Model
     * @param Builder<TModel> $query
     * @return Builder<TModel>
     */
    public function scopeStores(Builder $query, User $user): Builder
    {
        if ($this->hasGlobalStoreAccess($user)) {
            return $query;
        }

        return $query->whereIn(
            $query->getModel()->qualifyColumn('id'),
            $this->assignedStoreIdsQuery($user),
        );
    }

    /**
     * @template TModel of Model
     * @param Builder<TModel> $query
     * @return Builder<TModel>
     */
    public function scopeStoreOwned(Builder $query, User $user, string $storeColumn = 'store_id'): Builder
    {
        if ($this->hasGlobalStoreAccess($user)) {
            return $query;
        }

        return $query->whereIn(
            $query->getModel()->qualifyColumn($storeColumn),
            $this->assignedStoreIdsQuery($user),
        );
    }

    private function assignedStoreIdsQuery(User $user): Builder
    {
        return UserStoreRole::query()
            ->select('store_id')
            ->where('user_id', $user->getKey())
            ->distinct();
    }
}
