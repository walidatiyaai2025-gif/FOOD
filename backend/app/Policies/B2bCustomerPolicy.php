<?php

namespace App\Policies;

use App\Models\B2bCustomer;
use App\Models\User;

final class B2bCustomerPolicy
{
    public function view(User $user, B2bCustomer $customer): bool
    {
        return (int) $customer->user_id === (int) $user->getKey()
            || ($this->canAccessWholesale($user) && $user->hasPermission('customers.view'));
    }

    public function update(User $user, B2bCustomer $customer): bool
    {
        return (int) $customer->user_id === (int) $user->getKey()
            || ($this->canAccessWholesale($user) && $user->hasPermission('customers.edit'));
    }

    public function delete(User $user, B2bCustomer $customer): bool
    {
        return $this->canAccessWholesale($user)
            && $user->hasPermission('customers.delete');
    }

    private function canAccessWholesale(User $user): bool
    {
        $roles = array_values((array) config('admin.channels.b2b.global_roles', []));

        return $user->roles()
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global')
            ->whereIn('roles.code', $roles)
            ->exists();
    }
}
