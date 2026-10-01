<?php

namespace App\Services;

use App\Models\User;

/**
 * Compatibility facade for callers that adopted the first #735 service name.
 *
 * CommerceIdentityResolver is the single authoritative identity/ownership
 * kernel. This class contains no ownership queries or authorization rules.
 */
final class RetailMerchantIdentityService
{
    public function __construct(private readonly CommerceIdentityResolver $commerce) {}

    /** @return list<int> */
    public function managedRetailStoreIds(User $user): array
    {
        return $this->commerce->retailStoreIds($user);
    }

    /**
     * @return list<array{retail_store_id:int,b2b_customer_id:int}>
     */
    public function wholesaleIdentities(User $user): array
    {
        return array_map(
            static fn (array $link): array => [
                'retail_store_id' => $link['store_id'],
                'b2b_customer_id' => $link['b2b_customer_id'],
            ],
            $this->commerce->resolve($user)['retail_store_b2b_customers'],
        );
    }

    public function isRetailMerchant(User $user): bool
    {
        return $this->commerce->resolve($user)['is_retail_merchant'];
    }

    public function assertCanPurchaseFromRetailStore(User $user, int $storeId): void
    {
        $this->commerce->assertCanPurchaseFromRetailStore($user, $storeId);
    }

    /**
     * Canonical fields are returned alongside the short-lived #735 aliases so
     * in-flight downstream lanes can converge without reimplementing identity.
     *
     * @return array<string,mixed>
     */
    public function identityPayload(User $user): array
    {
        $identity = $this->commerce->resolve($user);

        return [
            ...$identity,
            'retail_merchant' => $identity['is_retail_merchant'],
            'retail_wholesale_accounts' => $this->wholesaleIdentities($user),
        ];
    }
}
