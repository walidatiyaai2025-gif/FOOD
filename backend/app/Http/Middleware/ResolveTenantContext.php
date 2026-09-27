<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Models\User;
use App\Support\StoreContext;
use App\Support\TenantContextResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTenantContext
{
    public function __construct(
        private readonly TenantContextResolver $resolver,
        private readonly StoreContext $context,
    ) {}

    public function handle(Request $request, Closure $next, string $channel): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $channel = strtolower($channel);
        abort_unless(in_array($channel, ['b2b', 'b2c'], true), 500, 'Invalid tenant context channel.');

        $storeId = $this->requestedStoreId($request);

        if ($channel === 'b2b') {
            $this->context->set($this->resolver->wholesale($user, $storeId));

            return $next($request);
        }

        $storeId ??= $this->resolver->defaultRetailStoreId($user);
        abort_if($storeId === null, 409, 'Select an authorized retail store.');

        $supportAccess = $request->boolean('support_access')
            || $request->header('X-FOODEX-Support-Access') === '1';

        $this->context->set($this->resolver->retail($user, $storeId, $supportAccess, $request));

        return $next($request);
    }

    private function requestedStoreId(Request $request): ?int
    {
        $routeStore = $request->route('store');

        if ($routeStore instanceof Store) {
            return (int) $routeStore->getKey();
        }

        if (is_numeric($routeStore)) {
            return (int) $routeStore;
        }

        $input = $request->input('store_id', $request->query('store_id'));

        if (is_numeric($input)) {
            return (int) $input;
        }

        $header = $request->header('X-FOODEX-Store-ID');

        return is_numeric($header) ? (int) $header : null;
    }
}
