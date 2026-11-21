<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class EnterpriseFinanceCollectionAccountMapping extends Model
{
    protected $table = 'enterprise_finance_collection_account_mappings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }
}
