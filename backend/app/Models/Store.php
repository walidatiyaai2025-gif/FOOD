<?php

namespace App\Models;

use App\Enums\StoreBusinessProfile;
use App\Services\StoreBusinessProfileService;
use App\Support\StoreAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    protected $table = 'stores';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'advertising_enabled' => 'boolean',
            'live_ads_enabled' => 'boolean',
            'coupons_enabled' => 'boolean',
        ];
    }

    public function storeType(): BelongsTo
    {
        return $this->belongsTo(StoreType::class);
    }

    public function defaultCustomerWholesalePriceTier(): BelongsTo
    {
        return $this->belongsTo(B2bPriceTier::class, 'default_customer_wholesale_price_tier_id');
    }

    /** @return HasMany<UserStoreRole, $this> */
    public function storeRoleAssignments(): HasMany
    {
        return $this->hasMany(UserStoreRole::class);
    }

    public function resolvedBusinessProfile(): ?StoreBusinessProfile
    {
        return app(StoreBusinessProfileService::class)->resolve($this);
    }

    public function isRetailStore(): bool
    {
        return app(StoreBusinessProfileService::class)->isRetailStore($this);
    }

    public function isRestaurant(): bool
    {
        return app(StoreBusinessProfileService::class)->isRestaurant($this);
    }

    /**
     * @param  Builder<Store>  $query
     * @return Builder<Store>
     */
    public function scopeBusinessProfile(
        Builder $query,
        StoreBusinessProfile|string $profile,
    ): Builder {
        $resolved = $profile instanceof StoreBusinessProfile
            ? $profile
            : StoreBusinessProfile::tryFrom($profile);

        if ($resolved === null) {
            return $query->whereRaw('1 = 0');
        }

        $query = $query
            ->whereHas('storeType', static fn (Builder $typeQuery) => $typeQuery->where('code', 'B2C'));

        if ($resolved === StoreBusinessProfile::RETAIL_STORE) {
            return $query->where(static fn (Builder $profileQuery) => $profileQuery
                ->where('stores.business_profile', $resolved->value)
                ->orWhereNull('stores.business_profile'));
        }

        return $query->where('stores.business_profile', $resolved->value);
    }

    /**
     * @param  Builder<Store>  $query
     * @return Builder<Store>
     */
    public function scopeRetailStores(Builder $query): Builder
    {
        return $query->businessProfile(StoreBusinessProfile::RETAIL_STORE);
    }

    /**
     * @param  Builder<Store>  $query
     * @return Builder<Store>
     */
    public function scopeRestaurants(Builder $query): Builder
    {
        return $query->businessProfile(StoreBusinessProfile::RESTAURANT);
    }

    /**
     * @param  Builder<Store>  $query
     * @return Builder<Store>
     */
    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        return app(StoreAccess::class)->scopeStores($query, $user);
    }
}
