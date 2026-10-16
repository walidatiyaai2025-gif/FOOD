<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseTreasuryCashClosing extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_CLOSED = 'closed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'opening_amount' => 'decimal:3',
            'receipt_total' => 'decimal:3',
            'payment_total' => 'decimal:3',
            'expected_closing_amount' => 'decimal:3',
            'declared_closing_amount' => 'decimal:3',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EnterpriseCashbox, $this> */
    public function cashbox(): BelongsTo
    {
        return $this->belongsTo(EnterpriseCashbox::class, 'cashbox_id');
    }
}
