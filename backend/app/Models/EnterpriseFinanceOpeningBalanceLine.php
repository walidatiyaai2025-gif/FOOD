<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseFinanceOpeningBalanceLine extends Model
{
    protected $table = 'enterprise_finance_opening_balance_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:3',
            'credit' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<EnterpriseFinanceOpeningBalanceBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(EnterpriseFinanceOpeningBalanceBatch::class, 'batch_id');
    }

    /** @return BelongsTo<EnterpriseFinanceAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(EnterpriseFinanceAccount::class, 'account_id');
    }

    /** @return BelongsTo<EnterpriseCostCenter, $this> */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(EnterpriseCostCenter::class, 'cost_center_id');
    }
}
