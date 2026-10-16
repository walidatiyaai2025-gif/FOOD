<?php

namespace App\Domain\RestaurantSaaS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $subscription_id
 * @property string $kind
 * @property string $status
 * @property string $provider
 * @property string $provider_reference
 * @property string|null $payment_method_reference
 * @property int $amount_minor
 * @property string $currency
 * @property Carbon $occurred_at
 * @property array<string, mixed>|null $metadata
 */
final class RestaurantSaasBillingRecord extends Model
{
    protected $table = 'restaurant_saas_billing_records';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
