<?php

namespace App\Support;

use App\Models\Store;
use App\Models\User;

final class B2cWorkspaceLandingResolver
{
    public function __construct(private readonly TenantContextResolver $tenants) {}

    public function urlFor(User $user): ?string
    {
        if (! $user->is_active || $user->hasRole('SUPER_ADMIN')) {
            return null;
        }

        $storeIds = $this->tenants->retailStoreIds($user);
        if (count($storeIds) !== 1) {
            return null;
        }

        $storeId = $storeIds[0];
        $profile = $this->tenants->businessProfileForStore($storeId);

        return match ($profile) {
            Store::BUSINESS_PROFILE_RETAIL_STORE => route('admin.b2c.dashboard', ['store_id' => $storeId]),
            Store::BUSINESS_PROFILE_RESTAURANT => route('admin.smart-ordering.index', ['store_id' => $storeId]),
            default => null,
        };
    }
}
