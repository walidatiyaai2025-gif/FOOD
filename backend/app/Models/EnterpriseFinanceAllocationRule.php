<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnterpriseFinanceAllocationRule extends Model
{
    protected $table = 'enterprise_finance_allocation_rules';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['allocation_percent' => 'decimal:3'];
    }
}
