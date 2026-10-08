<?php

namespace App\Services\Sms;

use App\Jobs\SendSmsJob;
use App\Models\SmsMessageLog;
use App\Models\SmsProviderSetting;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SmsGateway
{
    public function send(SmsMessage $message): SmsMessageLog
    {
        $setting = $this->setting();

        if (! $setting->enabled) {
            throw ValidationException::withMessages([
                'sms' => __('sms.errors.disabled'),
            ]);
        }

        if (! in_array($message->purpose, (array) config('sms.purposes'), true)) {
            throw ValidationException::withMessages([
                'purpose' => __('sms.errors.invalid_purpose'),
            ]);
        }

        if (in_array($message->purpose, ['otp', 'auth'], true) && ! $setting->otp_sender_enabled) {
            throw ValidationException::withMessages([
                'sms' => __('sms.errors.otp_disabled'),
            ]);
        }

        if (
            in_array($message->purpose, ['order_notification', 'dispatch', 'marketing'], true)
            && ! $setting->notification_sender_enabled
        ) {
            throw ValidationException::withMessages([
                'sms' => __('sms.errors.notifications_disabled'),
            ]);
        }

        $normalized = $this->normalizePhone(
            $message->recipient,
            (string) $setting->default_country_code,
        );

        $operator = $message->operatorId
            ?? $this->resolveOperator(
                $normalized,
                (string) $setting->operator_resolution_mode,
            );

        if ($operator === null) {
            throw ValidationException::withMessages([
                'operator_id' => __('sms.errors.operator_required'),
            ]);
        }

        $requestId = $message->requestId();

        if (! Str::isUuid($requestId)) {
            throw ValidationException::withMessages([
                'request_id' => __('sms.errors.invalid_request_id'),
            ]);
        }

        $existing = SmsMessageLog::query()
            ->where('request_id', $requestId)
            ->first();

        if ($existing instanceof SmsMessageLog) {
            return $existing;
        }

        $this->enforceRateLimit($message->purpose, $normalized);

        $effective = new SmsMessage(
            recipient: $normalized,
            message: $message->message,
            purpose: $message->purpose,
            locale: in_array($message->locale, ['ar', 'en'], true) ? $message->locale : 'ar',
            sender: trim((string) ($message->sender ?: $setting->default_sender_name)),
            operatorId: $operator,
            requestId: $requestId,
            correlationId: $message->correlationId,
            createdBy: $message->createdBy,
            isTest: $message->isTest,
        );

        if ($effective->message === '' || mb_strlen($effective->message) > 1000) {
            throw ValidationException::withMessages([
                'message' => __('sms.errors.invalid_message'),
            ]);
        }

        if ((string) $effective->sender === '') {
            throw ValidationException::withMessages([
                'sender' => __('sms.errors.sender_required'),
            ]);
        }

        $log = SmsMessageLog::query()->create([
            'request_id' => $requestId,
            'correlation_id' => $effective->correlationId,
            'phone_hash' => hash('sha256', $normalized),
            'recipient_masked' => $this->maskPhone($normalized),
            'country' => (string) $setting->default_country_code,
            'operator_id' => $operator,
            'sender' => $effective->sender,
            'purpose' => $effective->purpose,
            'locale' => $effective->locale,
            'provider' => (string) $setting->provider,
            'status' => 'sending',
            'is_test' => $effective->isTest,
            'created_by' => $effective->createdBy,
        ]);

        $attempts = max(1, min(5, ((int) $setting->retry_count) + 1));
        $result = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $result = $this->provider($setting)->send($setting, $effective);

            $log->forceFill([
                'attempts' => $attempt,
                'provider_response_code' => $result['provider_code'],
                'latency_ms' => $result['latency_ms'],
            ])->save();

            if (
                $result['status'] === 'sent'
                || ! $result['transient']
                || $attempt === $attempts
            ) {
                break;
            }

            $backoff = max(0, min(10, (int) $setting->retry_backoff_seconds));

            if ($backoff > 0) {
                usleep($backoff * 1_000_000);
            }
        }

        $log->forceFill([
            'status' => $result['status'],
            'provider_response_code' => $result['provider_code'],
            'error_code' => $result['error_code'],
            'error_message' => $result['status'] === 'sent'
                ? null
                : mb_substr($result['message'], 0, 500),
            'latency_ms' => $result['latency_ms'],
            'sent_at' => $result['status'] === 'sent' ? now() : null,
            'failed_at' => $result['status'] === 'failed' ? now() : null,
        ])->save();

        $log->refresh();

        return $log;
    }

    public function queue(SmsMessage $message): void
    {
        $requestId = $message->requestId();

        SendSmsJob::dispatch(new SmsMessage(
            recipient: $message->recipient,
            message: $message->message,
            purpose: $message->purpose,
            locale: $message->locale,
            sender: $message->sender,
            operatorId: $message->operatorId,
            requestId: $requestId,
            correlationId: $message->correlationId,
            createdBy: $message->createdBy,
            isTest: $message->isTest,
        ));
    }

    public function normalizePhone(string $phone, string $countryCode = '20'): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $countryCode = preg_replace('/\D+/', '', $countryCode) ?? '';

        if ($digits === '' || $countryCode === '') {
            throw ValidationException::withMessages([
                'phone' => __('sms.errors.invalid_phone'),
            ]);
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            $digits = $countryCode.substr($digits, 1);
        } elseif (! str_starts_with($digits, $countryCode)) {
            $digits = $countryCode.$digits;
        }

        if (strlen($digits) < 10 || strlen($digits) > 15) {
            throw ValidationException::withMessages([
                'phone' => __('sms.errors.invalid_phone'),
            ]);
        }

        return $digits;
    }

    public function resolveOperator(string $phone, string $mode = 'automatic'): ?int
    {
        if ($mode === 'manual') {
            return null;
        }

        $local = str_starts_with($phone, '20')
            ? '0'.substr($phone, 2)
            : $phone;

        return match (true) {
            str_starts_with($local, '010') => 1,
            str_starts_with($local, '012') => 2,
            str_starts_with($local, '011') => 3,
            str_starts_with($local, '015') => 7,
            default => null,
        };
    }

    public function maskToken(?string $token): string
    {
        $token = trim((string) $token);

        if ($token === '') {
            return __('sms.not_configured');
        }

        return str_repeat(
            '•',
            min(8, max(4, strlen($token) - 4)),
        ).substr($token, -4);
    }

    private function setting(): SmsProviderSetting
    {
        $setting = SmsProviderSetting::query()
            ->where('provider', 'advansys_bulk_sms')
            ->where('environment', 'production')
            ->first();

        if (! $setting instanceof SmsProviderSetting) {
            throw ValidationException::withMessages([
                'sms' => __('sms.errors.not_configured'),
            ]);
        }

        return $setting;
    }

    private function provider(SmsProviderSetting $setting): SmsProviderInterface
    {
        return match ((string) $setting->provider) {
            'advansys_bulk_sms' => app(AdvansysBulkSmsProvider::class),
            default => throw ValidationException::withMessages([
                'provider' => __('sms.errors.unsupported_provider'),
            ]),
        };
    }

    private function enforceRateLimit(string $purpose, string $phone): void
    {
        $max = $purpose === 'otp'
            ? (int) config('sms.rate_limits.otp_per_minute', 5)
            : (int) config('sms.rate_limits.default_per_minute', 30);

        if (! RateLimiter::attempt(
            'sms:'.$purpose.':'.hash('sha256', $phone),
            max(1, $max),
            static fn (): bool => true,
            60,
        )) {
            throw ValidationException::withMessages([
                'sms' => __('sms.errors.rate_limited'),
            ]);
        }
    }

    private function maskPhone(string $phone): string
    {
        $visible = min(4, strlen($phone));

        return str_repeat(
            '*',
            max(0, strlen($phone) - $visible),
        ).substr($phone, -$visible);
    }
}
