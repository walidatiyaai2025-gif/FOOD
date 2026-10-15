<?php

namespace App\Domain\RestaurantSaaS\Models;

use App\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RestaurantLocationProfile extends Model
{
    protected $table = 'restaurant_location_profiles';

    protected $guarded = [];

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
