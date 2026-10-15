<?php

namespace App\Domain\RestaurantSaaS\Models;

use App\Models\EnterpriseOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $plan_id
 * @property string $state
 * @property Carbon $effective_at
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $current_period_ends_at
 * @property Carbon|null $past_due_at
 * @property Carbon|null $grace_ends_at
 * @property Carbon|null $suspended_at
 * @property Carbon|null $canceled_at
 * @property bool $cancel_at_period_end
 * @property bool $is_complimentary
 * @property string|null $complimentary_reason
 * @property array<string, mixed>|null $billing_contact
 * @property array<string, mixed>|null $tax_profile
 * @property string|null $payment_method_reference
 * @property-read EnterpriseOrganization $organization
 * @property-read RestaurantSaasPlan $plan
 */
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
