<?php

namespace App\Domain\WhatsApp;

use App\Models\WhatsAppConnectionProfile;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class MetaCloudWhatsAppProvider implements WhatsAppProvider
{
    public function __construct(private readonly WhatsAppMediaService $media) {}

    public function validate(WhatsAppConnectionProfile $profile): ConnectionValidationResult
    {
        $token = $this->accessToken($profile);
        $phoneId = trim((string) ($profile->provider_phone_number_id ?? ''));

        if ($token === '' || $phoneId === '') {
            return new ConnectionValidationResult(
                valid: false,
                health: ConnectionHealthStatus::INVALID_CREDENTIALS,
                accountIdentity: null,
                phoneIdentity: null,
                providerAvailable: true,
                capabilities: [],
                diagnosticCode: 'missing_credentials',
                message: 'Meta access token and phone number ID are required.',
                checkedAt: new DateTimeImmutable,
            );
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout($this->timeout())
                ->get($this->graphUrl($profile, $phoneId), [
                    'fields' => 'id,display_phone_number,verified_name',
                ]);
        } catch (ConnectionException) {
            return $this->unavailable('provider_connection_failed');
        } catch (Throwable) {
            return $this->unavailable('provider_validation_failed');
        }

        if (! $response->successful()) {
            $invalidCredentials = in_array($response->status(), [400, 401, 403], true);

            return new ConnectionValidationResult(
                valid: false,
                health: $invalidCredentials
                    ? ConnectionHealthStatus::INVALID_CREDENTIALS
                    : ConnectionHealthStatus::PROVIDER_UNAVAILABLE,
                accountIdentity: null,
                phoneIdentity: null,
                providerAvailable: ! $response->serverError(),
                capabilities: [],
                diagnosticCode: $invalidCredentials ? 'invalid_credentials' : 'provider_http_error',
                message: $invalidCredentials
                    ? 'Meta rejected the configured connection credentials.'
                    : 'Meta provider validation is temporarily unavailable.',
                checkedAt: new DateTimeImmutable,
            );
        }

        if (! $profile->webhookSecretsConfigured()) {
            return new ConnectionValidationResult(
                valid: false,
                health: ConnectionHealthStatus::WEBHOOK_UNCONFIRMED,
                accountIdentity: $this->stringOrNull($response->json('verified_name')),
                phoneIdentity: $this->stringOrNull($response->json('display_phone_number')),
                providerAvailable: true,
                capabilities: ['text', 'template', 'media'],
                diagnosticCode: 'webhook_secrets_missing',
                message: 'Meta API is reachable, but webhook verification secrets are not configured.',
                checkedAt: new DateTimeImmutable,
            );
        }

        if ($profile->last_webhook_at === null) {
            return new ConnectionValidationResult(
                valid: false,
                health: ConnectionHealthStatus::WEBHOOK_UNCONFIRMED,
                accountIdentity: $this->stringOrNull($response->json('verified_name')),
                phoneIdentity: $this->stringOrNull($response->json('display_phone_number')),
                providerAvailable: true,
                capabilities: ['text', 'template', 'media'],
                diagnosticCode: 'webhook_not_confirmed',
                message: 'Meta API is reachable, but the configured webhook callback has not been verified.',
                checkedAt: new DateTimeImmutable,
            );
        }

        return new ConnectionValidationResult(
            valid: true,
            health: ConnectionHealthStatus::READY,
            accountIdentity: $this->stringOrNull($response->json('verified_name')),
            phoneIdentity: $this->stringOrNull($response->json('display_phone_number')) ?? $profile->normalized_phone_number,
            providerAvailable: true,
            capabilities: ['text', 'template', 'media', 'webhooks', 'delivery_status'],
            diagnosticCode: 'ok',
            message: 'Meta Cloud API connection validated.',
            checkedAt: new DateTimeImmutable,
        );
    }

    public function send(WhatsAppConnectionProfile $profile, OutboundMessage $message): ProviderSendResult
    {
        $token = $this->accessToken($profile);
        $phoneId = trim((string) ($profile->provider_phone_number_id ?? ''));

        if ($token === '' || $phoneId === '') {
            throw new ProviderTransportException('Meta connection is not configured.', false);
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout())
                ->post(
                    $this->graphUrl($profile, $phoneId).'/messages',
                    $this->messagePayload($profile, $message),
                );
        } catch (ConnectionException) {
            throw new ProviderTransportException(
                'Meta transport outcome is ambiguous after a network failure.',
                false,
                true,
            );
        } catch (ProviderTransportException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ProviderTransportException('Meta transport failed before confirmation.', true);
        }

        if (! $response->successful()) {
            $retryable = $response->status() === 429 || $response->serverError();
            throw new ProviderTransportException(
                'Meta transport returned HTTP '.$response->status().'.',
                $retryable,
            );
        }

        $providerId = $this->stringOrNull($response->json('messages.0.id'));
        if ($providerId === null) {
            throw new ProviderTransportException(
                'Meta accepted the request without a provider message ID.',
                false,
                true,
            );
        }

        return new ProviderSendResult($providerId);
    }

    public function normalizeWebhook(WhatsAppConnectionProfile $profile, array $payload): array
    {
        $events = [];

        foreach (($payload['entry'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach (($entry['changes'] ?? []) as $change) {
                if (! is_array($change)) {
                    continue;
                }
                if (strtolower((string) ($change['field'] ?? '')) === 'calls') {
                    // Voice-call signaling is handled by WhatsAppCallingService.
                    continue;
                }
                $value = $change['value'] ?? null;
                if (! is_array($value)) {
                    continue;
                }

                $to = isset($value['metadata']['display_phone_number'])
                    ? (string) $value['metadata']['display_phone_number']
                    : (string) $profile->normalized_phone_number;

                foreach (($value['messages'] ?? []) as $message) {
                    if (! is_array($message)) {
                        continue;
                    }
                    $type = strtolower((string) ($message['type'] ?? 'unknown'));
                    $metadata = [];
                    $body = null;

                    if ($type === 'text') {
                        $body = isset($message['text']['body']) ? (string) $message['text']['body'] : null;
                    } elseif (isset($message[$type]) && is_array($message[$type])) {
                        $metadata = $this->safeMediaMetadata($type, $message[$type]);
                        $body = isset($message[$type]['caption']) ? (string) $message[$type]['caption'] : null;
                    }

                    $events[] = new ProviderWebhookEvent(
                        kind: 'message',
                        providerMessageId: $this->stringOrNull($message['id'] ?? null),
                        from: $this->normalizePhone($message['from'] ?? null),
                        to: $this->normalizePhone($to),
                        contentType: $type,
                        body: $body,
                        metadata: $metadata,
                        deliveryStatus: null,
                        occurredAt: $this->timestamp($message['timestamp'] ?? null),
                    );
                }

                foreach (($value['statuses'] ?? []) as $status) {
                    if (! is_array($status)) {
                        continue;
                    }

                    $errorCodes = [];
                    foreach (($status['errors'] ?? []) as $error) {
                        if (is_array($error) && isset($error['code'])) {
                            $errorCodes[] = (string) $error['code'];
                        }
                    }

                    $events[] = new ProviderWebhookEvent(
                        kind: 'status',
                        providerMessageId: $this->stringOrNull($status['id'] ?? null),
                        from: null,
                        to: $this->normalizePhone($status['recipient_id'] ?? null),
                        contentType: null,
                        body: null,
                        metadata: $errorCodes === [] ? [] : ['error_codes' => $errorCodes],
                        deliveryStatus: strtolower((string) ($status['status'] ?? 'unknown')),
                        occurredAt: $this->timestamp($status['timestamp'] ?? null),
                    );
                }
            }
        }

        return $events;
    }

    /** @return array<string, mixed> */
    private function messagePayload(
        WhatsAppConnectionProfile $profile,
        OutboundMessage $message,
    ): array
    {
        $base = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => preg_replace('/\D+/', '', $message->recipient) ?? $message->recipient,
        ];

        if ($message->kind === 'template') {
            $name = trim((string) ($message->metadata['template_name'] ?? ''));
            $language = trim((string) ($message->metadata['language'] ?? 'en_US'));
            if ($name === '') {
                throw new ProviderTransportException('Template name is required.', false);
            }

            $template = [
                'name' => $name,
                'language' => ['code' => $language],
            ];
            if (isset($message->metadata['components']) && is_array($message->metadata['components'])) {
                $template['components'] = $message->metadata['components'];
            }

            return $base + ['type' => 'template', 'template' => $template];
        }

        if ($message->kind === 'media') {
            $mediaType = strtolower((string) ($message->metadata['media_type'] ?? ''));
            if (! in_array($mediaType, ['image', 'document', 'video', 'audio', 'sticker'], true)) {
                throw new ProviderTransportException('Unsupported Meta media type.', false);
            }

            $media = [];
            $mediaId = trim((string) ($message->metadata['media_id'] ?? ''));
            $mediaLink = trim((string) ($message->metadata['link'] ?? ''));
            if ($mediaId === '' && $mediaLink === '' && ! empty($message->metadata['local_path'])) {
                $mediaId = $this->media->uploadToMeta($profile, $message->metadata);
            }

            if ($mediaId !== '') {
                $media['id'] = $mediaId;
            } elseif ($mediaLink !== '') {
                $media['link'] = $mediaLink;
            } else {
                throw new ProviderTransportException('Media ID, link or local attachment is required.', false);
            }

            if ($message->body !== null && trim($message->body) !== '' && ! in_array($mediaType, ['audio', 'sticker'], true)) {
                $media['caption'] = trim($message->body);
            }
            if ($mediaType === 'document' && isset($message->metadata['filename'])) {
                $media['filename'] = mb_substr((string) $message->metadata['filename'], 0, 255);
            }

            return $base + ['type' => $mediaType, $mediaType => $media];
        }

        $body = trim((string) ($message->body ?? ''));
        if ($body === '') {
            throw new ProviderTransportException('Text body is required.', false);
        }

        return $base + [
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $body,
            ],
        ];
    }

    /** @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function safeMediaMetadata(string $type, array $source): array
    {
        $allowed = [];
        foreach (['id', 'mime_type', 'sha256', 'filename', 'caption'] as $key) {
            if (isset($source[$key]) && (is_string($source[$key]) || is_numeric($source[$key]))) {
                $allowed[$key] = mb_substr((string) $source[$key], 0, 500);
            }
        }

        return ['media_type' => $type] + $allowed;
    }

    private function graphUrl(WhatsAppConnectionProfile $profile, string $resource): string
    {
        $base = rtrim((string) config('whatsapp.meta.base_url', 'https://graph.facebook.com'), '/');
        $metadata = $profile->getAttribute('provider_metadata');
        $metadata = is_array($metadata) ? $metadata : [];
        $version = trim((string) ($metadata['graph_version'] ?? config('whatsapp.meta.graph_version', 'v26.0')));
        if (preg_match('/^v\d+\.\d+$/', $version) !== 1) {
            $version = 'v26.0';
        }

        return $base.'/'.$version.'/'.rawurlencode($resource);
    }

    private function accessToken(WhatsAppConnectionProfile $profile): string
    {
        $token = $profile->credential_secret;

        return is_string($token) ? trim($token) : '';
    }

    private function timeout(): int
    {
        return max(1, min(30, (int) config('whatsapp.meta.timeout_seconds', 10)));
    }

    private function unavailable(string $code): ConnectionValidationResult
    {
        return new ConnectionValidationResult(
            valid: false,
            health: ConnectionHealthStatus::PROVIDER_UNAVAILABLE,
            accountIdentity: null,
            phoneIdentity: null,
            providerAvailable: false,
            capabilities: [],
            diagnosticCode: $code,
            message: 'Meta Cloud API is temporarily unavailable.',
            checkedAt: new DateTimeImmutable,
        );
    }

    private function timestamp(mixed $value): DateTimeImmutable
    {
        if (is_numeric($value) && (int) $value > 0) {
            return (new DateTimeImmutable)->setTimestamp((int) $value);
        }

        return new DateTimeImmutable;
    }

    private function normalizePhone(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        return $digits === '' ? null : '+'.$digits;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 500);
    }
}
