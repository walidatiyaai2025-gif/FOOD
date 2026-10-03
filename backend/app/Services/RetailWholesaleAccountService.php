<?php

namespace App\Services;

use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RetailWholesaleAccountService
{
    public function __construct(
        private readonly B2bCustomerService $customers,
        private readonly PlatformCustomerService $platformCustomers,
    ) {}

    public function ensureForStore(Store $store, ?int $priceTierId = null, ?User $owner = null): B2bCustomer
    {
        return DB::transaction(function () use ($store, $priceTierId, $owner): B2bCustomer {
            $locked = Store::query()->whereKey($store->getKey())->lockForUpdate()->firstOrFail();
            $this->assertRetailStore($locked);

            $existingCustomerId = DB::table('retail_wholesale_accounts')
                ->where('retail_store_id', $locked->getKey())
                ->value('b2b_customer_id');

            if ($existingCustomerId !== null) {
                if ($owner instanceof User) {
                    DB::table('retail_wholesale_accounts')
                        ->where('retail_store_id', $locked->getKey())
                        ->update([
                            'owner_user_id' => $owner->getKey(),
                            'updated_at' => now(),
                        ]);
                }

                $customer = B2bCustomer::query()->findOrFail((int) $existingCustomerId);
                $this->syncIdentity($locked, $customer, $priceTierId, $owner);

                if ($owner instanceof User) {
                    $this->platformCustomers->reconcileRetailMerchantIdentity(
                        $owner,
                        $customer,
                        $locked,
                        'dashboard',
                    );
                }

                return $customer->refresh();
            }

            $customer = $this->customers->create([
                'name' => (string) $locked->name,
                'phone' => null,
                'email' => $owner?->email,
            ]);

            B2bAccount::query()->create([
                'customer_id' => $customer->legacy_customer_id,
                'b2b_customer_id' => $customer->getKey(),
                'price_tier_id' => $priceTierId,
                'company_name' => (string) $locked->name,
                'status' => $locked->is_active ? 'active' : 'suspended',
                'tax_number' => null,
                'credit_limit' => 0,
            ]);

            DB::table('retail_wholesale_accounts')->insert([
                'retail_store_id' => $locked->getKey(),
                'b2b_customer_id' => $customer->getKey(),
                'owner_user_id' => $owner?->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->syncIdentity($locked, $customer, $priceTierId, $owner);

            if ($owner instanceof User) {
                $this->platformCustomers->reconcileRetailMerchantIdentity(
                    $owner,
                    $customer,
                    $locked,
                    'dashboard',
                );
            }

            return $customer->refresh();
        }, 3);
    }

    public function syncForStore(Store $store, ?int $priceTierId = null, ?User $owner = null): B2bCustomer
    {
        return $this->ensureForStore($store, $priceTierId, $owner);
    }

    public function retailStoreIdForCustomer(int $b2bCustomerId): ?int
    {
        $storeId = DB::table('retail_wholesale_accounts')
            ->where('b2b_customer_id', $b2bCustomerId)
            ->value('retail_store_id');

        return $storeId === null ? null : (int) $storeId;
    }

    private function syncIdentity(
        Store $store,
        B2bCustomer $customer,
        ?int $priceTierId = null,
        ?User $owner = null,
    ): void {
        $customerUpdates = [
            'name' => (string) $store->name,
            'updated_at' => now(),
        ];

        if ($owner instanceof User) {
            $customerUpdates['email'] = strtolower(trim((string) $owner->email));
        }

        DB::table('b2b_customers')
            ->where('id', $customer->getKey())
            ->update($customerUpdates);

        if ($customer->legacy_customer_id !== null) {
            $legacyUpdates = [
                'name' => (string) $store->name,
                'type' => 'b2b',
                'updated_at' => now(),
            ];

            if ($owner instanceof User) {
                $legacyUpdates['email'] = strtolower(trim((string) $owner->email));
            }

            DB::table('customers')
                ->where('id', $customer->legacy_customer_id)
                ->update($legacyUpdates);
        }

        abort_if(
            $customer->legacy_customer_id === null,
            409,
            'Retail-linked Wholesale customer legacy identity is incomplete.',
        );

        $account = B2bAccount::query()
            ->where('b2b_customer_id', $customer->getKey())
            ->orWhere('customer_id', $customer->legacy_customer_id)
            ->first();

        if (! $account instanceof B2bAccount) {
            B2bAccount::query()->create([
                'customer_id' => $customer->legacy_customer_id,
                'b2b_customer_id' => $customer->getKey(),
                'price_tier_id' => $priceTierId,
                'company_name' => (string) $store->name,
                'status' => $store->is_active ? 'active' : 'suspended',
                'tax_number' => null,
                'credit_limit' => 0,
            ]);

            return;
        }

        $updates = [
            'customer_id' => $customer->legacy_customer_id,
            'b2b_customer_id' => $customer->getKey(),
            'company_name' => (string) $store->name,
            'status' => $store->is_active ? 'active' : 'suspended',
        ];
        if ($priceTierId !== null) {
            $updates['price_tier_id'] = $priceTierId;
        }

        $account->update($updates);
    }

    private function assertRetailStore(Store $store): void
    {
        $isRetail = DB::table('store_types')
            ->where('id', $store->store_type_id)
            ->where('code', 'B2C')
            ->exists();

        abort_unless($isRetail, 422, 'Wholesale customer linking is only available for Retail stores.');
    }
}
