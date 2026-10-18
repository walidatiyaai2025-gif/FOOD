<?php

namespace App\Services;

use App\Enums\StoreBusinessProfile;
use App\Models\Store;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

final class StoreBusinessProfileService
{
    public function resolve(Store|int $store): ?StoreBusinessProfile
    {
        $storeId = $store instanceof Store ? (int) $store->getKey() : $store;

        if ($storeId < 1) {
            return null;
        }

        $row = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->first([
                'store_types.code as channel',
                'stores.business_profile',
            ]);

        if ($row === null || $row->channel !== 'B2C' || ! is_string($row->business_profile)) {
            return null;
        }

        return StoreBusinessProfile::tryFrom($row->business_profile);
    }

    public function matches(Store|int $store, StoreBusinessProfile $profile): bool
    {
        return $this->resolve($store) === $profile;
    }

    public function isRetailStore(Store|int $store): bool
    {
        return $this->matches($store, StoreBusinessProfile::RETAIL_STORE);
    }

    public function isRestaurant(Store|int $store): bool
    {
        return $this->matches($store, StoreBusinessProfile::RESTAURANT);
    }

    public function assign(Store $store, StoreBusinessProfile|string $profile): Store
    {
        $resolved = $profile instanceof StoreBusinessProfile
            ? $profile
            : StoreBusinessProfile::tryFrom($profile);

        $channel = DB::table('store_types')
            ->where('id', $store->store_type_id)
            ->value('code');

        if ($resolved === null || $channel !== 'B2C') {
            throw ValidationException::withMessages([
                'business_profile' => ['Business profile must be RETAIL_STORE or RESTAURANT on a B2C store.'],
            ]);
        }

        $store->forceFill(['business_profile' => $resolved->value])->save();

        return $store->refresh();
    }
}
