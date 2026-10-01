<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RetailMerchantIdentityService
{
    public const SELF_STORE_PURCHASE_NOT_ALLOWED = 'SELF_STORE_PURCHASE_NOT_ALLOWED';

    /** @return list<int> */
    public function managedRetailStoreIds(User $user): array
    {
        return DB::table('user_store_roles')
            ->join('roles', 'roles.id', '=', 'user_store_roles.role_id')
            ->join('stores', 'stores.id', '=', 'user_store_roles.store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('user_store_roles.user_id', $user->getKey())
            ->where('roles.code', 'B2C_STORE_ADMIN')
            ->where('roles.is_active', true)
            ->where('roles.scope', 'store')
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->pluck('stores.id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<array{retail_store_id:int,b2b_customer_id:int}>
     */
    public function wholesaleIdentities(User $user): array
    {
        $storeIds = $this->managedRetailStoreIds($user);
        if ($storeIds === []) {
            return [];
        }

        return DB::table('retail_wholesale_accounts')
            ->whereIn('retail_store_id', $storeIds)
            ->orderBy('retail_store_id')
            ->get(['retail_store_id', 'b2b_customer_id'])
            ->map(static fn (object $row): array => [
                'retail_store_id' => (int) $row->retail_store_id,
                'b2b_customer_id' => (int) $row->b2b_customer_id,
            ])
            ->values()
            ->all();
    }

    public function isRetailMerchant(User $user): bool
    {
        return $this->managedRetailStoreIds($user) !== [];
    }

    public function assertCanPurchaseFromRetailStore(User $user, int $storeId): void
    {
        abort_if(
            in_array($storeId, $this->managedRetailStoreIds($user), true),
            403,
            self::SELF_STORE_PURCHASE_NOT_ALLOWED,
        );
    }

    /**
     * @return array{
     *   retail_merchant:bool,
     *   managed_retail_store_ids:list<int>,
     *   retail_wholesale_accounts:list<array{retail_store_id:int,b2b_customer_id:int}>
     * }
     */
    public function identityPayload(User $user): array
    {
        $managedStoreIds = $this->managedRetailStoreIds($user);

        return [
            'retail_merchant' => $managedStoreIds !== [],
            'managed_retail_store_ids' => $managedStoreIds,
            'retail_wholesale_accounts' => $managedStoreIds === []
                ? []
                : $this->wholesaleIdentities($user),
        ];
    }
}
