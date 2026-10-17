<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseSupplierPortalToken extends Model
{
    protected $table = 'enterprise_supplier_portal_tokens';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EnterpriseSupplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(EnterpriseSupplier::class, 'supplier_id');
    }
}
