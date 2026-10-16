<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnterpriseFinanceCreditTerm extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:3',
            'payment_terms_days' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }
}
