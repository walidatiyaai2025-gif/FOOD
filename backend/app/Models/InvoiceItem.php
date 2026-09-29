<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $table = 'invoice_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'line_snapshot' => 'array',
        ];
    }
}
