<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\PushDeliveryLog;
use App\Models\PushDeviceToken;
use App\Models\PushProviderSetting;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
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

        $audience = app(NotificationAudience::class);

        foreach ($devices as $device) {
            $deviceUser = $device->user;
            if (! $deviceUser instanceof User
                || ! $audience->apply(Notification::query(), $deviceUser)->whereKey($notification->id)->exists()) {
                continue;
            }

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

            $user = $deviceUser;
            $english = $user->locale === 'en';

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
                'credentials_json' => 'Firebase credentials are required.',
            ]);
        }

        $projectId = trim((string) ($credentials['project_id'] ?? ''));
        if ($projectId === '') {
            throw ValidationException::withMessages([
                'credentials_json' => 'Missing Firebase service-account credential: project_id.',
            ]);
        }

        $accessToken = trim((string) ($credentials['access_token'] ?? ''));
        $clientEmail = trim((string) ($credentials['client_email'] ?? ''));
        $privateKey = trim((string) ($credentials['private_key'] ?? ''));

        if ($accessToken === '' && ($clientEmail === '' || $privateKey === '')) {
            throw ValidationException::withMessages([
                'credentials_json' => 'Paste a Firebase service-account JSON containing project_id, client_email and private_key. Legacy access_token credentials are also supported.',
            ]);
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
            ->withToken($this->accessToken($provider, $credentials))
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

    /**
     * @param array<string, string> $credentials
     */
    private function accessToken(PushProviderSetting $provider, array $credentials): string
    {
        $legacy = trim((string) ($credentials['access_token'] ?? ''));
        if ($legacy !== '') {
            return $legacy;
        }

        $clientEmail = trim((string) ($credentials['client_email'] ?? ''));
        $privateKey = (string) ($credentials['private_key'] ?? '');
        $tokenUri = trim((string) ($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token'));
        $cacheKey = 'foodex:fcm:oauth:'.$provider->getKey().':'.hash('sha256', $clientEmail);

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($clientEmail, $privateKey, $tokenUri): string {
            $now = time();
            $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
            $claims = $this->base64Url(json_encode([
                'iss' => $clientEmail,
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR));
            $unsigned = $header.'.'.$claims;

            $signed = openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
            if (! $signed) {
                throw ValidationException::withMessages([
                    'credentials_json' => 'Firebase service-account private_key could not sign the OAuth request.',
                ]);
            }

            $response = Http::asForm()
                ->acceptJson()
                ->timeout(10)
                ->post($tokenUri, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $unsigned.'.'.$this->base64Url($signature),
                ]);

            $token = trim((string) data_get($response->json(), 'access_token', ''));
            if (! $response->successful() || $token === '') {
                $message = (string) (
                    data_get($response->json(), 'error_description')
                    ?? data_get($response->json(), 'error.message')
                    ?? data_get($response->json(), 'error')
                    ?? 'Unable to obtain Firebase OAuth access token.'
                );

                throw ValidationException::withMessages([
                    'credentials_json' => 'Firebase authentication failed: '.mb_substr($message, 0, 400),
                ]);
            }

            return $token;
        });
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
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
