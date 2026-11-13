<?php

namespace App\Domain\WhatsApp;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OperationalTenantScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DistributionRuleManager
{
    public function __construct(
        private readonly AgentAssignmentEngine $assignments,
        private readonly OperationalTenantScope $scope,
        private readonly RuntimePolicy $runtime,
        private readonly AuditLogger $audit,
    ) {}

    /** @return Collection<int, object> */
    public function permittedStores(User $actor): Collection
    {
        if (! $actor->is_active || ! $this->runtime->allowsAgentInbox()) {
            return collect();
        }

        $global = $actor->hasRole('SUPER_ADMIN');
        $viewIds = $this->scope->allowedStoreIds($actor, 'whatsapp.conversations.view');
        $ids = array_values(array_filter(
            $this->scope->allowedStoreIds($actor, 'whatsapp.conversations.assign'),
            static fn (int $id): bool => in_array($id, $viewIds, true)
                && ($global || $actor->hasPermission('whatsapp.customer_service.supervise', $id)),
        ));

        return DB::table('stores')
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    public function assertStore(User $actor, int $storeId): void
    {
        abort_unless($this->runtime->allowsAgentInbox(), 404);
        abort_unless($actor->is_active, 403);
        $stores = $this->permittedStores($actor);
        abort_unless($stores->isNotEmpty(), 403);
        abort_unless($stores->contains(static fn (object $store): bool => (int) $store->id === $storeId), 404);
    }

    /** @return Collection<int, User> */
    public function assignableAgents(User $actor, int $storeId): Collection
    {
        $this->assertStore($actor, $storeId);
        $ids = $this->assignments->manuallyAssignableAgentIds($storeId);

        return User::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(300)
            ->get(['id', 'name', 'is_active']);
    }

    /** @return LengthAwarePaginator<object> */
    public function paginate(User $actor, int $storeId): LengthAwarePaginator
    {
        $this->assertStore($actor, $storeId);

        $rules = DB::table('whats_app_assignment_rules')
            ->where('store_id', $storeId)
            ->orderBy('priority')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        // Restrict display names to agents currently authorized for this
        // exact store; legacy rule IDs must not reveal foreign user identities.
        $allowedAgentIds = $this->assignments->manuallyAssignableAgentIds($storeId);

        // Resolve agent labels once for the bounded page; avoid an N+1
        // lookup for every distribution rule card.
        $agentIds = $rules->getCollection()->flatMap(static function (object $rule): array {
            $configuration = json_decode((string) ($rule->configuration ?? '{}'), true);

            return is_array($configuration) && is_array($configuration['agent_ids'] ?? null)
                ? $configuration['agent_ids']
                : [];
        })->filter(static fn (mixed $id): bool => is_numeric($id) && in_array((int) $id, $allowedAgentIds, true))
            ->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $agentNames = User::query()->whereIn('id', $agentIds)->pluck('name', 'id');

        $rules->getCollection()->transform(function (object $rule) use ($agentNames, $allowedAgentIds): object {
            $configuration = json_decode((string) ($rule->configuration ?? '{}'), true);
            $configuration = is_array($configuration) ? $configuration : [];
            $ids = collect($configuration['agent_ids'] ?? [])
                ->filter(static fn (mixed $id): bool => is_numeric($id) && in_array((int) $id, $allowedAgentIds, true))
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()->values()->all();

            $rule->agent_ids = $ids;
            $rule->agent_names = collect($ids)->map(static fn (int $id): ?string => $agentNames->get($id))->filter()->values()->all();
            $rule->revision = self::revision($rule);
            $rule->editable = self::isManagedConfiguration($rule);

            return $rule;
        });

        return $rules;
    }

    /** @param array<string, mixed> $data */
    public function save(User $actor, array $data, ?int $ruleId = null): int
    {
        $storeId = (int) $data['store_id'];
        $this->assertStore($actor, $storeId);

        $agentIds = collect($data['agent_ids'] ?? [])
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()->values()->all();
        $allowedIds = $this->assignments->manuallyAssignableAgentIds($storeId);
        if (array_diff($agentIds, $allowedIds) !== []) {
            throw ValidationException::withMessages([
                'agent_ids' => [__('whatsapp_supervisor.distribution.errors.agent_scope')],
            ]);
        }

        $payload = [
            'name' => trim((string) $data['name']),
            'priority' => (int) $data['priority'],
            'is_active' => (bool) $data['is_active'],
            'store_id' => $storeId,
            'channel' => 'whatsapp',
            'strategy' => (string) $data['strategy'],
            'conditions' => null,
            'configuration' => json_encode([
                'agent_ids' => $agentIds,
                'managed_by' => 'v2_p202',
            ], JSON_THROW_ON_ERROR),
        ];

        return DB::transaction(function () use ($actor, $data, $payload, $ruleId, $storeId): int {
            $before = null;
            if ($ruleId !== null) {
                $existing = DB::table('whats_app_assignment_rules')
                    ->where('id', $ruleId)->lockForUpdate()->first();
                abort_unless($existing !== null && (int) $existing->store_id === $storeId, 404);
                if (! self::isManagedConfiguration($existing)) {
                    throw ValidationException::withMessages([
                        'rule' => [__('whatsapp_supervisor.distribution.errors.legacy_readonly')],
                    ]);
                }
                if (! hash_equals(self::revision($existing), (string) ($data['revision'] ?? ''))) {
                    throw ValidationException::withMessages([
                        'revision' => [__('whatsapp_supervisor.distribution.errors.stale')],
                    ]);
                }
                $before = (array) $existing;
                DB::table('whats_app_assignment_rules')
                    ->where('id', $ruleId)
                    ->update($payload + ['updated_at' => now()]);
                $id = $ruleId;
            } else {
                $id = (int) DB::table('whats_app_assignment_rules')
                    ->insertGetId($payload + [
                        'created_by' => $actor->getKey(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            $after = (array) DB::table('whats_app_assignment_rules')->where('id', $id)->first();
            $this->audit->record(
                $ruleId === null
                    ? 'whatsapp.distribution_rule_created'
                    : 'whatsapp.distribution_rule_updated',
                $actor,
                null,
                $before,
                $after,
            );

            return $id;
        }, 3);
    }

    public function setActive(User $actor, int $ruleId, int $storeId, bool $active, string $revision): void
    {
        $this->assertStore($actor, $storeId);

        DB::transaction(function () use ($actor, $ruleId, $storeId, $active, $revision): void {
            $existing = DB::table('whats_app_assignment_rules')
                ->where('id', $ruleId)->lockForUpdate()->first();
            abort_unless($existing !== null && (int) $existing->store_id === $storeId, 404);
            if (! self::isManagedConfiguration($existing)) {
                throw ValidationException::withMessages([
                    'rule' => [__('whatsapp_supervisor.distribution.errors.legacy_readonly')],
                ]);
            }
            if (! hash_equals(self::revision($existing), $revision)) {
                throw ValidationException::withMessages([
                    'revision' => [__('whatsapp_supervisor.distribution.errors.stale')],
                ]);
            }
            DB::table('whats_app_assignment_rules')
                ->where('id', $ruleId)->update(['is_active' => $active, 'updated_at' => now()]);
            $this->audit->record(
                'whatsapp.distribution_rule_status_changed',
                $actor,
                null,
                (array) $existing,
                (array) DB::table('whats_app_assignment_rules')->where('id', $ruleId)->first(),
            );
        }, 3);
    }

    public static function revision(object $rule): string
    {
        return hash('sha256', json_encode([
            (int) $rule->id,
            (string) $rule->name,
            (int) $rule->priority,
            (bool) $rule->is_active,
            (string) $rule->strategy,
            (string) $rule->channel,
            (string) $rule->configuration,
            (string) $rule->conditions,
            (string) $rule->updated_at,
        ], JSON_THROW_ON_ERROR));
    }

    public static function isManagedConfiguration(object $rule): bool
    {
        $configuration = json_decode((string) ($rule->configuration ?? '{}'), true);
        $conditions = json_decode((string) ($rule->conditions ?? 'null'), true);

        return is_array($configuration)
            && ($configuration['managed_by'] ?? null) === 'v2_p202'
            && ($conditions === null || $conditions === []);
    }
}
