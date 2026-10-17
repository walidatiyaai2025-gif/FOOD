<?php

namespace App\Http\Middleware;

use App\Models\AdminMfaCredential;
use App\Models\User;
use App\Services\AdminSecurityPolicy;
use App\Services\AdminSessionRegistry;
use App\Support\AdminNavigation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EnsureManagementDashboardAccess
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly AdminSecurityPolicy $securityPolicy,
        private readonly AdminSessionRegistry $securitySessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->guest(route('admin.b2c.login'));
        }

        App::setLocale(in_array($user->locale, ['ar', 'en'], true) ? $user->locale : 'ar');

        abort_unless($user->is_active, 403);
        abort_unless($this->navigation->canUseDashboard($user), 403);

        $routeName = $request->route()?->getName();
        if (is_string($routeName)
            && ($routeName === 'admin.erp.index' || str_starts_with($routeName, 'admin.erp.'))) {
            abort_unless($this->navigation->canAccessPlatformErp($user), 403);
        }

        try {
            $securitySession = $this->securitySessions->ensure($request, $user);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 403) {
                throw $exception;
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.b2c.login');
        }

        if ($this->securityPolicy->requiresMfa($user) && $securitySession->mfa_verified_at === null) {
            $request->session()->put('mfa_intended', $request->fullUrl());
            $hasMfa = AdminMfaCredential::query()
                ->where('user_id', $user->id)
                ->whereNotNull('enabled_at')
                ->whereNull('revoked_at')
                ->exists();

            return redirect()->route($hasMfa ? 'admin.mfa.challenge' : 'admin.mfa.enroll');
        }

        if ($user->last_seen_at === null || $user->last_seen_at->lt(now()->subMinute())) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
