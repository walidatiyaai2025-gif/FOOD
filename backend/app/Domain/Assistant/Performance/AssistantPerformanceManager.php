<?php

namespace App\Domain\Assistant\Performance;

use App\Domain\Assistant\Data\AiPrincipalContext;
use Illuminate\Support\Facades\Cache;

final class AssistantPerformanceManager
{
    public function acquireProvider(
        ?int $actorUserId,
        ?string $tenantKey,
        ?string $correlationId = null,
    ): AssistantProviderCapacityLease {
        if (! (bool) config('assistant.performance.enabled', true)) {
            return new AssistantProviderCapacityLease([], true);
        }

        $leaseSeconds = max(5, min(120, (int) config('assistant.performance.provider_lease_seconds', 120)));
        $owner = trim((string) $correlationId) !== '' ? (string) $correlationId : bin2hex(random_bytes(8));
        $acquired = [];

        $scopes = [
            ['global', max(1, (int) config('assistant.performance.provider_global_concurrency', 12))],
        ];

        if (is_string($tenantKey) && trim($tenantKey) !== '') {
            $scopes[] = ['tenant:'.hash('sha256', $tenantKey), max(1, (int) config('assistant.performance.provider_tenant_concurrency', 4))];
        }

        if (is_int($actorUserId) && $actorUserId > 0) {
            $scopes[] = ['user:'.$actorUserId, max(1, (int) config('assistant.performance.provider_user_concurrency', 2))];
        }

        foreach ($scopes as [$scope, $limit]) {
            $slot = $this->claimSlot((string) $scope, (int) $limit, $owner, $leaseSeconds);

            if ($slot === null) {
                foreach (array_reverse($acquired, true) as $key => $acquiredOwner) {
                    if (Cache::get($key) === $acquiredOwner) {
                        Cache::forget($key);
                    }
                }

                return new AssistantProviderCapacityLease([], false);
            }

            $acquired[$slot] = $owner;
        }

        return new AssistantProviderCapacityLease($acquired, true);
    }

    public function tenantKey(AiPrincipalContext $principal): string
    {
        $stores = $principal->permittedStoreIds;
        sort($stores);

        return implode('|', [
            $principal->businessScope,
            $principal->channel ?? 'all',
            $principal->activeStoreId === null ? 'none' : (string) $principal->activeStoreId,
            implode(',', $stores),
        ]);
    }

    public function cancel(int $actorUserId, string $correlationId): void
    {
        Cache::put(
            $this->cancelKey($actorUserId, $correlationId),
            true,
            max(30, min(900, (int) config('assistant.performance.cancellation_ttl_seconds', 300))),
        );
    }

    public function isCancelled(int $actorUserId, string $correlationId): bool
    {
        return Cache::get($this->cancelKey($actorUserId, $correlationId), false) === true;
    }

    public function registerActiveRequest(int $actorUserId, string $conversationPublicId, string $correlationId): void
    {
        Cache::put(
            $this->activeKey($actorUserId, $conversationPublicId),
            $correlationId,
            max(30, min(900, (int) config('assistant.performance.cancellation_ttl_seconds', 300))),
        );
    }

    public function cancelActiveRequest(int $actorUserId, string $conversationPublicId): bool
    {
        $correlationId = Cache::get($this->activeKey($actorUserId, $conversationPublicId));

        if (! is_string($correlationId) || trim($correlationId) === '') {
            return false;
        }

        $this->cancel($actorUserId, $correlationId);

        return true;
    }

    public function clearActiveRequest(int $actorUserId, string $conversationPublicId, string $correlationId): void
    {
        $key = $this->activeKey($actorUserId, $conversationPublicId);

        if (Cache::get($key) === $correlationId) {
            Cache::forget($key);
        }
    }

    public function hardLatencyBudgetMs(int $plannedToolCount): int
    {
        $key = $plannedToolCount <= 1 ? 'simple_hard_ms' : 'analysis_hard_ms';
        $default = $plannedToolCount <= 1 ? 6000 : 12000;

        return max(3000, min(30000, (int) config('assistant.performance.'.$key, $default)));
    }

    /** @return array{class:string,target_p50_ms:int,target_p95_ms:int,hard_ms:int,elapsed_ms:int} */
    public function latencyMetadata(int $plannedToolCount, int $elapsedMs): array
    {
        $simple = $plannedToolCount <= 1;

        return [
            'class' => $simple ? 'simple_lookup' : 'multi_tool_analysis',
            'target_p50_ms' => (int) config('assistant.performance.'.($simple ? 'simple_p50_ms' : 'analysis_p50_ms'), $simple ? 900 : 3500),
            'target_p95_ms' => (int) config('assistant.performance.'.($simple ? 'simple_p95_ms' : 'analysis_p95_ms'), $simple ? 2500 : 8000),
            'hard_ms' => $this->hardLatencyBudgetMs($plannedToolCount),
            'elapsed_ms' => $elapsedMs,
        ];
    }

    private function claimSlot(
        string $scope,
        int $limit,
        string $owner,
        int $leaseSeconds,
    ): ?string {
        for ($slot = 1; $slot <= $limit; $slot++) {
            $key = 'assistant:provider:capacity:'.$scope.':'.$slot;

            if (Cache::add($key, $owner, $leaseSeconds)) {
                return $key;
            }
        }

        return null;
    }

    private function cancelKey(int $actorUserId, string $correlationId): string
    {
        return 'assistant:cancel:'.$actorUserId.':'.hash('sha256', $correlationId);
    }

    private function activeKey(int $actorUserId, string $conversationPublicId): string
    {
        return 'assistant:active:'.$actorUserId.':'.hash('sha256', $conversationPublicId);
    }
}
