<?php

namespace App\Domain\Assistant\Intelligence;

use App\Domain\Assistant\Intelligence\Models\AssistantIntelligenceFinding;
use App\Domain\Assistant\Security\RetailerSellOutAccessPolicy;
use App\Models\User;
use App\Services\AssistantRuntimeSettings;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AssistantIntelligenceAuthorization
{
    public function __construct(
        private readonly AssistantRuntimeSettings $runtime,
        private readonly RetailerSellOutAccessPolicy $sellOut,
    ) {}

    public function canUse(User $user, ?int $storeId = null, ?string $channel = null): bool
    {
        if (! $this->runtime->enabled() || ! $this->runtime->readOnly() || ! (bool) $user->is_active) {
            return false;
        }

        if ($storeId !== null) {
            if (! $user->hasPermission('assistant.use', $storeId)) {
                return false;
            }

            return $channel === null || $this->storeChannel($storeId) === strtolower($channel);
        }

        if ($user->hasPermission('assistant.use')) {
            return true;
        }

        return $user->storeRoleAssignments()
            ->pluck('store_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->contains(fn (int $assignedStoreId): bool => $user->hasPermission('assistant.use', $assignedStoreId));
    }

    public function canUseFinding(User $user, AssistantIntelligenceFinding $finding): bool
    {
        return $this->canUseFindingPayload(
            $user,
            $finding->store_id === null ? null : (int) $finding->store_id,
            $finding->channel,
            $finding->evidence,
        );
    }

    /** @param array<string,mixed> $evidence */
    public function canUseFindingPayload(
        User $user,
        ?int $storeId,
        ?string $channel,
        array $evidence,
    ): bool {
        if (! $this->canUse($user, $storeId, $channel)) {
            return false;
        }

        if (strtoupper((string) ($evidence['data_class'] ?? '')) !== 'SELL_OUT') {
            return true;
        }

        $retailerIds = collect((array) ($evidence['retailer_store_ids'] ?? []))
            ->push($evidence['retailer_store_id'] ?? null)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $wholesaleStoreId = filter_var(
            $evidence['wholesale_store_id'] ?? null,
            FILTER_VALIDATE_INT,
        );
        $wholesaleStoreId = is_int($wholesaleStoreId) && $wholesaleStoreId > 0
            ? $wholesaleStoreId
            : null;

        // SELL_OUT evidence without its consent scope is fail-closed. This makes
        // the ingestion contract explicit for #2220 and prevents cached consent.
        if ($retailerIds === [] || $wholesaleStoreId === null) {
            return false;
        }

        try {
            $this->sellOut->assertCanReadMany(
                $user,
                $retailerIds,
                $wholesaleStoreId,
                RetailerSellOutAccessPolicy::PURPOSE_ASSISTANT_ANALYTICS,
                in_array($user->locale, ['ar', 'en'], true) ? $user->locale : 'ar',
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function assertCanUse(User $user, ?int $storeId = null, ?string $channel = null): void
    {
        abort_unless($this->canUse($user, $storeId, $channel), 403);
    }

    /** @param array<string,mixed> $evidence */
    public function assertCanUseFindingPayload(
        User $user,
        ?int $storeId,
        ?string $channel,
        array $evidence,
    ): void {
        abort_unless($this->canUseFindingPayload($user, $storeId, $channel, $evidence), 403);
    }

    private function storeChannel(int $storeId): ?string
    {
        $channel = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->value('store_types.code');

        return is_string($channel) ? strtolower($channel) : null;
    }
}
