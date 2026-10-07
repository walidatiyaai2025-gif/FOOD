<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Van extends Model
{
    protected $guarded = [];

    public function assignments(): HasMany
    {
        return $this->hasMany(VanAssignment::class);
    }

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'capacity_weight' => 'decimal:3',
        ];
    }
}
