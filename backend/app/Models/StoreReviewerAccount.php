<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreReviewerAccount extends Model
{
    protected $guarded = [];

    protected $hidden = ['secret_encrypted'];

    protected function casts(): array
    {
        return [
            'secret_encrypted' => 'encrypted',
            'context' => 'array',
            'is_active' => 'boolean',
            'secret_rotated_at' => 'datetime',
            'last_tested_at' => 'datetime',
        ];
    }

    public function maskedSecret(): string
    {
        return $this->secret_encrypted === null ? 'NOT SET' : '••••••••';
    }
}
