<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\User;
use App\Support\TenantContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AppPreviewTargetService
{
    private const MAX_RESULTS = 50;

    public function __construct(
        private readonly TenantContextResolver $tenantResolver,
        private readonly WholesalePrincipal $wholesalePrincipal,
    ) {}

    /**
     * Target discovery is advisory only. AppPreviewSessionService remains the
     * authorization authority and revalidates the selected target on create.
     *
     * @return list<array<string,mixed>>
     */
    public function discover(
        User $actor,
        string $targetType,
        string $channel,
        ?int $requestedStoreId,
        bool $supportAccess,
        Request $request,
        ?string $search = null,
    ): array {
        $targetType = strtolower(trim($targetType));
        $channel = strtolower(trim($channel));

        abort_unless(in_array($targetType, ['customer', 'driver'], true), 422);
        abort_unless(in_array($channel, ['b2b', 'b2c'], true), 422);

        $storeId = $this->authorizedStore(
            $actor,
            $channel,
            $requestedStoreId,
            $supportAccess,
            $request,
        );

        abort_unless($actor->hasPermission('app_preview.view', $storeId), 403);
        abort_unless(
            $actor->hasPermission("app_preview.impersonate_{$targetType}", $storeId),
            403,
        );

        $query = $search === null ? '' : trim($search);

        return $targetType === 'driver'
            ? $this->drivers($channel, $storeId, $query)
            : $this->customers($actor, $channel, $storeId, $supportAccess, $query);
    }

    private function authorizedStore(
        User $actor,
        string $channel,
        ?int $requestedStoreId,
        bool $supportAccess,
        Request $request,
    ): int {
        if ($channel === 'b2b') {
            $storeId = $this->wholesalePrincipal->storeId();
            abort_if(
                $requestedStoreId !== null && $requestedStoreId !== $storeId,
                422,
                'Wholesale preview uses the canonical principal store.',
            );
            $this->tenantResolver->wholesale($actor, $storeId);

            return $storeId;
        }

        abort_if($requestedStoreId === null, 422, 'Retail preview requires a store.');
        $this->tenantResolver->retail($actor, $requestedStoreId, $supportAccess, $request);

        return $requestedStoreId;
    }

    /** @return list<array<string,mixed>> */
    private function drivers(string $channel, int $storeId, string $search): array
    {
        $drivers = Driver::query()
            ->with('user')
            ->where('driver_type', $channel)
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->when($search !== '', fn ($query) => $query->whereHas(
                'user',
                fn ($userQuery) => $userQuery->where('name', 'like', '%'.$search.'%'),
            ))
            ->orderBy('id')
            ->limit(self::MAX_RESULTS * 2)
            ->get();

        $permission = "deliveries.{$channel}.execute";

        return $drivers
            ->filter(fn (Driver $driver): bool => $driver->user instanceof User
                && $driver->user->hasPermission($permission))
            ->take(self::MAX_RESULTS)
            ->map(fn (Driver $driver): array => [
                'user_id' => (int) $driver->user_id,
                'name' => (string) $driver->user->name,
                'locale' => (string) ($driver->user->locale ?: 'ar'),
                'driver_id' => (int) $driver->getKey(),
                'channel' => $channel,
                'store_id' => $storeId,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function customers(
        User $actor,
        string $channel,
        int $storeId,
        bool $supportAccess,
        string $search,
    ): array {
        if ($channel === 'b2b') {
            $customerIds = DB::table('b2b_customers')
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->merge(
                    DB::table('platform_customers')
                        ->where('is_active', true)
                        ->whereNotNull('user_id')
                        ->pluck('user_id'),
                )
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();
        } else {
            $customerIds = DB::table('b2c_customers')
                ->where('store_id', $storeId)
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->map(fn ($id): int => (int) $id);

            if ($actor->hasRole('SUPER_ADMIN') && $supportAccess) {
                $customerIds = $customerIds
                    ->merge(
                        DB::table('platform_customers')
                            ->where('is_active', true)
                            ->whereNotNull('user_id')
                            ->pluck('user_id')
                            ->map(fn ($id): int => (int) $id),
                    )
                    ->unique()
                    ->values();
            }
        }

        if ($customerIds->isEmpty()) {
            return [];
        }

        return User::query()
            ->whereIn('id', $customerIds->all())
            ->where('is_active', true)
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'name', 'locale'])
            ->map(fn (User $user): array => [
                'user_id' => (int) $user->getKey(),
                'name' => (string) $user->name,
                'locale' => (string) ($user->locale ?: 'ar'),
                'channel' => $channel,
                'store_id' => $storeId,
            ])
            ->values()
            ->all();
    }
}
