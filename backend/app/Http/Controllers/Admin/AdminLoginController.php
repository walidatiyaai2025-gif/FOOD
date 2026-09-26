<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CredentialAuthenticator;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AdminLoginController extends Controller
{
    public function __construct(
        private readonly AdminNavigation $navigation,
        private readonly CredentialAuthenticator $credentials,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $channel = $this->channel($request);
        $locale = $this->locale($request);
        App::setLocale($locale);
        $request->session()->put('admin_login_locale', $locale);

        $user = $request->user();

        if ($user instanceof User && $user->is_active) {
            $target = $this->targetChannel($user, $channel);

            if ($target !== null) {
                return redirect()->route("admin.{$target}.dashboard");
            }
        }

        return view('admin.login', [
            'channel' => $channel,
            'locale' => $locale,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $channel = $this->channel($request);
        $locale = $this->locale($request);
        App::setLocale($locale);
        $request->session()->put('admin_login_locale', $locale);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'locale' => ['nullable', 'in:ar,en'],
        ]);

        $user = $this->credentials->authenticate($credentials['email'], $credentials['password']);

        if (! $user) {
            Log::notice('Management login denied', [
                'channel' => $channel,
                'reason' => 'invalid_or_inactive_credentials',
            ]);

            throw ValidationException::withMessages([
                'email' => [__('admin_login.invalid_credentials')],
            ]);
        }

        $target = $this->targetChannel($user, $channel);

        if ($target === null) {
            Log::notice('Management login denied', [
                'channel' => $channel,
                'user_id' => $user->getKey(),
                'reason' => 'no_management_channel',
            ]);

            throw ValidationException::withMessages([
                'email' => [__('admin_login.not_authorized')],
            ]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $userLocale = in_array($user->locale, ['ar', 'en'], true) ? $user->locale : $locale;
        $request->session()->put('admin_login_locale', $userLocale);

        $this->audit->record(
            'management.login.succeeded',
            $user,
            $user,
            null,
            ['requested_channel' => $channel, 'granted_channel' => $target],
            $request,
        );

        Log::info('Management login succeeded', [
            'channel_requested' => $channel,
            'channel_granted' => $target,
            'user_id' => $user->getKey(),
        ]);

        return redirect()->route("admin.{$target}.dashboard");
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

    private function targetChannel(User $user, string $preferred): ?string
    {
        if (! $user->is_active) {
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
