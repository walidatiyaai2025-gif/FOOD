<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseProcurementMatchReview extends Model
{
    protected $table = 'enterprise_procurement_match_reviews';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tolerance_percent' => 'decimal:3',
            'quantity_variance' => 'decimal:3',
            'receipt_variance' => 'decimal:3',
            'price_variance' => 'decimal:3',
            'value_variance' => 'decimal:3',
            'line_results' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EnterprisePurchaseInvoice, $this> */
    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(EnterprisePurchaseInvoice::class, 'purchase_invoice_id');
    }
}
