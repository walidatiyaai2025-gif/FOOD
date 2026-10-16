<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnterpriseFinanceSourcePosting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event_version' => 'integer',
            'source_id' => 'integer',
            'occurred_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }
}
