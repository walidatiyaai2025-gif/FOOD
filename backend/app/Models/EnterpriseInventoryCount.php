<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnterpriseInventoryCount extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETED = 'completed';

    protected $table = 'enterprise_inventory_counts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
