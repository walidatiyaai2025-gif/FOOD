<?php

namespace App\Policies;

use App\Models\B2bCustomer;
use App\Models\User;

final class B2bCustomerPolicy
{
    public function view(User $user, B2bCustomer $customer): bool
    {
        return (int) $customer->user_id === (int) $user->getKey()
            || (($user->hasRole('SUPER_ADMIN') || $user->hasRole('B2B_ADMIN'))
                && $user->hasPermission('customers.view'));
    }

    public function update(User $user, B2bCustomer $customer): bool
    {
        return (int) $customer->user_id === (int) $user->getKey()
            || (($user->hasRole('SUPER_ADMIN') || $user->hasRole('B2B_ADMIN'))
                && $user->hasPermission('customers.edit'));
    }

    public function delete(User $user, B2bCustomer $customer): bool
    {
        return ($user->hasRole('SUPER_ADMIN') || $user->hasRole('B2B_ADMIN'))
            && $user->hasPermission('customers.delete');
    }
}
