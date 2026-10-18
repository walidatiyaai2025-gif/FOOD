<?php

namespace App\Models;

use App\Enums\StoreBusinessProfile;
use App\Services\StoreBusinessProfileService;
use App\Support\StoreAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class Store extends Model
{
    public const BUSINESS_PROFILE_RETAIL_STORE = 'RETAIL_STORE';

    public const BUSINESS_PROFILE_RESTAURANT = 'RESTAURANT';

    protected $table = 'stores';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (Store $store): void {
            if (! Schema::hasTable('stores') || ! Schema::hasColumn('stores', 'business_profile')) {
                return;
            }

            $profile = $store->business_profile;
            if ($profile !== null && ! in_array((string) $profile, self::businessProfiles(), true)) {
                throw new InvalidArgumentException('Unsupported B2C business profile ['.(string) $profile.'].');
            }

            if (! Schema::hasTable('store_types')) {
                return;
            }

            $channel = DB::table('store_types')->where('id', $store->store_type_id)->value('code');
            if ($channel === 'B2C') {
                $store->business_profile ??= self::BUSINESS_PROFILE_RETAIL_STORE;

                return;
            }

            if ($profile !== null) {
                throw new InvalidArgumentException('Only B2C stores may have a business profile.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'advertising_enabled' => 'boolean',
            'live_ads_enabled' => 'boolean',
            'coupons_enabled' => 'boolean',
        ];
    }

    /** @return list<string> */
    public static function businessProfiles(): array
    {
        return [
            self::BUSINESS_PROFILE_RETAIL_STORE,
            self::BUSINESS_PROFILE_RESTAURANT,
        ];
    }

    public static function effectiveB2cBusinessProfile(mixed $profile): ?string
    {
        if ($profile === null) {
            return self::BUSINESS_PROFILE_RETAIL_STORE;
        }

        if (! is_string($profile) || ! in_array($profile, self::businessProfiles(), true)) {
            return null;
        }

        return $profile;
    }

    public function storeType(): BelongsTo
    {
        return $this->belongsTo(StoreType::class, 'store_type_id');
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

    public function isRetailStoreProfile(): bool
    {
        return $this->resolvedBusinessProfile() === StoreBusinessProfile::RETAIL_STORE;
    }

    public function isRestaurantProfile(): bool
    {
        return $this->resolvedBusinessProfile() === StoreBusinessProfile::RESTAURANT;
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

        return $query
            ->where(function (Builder $profileQuery) use ($resolved): void {
                $profileQuery->where('stores.business_profile', $resolved->value);

                if ($resolved === StoreBusinessProfile::RETAIL_STORE) {
                    $profileQuery->orWhereNull('stores.business_profile');
                }
            })
            ->whereHas('storeType', static fn (Builder $typeQuery) => $typeQuery->where('code', 'B2C'));
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
