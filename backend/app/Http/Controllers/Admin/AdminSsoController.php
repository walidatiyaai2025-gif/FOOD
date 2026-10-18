<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminMfaCredential;
use App\Models\EnterpriseSsoIdentity;
use App\Models\User;
use App\Services\AdminSecurityPolicy;
use App\Services\AdminSessionRegistry;
use App\Services\AuditLogger;
use App\Services\EnterpriseOidcService;
use App\Support\AdminNavigation;
use App\Support\B2cWorkspaceLandingResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class AdminSsoController extends Controller
{
    public function __construct(
        private readonly EnterpriseOidcService $oidc,
        private readonly AdminSecurityPolicy $policy,
        private readonly AdminSessionRegistry $sessions,
        private readonly AdminNavigation $navigation,
        private readonly B2cWorkspaceLandingResolver $workspaceLanding,
        private readonly AuditLogger $audit,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        return redirect()->away($this->oidc->authorizationUrl($request));
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $identity = $this->oidc->identity($request);
        } catch (RuntimeException $exception) {
            report($exception);
            abort(403, 'Enterprise SSO authentication failed.');
        }

        $provider = (string) config('security.sso.provider', 'enterprise');
        $link = EnterpriseSsoIdentity::query()
            ->where('provider', $provider)
            ->where('subject', $identity['sub'])
            ->with('user')
            ->first();

        $user = $link?->user;

        if (! $user instanceof User
            && (bool) config('security.sso.allow_email_linking', false)
            && $identity['email_verified']
            && is_string($identity['email'])) {
            $user = User::query()->whereRaw('LOWER(email) = ?', [$identity['email']])->first();

            if ($user instanceof User) {
                $link = EnterpriseSsoIdentity::query()->updateOrCreate(
                    ['user_id' => $user->id, 'provider' => $provider],
                    ['subject' => $identity['sub'], 'email' => $identity['email']],
                );
            }
        }

        abort_unless($user instanceof User && $user->is_active && $this->navigation->canUseDashboard($user), 403);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $this->sessions->ensure($request, $user);

        if ($link instanceof EnterpriseSsoIdentity) {
            $link->forceFill([
                'email' => $identity['email'],
                'last_login_at' => now(),
            ])->save();
        }

        $this->audit->record(
            'management.sso.login_succeeded',
            $user,
            $user,
            null,
            ['provider' => $provider],
            $request,
        );

        $defaultTarget = $this->workspaceLanding->urlFor($user, $request) ?? route('admin.index');

        if ($this->policy->requiresMfa($user)) {
            $request->session()->put('mfa_intended', $defaultTarget);

            return redirect()->route(
                AdminMfaCredential::query()
                    ->where('user_id', $user->id)
                    ->whereNotNull('enabled_at')
                    ->whereNull('revoked_at')
                    ->exists()
                    ? 'admin.mfa.challenge'
                    : 'admin.mfa.enroll',
            );
        }

        return redirect()->to($defaultTarget);
    }
}
