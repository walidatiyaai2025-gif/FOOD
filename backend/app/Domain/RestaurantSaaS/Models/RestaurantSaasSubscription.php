<?php

namespace App\Domain\RestaurantSaaS\Models;

use App\Models\EnterpriseOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RestaurantSaasSubscription extends Model
{
    protected $table = 'restaurant_saas_subscriptions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'past_due_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'canceled_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'is_complimentary' => 'boolean',
            'billing_contact' => 'array',
            'tax_profile' => 'array',
        ];
    }

    /** @return BelongsTo<EnterpriseOrganization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(EnterpriseOrganization::class, 'organization_id');
    }

    /** @return BelongsTo<RestaurantSaasPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(RestaurantSaasPlan::class, 'plan_id');
    }
}
