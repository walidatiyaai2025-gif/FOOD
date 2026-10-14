<?php

namespace App\Domain\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppCall;
use App\Models\WhatsAppConnectionProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class WhatsAppCallingService
{
    public function __construct(
        private readonly RuntimePolicy $policy,
        private readonly ConversationService $conversations,
    ) {}

    /** @return array<string,mixed> */
    public function permissionForConversation(int $conversationId, User $actor): array
    {
        $conversation = $this->conversations->conversationForActor(
            $conversationId,
            $actor,
            'whatsapp.calls.view',
        );
        $phone = $this->recipient($conversation);

        return $this->permissionForPhone($phone, $this->primaryProfile());
    }

    /** @return array<string,mixed> */
    public function requestPermission(
        int $conversationId,
        User $actor,
        ?string $reason = null,
    ): array {
        $conversation = $this->conversations->conversationForActor(
            $conversationId,
            $actor,
            'whatsapp.calls.dial',
        );
        $phone = $this->recipient($conversation);
        $this->guardOutbound($phone);

        $profile = $this->primaryProfile();

        $text = trim((string) $reason);
        if ($text === '') {
            $text = 'FOODEX customer service would like to call you on WhatsApp.';
        }

        $response = $this->post($profile, 'messages', [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->digits($phone),
            'type' => 'interactive',
            'interactive' => [
                'type' => 'call_permission_request',
                'body' => ['text' => mb_substr($text, 0, 1024)],
                'action' => ['name' => 'call_permission_request'],
            ],
        ]);

        return [
            'ok' => true,
            'provider_message_id' => $this->stringOrNull(data_get($response, 'messages.0.id')),
        ];
    }

    public function startOutbound(
        int $conversationId,
        User $actor,
        string $sdpOffer,
    ): WhatsAppCall {
        $conversation = $this->conversations->conversationForActor(
            $conversationId,
            $actor,
            'whatsapp.calls.dial',
        );
        $phone = $this->recipient($conversation);
        $this->guardOutbound($phone);

        $profile = $this->primaryProfile();
        $permission = $this->permissionForPhone($phone, $profile);
        if (! ($permission['can_start'] ?? false)) {
            throw ValidationException::withMessages([
                'call' => ['The customer has not granted an active WhatsApp call permission or the call limit is exhausted.'],
            ]);
        }

        $publicId = (string) Str::uuid();
        $callback = 'foodex:'.$publicId;
        $call = WhatsAppCall::query()->create([
            'public_id' => $publicId,
            'connection_profile_id' => $profile->getKey(),
            'conversation_id' => $conversationId,
            'platform_customer_id' => $conversation->platform_customer_id,
            'store_id' => $conversation->store_id,
            'agent_user_id' => $actor->getKey(),
            'provider_call_id' => null,
            'direction' => 'BUSINESS_INITIATED',
            'status' => 'DIALING',
            'event' => 'connect',
            'from_phone' => $profile->normalized_phone_number,
            'to_phone' => $phone,
            'callback_data' => $callback,
            'started_at' => now(),
            'last_event_at' => now(),
            'metadata' => ['source' => 'foodex_agent_inbox'],
        ]);

        try {
            $response = $this->post($profile, 'calls', [
                'messaging_product' => 'whatsapp',
                'to' => $this->digits($phone),
                'action' => 'connect',
                'session' => [
                    'sdp_type' => 'offer',
                    'sdp' => $sdpOffer,
                ],
                'biz_opaque_callback_data' => $callback,
            ]);
        } catch (Throwable $exception) {
            $call->forceFill([
                'status' => 'FAILED',
                'event' => 'connect_failed',
                'ended_at' => now(),
                'last_event_at' => now(),
                'metadata' => array_merge($call->metadata ?? [], [
                    'error' => mb_substr($exception->getMessage(), 0, 500),
                ]),
            ])->save();

            throw $exception;
        }

        $providerCallId = $this->stringOrNull(data_get($response, 'calls.0.id'));
        if ($providerCallId === null) {
            $call->forceFill([
                'status' => 'FAILED',
                'event' => 'connect_failed',
                'ended_at' => now(),
                'last_event_at' => now(),
            ])->save();

            throw new ProviderTransportException(
                'Meta accepted the call request without a WhatsApp call ID.',
                false,
            );
        }

        $existing = WhatsAppCall::query()
            ->where('connection_profile_id', $profile->getKey())
            ->where('provider_call_id', $providerCallId)
            ->whereKeyNot($call->getKey())
            ->first();

        if ($existing instanceof WhatsAppCall) {
            $existing->forceFill([
                'conversation_id' => $existing->conversation_id ?? $conversationId,
                'platform_customer_id' => $existing->platform_customer_id ?? $conversation->platform_customer_id,
                'store_id' => $existing->store_id ?? $conversation->store_id,
                'agent_user_id' => $existing->agent_user_id ?? $actor->getKey(),
                'callback_data' => $existing->callback_data ?? $callback,
                'updated_at' => now(),
            ])->save();
            $call->delete();

            return $existing->refresh();
        }

        $call->forceFill([
            'provider_call_id' => $providerCallId,
            'last_event_at' => now(),
        ])->save();

        return $call->refresh();
    }

    public function signal(
        WhatsAppCall $call,
        User $actor,
        string $action,
        ?string $sdp = null,
    ): WhatsAppCall {
        abort_unless($call->conversation_id !== null, 404);
        $this->conversations->conversationForActor(
            (int) $call->conversation_id,
            $actor,
            'whatsapp.calls.manage',
        );

        if (! in_array($action, ['pre_accept', 'accept', 'reject', 'terminate'], true)) {
            abort(422);
        }

        if ($call->provider_call_id === null) {
            throw ValidationException::withMessages([
                'call' => ['The WhatsApp call has not received its provider call ID yet.'],
            ]);
        }

        if (in_array($action, ['pre_accept', 'accept'], true)
            && ($sdp === null || trim($sdp) === '')) {
            throw ValidationException::withMessages([
                'sdp' => ['A WebRTC SDP answer is required to accept the call.'],
            ]);
        }

        if ($call->direction === 'USER_INITIATED' && in_array($action, ['pre_accept', 'accept'], true)) {
            $claimed = WhatsAppCall::query()
                ->whereKey($call->getKey())
                ->where(function ($query) use ($actor): void {
                    $query->whereNull('agent_user_id')
                        ->orWhere('agent_user_id', $actor->getKey());
                })
                ->update([
                    'agent_user_id' => $actor->getKey(),
                    'updated_at' => now(),
                ]);
            abort_if($claimed !== 1, 409, 'This call was answered by another agent.');
            $call->refresh();
        }

        abort_if(
            $call->agent_user_id !== null
                && (int) $call->agent_user_id !== (int) $actor->getKey()
                && ! $actor->hasPermission(
                    'whatsapp.customer_service.supervise',
                    $call->store_id === null ? null : (int) $call->store_id,
                ),
            409,
            'This call is owned by another agent.',
        );

        $profile = WhatsAppConnectionProfile::query()->findOrFail((int) $call->connection_profile_id);
        $payload = [
            'messaging_product' => 'whatsapp',
            'call_id' => $call->provider_call_id,
            'action' => $action,
        ];
        if (in_array($action, ['pre_accept', 'accept'], true)) {
            $payload['session'] = [
                'sdp_type' => 'answer',
                'sdp' => $sdp,
            ];
        }
        if ($action === 'accept') {
            $payload['biz_opaque_callback_data'] = $call->callback_data;
        }

        $this->post($profile, 'calls', $payload);

        $updates = [
            'event' => $action,
            'last_event_at' => now(),
        ];
        if ($action === 'pre_accept') {
            $updates['status'] = 'PRE_ACCEPTED';
        } elseif ($action === 'accept') {
            $updates['status'] = 'ACCEPTED';
            $updates['answered_at'] = $call->answered_at ?? now();
        } elseif ($action === 'reject') {
            $updates['status'] = 'REJECTED';
            $updates['ended_at'] = now();
        } elseif ($action === 'terminate') {
            $updates['status'] = 'TERMINATING';
        }

        $call->forceFill($updates)->save();

        return $call->refresh();
    }

    /** @param array<string,mixed> $payload */
    public function ingestWebhook(
        WhatsAppConnectionProfile $profile,
        array $payload,
    ): void {
        foreach (($payload['entry'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach (($entry['changes'] ?? []) as $change) {
                if (! is_array($change) || strtolower((string) ($change['field'] ?? '')) !== 'calls') {
                    continue;
                }

                $value = $change['value'] ?? null;
                if (! is_array($value)) {
                    continue;
                }

                $contactName = $this->stringOrNull(data_get($value, 'contacts.0.profile.name'));

                foreach (($value['calls'] ?? []) as $event) {
                    if (is_array($event)) {
                        $this->ingestCallEvent($profile, $event, $contactName);
                    }
                }

                foreach (($value['statuses'] ?? []) as $status) {
                    if (is_array($status)
                        && strtolower((string) ($status['type'] ?? 'call')) === 'call') {
                        $this->ingestCallStatus($profile, $status);
                    }
                }
            }
        }
    }

    /** @param array<string,mixed> $event */
    private function ingestCallEvent(
        WhatsAppConnectionProfile $profile,
        array $event,
        ?string $contactName,
    ): void {
        $providerId = $this->stringOrNull($event['id'] ?? null);
        if ($providerId === null) {
            return;
        }

        $direction = strtoupper((string) ($event['direction'] ?? 'USER_INITIATED'));
        $direction = in_array($direction, ['USER_INITIATED', 'BUSINESS_INITIATED'], true)
            ? $direction
            : 'USER_INITIATED';
        $eventName = strtolower((string) ($event['event'] ?? 'connect'));
        $callback = $this->stringOrNull($event['biz_opaque_callback_data'] ?? null);

        $call = WhatsAppCall::query()
            ->where('connection_profile_id', $profile->getKey())
            ->where('provider_call_id', $providerId)
            ->first();

        if (! $call instanceof WhatsAppCall && $callback !== null) {
            $call = WhatsAppCall::query()
                ->where('connection_profile_id', $profile->getKey())
                ->where('callback_data', $callback)
                ->first();
        }

        $from = $this->normalizePhone($event['from'] ?? null);
        $to = $this->normalizePhone($event['to'] ?? null);
        $participant = $direction === 'USER_INITIATED' ? $from : $to;

        if (! $call instanceof WhatsAppCall) {
            [$conversationId, $customerId, $storeId] = $this->conversationForPhone(
                $participant,
                $direction === 'USER_INITIATED',
            );

            $call = WhatsAppCall::query()->create([
                'public_id' => (string) Str::uuid(),
                'connection_profile_id' => $profile->getKey(),
                'conversation_id' => $conversationId,
                'platform_customer_id' => $customerId,
                'store_id' => $storeId,
                'agent_user_id' => null,
                'provider_call_id' => $providerId,
                'direction' => $direction,
                'status' => $direction === 'USER_INITIATED' ? 'INCOMING' : 'CONNECTING',
                'from_phone' => $from,
                'to_phone' => $to,
                'callback_data' => $callback,
                'started_at' => $this->timestamp($event['timestamp'] ?? null),
                'last_event_at' => now(),
                'metadata' => $contactName === null ? [] : ['contact_name' => $contactName],
            ]);
        }

        $session = isset($event['session']) && is_array($event['session'])
            ? $event['session']
            : [];
        $status = $call->status;

        if ($eventName === 'connect') {
            $status = $direction === 'USER_INITIATED' ? 'INCOMING' : 'CONNECTING';
        } elseif ($eventName === 'terminate') {
            $status = strtoupper((string) ($event['status'] ?? 'TERMINATED'));
        }

        $updates = [
            'provider_call_id' => $providerId,
            'direction' => $direction,
            'status' => $status,
            'event' => $eventName,
            'from_phone' => $from ?? $call->from_phone,
            'to_phone' => $to ?? $call->to_phone,
            'callback_data' => $callback ?? $call->callback_data,
            'last_event_at' => now(),
        ];

        $sdp = $this->stringOrNull($session['sdp'] ?? null, 250000);
        if ($sdp !== null) {
            $updates['remote_sdp'] = $sdp;
            $updates['remote_sdp_type'] = mb_substr(
                strtolower((string) ($session['sdp_type'] ?? '')),
                0,
                12,
            );
        }

        if ($eventName === 'terminate') {
            $updates['ended_at'] = $this->timestamp($event['end_time'] ?? $event['timestamp'] ?? null) ?? now();
            $duration = $event['duration'] ?? null;
            if (is_numeric($duration)) {
                $updates['duration_seconds'] = max(0, (int) $duration);
            }
        }

        $metadata = is_array($call->metadata) ? $call->metadata : [];
        if ($contactName !== null) {
            $metadata['contact_name'] = $contactName;
        }
        if (isset($event['errors']) && is_array($event['errors'])) {
            $metadata['errors'] = array_slice($event['errors'], 0, 5);
        }
        $updates['metadata'] = $metadata;

        $call->forceFill($updates)->save();
    }

    /** @param array<string,mixed> $status */
    private function ingestCallStatus(
        WhatsAppConnectionProfile $profile,
        array $status,
    ): void {
        $providerId = $this->stringOrNull($status['id'] ?? null);
        if ($providerId === null) {
            return;
        }

        $call = WhatsAppCall::query()
            ->where('connection_profile_id', $profile->getKey())
            ->where('provider_call_id', $providerId)
            ->first();
        if (! $call instanceof WhatsAppCall) {
            return;
        }

        $next = strtoupper((string) ($status['status'] ?? $call->status));
        $updates = [
            'status' => mb_substr($next, 0, 32),
            'event' => 'status',
            'last_event_at' => now(),
        ];

        if ($next === 'ACCEPTED') {
            $updates['answered_at'] = $call->answered_at ?? $this->timestamp($status['timestamp'] ?? null) ?? now();
        } elseif (in_array($next, ['REJECTED', 'FAILED', 'NO_ANSWER', 'COMPLETED', 'TERMINATED', 'CANCELED', 'CANCELLED'], true)) {
            $updates['ended_at'] = $call->ended_at ?? $this->timestamp($status['timestamp'] ?? null) ?? now();
        }

        $call->forceFill($updates)->save();
    }

    /** @return array{0:?int,1:?int,2:?int} */
    private function conversationForPhone(?string $phone, bool $createInbound): array
    {
        if ($phone === null) {
            return [null, null, null];
        }

        $digits = $this->digits($phone);
        $customer = DB::table('platform_customers')
            ->where('is_active', true)
            ->whereIn('phone', [$phone, $digits])
            ->first();

        $customerId = $customer === null ? null : (int) $customer->id;
        $storeId = $customer?->origin_store_id === null ? null : (int) $customer->origin_store_id;

        $existing = DB::table('whats_app_conversations')
            ->whereNotIn('status', ['RESOLVED', 'CLOSED'])
            ->where(function ($query) use ($customerId, $phone): void {
                if ($customerId !== null) {
                    $query->where('platform_customer_id', $customerId);
                } else {
                    $query->whereNull('platform_customer_id')
                        ->where('external_participant_phone', $phone);
                }
            })
            ->orderByDesc('updated_at')
            ->value('id');

        if ($existing !== null) {
            return [(int) $existing, $customerId, $storeId];
        }

        if (! $createInbound) {
            return [null, $customerId, $storeId];
        }

        $conversationId = $this->conversations->findOrCreateForInbound(
            $customerId,
            $storeId,
            'whatsapp',
            $phone,
        );

        return [$conversationId, $customerId, $storeId];
    }

    /** @return array<string,mixed> */
    private function permissionForPhone(
        string $phone,
        WhatsAppConnectionProfile $profile,
    ): array {
        if (! $this->policy->allowsCallWebhooks()) {
            throw ValidationException::withMessages([
                'call' => ['WhatsApp calling is disabled by the current runtime policy.'],
            ]);
        }

        $response = $this->get($profile, 'call_permissions', [
            'user_wa_id' => $this->digits($phone),
        ]);

        $actions = is_array($response['actions'] ?? null) ? $response['actions'] : [];
        $canStart = false;
        $canRequest = false;
        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }
            $name = (string) ($action['action_name'] ?? '');
            $allowed = (bool) ($action['can_perform_action'] ?? false);
            if ($name === 'start_call') {
                $canStart = $allowed;
            } elseif ($name === 'send_call_permission_request') {
                $canRequest = $allowed;
            }
        }

        return [
            'status' => strtolower((string) data_get($response, 'permission.status', 'no_permission')),
            'expiration_time' => data_get($response, 'permission.expiration_time'),
            'can_start' => $canStart,
            'can_request' => $canRequest,
            'actions' => $actions,
        ];
    }

    private function guardOutbound(string $phone): void
    {
        if (! $this->policy->allowsOutboundCalls($phone)) {
            throw ValidationException::withMessages([
                'call' => ['Outbound WhatsApp calling is blocked by the current lifecycle, emergency stop, capability, or test-number policy.'],
            ]);
        }
    }

    private function primaryProfile(): WhatsAppConnectionProfile
    {
        $profile = WhatsAppConnectionProfile::query()
            ->where('is_primary', true)
            ->where('status', ConnectionProfileStatus::ACTIVE->value)
            ->first();

        if (! $profile instanceof WhatsAppConnectionProfile) {
            throw ValidationException::withMessages([
                'call' => ['No active primary WhatsApp connection is configured.'],
            ]);
        }

        if (! in_array(strtolower((string) $profile->provider_type), ['meta_cloud', 'meta', 'whatsapp_cloud'], true)) {
            throw ValidationException::withMessages([
                'call' => ['WhatsApp voice calling currently requires a Meta Cloud connection.'],
            ]);
        }

        return $profile;
    }

    private function recipient(object $conversation): string
    {
        $phone = $conversation->external_participant_phone ?? null;
        if ($conversation->platform_customer_id !== null) {
            $phone = DB::table('platform_customers')
                ->where('id', $conversation->platform_customer_id)
                ->value('phone') ?? $phone;
        }

        $normalized = $this->normalizePhone($phone);
        if ($normalized === null) {
            throw ValidationException::withMessages([
                'call' => ['The selected conversation has no valid WhatsApp phone number.'],
            ]);
        }

        return $normalized;
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function post(
        WhatsAppConnectionProfile $profile,
        string $resource,
        array $payload,
    ): array {
        $token = $this->accessToken($profile);
        $phoneId = trim((string) ($profile->provider_phone_number_id ?? ''));
        if ($token === '' || $phoneId === '') {
            throw new ProviderTransportException('Meta connection is not configured for WhatsApp calling.', false);
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout())
                ->post($this->graphUrl($profile, $phoneId).'/'.$resource, $payload);
        } catch (ConnectionException) {
            throw new ProviderTransportException('Meta WhatsApp Calling API is temporarily unreachable.', true);
        } catch (Throwable $exception) {
            if ($exception instanceof ProviderTransportException) {
                throw $exception;
            }
            throw new ProviderTransportException('Meta WhatsApp Calling API request failed.', true);
        }

        if (! $response->successful()) {
            $message = trim((string) $response->json('error.message', ''));
            throw new ProviderTransportException(
                'Meta WhatsApp Calling API returned HTTP '.$response->status()
                    .($message === '' ? '.' : ': '.mb_substr($message, 0, 300)),
                $response->status() === 429 || $response->serverError(),
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /** @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function get(
        WhatsAppConnectionProfile $profile,
        string $resource,
        array $query,
    ): array {
        $token = $this->accessToken($profile);
        $phoneId = trim((string) ($profile->provider_phone_number_id ?? ''));
        if ($token === '' || $phoneId === '') {
            throw new ProviderTransportException('Meta connection is not configured for WhatsApp calling.', false);
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout($this->timeout())
                ->get($this->graphUrl($profile, $phoneId).'/'.$resource, $query);
        } catch (ConnectionException) {
            throw new ProviderTransportException('Meta WhatsApp Calling API is temporarily unreachable.', true);
        } catch (Throwable) {
            throw new ProviderTransportException('Meta WhatsApp Calling API request failed.', true);
        }

        if (! $response->successful()) {
            $message = trim((string) $response->json('error.message', ''));
            throw new ProviderTransportException(
                'Meta WhatsApp Calling API returned HTTP '.$response->status()
                    .($message === '' ? '.' : ': '.mb_substr($message, 0, 300)),
                $response->status() === 429 || $response->serverError(),
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function graphUrl(WhatsAppConnectionProfile $profile, string $resource): string
    {
        $base = rtrim((string) config('whatsapp.meta.base_url', 'https://graph.facebook.com'), '/');
        $metadata = $profile->provider_metadata;
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

    private function digits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function normalizePhone(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        return $digits === '' ? null : '+'.$digits;
    }

    private function timestamp(mixed $value): ?Carbon
    {
        if (is_numeric($value) && (int) $value > 0) {
            return Carbon::createFromTimestamp((int) $value);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value, int $max = 512): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
