<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class FieldOperationsPilotReadinessService
{
    public const STATE_NOT_READY = 'NOT_READY';
    public const STATE_READY = 'READY';
    public const STATE_ACTIVE = 'ACTIVE';
    public const STATE_SUSPENDED = 'SUSPENDED';

    public function __construct(
        private readonly FieldOperationConfigurationService $config,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string,string|int> $scope
     *  @return array<string,mixed>
     */
    public function evaluate(array $scope): array
    {
        $checks = [
            'geometry' => $this->tableHasRows('territory_geometries', $scope, 'service_territory_id', 'territory'),
            'address_mapping' => $this->addressMappingReady($scope),
            'warehouse' => $this->warehouseReady($scope),
            'eligible_van_pool' => $this->eligibleVanReady($scope),
            'mobile_compatibility' => $this->configEnabled('pilot.mobile_compatible', $scope),
            'live_location_health' => $this->configEnabled('pilot.live_location_healthy', $scope),
            'finance_policy' => $this->configEnabled('pilot.finance_policy_ready', $scope),
            'support_permissions' => $this->configEnabled('pilot.support_permissions_ready', $scope),
        ];

        $failed = array_keys(array_filter($checks, fn (bool $ok): bool => ! $ok));
        $activation = $this->resolveActivation($scope);

        $state = self::STATE_NOT_READY;
        if ($activation['suspended']) {
            $state = self::STATE_SUSPENDED;
        } elseif ($failed === []) {
            $state = $activation['enabled'] ? self::STATE_ACTIVE : self::STATE_READY;
        }

        return [
            'state' => $state,
            'ready' => $failed === [],
            'active' => $state === self::STATE_ACTIVE,
            'failed_checks' => $failed,
            'checks' => $checks,
            'activation' => $activation,
        ];
    }

    /** @param array<string,string|int> $scope */
    public function activate(User $actor, string $scopeType, string $scopeKey, ?string $reason = null, ?Request $request = null): array
    {
        $scope = [$scopeType => $scopeKey];
        $readiness = $this->evaluate($scope);

        if (! $readiness['ready']) {
            throw ValidationException::withMessages([
                'readiness' => ['Pilot activation is fail-closed until all required readiness checks pass.'],
            ]);
        }

        $this->ensureDefinitions();

        $draft = $this->config->createDraft(
            $actor,
            'pilot.activation.enabled',
            $scopeType,
            $scopeKey,
            true,
            $reason ?? 'pilot activation',
            request: $request,
        );
        $published = $this->config->publish($actor, $draft, $request);

        $this->audit->record(
            'field_operations.pilot.activated',
            $actor,
            $published,
            null,
            ['scope_type' => $scopeType, 'scope_key' => $scopeKey],
            $request,
        );

        return $this->evaluate($scope);
    }

    /** @param array<string,string|int> $scope */
    private function resolveActivation(array $scope): array
    {
        $this->ensureDefinitions();

        $enabled = $this->config->resolve('pilot.activation.enabled', $scope);
        $suspended = $this->config->resolve('pilot.activation.suspended', $scope);

        return [
            'enabled' => (bool) $enabled['value'],
            'suspended' => (bool) $suspended['value'],
            'source' => [
                'enabled' => [
                    'scope_type' => $enabled['scope_type'],
                    'scope_key' => $enabled['scope_key'],
                    'revision' => $enabled['revision'],
                ],
                'suspended' => [
                    'scope_type' => $suspended['scope_type'],
                    'scope_key' => $suspended['scope_key'],
                    'revision' => $suspended['revision'],
                ],
            ],
        ];
    }

    private function ensureDefinitions(): void
    {
        $definitions = [
            ['pilot.activation.enabled', false, 'fail_closed'],
            ['pilot.activation.suspended', false, 'fail_closed'],
            ['pilot.mobile_compatible', false, 'fail_closed'],
            ['pilot.live_location_healthy', false, 'fail_closed'],
            ['pilot.finance_policy_ready', false, 'fail_closed'],
            ['pilot.support_permissions_ready', false, 'fail_closed'],
        ];

        foreach ($definitions as [$key, $default, $policy]) {
            $this->config->upsertDefinition(
                $key,
                'boolean',
                $default,
                $policy,
                [],
                [],
                false,
                'Field Operations pilot readiness/activation control.',
            );
        }
    }

    /** @param array<string,string|int> $scope */
    private function configEnabled(string $key, array $scope): bool
    {
        $this->ensureDefinitions();

        return (bool) $this->config->resolve($key, $scope)['value'];
    }

    /** @param array<string,string|int> $scope */
    private function addressMappingReady(array $scope): bool
    {
        if (! Schema::hasTable('address_territory_resolutions')) {
            return false;
        }

        $query = DB::table('address_territory_resolutions')
            ->where('serviceability_status', 'serviceable');

        if (isset($scope['territory'])) {
            $query->where('service_territory_id', (int) $scope['territory']);
        }

        return $query->exists();
    }

    /** @param array<string,string|int> $scope */
    private function warehouseReady(array $scope): bool
    {
        if (! Schema::hasTable('warehouses')) {
            return false;
        }

        if (isset($scope['warehouse'])) {
            return DB::table('warehouses')->where('id', (int) $scope['warehouse'])->exists();
        }

        return DB::table('warehouses')->exists();
    }

    /** @param array<string,string|int> $scope */
    private function eligibleVanReady(array $scope): bool
    {
        if (! Schema::hasTable('vans')) {
            return false;
        }

        $query = DB::table('vans')->where('status', 'active');

        if (isset($scope['van'])) {
            $query->where('id', (int) $scope['van']);
        }

        return $query->exists();
    }

    /** @param array<string,string|int> $scope */
    private function tableHasRows(string $table, array $scope, string $column, string $scopeKey): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        $query = DB::table($table);
        if (isset($scope[$scopeKey])) {
            $query->where($column, (int) $scope[$scopeKey]);
        }

        return $query->exists();
    }
}
