<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseInventoryLotMovement extends Model
{
    protected $table = 'enterprise_inventory_lot_movements';

    protected $guarded = [];

    /** @return BelongsTo<EnterpriseInventoryLot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(EnterpriseInventoryLot::class, 'inventory_lot_id');
    }
}
