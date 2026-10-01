<?php

namespace App\Services;

use App\Exceptions\SelfStorePurchaseNotAllowed;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RetailMerchantIdentityService
{
    /**
     * Resolve the authoritative Retail Merchant identity for the same User
     * account that authenticates to the Management Dashboard.
     *
     * @return array{
     *   retail_merchant:bool,
     *   b2b_customer_ids:list<int>,
     *   owned_retail_store_ids:list<int>,
     *   managed_retail_store_ids:list<int>,
     *   retail_store_ids:list<int>,
     *   retail_wholesale_accounts:list<array{retail_store_id:int,b2b_customer_id:int}>
     * }
     */
    public function identityPayload(User $user): array
    {
        $ownedStoreIds = $this->ownedRetailStoreIds($user);
        $managedStoreIds = $this->managedRetailStoreIds($user);
        $retailStoreIds = $this->mergeIds($ownedStoreIds, $managedStoreIds);
        $wholesaleIdentities = $this->wholesaleIdentitiesForStoreIds($retailStoreIds);

        $directB2bCustomerIds = DB::table('b2b_customers')
            ->where('user_id', $user->getKey())
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $linkedB2bCustomerIds = array_map(
            static fn (array $identity): int => $identity['b2b_customer_id'],
            $wholesaleIdentities,
        );

        return [
            'retail_merchant' => $retailStoreIds !== [],
            'b2b_customer_ids' => $this->mergeIds($directB2bCustomerIds, $linkedB2bCustomerIds),
            'owned_retail_store_ids' => $ownedStoreIds,
            'managed_retail_store_ids' => $managedStoreIds,
            'retail_store_ids' => $retailStoreIds,
            'retail_wholesale_accounts' => $wholesaleIdentities,
        ];
    }

    /** @return list<int> */
    public function ownedRetailStoreIds(User $user): array
    {
        return DB::table('retail_wholesale_accounts')
            ->join('stores', 'stores.id', '=', 'retail_wholesale_accounts.retail_store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('retail_wholesale_accounts.owner_user_id', $user->getKey())
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->orderBy('stores.id')
            ->pluck('stores.id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

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
            ->orderBy('stores.id')
            ->pluck('stores.id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<int> */
    public function retailStoreIds(User $user): array
    {
        return $this->mergeIds(
            $this->ownedRetailStoreIds($user),
            $this->managedRetailStoreIds($user),
        );
    }

    /** @return list<int> */
    public function wholesaleEntitledRetailStoreIds(User $user): array
    {
        $storeIds = $this->retailStoreIds($user);
        if ($storeIds === []) {
            return [];
        }

        return DB::table('retail_wholesale_accounts')
            ->join('stores', 'stores.id', '=', 'retail_wholesale_accounts.retail_store_id')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->join('b2b_accounts', 'b2b_accounts.b2b_customer_id', '=', 'retail_wholesale_accounts.b2b_customer_id')
            ->whereIn('retail_wholesale_accounts.retail_store_id', $storeIds)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->where('b2b_accounts.status', 'active')
            ->whereNotNull('b2b_accounts.price_tier_id')
            ->orderBy('retail_wholesale_accounts.retail_store_id')
            ->pluck('retail_wholesale_accounts.retail_store_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<array{retail_store_id:int,b2b_customer_id:int}>
     */
    public function wholesaleIdentities(User $user): array
    {
        return $this->wholesaleIdentitiesForStoreIds($this->retailStoreIds($user));
    }

    public function isRetailMerchant(User $user): bool
    {
        return $this->retailStoreIds($user) !== [];
    }

    public function assertCanPurchaseFromRetailStore(User $user, int $storeId): void
    {
        if (in_array($storeId, $this->retailStoreIds($user), true)) {
            throw new SelfStorePurchaseNotAllowed($storeId);
        }
    }

    /**
     * @param  list<int>  $storeIds
     * @return list<array{retail_store_id:int,b2b_customer_id:int}>
     */
    private function wholesaleIdentitiesForStoreIds(array $storeIds): array
    {
        if ($storeIds === []) {
            return [];
        }

        return DB::table('retail_wholesale_accounts')
            ->whereIn('retail_store_id', $storeIds)
            ->orderBy('retail_store_id')
            ->get(['retail_store_id', 'b2b_customer_id'])
            ->map(static fn (object $link): array => [
                'retail_store_id' => (int) $link->retail_store_id,
                'b2b_customer_id' => (int) $link->b2b_customer_id,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  ...$groups
     * @return list<int>
     */
    private function mergeIds(array ...$groups): array
    {
        $ids = [];

        foreach ($groups as $group) {
            foreach ($group as $id) {
                $ids[(int) $id] = (int) $id;
            }
        }

        ksort($ids);

        return array_values($ids);
    }
}
