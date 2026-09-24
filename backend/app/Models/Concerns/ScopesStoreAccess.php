<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Support\StoreAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait ScopesStoreAccess
{
    /**
     * @template TModel of Model
     * @param Builder<TModel> $query
     * @return Builder<TModel>
     */
    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        return app(StoreAccess::class)->scopeStoreOwned($query, $user);
    }
}
