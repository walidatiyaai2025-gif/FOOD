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
        $supplied = $request->query('hub.verify_token');
        $challenge = $request->query('hub.challenge');
        $mode = $request->query('hub.mode');

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

        $eventId = $ingress->capture($profile, $payload, $raw);

        return response()->json([
            'accepted' => true,
            'event_id' => $eventId,
        ]);
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
