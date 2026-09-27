<?php

namespace App\Services;

use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

final class RetailWholesaleAccountService
{
    public function __construct(private readonly B2bCustomerService $customers) {}

    public function ensureForStore(Store $store): B2bCustomer
    {
        return DB::transaction(function () use ($store): B2bCustomer {
            $locked = Store::query()->whereKey($store->getKey())->lockForUpdate()->firstOrFail();
            $this->assertRetailStore($locked);

            $existingCustomerId = DB::table('retail_wholesale_accounts')
                ->where('retail_store_id', $locked->getKey())
                ->value('b2b_customer_id');

            if ($existingCustomerId !== null) {
                $customer = B2bCustomer::query()->findOrFail((int) $existingCustomerId);
                $this->syncIdentity($locked, $customer);

                return $customer->refresh();
            }

            $customer = $this->customers->create([
                'name' => (string) $locked->name,
                'phone' => null,
                'email' => null,
            ]);

            B2bAccount::query()->create([
                'customer_id' => $customer->legacy_customer_id,
                'b2b_customer_id' => $customer->getKey(),
                'price_tier_id' => null,
                'company_name' => (string) $locked->name,
                'status' => $locked->is_active ? 'active' : 'suspended',
                'tax_number' => null,
                'credit_limit' => 0,
            ]);

            DB::table('retail_wholesale_accounts')->insert([
                'retail_store_id' => $locked->getKey(),
                'b2b_customer_id' => $customer->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $customer->refresh();
        }, 3);
    }

    public function syncForStore(Store $store): B2bCustomer
    {
        $customer = $this->ensureForStore($store);
        $this->syncIdentity($store->fresh(), $customer);

        return $customer->refresh();
    }

    public function retailStoreIdForCustomer(int $b2bCustomerId): ?int
    {
        $storeId = DB::table('retail_wholesale_accounts')
            ->where('b2b_customer_id', $b2bCustomerId)
            ->value('retail_store_id');

        return $storeId === null ? null : (int) $storeId;
    }

    private function syncIdentity(Store $store, B2bCustomer $customer): void
    {
        DB::table('b2b_customers')
            ->where('id', $customer->getKey())
            ->update([
                'name' => (string) $store->name,
                'updated_at' => now(),
            ]);

        if ($customer->legacy_customer_id !== null) {
            DB::table('customers')
                ->where('id', $customer->legacy_customer_id)
                ->update([
                    'name' => (string) $store->name,
                    'type' => 'b2b',
                    'updated_at' => now(),
                ]);
        }

        DB::table('b2b_accounts')
            ->where('b2b_customer_id', $customer->getKey())
            ->update([
                'company_name' => (string) $store->name,
                'status' => $store->is_active ? 'active' : 'suspended',
                'updated_at' => now(),
            ]);
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
