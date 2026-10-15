<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseBankAccount extends Model
{
    public const VISIBILITY_STORE = 'store';

    public const VISIBILITY_COMPANY = 'company';

    protected $guarded = [];

    protected $hidden = ['account_reference'];

    protected function casts(): array
    {
        return [
            'account_reference' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<EnterpriseCompany, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(EnterpriseCompany::class, 'company_id');
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
