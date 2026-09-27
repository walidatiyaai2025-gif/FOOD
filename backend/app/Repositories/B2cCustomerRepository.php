<?php

namespace App\Repositories;

use App\Models\B2cCustomer;
use App\Models\User;

final class B2cCustomerRepository
{
    public function forUserAndStore(User $user, int $storeId): ?B2cCustomer
    {
        return B2cCustomer::query()
            ->where('user_id', $user->getKey())
            ->where('store_id', $storeId)
            ->first();
    }

    /** @return list<int> */
    public function storeIdsForUser(User $user): array
    {
        return B2cCustomer::query()
            ->where('user_id', $user->getKey())
            ->orderBy('store_id')
            ->pluck('store_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function findForStore(int $id, int $storeId): ?B2cCustomer
    {
        return B2cCustomer::query()
            ->whereKey($id)
            ->where('store_id', $storeId)
            ->first();
    }
}
