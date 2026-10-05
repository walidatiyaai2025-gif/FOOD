<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoutingDecisionTrace extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'input_snapshot' => 'array',
            'result' => 'array',
            'evaluated_rules' => 'array',
            'decided_at' => 'datetime',
        ];
    }
}
