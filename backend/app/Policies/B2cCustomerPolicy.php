<?php

namespace App\Policies;

use App\Models\B2cCustomer;
use App\Models\User;

final class B2cCustomerPolicy
{
    public function view(User $user, B2cCustomer $customer): bool
    {
        if ((int) $customer->user_id === (int) $user->getKey()) {
            return true;
        }

        if ($user->hasRole('SUPER_ADMIN')) {
            return false;
        }

        return $user->hasPermission('customers.view', (int) $customer->store_id);
    }

    public function update(User $user, B2cCustomer $customer): bool
    {
        if ((int) $customer->user_id === (int) $user->getKey()) {
            return true;
        }

        if ($user->hasRole('SUPER_ADMIN')) {
            return false;
        }

        return $user->hasPermission('customers.edit', (int) $customer->store_id);
    }

    public function delete(User $user, B2cCustomer $customer): bool
    {
        return ! $user->hasRole('SUPER_ADMIN')
            && $user->hasPermission('customers.delete', (int) $customer->store_id);
    }
}
