<?php

namespace App\Providers;

use App\Domain\Assistant\Business\AssistantBusinessToolSet;
use App\Domain\Assistant\Contracts\AssistantBrainInterface;
use App\Domain\Assistant\Conversation\DeterministicBrain;
use App\Domain\Assistant\Operations\AssistantOperationsToolSet;
use App\Domain\Assistant\Tools\AssistantToolRegistry;
use App\Domain\RestaurantSaaS\RestaurantIntegrationService;
use App\Domain\SmartOrdering\SmartOrderingLoyaltyOrderObserver;
use App\Domain\Updater\LaravelUpdateRuntime;
use App\Domain\Updater\UpdateRuntime;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Search\GlobalSearchRegistry;
use App\Enterprise\Search\OrderGlobalSearchProvider;
use App\Enterprise\Search\OrganizationGlobalSearchProvider;
use App\Models\B2bCustomer;
use App\Models\B2cCustomer;
use App\Models\Order;
use App\Models\User;
use App\Policies\B2bCustomerPolicy;
use App\Policies\B2cCustomerPolicy;
use App\Services\DatabaseTranslationLoader;
use App\Support\StoreContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ModuleEntitlementResolver::class);
        $this->app->scoped(GlobalSearchRegistry::class, function ($app): GlobalSearchRegistry {
            $registry = new GlobalSearchRegistry(
                $app->make(ModuleRegistry::class),
                $app->make(ModuleEntitlementResolver::class),
            );

            return $registry
                ->registerCore('orders', fn (): OrderGlobalSearchProvider => $app->make(OrderGlobalSearchProvider::class))
                ->registerCore('organizations', fn (): OrganizationGlobalSearchProvider => $app->make(OrganizationGlobalSearchProvider::class));
        });
        $this->app->singleton(AssistantToolRegistry::class, function ($app): AssistantToolRegistry {
            return new AssistantToolRegistry([
                ...$app->make(AssistantBusinessToolSet::class)->all(),
                ...$app->make(AssistantOperationsToolSet::class)->all(),
            ]);
        });
        $this->app->bind(AssistantBrainInterface::class, DeterministicBrain::class);
        $this->app->scoped(StoreContext::class, static fn (): StoreContext => new StoreContext);
        $this->app->bind(UpdateRuntime::class, LaravelUpdateRuntime::class);
        $this->app->extend(
            'translation.loader',
            static fn (Loader $loader): Loader => new DatabaseTranslationLoader($loader),
        );
    }

    public function boot(): void
    {
        Paginator::defaultView('pagination.foodex');
        Paginator::defaultSimpleView('pagination.foodex-simple');

        Gate::policy(B2bCustomer::class, B2bCustomerPolicy::class);
        Gate::policy(B2cCustomer::class, B2cCustomerPolicy::class);
        Order::observe(SmartOrderingLoyaltyOrderObserver::class);

        RateLimiter::for('login', function (Request $request): array {
            $email = Str::lower((string) $request->input('email', ''));

            return [
                Limit::perMinute(20)->by('login-ip:'.$request->ip()),
                Limit::perMinute(5)->by('login:'.$email.'|'.$request->ip()),
            ];
        });

        RateLimiter::for('assistant', function (Request $request): Limit {
            $userId = $request->user()?->getAuthIdentifier();
            $key = $userId === null ? 'assistant:ip:'.$request->ip() : 'assistant:user:'.$userId;

            return Limit::perMinute(max(1, (int) config('assistant.rate_limit', 30)))->by($key);
        });

        RateLimiter::for('restaurant-integration-api', function (Request $request): Limit {
            $credential = (string) $request->header('X-Restaurant-App-Key', '');
            $publicId = Str::before($credential, '.');
            $key = Str::isUuid($publicId) ? $publicId : $request->ip();

            return Limit::perMinute(120)
                ->by('restaurant-integration:'.$key)
                ->response(function (Request $request, array $headers) use ($publicId) {
                    if (Str::isUuid($publicId)) {
                        app(RestaurantIntegrationService::class)->recordRateLimitViolation($publicId);
                    }

                    return response()->json(['message' => 'Too Many Requests.'], 429, $headers);
                });
        });

        foreach (array_keys((array) config('permissions.abilities', [])) as $ability) {
            Gate::define(
                $ability,
                static fn (User $user, ?int $storeId = null): bool => $user->hasPermission($ability, $storeId),
            );
        }
    }
}
