<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AdminNavigation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureManagementDashboardAccess
{
    public function __construct(private readonly AdminNavigation $navigation)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);
        abort_unless($user->is_active, 403);
        abort_unless($this->navigation->canUseDashboard($user), 403);

        return $next($request);
    }
}
