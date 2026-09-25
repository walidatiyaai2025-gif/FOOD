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

        if (is_string($customerType)) {
            $apps[] = 'customer';
            $channels[] = strtolower($customerType);
        }

        if (is_string($driverType)) {
            $apps[] = 'driver';
            $channels[] = strtolower($driverType);
        }

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
            });
    }
}
