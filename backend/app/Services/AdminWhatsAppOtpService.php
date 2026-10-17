<?php

namespace App\Services;

use App\Domain\WhatsApp\ConnectionHealthStatus;
use App\Domain\WhatsApp\ConnectionProfileStatus;
use App\Domain\WhatsApp\OutboundMessage;
use App\Domain\WhatsApp\RuntimePolicy;
use App\Domain\WhatsApp\WhatsAppProviderRegistry;
use App\Models\User;
use App\Models\WhatsAppConnectionProfile;
use App\Support\AdminNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AdminWhatsAppOtpService
{
    public function __construct(
        private readonly RuntimePolicy $runtime,
        private readonly WhatsAppProviderRegistry $providers,
        private readonly AdminNavigation $navigation,
        private readonly AuditLogger $audit,
    ) {}

    public function isAvailable(string $locale = 'en'): bool
    {
        if (! (bool) config('whatsapp_auth.enabled', true)) {
            return false;
        }

        $state = $this->runtime->state();
        if (! $this->runtime->isOperational($state) || $state->emergencyStop) {
            return false;
        }

        return $this->profile() !== null && $this->template($locale) !== null;
    }

    public function normalizePhone(string $value): ?string
    {
        $phone = trim($value);
        if ($phone === '') {
            return null;
        }

        $phone = preg_replace('/[\s().\-]+/', '', $phone) ?? '';
        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        } elseif (! str_starts_with($phone, '+')) {
            $digits = preg_replace('/\D+/', '', $phone) ?? '';
            $country = preg_replace('/\D+/', '', (string) config('whatsapp_auth.default_country_code', '965')) ?? '';
            if ($digits === '' || $country === '') {
                return null;
            }
            $phone = '+'.$country.$digits;
        }

        return preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone) === 1 ? $phone : null;
    }

    public function request(string $rawPhone, string $locale, Request $request): string
    {
        $phone = $this->normalizePhone($rawPhone);
        if ($phone === null) {
            throw ValidationException::withMessages(['email' => [__('admin_login.invalid_identifier')]]);
        }

        if (! $this->isAvailable($locale)) {
            throw ValidationException::withMessages(['email' => [__('admin_login.whatsapp_unavailable')]]);
        }

        $user = User::query()
            ->where('phone_e164', $phone)
            ->whereNotNull('phone_verified_at')
            ->where('is_active', true)
            ->first();

        if ($user !== null && $this->navigation->for($user) === []) {
            $user = null;
        }

        $code = $this->code();
        $id = (string) Str::uuid();
        $now = now();

        DB::table('admin_whatsapp_otp_challenges')->insert([
            'id' => $id,
            'user_id' => $user?->id,
            'phone_e164' => $phone,
            'otp_hash' => Hash::make($code),
            'attempt_count' => 0,
            'resend_count' => 0,
            'expires_at' => $now->copy()->addMinutes((int) config('whatsapp_auth.ttl_minutes', 5)),
            'resend_available_at' => $now->copy()->addSeconds((int) config('whatsapp_auth.resend_cooldown_seconds', 60)),
            'sent_at' => null,
            'send_failed_at' => null,
            'consumed_at' => null,
            'request_ip_hash' => $this->hashMetadata($request->ip()),
            'device_hash' => $this->hashMetadata($request->userAgent()),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->audit->record(
            'management.whatsapp_otp.requested',
            null,
            $user,
            null,
            ['challenge_id' => $id, 'eligible' => $user !== null],
            $request,
        );

        if ($user !== null) {
            $this->send($id, $phone, $code, $locale, $user, $request);
        }

        return $id;
    }

    public function resend(string $challengeId, string $locale, Request $request): void
    {
        $payload = DB::transaction(function () use ($challengeId): array {
            $row = DB::table('admin_whatsapp_otp_challenges')
                ->where('id', $challengeId)
                ->lockForUpdate()
                ->first();

            if ($row === null || $row->consumed_at !== null || now()->greaterThanOrEqualTo($row->expires_at)) {
                throw ValidationException::withMessages(['code' => [__('admin_login.otp_invalid')]]);
            }
            if ((int) $row->resend_count >= (int) config('whatsapp_auth.max_resends', 3)) {
                throw ValidationException::withMessages(['code' => [__('admin_login.otp_resend_limit')]]);
            }
            if ($row->resend_available_at !== null && now()->lessThan($row->resend_available_at)) {
                throw ValidationException::withMessages(['code' => [__('admin_login.otp_resend_wait')]]);
            }

            $code = $this->code();
            $now = now();
            DB::table('admin_whatsapp_otp_challenges')->where('id', $challengeId)->update([
                'otp_hash' => Hash::make($code),
                'resend_count' => (int) $row->resend_count + 1,
                'expires_at' => $now->copy()->addMinutes((int) config('whatsapp_auth.ttl_minutes', 5)),
                'resend_available_at' => $now->copy()->addSeconds((int) config('whatsapp_auth.resend_cooldown_seconds', 60)),
                'sent_at' => null,
                'send_failed_at' => null,
                'updated_at' => $now,
            ]);

            return [
                'phone' => (string) $row->phone_e164,
                'code' => $code,
                'user_id' => $row->user_id === null ? null : (int) $row->user_id,
            ];
        });

        $user = $payload['user_id'] === null ? null : User::query()->find($payload['user_id']);
        if ($user !== null && $user->is_active && $user->phone_verified_at !== null
            && hash_equals((string) $user->phone_e164, (string) $payload['phone'])
            && $this->navigation->for($user) !== []) {
            $this->send($challengeId, (string) $payload['phone'], (string) $payload['code'], $locale, $user, $request);
        }

        $this->audit->record(
            'management.whatsapp_otp.resent',
            null,
            $user,
            null,
            ['challenge_id' => $challengeId],
            $request,
        );
    }

    public function verify(string $challengeId, string $code, Request $request): ?User
    {
        $maxAttempts = (int) config('whatsapp_auth.max_attempts', 5);

        $user = DB::transaction(function () use ($challengeId, $code, $maxAttempts): ?User {
            $row = DB::table('admin_whatsapp_otp_challenges')
                ->where('id', $challengeId)
                ->lockForUpdate()
                ->first();

            if ($row === null || $row->consumed_at !== null || now()->greaterThanOrEqualTo($row->expires_at)
                || (int) $row->attempt_count >= $maxAttempts) {
                return null;
            }

            $attempts = (int) $row->attempt_count + 1;
            DB::table('admin_whatsapp_otp_challenges')->where('id', $challengeId)->update([
                'attempt_count' => $attempts,
                'updated_at' => now(),
            ]);

            if (! Hash::check($code, (string) $row->otp_hash) || $row->user_id === null) {
                return null;
            }

            $user = User::query()->find((int) $row->user_id);
            if ($user === null || ! $user->is_active || $user->phone_verified_at === null
                || ! is_string($user->phone_e164) || ! hash_equals($user->phone_e164, (string) $row->phone_e164)
                || $this->navigation->for($user) === []) {
                return null;
            }

            DB::table('admin_whatsapp_otp_challenges')->where('id', $challengeId)->update([
                'consumed_at' => now(),
                'updated_at' => now(),
            ]);

            return $user;
        });

        $this->audit->record(
            $user === null ? 'management.whatsapp_otp.verification_failed' : 'management.whatsapp_otp.verified',
            $user,
            $user,
            null,
            ['challenge_id' => $challengeId],
            $request,
        );

        return $user;
    }

    private function send(
        string $challengeId,
        string $phone,
        string $code,
        string $locale,
        User $user,
        Request $request,
    ): void {
        $profile = $this->profile();
        $template = $this->template($locale);

        if ($profile === null || $template === null || ! $this->runtime->allowsTransactionalNotifications($phone)) {
            DB::table('admin_whatsapp_otp_challenges')->where('id', $challengeId)->update([
                'send_failed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record(
                'management.whatsapp_otp.send_failed',
                null,
                $user,
                null,
                ['challenge_id' => $challengeId, 'reason' => 'runtime_unavailable'],
                $request,
            );

            return;
        }

        try {
            $language = strtolower((string) $template->locale) === 'ar' ? 'ar' : 'en_US';
            $message = new OutboundMessage(
                recipient: $phone,
                kind: 'template',
                body: null,
                metadata: [
                    'template_name' => (string) $template->provider_name,
                    'language' => $language,
                    'components' => [[
                        'type' => 'body',
                        'parameters' => [['type' => 'text', 'text' => $code]],
                    ]],
                ],
                idempotencyKey: 'management-otp:'.$challengeId,
            );

            $this->providers->forProfile($profile)->send($profile, $message);
            DB::table('admin_whatsapp_otp_challenges')->where('id', $challengeId)->update([
                'sent_at' => now(),
                'send_failed_at' => null,
                'updated_at' => now(),
            ]);
            $this->audit->record(
                'management.whatsapp_otp.sent',
                null,
                $user,
                null,
                ['challenge_id' => $challengeId, 'profile_id' => $profile->id],
                $request,
            );
        } catch (Throwable $exception) {
            DB::table('admin_whatsapp_otp_challenges')->where('id', $challengeId)->update([
                'send_failed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record(
                'management.whatsapp_otp.send_failed',
                null,
                $user,
                null,
                ['challenge_id' => $challengeId, 'reason' => 'provider_failure'],
                $request,
            );
        }
    }

    private function profile(): ?WhatsAppConnectionProfile
    {
        return WhatsAppConnectionProfile::query()
            ->where('is_primary', true)
            ->where('status', ConnectionProfileStatus::ACTIVE->value)
            ->where('health_status', ConnectionHealthStatus::READY->value)
            ->where('receive_only', false)
            ->first();
    }

    private function template(string $locale): ?object
    {
        $normalized = strtolower($locale) === 'ar' ? 'ar' : 'en';

        return DB::table('whats_app_templates')
            ->where('category', 'authentication')
            ->where('provider_status', 'approved')
            ->where('is_active', true)
            ->whereIn('locale', $normalized === 'ar' ? ['ar'] : ['en', 'en_us', 'en-US', 'en_US'])
            ->orderByRaw('CASE WHEN LOWER(locale) = ? THEN 0 ELSE 1 END', [$normalized])
            ->orderByDesc('id')
            ->first();
    }

    private function code(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function hashMetadata(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : hash('sha256', $value);
    }
}
