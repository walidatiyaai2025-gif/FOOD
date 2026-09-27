<?php

namespace App\Repositories;

use App\Models\B2bCustomer;
use App\Models\User;

final class B2bCustomerRepository
{
    public function forUser(User $user): ?B2bCustomer
    {
        return B2bCustomer::query()
            ->where('user_id', $user->getKey())
            ->first();
    }

    public function find(int $id): ?B2bCustomer
    {
        return B2bCustomer::query()->find($id);
    }
}
