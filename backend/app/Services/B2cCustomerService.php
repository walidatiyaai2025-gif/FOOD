<?php

namespace App\Services;

use App\Models\B2cCustomer;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class B2cCustomerService
{
    /** @param array{name:string,phone?:?string,email?:?string} $data */
    public function create(int $storeId, array $data, ?User $user = null): B2cCustomer
    {
        $this->assertRetailStore($storeId);

        return DB::transaction(function () use ($storeId, $data, $user): B2cCustomer {
            $legacy = $user === null
                ? null
                : Customer::query()->where('user_id', $user->getKey())->where('type', 'b2c')->first();

            if (! $legacy instanceof Customer && $user?->is_platform_customer) {
                // The legacy customers table has a unique user_id and cannot hold
                // a second B2C compatibility row for a platform-wide customer.
                // Reuse the registered customer's existing legacy row; the
                // authoritative B2C identity remains store-scoped below.
                $legacy = Customer::query()->where('user_id', $user->getKey())->first();
            }

            if (! $legacy instanceof Customer) {
                $legacy = Customer::query()->create([
                    'user_id' => $user?->getKey(),
                    'type' => 'b2c',
                    'name' => $data['name'],
                    'phone' => $data['phone'] ?? null,
                    'email' => $data['email'] ?? null,
                ]);
            }

            return B2cCustomer::query()->firstOrCreate(
                [
                    'legacy_customer_id' => $legacy->getKey(),
                    'store_id' => $storeId,
                ],
                [
                    'user_id' => $user?->getKey(),
                    'name' => $data['name'],
                    'phone' => $data['phone'] ?? null,
                    'email' => $data['email'] ?? null,
                ],
            );
        });
    }

    /** @param array{name?:string,phone?:?string,email?:?string} $data */
    public function update(B2cCustomer $customer, array $data): B2cCustomer
    {
        $customer->fill($data);
        $customer->save();

        return $customer->refresh();
    }

    private function assertRetailStore(int $storeId): void
    {
        $exists = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->exists();

        abort_unless($exists, 404);
    }
}
