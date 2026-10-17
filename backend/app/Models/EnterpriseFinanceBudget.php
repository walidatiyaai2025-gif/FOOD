<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseFinanceBudget extends Model
{
    protected $table = 'enterprise_finance_budgets';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    /** @return HasMany<EnterpriseFinanceBudgetLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(EnterpriseFinanceBudgetLine::class, 'budget_id');
    }
}
