<?php

namespace App\Providers;

use App\Domain\Updater\LaravelUpdateRuntime;
use App\Domain\Updater\UpdateRuntime;
use App\Models\User;
use App\Services\DatabaseTranslationLoader;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Contracts\Translation\Loader;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UpdateRuntime::class, LaravelUpdateRuntime::class);
        $this->app->extend(
            'translation.loader',
            static fn (Loader $loader): Loader => new DatabaseTranslationLoader($loader),
        );
    }

    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $email = Str::lower((string) $request->input('email', ''));

            return [
                Limit::perMinute(20)->by('login-ip:'.$request->ip()),
                Limit::perMinute(5)->by('login:'.$email.'|'.$request->ip()),
            ];
        });

        foreach (array_keys((array) config('permissions.abilities', [])) as $ability) {
            Gate::define(
                $ability,
                static fn (User $user, ?int $storeId = null): bool => $user->hasPermission($ability, $storeId),
            );
        }
    }
}
