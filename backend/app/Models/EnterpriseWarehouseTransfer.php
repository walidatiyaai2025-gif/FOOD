<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnterpriseWarehouseTransfer extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_RECEIVED = 'received';

    protected $table = 'enterprise_warehouse_transfers';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }
}
