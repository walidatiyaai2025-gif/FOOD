<?php

namespace App\Models;

use App\Support\StoreAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    protected $table = 'stores';

    protected $guarded = [];

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
