<?php

namespace App\Models;

use App\Support\StoreAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $table = 'inventories';

    protected $guarded = [];

    /**
     * @param  Builder<Inventory>  $query
     * @return Builder<Inventory>
     */
    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        $warehouses = app(StoreAccess::class)
            ->scopeStoreOwned(Warehouse::query(), $user)
            ->select('warehouses.id');

        return $query->whereIn(
            $query->getModel()->qualifyColumn('warehouse_id'),
            $warehouses,
        );
    }
}
