<?php

namespace App\Models;

use App\Support\StoreAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
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
            $profile = $store->business_profile;
            if ($profile !== null && ! in_array($profile, self::businessProfiles(), true)) {
                throw new InvalidArgumentException('Unsupported B2C business profile ['.(string) $profile.'].');
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

    public function isRetailStoreProfile(): bool
    {
        return $this->business_profile === self::BUSINESS_PROFILE_RETAIL_STORE;
    }

    public function isRestaurantProfile(): bool
    {
        return $this->business_profile === self::BUSINESS_PROFILE_RESTAURANT;
    }

    /** @param Builder<Store> $query
     * @return Builder<Store>
     */
    public function scopeBusinessProfile(Builder $query, string $profile): Builder
    {
        if (! in_array($profile, self::businessProfiles(), true)) {
            throw new InvalidArgumentException('Unsupported B2C business profile ['.$profile.'].');
        }

        return $query->where('business_profile', $profile);
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

    /**
     * @param  Builder<Store>  $query
     * @return Builder<Store>
     */
    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        return app(StoreAccess::class)->scopeStores($query, $user);
    }
}
