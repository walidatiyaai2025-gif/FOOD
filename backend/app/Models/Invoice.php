<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $table = 'invoices';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'commercial_snapshot' => 'array',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
