<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminMfaCredential;
use App\Models\User;
use App\Services\AdminSecurityPolicy;
use App\Services\AdminSessionRegistry;
use App\Services\AdminWhatsAppOtpService;
use App\Services\AuditLogger;
use App\Services\CredentialAuthenticator;
use App\Support\AdminNavigation;
use App\Support\B2cWorkspaceLandingResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminLoginController extends Controller
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly B2cWorkspaceLandingResolver $workspaceLanding,
        private readonly CredentialAuthenticator $credentials,
        private readonly AuditLogger $audit,
        private readonly AdminSecurityPolicy $securityPolicy,
        private readonly AdminSessionRegistry $securitySessions,
        private readonly AdminWhatsAppOtpService $whatsAppOtp,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $channel = $this->channel($request);
        $locale = $this->locale($request);
        App::setLocale($locale);
        $request->session()->put('admin_login_locale', $locale);

        $user = $request->user();

        if ($user instanceof User && $user->is_active) {
            $preferredChannel = $user->hasRole('SUPER_ADMIN') ? 'b2b' : $channel;
            $target = $this->targetChannel($user, $preferredChannel);

            if ($target !== null) {
                return redirect()->to($this->defaultWorkspaceUrl($user, $target, $request));
            }
        }

        return view('admin.login', [
            'channel' => $channel,
            'locale' => $locale,
            'ssoReadiness' => $this->securityPolicy->ssoReadiness(),
            'whatsAppOtpAvailable' => $this->whatsAppOtp->isAvailable($locale),
        ]);
    }

    public function csrfToken(Request $request): JsonResponse
    {
        return response()
            ->json(['token' => csrf_token()])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function store(Request $request): RedirectResponse
    {
        $channel = $this->channel($request);
        $locale = $this->locale($request);
        App::setLocale($locale);
        $request->session()->put('admin_login_locale', $locale);

        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string'],
            'locale' => ['nullable', 'in:ar,en'],
        ]);

        $identifier = trim((string) $data['email']);

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false) {
            if (! isset($data['password']) || (string) $data['password'] === '') {
                throw ValidationException::withMessages([
                    'password' => [__('validation.required', ['attribute' => __('admin_login.password')])],
                ]);
            }

            $user = $this->credentials->authenticate($identifier, (string) $data['password']);

            if ($user === null) {
                Log::notice('Management login denied', [
                    'channel' => $channel,
                    'reason' => 'invalid_or_inactive_credentials',
                ]);
                $this->recordAuthFailure($request, $channel, 'invalid_or_inactive_credentials');

                throw ValidationException::withMessages([
                    'email' => [__('admin_login.invalid_credentials')],
                ]);
            }

            return $this->completeLogin($request, $user, $channel, $locale, 'password');
        }

        $phone = $this->whatsAppOtp->normalizePhone($identifier);
        if ($phone === null) {
            $errors = ['email' => [__('admin_login.invalid_identifier')]];
            if (! isset($data['password']) || (string) $data['password'] === '') {
                $errors['password'] = [__('validation.required', ['attribute' => __('admin_login.password')])];
            }

            throw ValidationException::withMessages($errors);
        }

        $challenge = $this->whatsAppOtp->request($phone, $locale, $request);

        return redirect()->route("admin.{$channel}.login.whatsapp", [
            'challenge' => $challenge,
            'locale' => $locale,
        ])->with('status', __('admin_login.otp_generic_sent'));
    }

    public function showWhatsapp(Request $request, string $challenge): View|RedirectResponse
    {
        $channel = $this->channel($request);
        $locale = $this->locale($request);
        App::setLocale($locale);
        $request->session()->put('admin_login_locale', $locale);

        if ($request->user() instanceof User) {
            return $this->show($request);
        }

        $row = DB::table('admin_whatsapp_otp_challenges')->where('id', $challenge)->first();
        abort_if($row === null, 404);

        return view('admin.auth.whatsapp-otp', [
            'channel' => $channel,
            'locale' => $locale,
            'challenge' => $challenge,
            'resendAvailableAt' => $row->resend_available_at,
            'expired' => now()->greaterThanOrEqualTo($row->expires_at) || $row->consumed_at !== null,
        ]);
    }

    public function verifyWhatsapp(Request $request, string $challenge): RedirectResponse
    {
        $channel = $this->channel($request);
        $locale = $this->locale($request);
        App::setLocale($locale);

        $data = $request->validate([
            'code' => ['required', 'regex:/^[0-9]{6}$/D'],
            'locale' => ['nullable', 'in:ar,en'],
        ]);

        $user = $this->whatsAppOtp->verify($challenge, (string) $data['code'], $request);
        if ($user === null) {
            throw ValidationException::withMessages([
                'code' => [__('admin_login.otp_invalid')],
            ]);
        }

        return $this->completeLogin($request, $user, $channel, $locale, 'whatsapp_otp');
    }

    public function resendWhatsapp(Request $request, string $challenge): RedirectResponse
    {
        $channel = $this->channel($request);
        $locale = $this->locale($request);
        App::setLocale($locale);

        $request->validate(['locale' => ['nullable', 'in:ar,en']]);
        $this->whatsAppOtp->resend($challenge, $locale, $request);

        return redirect()->route("admin.{$channel}.login.whatsapp", [
            'challenge' => $challenge,
            'locale' => $locale,
        ])->with('status', __('admin_login.otp_generic_sent'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->audit->record('management.logout', $user, $user, null, null, $request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Log::info('Management logout', ['user_id' => $user?->getKey()]);

        return redirect()->route('admin.b2c.login');
    }

    private function completeLogin(
        Request $request,
        User $user,
        string $channel,
        string $locale,
        string $method,
    ): RedirectResponse {
        $preferredChannel = $user->hasRole('SUPER_ADMIN') ? 'b2b' : $channel;
        $target = $this->targetChannel($user, $preferredChannel);

        if ($target === null) {
            Log::notice('Management login denied', [
                'channel' => $channel,
                'user_id' => $user->getKey(),
                'reason' => 'no_management_channel',
                'method' => $method,
            ]);
            $this->audit->record(
                'management.login.failed',
                $user,
                $user,
                null,
                ['requested_channel' => $channel, 'reason' => 'no_management_channel', 'method' => $method],
                $request,
            );

            throw ValidationException::withMessages([
                'email' => [__('admin_login.not_authorized')],
            ]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        try {
            $this->securitySessions->ensure($request, $user);

            $userLocale = in_array($user->locale, ['ar', 'en'], true) ? $user->locale : $locale;
            $request->session()->put('admin_login_locale', $userLocale);

            $this->audit->record(
                'management.login.succeeded',
                $user,
                $user,
                null,
                ['requested_channel' => $channel, 'granted_channel' => $target, 'method' => $method],
                $request,
            );

            Log::info('Management login succeeded', [
                'channel_requested' => $channel,
                'channel_granted' => $target,
                'user_id' => $user->getKey(),
                'method' => $method,
            ]);

            $intended = $this->authorizedIntendedUrl($request, $user);
            $defaultTarget = $this->defaultWorkspaceUrl($user, $target, $request);

            if ($this->navigation->usesCustomerServiceDashboardProfile($user)) {
                $intended = null;
            }

            if ($this->securityPolicy->requiresMfa($user)) {
                $request->session()->put('mfa_intended', $intended ?? $defaultTarget);
                $hasMfa = AdminMfaCredential::query()
                    ->where('user_id', $user->id)
                    ->whereNotNull('enabled_at')
                    ->whereNull('revoked_at')
                    ->exists();

                return redirect()->route($hasMfa ? 'admin.mfa.challenge' : 'admin.mfa.enroll');
            }

            if ($intended !== null) {
                return redirect()->to($intended);
            }

            return redirect()->to($defaultTarget);
        } catch (Throwable $exception) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            Log::warning('Management login completion failed; authentication state cleared', [
                'channel_requested' => $channel,
                'channel_granted' => $target,
                'user_id' => $user->getKey(),
                'method' => $method,
                'exception' => $exception::class,
            ]);

            throw $exception;
        }
    }

    private function recordAuthFailure(Request $request, string $channel, string $reason): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => null,
            'event' => 'management.login.failed',
            'auditable_type' => 'management_auth',
            'auditable_id' => null,
            'before' => null,
            'after' => json_encode([
                'requested_channel' => $channel,
                'reason' => $reason,
            ], JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 2000),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function channel(Request $request): string
    {
        $channel = (string) $request->route('channel', 'b2c');

        abort_unless(in_array($channel, ['b2b', 'b2c'], true), 404);

        return $channel;
    }

    private function locale(Request $request): string
    {
        $locale = $request->input(
            'locale',
            $request->query('locale', $request->session()->get('admin_login_locale', config('app.locale', 'ar'))),
        );

        return in_array($locale, ['ar', 'en'], true) ? $locale : 'ar';
    }

    private function authorizedIntendedUrl(Request $request, User $user): ?string
    {
        $intended = $request->session()->pull('url.intended');

        if (! is_string($intended) || trim($intended) === '') {
            return null;
        }

        $parts = parse_url($intended);

        if ($parts === false) {
            return null;
        }

        $host = $parts['host'] ?? null;
        $scheme = $parts['scheme'] ?? null;
        $port = $parts['port'] ?? null;
        $path = (string) ($parts['path'] ?? '');

        if ($host !== null && strcasecmp((string) $host, $request->getHost()) !== 0) {
            return null;
        }

        if ($scheme !== null && strtolower((string) $scheme) !== $request->getScheme()) {
            return null;
        }

        if ($port !== null && (int) $port !== $request->getPort()) {
            return null;
        }

        if ($path !== '/admin' && ! str_starts_with($path, '/admin/')) {
            return null;
        }

        if (in_array($path, ['/admin/b2b/login', '/admin/b2c/login', '/admin/logout'], true)) {
            return null;
        }

        foreach (['b2b', 'b2c'] as $channel) {
            $prefix = "/admin/{$channel}";

            if (($path === $prefix || str_starts_with($path, "{$prefix}/"))
                && ! $this->navigation->canAccess($user, $channel)) {
                return null;
            }
        }

        if (! $this->workspaceLanding->allowsIntendedUrl($user, $request, $intended)) {
            return null;
        }

        return $intended;
    }

    private function defaultWorkspaceUrl(User $user, string $channel, Request $request): string
    {
        if ($channel === 'b2c') {
            return $this->workspaceLanding->urlFor($user, $request)
                ?? $this->navigation->defaultWorkspaceUrl($user, $channel);
        }

        return $this->navigation->defaultWorkspaceUrl($user, $channel);
    }

    private function targetChannel(User $user, string $preferred): ?string
    {
        if ($user->is_active === false) {
            return null;
        }

        $available = array_keys($this->navigation->for($user));

        if (in_array($preferred, $available, true)) {
            return $preferred;
        }

        foreach (['b2c', 'b2b'] as $fallback) {
            if (in_array($fallback, $available, true)) {
                return $fallback;
            }
        }

        return null;
    }
}
