<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class NotificationAudience
{
    public function apply(Builder $query, User $user): Builder
    {
        $customerType = DB::table('customers')->where('user_id', $user->id)->value('type');
        $driverType = DB::table('drivers')->where('user_id', $user->id)->value('driver_type');

        $apps = [];
        $channels = [];
        $dashboardStoreIds = [];

        if (is_string($customerType)) {
            $apps[] = 'customer';
            $channels[] = strtolower($customerType);
        }

        if (is_string($driverType)) {
            $apps[] = 'driver';
            $channels[] = strtolower($driverType);
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
            ->where(function (Builder $audience) use ($user, $customerType, $driverType): void {
                $audience->where('audience', 'all')
                    ->orWhere('user_id', $user->id);

                if ($customerType !== null) {
                    $audience->orWhere('audience', 'customer');
                }

                if ($driverType !== null) {
                    $audience->orWhere('audience', 'driver');
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
            });
    }
}
