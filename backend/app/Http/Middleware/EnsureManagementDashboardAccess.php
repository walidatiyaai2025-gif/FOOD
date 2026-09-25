<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AdminNavigation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class EnsureManagementDashboardAccess
{
    public function __construct(private readonly AdminNavigation $navigation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        App::setLocale(in_array($user->locale, ['ar', 'en'], true) ? $user->locale : 'ar');

        abort_unless($user->is_active, 403);
        abort_unless($this->navigation->canUseDashboard($user), 403);

        return $next($request);
    }
}
