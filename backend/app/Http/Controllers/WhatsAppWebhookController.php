<?php

namespace App\Http\Controllers;

use App\Domain\WhatsApp\WebhookIngress;
use App\Models\WhatsAppConnectionProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JsonException;

final class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request, WhatsAppConnectionProfile $profile): Response
    {
        abort_unless($this->isMeta($profile), 404);

        $configured = $profile->webhook_verify_token;
        $supplied = $request->query('hub.verify_token', $request->query('hub_verify_token'));
        $challenge = $request->query('hub.challenge', $request->query('hub_challenge'));
        $mode = $request->query('hub.mode', $request->query('hub_mode'));

        abort_unless(
            is_string($configured)
            && $configured !== ''
            && is_string($supplied)
            && hash_equals($configured, $supplied)
            && $mode === 'subscribe'
            && is_string($challenge),
            403,
        );

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function ingest(
        Request $request,
        WhatsAppConnectionProfile $profile,
        WebhookIngress $ingress,
    ): JsonResponse {
        abort_unless($this->isMeta($profile), 404);

        $raw = $request->getContent();
        $secret = $profile->webhook_signing_secret;
        $signature = strtolower((string) $request->header('X-Hub-Signature-256', ''));

        abort_unless(is_string($secret) && $secret !== '', 404);
        $expected = 'sha256='.hash_hmac('sha256', $raw, $secret);
        abort_unless(hash_equals($expected, $signature), 401);

        try {
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(400);
        }
        abort_unless(is_array($payload), 400);
        abort_unless($this->matchesProfilePhoneId($profile, $payload), 404);

        $eventId = $ingress->capture($profile, $payload, $raw);

        return response()->json([
            'accepted' => true,
            'event_id' => $eventId,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function matchesProfilePhoneId(
        WhatsAppConnectionProfile $profile,
        array $payload,
    ): bool {
        $configured = trim((string) ($profile->provider_phone_number_id ?? ''));
        if ($configured === '') {
            return false;
        }

        $observed = [];
        foreach (($payload['entry'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            foreach (($entry['changes'] ?? []) as $change) {
                if (! is_array($change)) {
                    continue;
                }
                $value = $change['value'] ?? null;
                if (! is_array($value)) {
                    continue;
                }
                $phoneId = $value['metadata']['phone_number_id'] ?? null;
                if (is_string($phoneId) || is_numeric($phoneId)) {
                    $phoneId = trim((string) $phoneId);
                    if ($phoneId !== '') {
                        $observed[] = $phoneId;
                    }
                }
            }
        }

        if ($observed === []) {
            return true;
        }

        foreach (array_unique($observed) as $phoneId) {
            if (! hash_equals($configured, $phoneId)) {
                return false;
            }
        }

        return true;
    }

    private function isMeta(WhatsAppConnectionProfile $profile): bool
    {
        return in_array(strtolower((string) $profile->provider_type), [
            'meta_cloud',
            'meta',
            'whatsapp_cloud',
        ], true);
    }
}
