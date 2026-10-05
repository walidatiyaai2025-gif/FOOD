<?php

namespace App\Services;

use App\Models\OperationalConfiguration;
use App\Models\OperationalConfigurationAudit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationalConfigurationRegistry
{
    public const ROUTING_MODES = ['automatic', 'manual', 'hybrid'];

    public const MODULES = [
        'van_access',
        'field_customer_visits',
        'field_order_capture',
        'van_warehouse_pickup',
        'van_route_execution',
        'automatic_routing',
        'manual_routing',
        'live_van_tracking',
        'live_driver_tracking',
        'zone_map_overlays',
        'collection_at_delivery',
        'customer_collection',
        'partial_collection',
        'driver_wallet',
        'van_wallet',
        'remittance_submission',
        'remittance_approval',
        'customer_collection_notifications',
        'route_deviation_warnings',
        'shift_close',
        'direct_van_stock_sales',
    ];

    public const DEPENDENCIES = [
        'field_order_capture' => ['van_access'],
        'van_route_execution' => ['van_access'],
        'automatic_routing' => [],
        'manual_routing' => [],
        'collection_at_delivery' => [],
        'remittance_submission' => [],
        'live_van_tracking' => ['van_access'],
    ];

    public const FAILURE_MODES = [
        'financial_posting' => 'fail_closed',
        'credit_authorization' => 'fail_closed',
        'customer_scope' => 'fail_closed',
        'duplicate_collection' => 'fail_closed',
        'currency_validation' => 'fail_closed',
        'automatic_routing' => 'degrade',
        'route_optimization' => 'degrade',
        'live_van_tracking' => 'degrade',
        'zone_map_overlays' => 'degrade',
        'push_notifications' => 'degrade',
    ];

    public function createDraft(
        string $key,
        array $value,
        string $valueType,
        string $scopeType,
        string $scopeKey,
        User $actor,
        ?string $reason = null,
        array $schema = [],
        array $dependencies = [],
        string $failureMode = 'degrade',
        bool $isEmergency = false,
    ): OperationalConfiguration {
        $this->validateControl($key, $value, $valueType, $failureMode, $dependencies);

        $revision = ((int) OperationalConfiguration::query()
            ->where('key', $key)
            ->where('scope_type', $scopeType)
            ->where('scope_key', $scopeKey)
            ->max('revision')) + 1;

        $configuration = OperationalConfiguration::query()->create([
            'key' => $key,
            'scope_type' => $scopeType,
            'scope_key' => $scopeKey,
            'revision' => $revision,
            'status' => 'draft',
            'value_type' => $valueType,
            'value' => $value,
            'schema' => $schema ?: null,
            'dependencies' => $dependencies ?: null,
            'failure_mode' => $failureMode,
            'is_emergency' => $isEmergency,
            'created_by' => $actor->id,
            'reason' => $reason,
        ]);

        $this->audit($configuration, 'draft_created', null, $value, $actor, $reason);

        return $configuration;
    }

    public function publish(
        OperationalConfiguration $configuration,
        User $actor,
        ?Carbon $effectiveFrom = null,
        ?string $reason = null,
    ): OperationalConfiguration {
        if ($configuration->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'Only draft operational configuration revisions can be published.',
            ]);
        }

        return DB::transaction(function () use ($configuration, $actor, $effectiveFrom, $reason): OperationalConfiguration {
            OperationalConfiguration::query()
                ->where('key', $configuration->key)
                ->where('scope_type', $configuration->scope_type)
                ->where('scope_key', $configuration->scope_key)
                ->where('status', 'published')
                ->update([
                    'status' => 'retired',
                    'effective_until' => $effectiveFrom ?? now(),
                    'updated_at' => now(),
                ]);

            $configuration->forceFill([
                'status' => 'published',
                'effective_from' => $effectiveFrom ?? now(),
                'published_by' => $actor->id,
                'published_at' => now(),
                'reason' => $reason ?? $configuration->reason,
            ])->save();

            $this->audit(
                $configuration,
                'published',
                null,
                $configuration->value,
                $actor,
                $reason,
            );

            return $configuration->fresh();
        });
    }

    /**
     * @param array<int,array{type:string,key:string}> $scopePrecedence Most-specific scope first.
     */
    public function resolveEffective(
        string $key,
        array $scopePrecedence,
        ?Carbon $at = null,
    ): ?OperationalConfiguration {
        $at ??= now();

        foreach ($scopePrecedence as $scope) {
            $candidate = OperationalConfiguration::query()
                ->where('key', $key)
                ->where('scope_type', $scope['type'])
                ->where('scope_key', $scope['key'])
                ->where('status', 'published')
                ->where(function ($query) use ($at): void {
                    $query->whereNull('effective_from')->orWhere('effective_from', '<=', $at);
                })
                ->where(function ($query) use ($at): void {
                    $query->whereNull('effective_until')->orWhere('effective_until', '>', $at);
                })
                ->orderByDesc('revision')
                ->first();

            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    public function routingMode(array $scopePrecedence, ?Carbon $at = null): string
    {
        $configuration = $this->resolveEffective('routing.mode', $scopePrecedence, $at);
        $mode = $configuration?->value['mode'] ?? 'manual';

        return in_array($mode, self::ROUTING_MODES, true) ? $mode : 'manual';
    }

    public function moduleEnabled(string $module, array $scopePrecedence, ?Carbon $at = null): bool
    {
        if (! in_array($module, self::MODULES, true)) {
            return false;
        }

        $configuration = $this->resolveEffective('module.'.$module, $scopePrecedence, $at);

        return (bool) ($configuration?->value['enabled'] ?? false);
    }

    private function validateControl(
        string $key,
        array $value,
        string $valueType,
        string $failureMode,
        array $dependencies,
    ): void {
        if (! in_array($failureMode, ['fail_closed', 'degrade'], true)) {
            throw ValidationException::withMessages([
                'failure_mode' => 'Operational controls must declare fail_closed or degrade behavior.',
            ]);
        }

        if ($key === 'routing.mode' && ! in_array($value['mode'] ?? null, self::ROUTING_MODES, true)) {
            throw ValidationException::withMessages([
                'value.mode' => 'Routing mode must be automatic, manual, or hybrid.',
            ]);
        }

        if (str_starts_with($key, 'module.')) {
            $module = substr($key, strlen('module.'));
            if (! in_array($module, self::MODULES, true)) {
                throw ValidationException::withMessages(['key' => 'Unknown operational module.']);
            }

            if ($valueType !== 'boolean' || ! array_key_exists('enabled', $value) || ! is_bool($value['enabled'])) {
                throw ValidationException::withMessages([
                    'value.enabled' => 'Module controls require a boolean enabled value.',
                ]);
            }
        }

        foreach ($dependencies as $dependency) {
            if (! is_string($dependency) || $dependency === '') {
                throw ValidationException::withMessages([
                    'dependencies' => 'Dependencies must use stable non-empty codes.',
                ]);
            }
        }
    }

    private function audit(
        OperationalConfiguration $configuration,
        string $action,
        ?array $before,
        ?array $after,
        User $actor,
        ?string $reason,
    ): void {
        OperationalConfigurationAudit::query()->create([
            'operational_configuration_id' => $configuration->id,
            'action' => $action,
            'before_value' => $before,
            'after_value' => $after,
            'actor_user_id' => $actor->id,
            'reason' => $reason,
        ]);
    }
}
