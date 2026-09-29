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

    /**
     * Validate that the configured Firebase credentials can produce a usable
     * OAuth access token. Service-account credentials are preferred; legacy
     * explicit access tokens remain supported for backwards compatibility.
     *
     * @return array{project_id:string,auth_mode:string}
     */
    public function testProvider(PushProviderSetting $provider): array
    {
        $credentials = $this->credentials($provider);
        $this->accessToken($provider, $credentials);

        return [
            'project_id' => (string) $credentials['project_id'],
            'auth_mode' => isset($credentials['client_email']) ? 'service_account' : 'access_token',
        ];
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
                'error_message' => mb_substr($this->exceptionMessage($exception), 0, 500),
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
            ->limit(2000)
            ->get();

        $audience = app(NotificationAudience::class);
        $imageUrl = $this->notificationImageUrl($notification);

        foreach ($devices as $device) {
            $deviceUser = $device->user;

            if ($deviceUser instanceof User) {
                if (! $audience->apply(Notification::query(), $deviceUser)->whereKey($notification->id)->exists()) {
                    continue;
                }
            } elseif (! $this->anonymousDeviceMatches($device, $notification)) {
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

            $english = ($deviceUser?->locale ?? $device->locale) === 'en';

            $this->send($provider, $device, [
                'title' => $english
                    ? $notification->title_en
                    : $notification->title_ar,
                'body' => $english
                    ? $notification->body_en
                    : $notification->body_ar,
                'image_url' => $imageUrl,
                'data' => [
                    'notification_id' => (string) $notification->id,
                    'type' => (string) $notification->type,
                    'title' => $english
                        ? (string) $notification->title_en
                        : (string) $notification->title_ar,
                    'body' => $english
                        ? (string) $notification->body_en
                        : (string) $notification->body_ar,
                    'image_url' => $imageUrl ?? '',
                    'visible_notification' => '1',
                ],
            ]);
        }
    }

    private function anonymousDeviceMatches(PushDeviceToken $device, Notification $notification): bool
    {
        if ($device->app !== 'customer' || ! in_array($notification->audience, ['all', 'customer'], true)) {
            return false;
        }

        if (! in_array($notification->app, ['all', 'customer'], true)) {
            return false;
        }

        if ($notification->target_channel !== 'all'
            && $device->target_channel !== 'all'
            && $device->target_channel !== $notification->target_channel) {
            return false;
        }

        if ($notification->store_id !== null
            && (int) $notification->store_id !== (int) ($device->store_id ?? 0)) {
            return false;
        }

        return true;
    }

    private function notificationImageUrl(Notification $notification): ?string
    {
        $path = $notification->image_path;
        if (is_string($path) && trim($path) !== '') {
            $value = trim($path);

            return str_starts_with($value, 'http://') || str_starts_with($value, 'https://')
                ? $value
                : url('/'.ltrim($value, '/'));
        }

        return url('/brand/foodex-economical-group.png');
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

        $projectId = $credentials['project_id'] ?? null;
        if (! is_string($projectId) || trim($projectId) === '') {
            throw ValidationException::withMessages([
                'credentials_json' => 'Missing Firebase service-account credential: project_id.',
            ]);
        }

        $legacyToken = $credentials['access_token'] ?? null;
        if (is_string($legacyToken) && trim($legacyToken) !== '') {
            /** @var array<string, string> $credentials */
            return $credentials;
        }

        foreach (['client_email', 'private_key'] as $key) {
            if (
                ! isset($credentials[$key])
                || ! is_string($credentials[$key])
                || trim($credentials[$key]) === ''
            ) {
                throw ValidationException::withMessages([
                    'credentials_json' => "Missing Firebase service-account credential: {$key}.",
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
        $imageUrl = isset($payload['image_url']) && is_string($payload['image_url']) && trim($payload['image_url']) !== ''
            ? trim($payload['image_url'])
            : null;

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
                        'notification' => array_filter([
                            'title' => $payload['title'] ?? '',
                            'body' => $payload['body'] ?? '',
                            'image' => $imageUrl,
                        ]),
                        'data' => $payload['data'] ?? [],
                        'android' => [
                            'priority' => 'high',
                            'notification' => (object) array_filter([
                                'sound' => $provider->default_sound ?? 'default',
                                'channel_id' => $provider->default_channel,
                                'icon' => $provider->default_icon,
                                'image' => $imageUrl,
                                'visibility' => 'PUBLIC',
                            ]),
                        ],
                        'apns' => [
                            'headers' => [
                                'apns-priority' => '10',
                            ],
                            'payload' => [
                                'aps' => (object) array_filter([
                                    'sound' => $provider->default_sound ?? 'default',
                                    'category' => $provider->default_category,
                                    'content-available' => 1,
                                    'mutable-content' => $imageUrl === null ? null : 1,
                                ]),
                            ],
                            'fcm_options' => (object) array_filter([
                                'image' => $imageUrl,
                            ]),
                        ],
                    ],
                ],
            );
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function accessToken(PushProviderSetting $provider, array $credentials): string
    {
        $legacy = $credentials['access_token'] ?? null;
        if (is_string($legacy) && trim($legacy) !== '') {
            return trim($legacy);
        }

        $projectId = (string) $credentials['project_id'];
        $clientEmail = (string) $credentials['client_email'];
        $privateKey = str_replace('\\n', "\n", (string) $credentials['private_key']);
        $tokenUri = trim((string) ($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token'));
        if ($tokenUri === '') {
            $tokenUri = 'https://oauth2.googleapis.com/token';
        }

        $cacheKey = 'foodex:fcm-token:'.($provider->getKey() ?? 'new').':'.sha1(
            $projectId.'|'.$clientEmail.'|'.$privateKey,
        );
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $issuedAt = time();
        $header = $this->base64Url(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ], JSON_THROW_ON_ERROR));
        $claims = $this->base64Url(json_encode([
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $tokenUri,
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600,
        ], JSON_THROW_ON_ERROR));
        $unsigned = $header.'.'.$claims;
        $signature = '';
        $signed = openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (! $signed) {
            throw ValidationException::withMessages([
                'credentials_json' => 'Firebase service-account private_key is invalid or unreadable.',
            ]);
        }

        $assertion = $unsigned.'.'.$this->base64Url($signature);
        $response = Http::asForm()
            ->acceptJson()
            ->timeout(12)
            ->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

        if (! $response->successful()) {
            $message = data_get($response->json(), 'error_description')
                ?? data_get($response->json(), 'error.message')
                ?? data_get($response->json(), 'error')
                ?? 'Google OAuth rejected the Firebase service account.';
            throw ValidationException::withMessages([
                'credentials_json' => 'Firebase authentication failed: '.mb_substr((string) $message, 0, 350),
            ]);
        }

        $token = $response->json('access_token');
        if (! is_string($token) || trim($token) === '') {
            throw ValidationException::withMessages([
                'credentials_json' => 'Firebase authentication returned no access_token.',
            ]);
        }

        $expiresIn = max(300, (int) $response->json('expires_in', 3600));
        Cache::put($cacheKey, $token, now()->addSeconds(max(60, $expiresIn - 300)));

        return $token;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function exceptionMessage(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            $first = collect($exception->errors())->flatten()->first();
            if (is_string($first) && trim($first) !== '') {
                return $first;
            }
        }

        return $exception->getMessage() !== ''
            ? $exception->getMessage()
            : class_basename($exception);
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
