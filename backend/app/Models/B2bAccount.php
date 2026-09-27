<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class B2bAccount extends Model
{
    protected $table = 'b2b_accounts';

    protected $guarded = [];

    /**
     * Transitional compatibility relation. New business logic must use b2bCustomer().
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function b2bCustomer(): BelongsTo
    {
        return $this->belongsTo(B2bCustomer::class, 'b2b_customer_id');
    }
}
