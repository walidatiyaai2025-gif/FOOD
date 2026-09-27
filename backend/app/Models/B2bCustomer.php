<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class B2bCustomer extends Model
{
    protected $table = 'b2b_customers';

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): HasOne
    {
        return $this->hasOne(B2bAccount::class, 'b2b_customer_id');
    }

    public function legacyCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'legacy_customer_id');
    }
}
