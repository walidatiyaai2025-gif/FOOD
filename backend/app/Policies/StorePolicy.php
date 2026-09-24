<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;
use App\Support\StoreAccess;

class StorePolicy
{
    public function __construct(private readonly StoreAccess $storeAccess) {}

    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Store $store): bool
    {
        return $user->is_active && $this->storeAccess->canAccess($user, $store);
    }
}
