<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushDeviceToken extends Model
{
    protected $guarded = [];
    protected $hidden = ['token_encrypted', 'token_hash'];

    protected function casts(): array
    {
        return ['token_encrypted' => 'encrypted', 'revoked_at' => 'datetime'];
    }
}
