<?php

namespace App\Services\Sms;

use App\Models\SmsProviderSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class AdvansysBulkSmsProvider implements SmsProviderInterface
{
    /**
     * @return array{status:string,provider_code:string,error_code:?string,message:string,latency_ms:int,transient:bool}
     */
    public function send(SmsProviderSetting $setting, SmsMessage $message): array
    {
        $url = $this->validatedUrl($setting);
        $token = trim((string) $setting->getAttribute('api_token_encrypted'));

        if ($token === '') {
            throw ValidationException::withMessages([
                'api_token' => __('sms.errors.token_required'),
            ]);
        }

        $started = hrtime(true);

        try {
            $response = Http::acceptJson()
                ->withHeaders(['Authorization' => $token])
                ->timeout(max(1, min(30, (int) $setting->request_timeout)))
                ->post($url, [
                    'PhoneNumber' => $message->recipient,
                    'Message' => $message->message,
                    'SenderName' => (string) $message->sender,
                    'RequestID' => $message->requestId(),
                    'OperatorID' => $message->operatorId,
                ]);
        } catch (ConnectionException) {
            return [
                'status' => 'failed',
                'provider_code' => 'connection_error',
                'error_code' => 'provider_unavailable',
                'message' => __('sms.errors.provider_unavailable'),
                'latency_ms' => $this->latency($started),
                'transient' => true,
            ];
        }

        if (! $response->successful()) {
            return [
                'status' => 'failed',
                'provider_code' => 'http_'.$response->status(),
                'error_code' => $response->status() === 429 ? 'rate_limited' : 'provider_http_error',
                'message' => __('sms.errors.provider_http'),
                'latency_ms' => $this->latency($started),
                'transient' => $response->status() === 429 || $response->status() >= 500,
            ];
        }

        $raw = trim((string) $response->body());
        $decoded = $response->json();
        $code = is_scalar($decoded) ? (string) $decoded : $raw;

        if (is_array($decoded)) {
            $candidate = $decoded['code'] ?? $decoded['result'] ?? $decoded['status'] ?? null;

            if (is_scalar($candidate)) {
                $code = (string) $candidate;
            }
        }

        return $this->mapCode(trim($code), $this->latency($started));
    }

    /**
     * @return array{status:string,provider_code:string,error_code:?string,message:string,latency_ms:int,transient:bool}
     */
    private function mapCode(string $code, int $latency): array
    {
        return match ($code) {
            '1' => [
                'status' => 'sent',
                'provider_code' => '1',
                'error_code' => null,
                'message' => __('sms.provider.sent'),
                'latency_ms' => $latency,
                'transient' => false,
            ],
            '-1' => [
                'status' => 'failed',
                'provider_code' => '-1',
                'error_code' => 'invalid_authorization',
                'message' => __('sms.provider.invalid_authorization'),
                'latency_ms' => $latency,
                'transient' => false,
            ],
            '-2' => [
                'status' => 'failed',
                'provider_code' => '-2',
                'error_code' => 'empty_mobile',
                'message' => __('sms.provider.empty_mobile'),
                'latency_ms' => $latency,
                'transient' => false,
            ],
            '-3' => [
                'status' => 'failed',
                'provider_code' => '-3',
                'error_code' => 'empty_message',
                'message' => __('sms.provider.empty_message'),
                'latency_ms' => $latency,
                'transient' => false,
            ],
            '-4' => [
                'status' => 'failed',
                'provider_code' => '-4',
                'error_code' => 'invalid_sender',
                'message' => __('sms.provider.invalid_sender'),
                'latency_ms' => $latency,
                'transient' => false,
            ],
            '-5' => [
                'status' => 'failed',
                'provider_code' => '-5',
                'error_code' => 'no_credit',
                'message' => __('sms.provider.no_credit'),
                'latency_ms' => $latency,
                'transient' => false,
            ],
            default => [
                'status' => 'failed',
                'provider_code' => $code === '' ? 'empty_response' : mb_substr($code, 0, 32),
                'error_code' => 'unknown_provider_response',
                'message' => __('sms.provider.unknown_response'),
                'latency_ms' => $latency,
                'transient' => false,
            ],
        };
    }

    private function validatedUrl(SmsProviderSetting $setting): string
    {
        $base = rtrim(trim((string) $setting->api_base_url), '/');
        $parts = parse_url($base);

        if (
            ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'hub.advansystelecom.com'
            || ! in_array((string) ($parts['path'] ?? ''), ['', '/'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            throw ValidationException::withMessages([
                'api_base_url' => __('sms.errors.invalid_provider_url'),
            ]);
        }

        $path = trim((string) $setting->endpoint_path);

        if ($path !== '/generalapiv12/api/bulkSMS/ForwardSMS') {
            throw ValidationException::withMessages([
                'endpoint_path' => __('sms.errors.invalid_endpoint'),
            ]);
        }

        return $base.$path;
    }

    private function latency(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
