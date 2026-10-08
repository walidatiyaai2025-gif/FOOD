<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\VanRuntimeContextResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureVanRuntimeAccess
{
    public function __construct(private readonly VanRuntimeContextResolver $contexts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);
        abort_unless($user->hasPermission('van.login'), 403, 'Van App access is not enabled for this account.');
        abort_unless($user->tokenCan('app:van'), 403, 'This token is not authorized for the Van App.');

        $context = $this->contexts->resolve($user);
        abort_unless($context['selected'] !== null, 403, 'No effective Van assignment is available for this account.');

        $request->attributes->set('van_runtime_context', $context['selected']);

        return $next($request);
    }
}
