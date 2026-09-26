<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\PushDeliveryLog;
use App\Models\PushDeviceToken;
use App\Models\PushProviderSetting;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PushDeliveryService
{
    public function validateProvider(PushProviderSetting $provider): void
    {
        $this->credentials($provider);
    }

    public function send(
        PushProviderSetting $provider,
        PushDeviceToken $device,
        array $payload,
        bool $isTest = false,
    ): PushDeliveryLog {
        $log = PushDeliveryLog::query()->create([
            'user_id' => $device->user_id,
            'device_id' => $device->id,
            'app' => $device->app,
            'platform' => $device->platform,
            'environment' => $device->environment,
            'status' => 'sending',
            'is_test' => $isTest,
        ]);

        try {
            $credentials = $this->credentials($provider);

            $response = $this->firebase($provider, $device, $payload, $credentials);

            $body = $response->json();
            $successful = $response->successful();

            $log->update([
                'status' => $successful ? 'sent' : 'failed',
                'response_code' => $response->status(),
                'provider_message_id' => is_array($body)
                    ? ($body['name'] ?? ($body['id'] ?? null))
                    : null,
                'error_code' => $successful
                    ? null
                    : 'provider_http_'.$response->status(),
                'error_message' => $successful
                    ? null
                    : $this->safeError($body),
            ]);
        } catch (Throwable $exception) {
            $log->update([
                'status' => 'failed',
                'error_code' => class_basename($exception),
                'error_message' => mb_substr($exception->getMessage(), 0, 500),
            ]);
        }

        return $log->fresh();
    }

    public function dispatchNotification(Notification $notification): void
    {
        if (! in_array($notification->channel, ['push', 'both'], true)) {
            return;
        }

        $devices = PushDeviceToken::query()
            ->whereNull('revoked_at')
            ->when(
                $notification->app !== 'all',
                fn ($query) => $query->where('app', $notification->app),
            )
            ->when(
                $notification->audience === 'customer',
                fn ($query) => $query->where('app', 'customer'),
            )
            ->when(
                $notification->audience === 'driver',
                fn ($query) => $query->where('app', 'driver'),
            )
            ->when(
                $notification->audience === 'user',
                fn ($query) => $query->where('user_id', $notification->user_id),
            )
            ->with('user:id,locale')
            ->limit(500)
            ->get();

        foreach ($devices as $device) {
            $provider = PushProviderSetting::query()
                ->where('app', $device->app)
                ->where('platform', $device->platform)
                ->where('environment', $device->environment)
                ->where('enabled', true)
                ->first();

            if ($provider === null) {
                PushDeliveryLog::query()->create([
                    'user_id' => $device->user_id,
                    'device_id' => $device->id,
                    'app' => $device->app,
                    'platform' => $device->platform,
                    'environment' => $device->environment,
                    'status' => 'skipped',
                    'error_code' => 'provider_not_configured',
                    'error_message' => 'No enabled provider configuration exists for this device.',
                ]);

                continue;
            }

            $user = $device->user;
            $english = $user instanceof User && $user->locale === 'en';

            $this->send($provider, $device, [
                'title' => $english
                    ? $notification->title_en
                    : $notification->title_ar,
                'body' => $english
                    ? $notification->body_en
                    : $notification->body_ar,
                'data' => [
                    'notification_id' => (string) $notification->id,
                    'type' => (string) $notification->type,
                ],
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function credentials(PushProviderSetting $provider): array
    {
        $credentials = $provider->getAttribute('credentials_encrypted');

        if (! is_array($credentials)) {
            throw ValidationException::withMessages([
                'credentials_json' => 'Provider credentials are required.',
            ]);
        }

        $required = ['project_id', 'access_token'];

        foreach ($required as $key) {
            if (
                ! isset($credentials[$key])
                || ! is_string($credentials[$key])
                || trim($credentials[$key]) === ''
            ) {
                throw ValidationException::withMessages([
                    'credentials_json' => "Missing provider credential: {$key}.",
                ]);
            }
        }

        /** @var array<string, string> $credentials */
        return $credentials;
    }

    private function firebase(
        PushProviderSetting $provider,
        PushDeviceToken $device,
        array $payload,
        array $credentials,
    ): Response {
        return Http::acceptJson()
            ->withToken((string) $credentials['access_token'])
            ->timeout(10)
            ->post(
                'https://fcm.googleapis.com/v1/projects/'
                    .rawurlencode((string) $credentials['project_id'])
                    .'/messages:send',
                [
                    'message' => [
                        'token' => $device->plainToken(),
                        'notification' => [
                            'title' => $payload['title'] ?? '',
                            'body' => $payload['body'] ?? '',
                        ],
                        'data' => $payload['data'] ?? [],
                        'android' => [
                            'notification' => array_filter([
                                'sound' => $provider->default_sound,
                                'channel_id' => $provider->default_channel,
                                'icon' => $provider->default_icon,
                            ]),
                        ],
                        'apns' => [
                            'payload' => [
                                'aps' => array_filter([
                                    'sound' => $provider->default_sound ?? 'default',
                                    'category' => $provider->default_category,
                                ]),
                            ],
                        ],
                    ],
                ],
            );
    }

    private function safeError(mixed $body): string
    {
        $message = is_array($body)
            ? (
                data_get($body, 'error.message')
                ?? data_get($body, 'reason')
                ?? 'Provider rejected the request.'
            )
            : 'Provider rejected the request.';

        return mb_substr((string) $message, 0, 500);
    }
}
