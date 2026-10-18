<?php

namespace App\Domain\WhatsApp;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AgentAssignmentEngine
{
    /** @return list<int> */
    public function eligibleAgentIds(?int $storeId, ?string $channel = null): array
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(function (User $user) use ($storeId): bool {
                if (! $user->hasPermission('whatsapp.conversations.view', $storeId)) {
                    return false;
                }

                $profile = DB::table('whats_app_agent_profiles')->where('user_id', $user->getKey())->first();
                if ($profile === null
                    || ! (bool) $profile->accepts_new
                    || strtoupper((string) $profile->status) !== 'ONLINE') {
                    return false;
                }

                return $this->activeLoad((int) $user->getKey()) < max(1, (int) $profile->capacity);
            })
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /** @return list<int> */
    public function manuallyAssignableAgentIds(?int $storeId): array
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $this->canWorkConversation($user, $storeId))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public function activeLoad(int $agentId): int
    {
        return DB::table('whats_app_conversations')
            ->where('primary_agent_id', $agentId)
            ->whereNotIn('status', ['RESOLVED', 'CLOSED'])
            ->count();
    }

    /** @return array{id:int,name:string,status:string,capacity:int,load:int,accepts_new:bool,auto_eligible:bool} */
    public function assertManuallyAssignable(int $agentId, ?int $storeId): array
    {
        $agent = User::query()->whereKey($agentId)->where('is_active', true)->first();

        if (! $agent instanceof User || ! $this->canWorkConversation($agent, $storeId)) {
            throw ValidationException::withMessages([
                'agent_id' => [__('whatsapp.inbox.errors.agent_scope')],
            ]);
        }

        return $this->summary($agent, $storeId);
    }

    /** @return array{id:int,name:string,status:string,capacity:int,load:int} */
    public function assertEligible(int $agentId, ?int $storeId): array
    {
        $agent = User::query()->whereKey($agentId)->where('is_active', true)->first();

        if (! $agent instanceof User || ! $agent->hasPermission('whatsapp.conversations.view', $storeId)) {
            throw ValidationException::withMessages([
                'agent_id' => [__('whatsapp.inbox.errors.agent_scope')],
            ]);
        }

        $profile = DB::table('whats_app_agent_profiles')->where('user_id', $agentId)->first();
        if ($profile === null
            || ! (bool) $profile->accepts_new
            || strtoupper((string) $profile->status) !== 'ONLINE') {
            throw ValidationException::withMessages([
                'agent_id' => [__('whatsapp.inbox.errors.agent_unavailable')],
            ]);
        }

        $load = $this->activeLoad($agentId);
        if ($load >= max(1, (int) $profile->capacity)) {
            throw ValidationException::withMessages([
                'agent_id' => [__('whatsapp.inbox.errors.agent_capacity')],
            ]);
        }

        return [
            'id' => $agentId,
            'name' => (string) $agent->name,
            'status' => (string) $profile->status,
            'capacity' => (int) $profile->capacity,
            'load' => $load,
        ];
    }

    public function choose(?int $storeId, ?string $channel, string $strategy = 'rule_based'): ?int
    {
        $eligible = $this->eligibleAgentIds($storeId, $channel);
        if ($eligible === []) {
            return null;
        }

        if (in_array($strategy, ['rule_based', 'store_based', 'channel_based'], true)) {
            $rule = DB::table('whats_app_assignment_rules')
                ->where('is_active', true)
                ->where(function ($query) use ($storeId): void {
                    $query->whereNull('store_id');
                    if ($storeId !== null) {
                        $query->orWhere('store_id', $storeId);
                    }
                })
                ->where(function ($query) use ($channel): void {
                    $query->whereNull('channel');
                    if ($channel !== null) {
                        $query->orWhere('channel', $channel);
                    }
                })
                ->orderBy('priority')
                ->orderBy('id')
                ->first();

            if ($rule !== null) {
                $configuration = json_decode((string) ($rule->configuration ?? '{}'), true);
                $preferred = collect(is_array($configuration) ? ($configuration['agent_ids'] ?? []) : [])
                    ->filter(fn ($id): bool => is_numeric($id) && in_array((int) $id, $eligible, true))
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all();

                if ($preferred !== []) {
                    $eligible = $preferred;
                }

                $strategy = (string) $rule->strategy;
            } else {
                $strategy = 'round_robin';
            }
        }

        if ($strategy === 'least_loaded') {
            $ranked = collect($eligible)
                ->map(fn (int $id): array => ['id' => $id, 'load' => $this->activeLoad($id)])
                ->sortBy([['load', 'asc'], ['id', 'asc']])
                ->values();

            return $ranked->isEmpty() ? null : (int) $ranked->first()['id'];
        }

        sort($eligible);
        $last = DB::table('whats_app_assignment_history')
            ->when($storeId !== null, fn ($query) => $query->where('store_id', $storeId))
            ->when($channel !== null, fn ($query) => $query->where('channel', $channel))
            ->whereNotNull('new_primary_agent_id')
            ->orderByDesc('id')
            ->value('new_primary_agent_id');

        $position = $last === null ? false : array_search((int) $last, $eligible, true);
        $index = $position === false ? 0 : (((int) $position + 1) % count($eligible));

        return $eligible[$index];
    }

    public function setStatus(User $actor, string $status, int $capacity, bool $acceptsNew): void
    {
        $status = strtoupper($status);
        if (! in_array($status, ['ONLINE', 'BUSY', 'OFFLINE', 'AWAY'], true)) {
            throw ValidationException::withMessages(['status' => [__('whatsapp.inbox.errors.invalid_agent_status')]]);
        }

        DB::table('whats_app_agent_profiles')->updateOrInsert(
            ['user_id' => $actor->getKey()],
            [
                'status' => $status,
                'capacity' => max(1, min(500, $capacity)),
                'accepts_new' => $acceptsNew,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /** @return Collection<int, covariant object> */
    public function summaries(?int $storeId): Collection
    {
        $ids = $this->eligibleAgentIds($storeId);

        return User::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): object => (object) $this->summary($user, $storeId));
    }

    /** @return Collection<int, covariant object> */
    public function manualAssignmentSummaries(?int $storeId): Collection
    {
        $ids = $this->manuallyAssignableAgentIds($storeId);

        return User::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): object => (object) $this->summary($user, $storeId));
    }

    private function canWorkConversation(User $user, ?int $storeId): bool
    {
        return $user->hasPermission('whatsapp.conversations.view', $storeId)
            && $user->hasPermission('whatsapp.conversations.reply', $storeId);
    }

    /** @return array{id:int,name:string,status:string,capacity:int,load:int,accepts_new:bool,auto_eligible:bool} */
    private function summary(User $user, ?int $storeId): array
    {
        $profile = DB::table('whats_app_agent_profiles')->where('user_id', $user->getKey())->first();
        $status = strtoupper((string) ($profile->status ?? 'OFFLINE'));
        $capacity = max(0, (int) ($profile->capacity ?? 0));
        $load = $this->activeLoad((int) $user->getKey());
        $acceptsNew = (bool) ($profile->accepts_new ?? false);

        return [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->name,
            'status' => $status,
            'capacity' => $capacity,
            'load' => $load,
            'accepts_new' => $acceptsNew,
            'auto_eligible' => $status === 'ONLINE'
                && $acceptsNew
                && $capacity > 0
                && $load < $capacity
                && $this->canWorkConversation($user, $storeId),
        ];
    }
}
