<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminMfaCredential;
use App\Models\User;
use App\Services\AdminSecurityPolicy;
use App\Services\AdminSessionRegistry;
use App\Services\AdminTotpService;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;

final class AdminMfaController extends Controller
{
    public function __construct(
        private readonly AdminTotpService $totp,
        private readonly AdminSessionRegistry $sessions,
        private readonly AdminSecurityPolicy $policy,
        private readonly AuditLogger $audit,
    ) {}

    public function enroll(Request $request): View
    {
        $user = $this->user($request);
        $this->locale($user);

        $credential = AdminMfaCredential::query()->firstOrNew(['user_id' => $user->id]);
        if (! $credential->exists || $credential->revoked_at !== null || $credential->enabled_at !== null) {
            if ($credential->enabled_at !== null && $credential->revoked_at === null) {
                return view('admin.mfa', [
                    'mode' => 'enrolled',
                    'credential' => $credential,
                    'secret' => null,
                    'provisioningUri' => null,
                    'recoveryCodes' => (array) $request->session()->pull('mfa_recovery_codes', []),
                    'policyRequired' => $this->policy->requiresMfa($user),
                ]);
            }

            $credential->forceFill([
                'secret' => $this->totp->generateSecret(),
                'recovery_codes' => null,
                'enabled_at' => null,
                'last_verified_at' => null,
                'revoked_at' => null,
                'revoked_by' => null,
            ])->save();
        }

        return view('admin.mfa', [
            'mode' => 'enroll',
            'credential' => $credential,
            'secret' => $credential->secret,
            'provisioningUri' => $this->totp->provisioningUri($user->email, $credential->secret),
            'recoveryCodes' => [],
            'policyRequired' => $this->policy->requiresMfa($user),
        ]);
    }

    public function confirmEnrollment(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $credential = AdminMfaCredential::query()
            ->where('user_id', $user->id)
            ->whereNull('enabled_at')
            ->whereNull('revoked_at')
            ->firstOrFail();

        if (! $this->totp->verify($credential->secret, (string) $data['code'])) {
            throw ValidationException::withMessages(['code' => [__('security_governance.mfa.invalid_code')]]);
        }

        $codes = $this->totp->generateRecoveryCodes();
        $credential->forceFill([
            'recovery_codes' => $this->totp->recoveryHashes($codes),
            'enabled_at' => now(),
            'last_verified_at' => now(),
        ])->save();

        $this->sessions->markMfaVerified($request, $user);
        $request->session()->flash('mfa_recovery_codes', $codes);
        $this->audit->record('management.mfa.enrolled', $user, $user, null, null, $request);

        return redirect()->route('admin.mfa.enroll')->with('status', __('security_governance.mfa.enrolled'));
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        $user = $this->user($request);
        $this->locale($user);
        $credential = $this->enabledCredential($user);

        if (! $credential) {
            return redirect()->route('admin.mfa.enroll');
        }

        return view('admin.mfa', [
            'mode' => 'challenge',
            'credential' => $credential,
            'secret' => null,
            'provisioningUri' => null,
            'recoveryCodes' => [],
            'policyRequired' => $this->policy->requiresMfa($user),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);
        $credential = $this->enabledCredential($user);
        abort_unless($credential instanceof AdminMfaCredential, 403);

        $code = trim((string) $data['code']);
        $valid = $this->totp->verify($credential->secret, $code)
            || $this->totp->consumeRecoveryCode($credential, $code);

        if (! $valid) {
            $this->audit->record('management.mfa.challenge_failed', $user, $user, null, null, $request);
            throw ValidationException::withMessages(['code' => [__('security_governance.mfa.invalid_code')]]);
        }

        $credential->forceFill(['last_verified_at' => now()])->save();
        $this->sessions->markMfaVerified($request, $user);
        $this->audit->record('management.mfa.challenge_succeeded', $user, $user, null, null, $request);

        $target = $request->session()->pull('mfa_intended');
        if (is_string($target) && str_starts_with($target, url('/admin'))) {
            return redirect()->to($target);
        }

        return redirect()->route('admin.index');
    }

    private function enabledCredential(User $user): ?AdminMfaCredential
    {
        return AdminMfaCredential::query()
            ->where('user_id', $user->id)
            ->whereNotNull('enabled_at')
            ->whereNull('revoked_at')
            ->first();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active, 403);

        return $user;
    }

    private function locale(User $user): void
    {
        App::setLocale(in_array($user->locale, ['ar', 'en'], true) ? $user->locale : 'ar');
    }
}
