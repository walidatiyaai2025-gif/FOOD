<?php

namespace App\Domain\SmartOrdering\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $site_id
 * @property string $code
 * @property string|null $name_ar
 * @property string $name_en
 * @property string $timezone
 * @property bool $is_default
 * @property bool $is_active
 * @property bool $accepting_orders
 * @property bool $supports_dine_in
 * @property bool $supports_pickup
 * @property bool $supports_delivery
 * @property bool $allow_scheduled
 * @property int $pickup_lead_minutes
 * @property int $max_schedule_days
 * @property float $service_charge
 * @property string|null $whatsapp_routing_identity
 * @property array<int,string>|null $holiday_closed_dates
 * @property-read int $store_id
 * @property-read string $name
 */
class SmartOrderingBranch extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<SmartOrderingSite, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(SmartOrderingSite::class, 'site_id');
    }

    public function getStoreIdAttribute(): int
    {
        $site = $this->getRelationValue('site');

        return $site instanceof SmartOrderingSite ? (int) $site->store_id : 0;
    }

    public function getNameAttribute(): string
    {
        if (app()->getLocale() === 'ar') {
            return (string) ($this->name_ar ?: $this->name_en);
        }

        return (string) ($this->name_en ?: $this->name_ar);
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'accepting_orders' => 'boolean',
            'supports_dine_in' => 'boolean',
            'supports_pickup' => 'boolean',
            'supports_delivery' => 'boolean',
            'allow_scheduled' => 'boolean',
            'holiday_closed_dates' => 'array',
            'service_charge' => 'float',
        ];
    }
}
