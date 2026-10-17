<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnterpriseFinanceTaxCode extends Model
{
    protected $table = 'enterprise_finance_tax_codes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rate_percent' => 'decimal:3',
            'recoverable_percent' => 'decimal:3',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}
