<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PlatformCustomer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'registered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (PlatformCustomer $customer): void {
            if ($customer->isDirty([
                'origin_channel',
                'origin_store_id',
                'registration_source',
                'registered_at',
            ])) {
                throw new LogicException('Platform Customer registration origin is immutable.');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function legacyCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'legacy_customer_id');
    }

    public function originStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'origin_store_id');
    }
}
