<?php

namespace App\Services;

use App\Models\B2bCustomer;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class B2bCustomerService
{
    /** @param array{name:string,phone?:?string,email?:?string} $data */
    public function create(array $data, ?User $user = null): B2bCustomer
    {
        return DB::transaction(function () use ($data, $user): B2bCustomer {
            $legacy = Customer::query()->create([
                'user_id' => $user?->getKey(),
                'type' => 'b2b',
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
            ]);

            return B2bCustomer::query()->create([
                'legacy_customer_id' => $legacy->getKey(),
                'user_id' => $user?->getKey(),
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
            ]);
        });
    }
}
