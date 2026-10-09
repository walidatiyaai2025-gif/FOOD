<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class NotificationAudience
{
    public function __construct(private readonly VanRuntimeContextResolver $vanRuntimeContexts) {}

    public function apply(Builder $query, User $user): Builder
    {
        $customerChannels = collect(
            DB::table('customers')
                ->where('user_id', $user->id)
                ->pluck('type'),
        )
            ->when(
                DB::table('b2b_customers')->where('user_id', $user->id)->exists(),
                fn ($channels) => $channels->push('b2b'),
            )
            ->when(
                DB::table('b2c_customers')->where('user_id', $user->id)->exists(),
                fn ($channels) => $channels->push('b2c'),
            )
            ->filter(fn ($channel): bool => is_string($channel)
                && in_array(strtolower($channel), ['b2b', 'b2c'], true))
            ->map(static fn (string $channel): string => strtolower($channel))
            ->unique()
            ->values();

        $driverChannels = collect(
            DB::table('drivers')
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->pluck('driver_type'),
        )
            ->filter(fn ($channel): bool => is_string($channel)
                && in_array(strtolower($channel), ['b2b', 'b2c'], true))
            ->map(static fn (string $channel): string => strtolower($channel))
            ->unique()
            ->values();

        $apps = [];
        $channels = $customerChannels
            ->merge($driverChannels)
            ->unique()
            ->values()
            ->all();
        $dashboardStoreIds = [];

        $isCustomer = $customerChannels->isNotEmpty();
        $isDriver = $driverChannels->isNotEmpty();
        $isVan = false;

        if (
            $user->hasPermission('van.login')
            || $user->hasRole('B2B_DRIVER')
            || $user->hasRole('B2C_DRIVER')
        ) {
            $vanContext = $this->vanRuntimeContexts->resolve($user)['selected'];
            $isVan = $vanContext !== null
                && $this->vanRuntimeContexts->canUseRuntime($user, $vanContext);
        }

        if ($isCustomer) {
            $apps[] = 'customer';
        }

        if ($isDriver) {
            $apps[] = 'driver';
        }

        if ($isVan) {
            $apps[] = 'van';
            $channels[] = 'b2b';
        }

        $dashboardRoles = array_values((array) config('admin.dashboard_roles', []));
        $isDashboardUser = $user->roles()
            ->where('roles.is_active', true)
            ->whereIn('roles.code', $dashboardRoles)
            ->exists()
            || $user->storeRoleAssignments()
                ->whereHas('role', fn (Builder $roles) => $roles
                    ->where('is_active', true)
                    ->whereIn('code', $dashboardRoles))
                ->exists();

        if ($isDashboardUser) {
            $apps[] = 'dashboard';
            foreach (['b2b', 'b2c'] as $dashboardChannel) {
                $allowed = collect(['notifications.view', 'orders.view', 'finance.view'])
                    ->flatMap(fn (string $permission): array => app(OperationalTenantScope::class)
                        ->allowedStoreIds($user, $permission, $dashboardChannel))
                    ->map(static fn ($id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                if ($allowed !== []) {
                    $channels[] = $dashboardChannel;
                    $dashboardStoreIds = array_merge($dashboardStoreIds, $allowed);
                }
            }
        }

        $storeIds = collect(DB::table('user_store_roles')
            ->where('user_id', $user->id)
            ->pluck('store_id'))
            ->merge(DB::table('b2c_customers')->where('user_id', $user->id)->pluck('store_id'))
            ->merge(DB::table('drivers')->where('user_id', $user->id)->whereNotNull('store_id')->pluck('store_id'))
            ->merge($dashboardStoreIds)
            ->merge(
                DB::table('orders')
                    ->join('b2b_customers', 'b2b_customers.id', '=', 'orders.b2b_customer_id')
                    ->where('b2b_customers.user_id', $user->id)
                    ->pluck('orders.store_id'),
            )
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $query
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(function (Builder $audience) use ($user, $isCustomer, $isDriver, $isVan): void {
                $audience->where('audience', 'all')
                    ->orWhere('user_id', $user->id);

                if ($isCustomer) {
                    $audience->orWhere('audience', 'customer');
                }

                if ($isDriver) {
                    $audience->orWhere('audience', 'driver');
                }

                if ($isVan) {
                    $audience->orWhere('audience', 'van');
                }
            })
            ->where(function (Builder $app) use ($apps): void {
                $app->where('app', 'all');
                if ($apps !== []) {
                    $app->orWhereIn('app', array_values(array_unique($apps)));
                }
            })
            ->where(function (Builder $channel) use ($channels): void {
                $channel->where('target_channel', 'all');
                if ($channels !== []) {
                    $channel->orWhereIn('target_channel', array_values(array_unique($channels)));
                }
            })
            ->where(function (Builder $scope) use ($user, $storeIds): void {
                $scope->whereNull('store_id')
                    ->orWhere('user_id', $user->id);

                if ($storeIds !== []) {
                    $scope->orWhereIn('store_id', $storeIds);
                }
            })
            ->where(function (Builder $targeting) use ($user): void {
                $targeting->whereNull('data->eligible_user_ids')
                    ->orWhereJsonContains('data->eligible_user_ids', (int) $user->id);
            });
    }
}
