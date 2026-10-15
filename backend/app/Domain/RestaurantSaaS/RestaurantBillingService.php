<?php

namespace App\Domain\RestaurantSaaS;

use App\Domain\RestaurantSaaS\Models\RestaurantSaasBillingRecord;
use App\Domain\RestaurantSaaS\Models\RestaurantSaasPlan;
use App\Domain\RestaurantSaaS\Models\RestaurantSaasSubscription;
use App\Domain\RestaurantSaaS\Models\RestaurantSaasUsageMeter;
use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Models\EnterpriseOrganization;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RestaurantBillingService
{
    public const TRIAL = 'TRIAL';

    public const ACTIVE = 'ACTIVE';

    public const PAST_DUE = 'PAST_DUE';

    public const GRACE = 'GRACE';

    public const SUSPENDED = 'SUSPENDED';

    public const CANCELED = 'CANCELED';

    private const ACCESS_STATES = [
        self::TRIAL,
        self::ACTIVE,
        self::PAST_DUE,
        self::GRACE,
    ];

    private const TRANSITIONS = [
        self::TRIAL => [self::ACTIVE, self::PAST_DUE, self::CANCELED],
        self::ACTIVE => [self::PAST_DUE, self::CANCELED],
        self::PAST_DUE => [self::GRACE, self::SUSPENDED, self::ACTIVE, self::CANCELED],
        self::GRACE => [self::SUSPENDED, self::ACTIVE, self::CANCELED],
        self::SUSPENDED => [self::ACTIVE, self::CANCELED],
        self::CANCELED => [],
    ];

    public function __construct(
        private readonly ModuleEntitlementManager $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Select or change a plan without plan-name conditionals.
     *
     * @param  array<string, mixed>  $options
     */
    public function subscribe(
        EnterpriseOrganization $organization,
        RestaurantSaasPlan $plan,
        ?Authenticatable $actor = null,
        array $options = [],
    ): RestaurantSaasSubscription {
        $this->assertPlanSelectable($plan);

        return DB::transaction(function () use ($organization, $plan, $actor, $options): RestaurantSaasSubscription {
            $subscription = RestaurantSaasSubscription::query()
                ->where('organization_id', $organization->getKey())
                ->lockForUpdate()
                ->first();
            $oldPlan = $subscription?->plan()->first();
            $before = $subscription === null ? null : $this->subscriptionSnapshot($subscription);

            $state = (string) ($options['state'] ?? ($options['trial_ends_at'] ?? null ? self::TRIAL : self::ACTIVE));
            if (! in_array($state, [self::TRIAL, self::ACTIVE], true)) {
                throw ValidationException::withMessages([
                    'state' => ['A new or changed plan must start in TRIAL or ACTIVE state.'],
                ]);
            }

            $subscription ??= new RestaurantSaasSubscription;
            $subscription->fill([
                'organization_id' => (int) $organization->getKey(),
                'plan_id' => (int) $plan->getKey(),
                'state' => $state,
                'effective_at' => $options['effective_at'] ?? now(),
                'trial_ends_at' => $options['trial_ends_at'] ?? null,
                'current_period_ends_at' => $options['current_period_ends_at'] ?? null,
                'past_due_at' => null,
                'grace_ends_at' => null,
                'suspended_at' => null,
                'canceled_at' => null,
                'cancel_at_period_end' => (bool) ($options['cancel_at_period_end'] ?? false),
                'is_complimentary' => (bool) ($options['is_complimentary'] ?? false),
                'complimentary_reason' => $options['complimentary_reason'] ?? null,
                'billing_contact' => $options['billing_contact'] ?? null,
                'tax_profile' => $options['tax_profile'] ?? null,
                'payment_method_reference' => $options['payment_method_reference'] ?? null,
            ]);
            $subscription->save();

            $this->reconcileEntitlements(
                $organization,
                $oldPlan?->entitlements,
                $plan->entitlements,
                $state,
                $actor,
            );

            $subscription = $subscription->refresh();
            $after = $this->subscriptionSnapshot($subscription);
            if ($before !== $after) {
                $this->audit->record(
                    'restaurant_saas.subscription.changed',
                    $actor,
                    $subscription,
                    $before,
                    $after,
                );
            }

            return $subscription;
        });
    }

    public function transition(
        RestaurantSaasSubscription $subscription,
        string $toState,
        ?Authenticatable $actor = null,
        ?CarbonInterface $graceEndsAt = null,
    ): RestaurantSaasSubscription {
        $toState = strtoupper(trim($toState));

        return DB::transaction(function () use ($subscription, $toState, $actor, $graceEndsAt): RestaurantSaasSubscription {
            $locked = RestaurantSaasSubscription::query()
                ->with(['organization', 'plan'])
                ->lockForUpdate()
                ->findOrFail($subscription->getKey());

            if ($locked->state === $toState) {
                return $locked;
            }

            $allowed = self::TRANSITIONS[(string) $locked->state] ?? [];
            if (! in_array($toState, $allowed, true)) {
                throw ValidationException::withMessages([
                    'state' => ["Subscription cannot transition from {$locked->state} to {$toState}."],
                ]);
            }

            $before = $this->subscriptionSnapshot($locked);
            $locked->state = $toState;

            if ($toState === self::PAST_DUE) {
                $locked->past_due_at ??= now();
            }

            if ($toState === self::GRACE) {
                if ($graceEndsAt === null || $graceEndsAt->isPast()) {
                    throw ValidationException::withMessages([
                        'grace_ends_at' => ['Grace state requires a future grace end.'],
                    ]);
                }
                $locked->grace_ends_at = $graceEndsAt;
            }

            if ($toState === self::SUSPENDED) {
                $locked->suspended_at = now();
            }

            if ($toState === self::ACTIVE) {
                $locked->past_due_at = null;
                $locked->grace_ends_at = null;
                $locked->suspended_at = null;
            }

            if ($toState === self::CANCELED) {
                $locked->canceled_at = now();
            }

            $locked->save();

            $this->reconcileEntitlements(
                $locked->organization,
                $locked->plan->entitlements,
                $locked->plan->entitlements,
                $toState,
                $actor,
            );

            $locked = $locked->refresh();
            $this->audit->record(
                'restaurant_saas.subscription.state_changed',
                $actor,
                $locked,
                $before,
                $this->subscriptionSnapshot($locked),
            );

            return $locked;
        });
    }

    /**
     * Add-ons are subscription configuration, not module implementations.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function setAddon(
        RestaurantSaasSubscription $subscription,
        string $code,
        int $quantity,
        ?CarbonInterface $effectiveFrom = null,
        ?CarbonInterface $effectiveTo = null,
        ?array $metadata = null,
        ?Authenticatable $actor = null,
    ): void {
        $code = trim($code);
        if ($code === '' || $quantity < 1) {
            throw ValidationException::withMessages([
                'addon' => ['Add-on code must be non-empty and quantity must be positive.'],
            ]);
        }

        DB::transaction(function () use (
            $subscription,
            $code,
            $quantity,
            $effectiveFrom,
            $effectiveTo,
            $metadata,
            $actor,
        ): void {
            $before = DB::table('restaurant_saas_subscription_addons')
                ->where('subscription_id', $subscription->getKey())
                ->where('code', $code)
                ->first();

            $values = [
                'quantity' => $quantity,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'metadata' => $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ];

            DB::table('restaurant_saas_subscription_addons')->updateOrInsert(
                [
                    'subscription_id' => $subscription->getKey(),
                    'code' => $code,
                ],
                $before === null
                    ? [...$values, 'created_at' => now()]
                    : $values,
            );

            $after = DB::table('restaurant_saas_subscription_addons')
                ->where('subscription_id', $subscription->getKey())
                ->where('code', $code)
                ->first();

            if ((array) $before !== (array) $after) {
                $this->audit->record(
                    'restaurant_saas.subscription.addon_changed',
                    $actor,
                    $subscription,
                    $before === null ? null : (array) $before,
                    $after === null ? null : (array) $after,
                );
            }
        });
    }

    public function recordUsage(
        EnterpriseOrganization $organization,
        string $meterKey,
        int $quantity,
        string $idempotencyKey,
        CarbonInterface $occurredAt,
        ?string $sourceReference = null,
        ?Authenticatable $actor = null,
    ): RestaurantSaasUsageMeter {
        $meterKey = trim($meterKey);
        $idempotencyKey = trim($idempotencyKey);

        if ($meterKey === '' || $idempotencyKey === '' || $quantity < 0) {
            throw ValidationException::withMessages([
                'meter' => ['Meter key and idempotency key are required; quantity cannot be negative.'],
            ]);
        }

        return DB::transaction(function () use (
            $organization,
            $meterKey,
            $quantity,
            $idempotencyKey,
            $occurredAt,
            $sourceReference,
            $actor,
        ): RestaurantSaasUsageMeter {
            $existing = RestaurantSaasUsageMeter::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $same = (int) $existing->organization_id === (int) $organization->getKey()
                    && (string) $existing->meter_key === $meterKey
                    && (int) $existing->quantity === $quantity
                    && (string) $existing->period_key === $occurredAt->format('Y-m')
                    && (string) ($existing->source_reference ?? '') === (string) ($sourceReference ?? '');

                if (! $same) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['Idempotency key was already used for different usage data.'],
                    ]);
                }

                return $existing;
            }

            $meter = RestaurantSaasUsageMeter::query()->create([
                'organization_id' => (int) $organization->getKey(),
                'meter_key' => $meterKey,
                'quantity' => $quantity,
                'period_key' => $occurredAt->format('Y-m'),
                'idempotency_key' => $idempotencyKey,
                'source_reference' => $sourceReference,
                'occurred_at' => $occurredAt,
            ]);

            $this->audit->record(
                'restaurant_saas.usage.recorded',
                $actor,
                $meter,
                null,
                [
                    'organization_id' => (int) $organization->getKey(),
                    'meter_key' => $meterKey,
                    'quantity' => $quantity,
                    'period_key' => $occurredAt->format('Y-m'),
                    'source_reference' => $sourceReference,
                ],
            );

            return $meter->refresh();
        });
    }

    /**
     * Persist provider-observed state only. The caller performs any network call before this method.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function reconcileBillingRecord(
        RestaurantSaasSubscription $subscription,
        string $kind,
        string $status,
        string $provider,
        string $providerReference,
        int $amountMinor,
        string $currency,
        CarbonInterface $occurredAt,
        ?string $paymentMethodReference = null,
        ?array $metadata = null,
        ?Authenticatable $actor = null,
    ): RestaurantSaasBillingRecord {
        if ($amountMinor < 0 || trim($providerReference) === '') {
            throw ValidationException::withMessages([
                'billing_record' => ['Provider reference is required and amount cannot be negative.'],
            ]);
        }

        return DB::transaction(function () use (
            $subscription,
            $kind,
            $status,
            $provider,
            $providerReference,
            $amountMinor,
            $currency,
            $occurredAt,
            $paymentMethodReference,
            $metadata,
            $actor,
        ): RestaurantSaasBillingRecord {
            $record = RestaurantSaasBillingRecord::query()
                ->where('provider', $provider)
                ->where('provider_reference', $providerReference)
                ->where('kind', $kind)
                ->lockForUpdate()
                ->first();
            $before = $record === null ? null : $this->billingSnapshot($record);

            $record ??= new RestaurantSaasBillingRecord;
            $record->fill([
                'subscription_id' => (int) $subscription->getKey(),
                'kind' => trim($kind),
                'status' => trim($status),
                'provider' => trim($provider),
                'provider_reference' => trim($providerReference),
                'payment_method_reference' => $paymentMethodReference,
                'amount_minor' => $amountMinor,
                'currency' => strtoupper(trim($currency)),
                'occurred_at' => $occurredAt,
                'metadata' => $metadata,
            ]);
            $record->save();

            $record = $record->refresh();
            $after = $this->billingSnapshot($record);
            if ($before !== $after) {
                $this->audit->record(
                    'restaurant_saas.billing.reconciled',
                    $actor,
                    $record,
                    $before,
                    $after,
                );
            }

            return $record;
        });
    }

    private function assertPlanSelectable(RestaurantSaasPlan $plan): void
    {
        $today = now()->startOfDay();

        if (! $plan->is_active
            || ($plan->effective_from !== null && $plan->effective_from->isAfter($today))
            || ($plan->effective_to !== null && $plan->effective_to->isBefore($today))) {
            throw ValidationException::withMessages([
                'plan' => ['Plan is not active for the requested effective date.'],
            ]);
        }
    }

    /**
     * @param  array<string, list<string>|null>|null  $previous
     * @param  array<string, list<string>|null>|null  $next
     */
    private function reconcileEntitlements(
        EnterpriseOrganization $organization,
        ?array $previous,
        ?array $next,
        string $state,
        ?Authenticatable $actor,
    ): void {
        $previous ??= [];
        $next ??= [];
        $moduleCodes = array_values(array_unique([
            ...array_keys($previous),
            ...array_keys($next),
        ]));
        sort($moduleCodes);

        $scope = new EntitlementScope(
            EntitlementScope::ORGANIZATION,
            (int) $organization->getKey(),
        );
        $stateAllowsAccess = in_array($state, self::ACCESS_STATES, true);

        foreach ($moduleCodes as $moduleCode) {
            $isIncluded = array_key_exists($moduleCode, $next);
            $capabilities = $isIncluded ? $next[$moduleCode] : null;
            if ($capabilities === []) {
                $capabilities = null;
            }

            $this->entitlements->set(
                $moduleCode,
                $scope,
                $stateAllowsAccess && $isIncluded,
                $capabilities,
                $actor,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionSnapshot(RestaurantSaasSubscription $subscription): array
    {
        return [
            'organization_id' => (int) $subscription->organization_id,
            'plan_id' => (int) $subscription->plan_id,
            'state' => (string) $subscription->state,
            'effective_at' => $subscription->effective_at?->toISOString(),
            'trial_ends_at' => $subscription->trial_ends_at?->toISOString(),
            'current_period_ends_at' => $subscription->current_period_ends_at?->toISOString(),
            'past_due_at' => $subscription->past_due_at?->toISOString(),
            'grace_ends_at' => $subscription->grace_ends_at?->toISOString(),
            'suspended_at' => $subscription->suspended_at?->toISOString(),
            'canceled_at' => $subscription->canceled_at?->toISOString(),
            'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
            'is_complimentary' => (bool) $subscription->is_complimentary,
            'complimentary_reason' => $subscription->complimentary_reason,
            'billing_contact' => $subscription->billing_contact,
            'tax_profile' => $subscription->tax_profile,
            'payment_method_reference' => $subscription->payment_method_reference,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function billingSnapshot(RestaurantSaasBillingRecord $record): array
    {
        return [
            'subscription_id' => (int) $record->subscription_id,
            'kind' => (string) $record->kind,
            'status' => (string) $record->status,
            'provider' => (string) $record->provider,
            'provider_reference' => (string) $record->provider_reference,
            'payment_method_reference' => $record->payment_method_reference,
            'amount_minor' => (int) $record->amount_minor,
            'currency' => (string) $record->currency,
            'occurred_at' => $record->occurred_at?->toISOString(),
            'metadata' => $record->metadata,
        ];
    }
}
