<?php

namespace App\Http\Middleware;

use App\Domain\RestaurantSaaS\RestaurantOnboardingService;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContextResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the progressive Restaurant setup path on first-class management pages.
 * Permissions remain authoritative; this middleware only prevents skipping setup stages.
 */
final class EnsureRestaurantSetupStage
{
    public function __construct(
        private readonly RestaurantOnboardingService $onboarding,
        private readonly TenantContextResolver $tenants,
    ) {}

    public function handle(Request $request, Closure $next, string $stage): Response
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $next($request);
        }

        $store = $this->resolveStore($request, $actor);
        if (! $store instanceof Store) {
            return $next($request);
        }

        $status = $this->onboarding->status($store, $actor);
        $launched = (bool) ($status['launched'] ?? false);

        if ($launched) {
            return $next($request);
        }

        $allowed = false;
        if ($stage === 'launched') {
            $allowed = false;
        } elseif ($stage === 'qr') {
            $entry = collect($status['setup_flow'] ?? [])->firstWhere('key', 'entry');
            $qr = collect($status['gates'] ?? [])->firstWhere('key', 'qr');
            $allowed = (bool) ($entry['available'] ?? false) && (bool) ($qr['required'] ?? false);
        } else {
            $step = collect($status['setup_flow'] ?? [])->firstWhere('key', $stage);
            $allowed = (bool) ($step['available'] ?? false);
        }

        if ($allowed) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('restaurant_onboarding.validation.step_locked'),
                'current_step' => $status['current_step'] ?? 'identity',
            ], 409);
        }

        $params = ['store_id' => (int) $store->getKey()];
        if ($request->boolean('support_access')) {
            $params['support_access'] = 1;
        }

        return redirect()
            ->to(route('admin.smart-ordering.index', $params).'#settings-launch')
            ->withErrors(['setup' => __('restaurant_onboarding.validation.step_locked')]);
    }

    private function resolveStore(Request $request, User $actor): ?Store
    {
        $storeId = (int) $request->integer('store_id');

        $routeSite = $request->route('site');
        if ($storeId <= 0 && $routeSite instanceof SmartOrderingSite) {
            $storeId = (int) $routeSite->store_id;
        } elseif ($storeId <= 0 && (is_int($routeSite) || (is_string($routeSite) && ctype_digit($routeSite)))) {
            $storeId = (int) (SmartOrderingSite::query()->whereKey((int) $routeSite)->value('store_id') ?? 0);
        }

        $siteKey = trim((string) $request->query('site', ''));
        if ($storeId <= 0 && $siteKey !== '') {
            $storeId = (int) (SmartOrderingSite::query()
                ->where('public_id', $siteKey)
                ->orWhere('slug', $siteKey)
                ->value('store_id') ?? 0);
        }

        if ($storeId <= 0) {
            $storeId = (int) ($this->tenants->retailStoreIds($actor)[0] ?? 0);
        }

        return $storeId > 0 ? Store::query()->find($storeId) : null;
    }
}
