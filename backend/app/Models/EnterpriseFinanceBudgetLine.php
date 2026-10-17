<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseFinanceBudgetLine extends Model
{
    protected $table = 'enterprise_finance_budget_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:3'];
    }

    /** @return BelongsTo<EnterpriseFinanceBudget, $this> */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(EnterpriseFinanceBudget::class, 'budget_id');
    }
}
