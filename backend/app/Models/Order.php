<?php

namespace App\Models;

use App\Models\Concerns\ScopesStoreAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Order extends Model
{
    use ScopesStoreAccess;

    private const COMMERCIAL_FIELDS = [
        'customer_id',
        'b2b_customer_id',
        'b2c_customer_id',
        'store_id',
        'warehouse_id',
        'address_id',
        'delivery_address_snapshot',
        'delivery_latitude',
        'delivery_longitude',
        'currency',
        'subtotal',
        'discount_total',
        'delivery_total',
        'tax_total',
        'grand_total',
        'quote_id',
        'quoted_at',
        'b2b_account_id_snapshot',
        'price_tier_id_snapshot',
        'price_tier_code_snapshot',
        'pricing_snapshot',
        'payment_method',
    ];

    protected $table = 'orders';

    protected $guarded = [];

    protected $casts = [
        'quoted_at' => 'datetime',
        'commercial_locked_at' => 'datetime',
        'pricing_snapshot' => 'array',
        'delivery_address_snapshot' => 'array',
        'delivery_latitude' => 'float',
        'delivery_longitude' => 'float',
    ];

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    protected static function booted(): void
    {
        static::updating(function (Order $order): void {
            if ($order->getOriginal('commercial_locked_at') === null) {
                return;
            }

            if ($order->isDirty(self::COMMERCIAL_FIELDS)) {
                throw new LogicException('Issued order commercial snapshots are immutable. Void/reissue the invoice or create a replacement order.');
            }
        });
    }
}
