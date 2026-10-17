<?php

namespace App\Domain\RestaurantSaaS;

use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RestaurantIntegrationService
{
    public const CONNECTOR_TYPES = ['delivery_marketplace', 'pos', 'generic'];

    public const CAPABILITIES = [
        'menu.read',
        'menu.sync',
        'availability.sync',
        'orders.read',
        'orders.ingest',
        'orders.status.sync',
        'webhooks',
    ];

    /** @return list<array<string,mixed>> */
    public function integrations(SmartOrderingSite $site): array
    {
        return DB::table('restaurant_saas_integrations as i')
            ->leftJoin('smart_ordering_branches as b', 'b.id', '=', 'i.branch_id')
            ->where('i.site_id', $site->getKey())
            ->orderBy('i.display_name')
            ->limit(200)
            ->get([
                'i.public_id', 'i.connector_type', 'i.provider_key', 'i.display_name', 'i.status',
                'i.capabilities', 'i.credential_reference', 'i.last_success_at', 'i.last_failure_at',
                'i.last_error_code', 'b.name_ar as branch_name_ar', 'b.name_en as branch_name_en',
            ])
            ->map(fn (object $row): array => [
                ...((array) $row),
                'capabilities' => $this->decodeList($row->capabilities),
            ])
            ->all();
    }

    /** @param list<string> $capabilities */
    public function install(
        User $actor,
        SmartOrderingSite $site,
        ?int $branchId,
        string $connectorType,
        string $providerKey,
        string $displayName,
        array $capabilities,
        ?string $credentialReference = null,
    ): string {
        abort_unless(in_array($connectorType, self::CONNECTOR_TYPES, true), 422);
        $providerKey = Str::lower(trim($providerKey));
        $displayName = trim($displayName);
        abort_if($providerKey === '' || $displayName === '', 422);

        if ($branchId !== null) {
            $this->assertBranch($site, $branchId);
        }

        $capabilities = array_values(array_unique(array_intersect(self::CAPABILITIES, $capabilities)));
        abort_if($capabilities === [], 422, 'At least one supported connector capability is required.');

        $existing = DB::table('restaurant_saas_integrations')
            ->where('site_id', $site->getKey())
            ->where('provider_key', $providerKey)
            ->when($branchId === null, fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where('branch_id', $branchId))
            ->first(['public_id']);
        abort_if($existing !== null, 409, 'This connector is already installed for the selected scope.');

        $publicId = (string) Str::uuid();
        DB::table('restaurant_saas_integrations')->insert([
            'public_id' => $publicId,
            'site_id' => (int) $site->getKey(),
            'branch_id' => $branchId,
            'connector_type' => $connectorType,
            'provider_key' => mb_substr($providerKey, 0, 80),
            'display_name' => mb_substr($displayName, 0, 120),
            'status' => 'disabled',
            'capabilities' => json_encode($capabilities, JSON_THROW_ON_ERROR),
            'credential_reference' => $credentialReference === null ? null : mb_substr(trim($credentialReference), 0, 255),
            'created_by' => (int) $actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    public function setStatus(SmartOrderingSite $site, string $publicId, string $status): void
    {
        abort_unless(in_array($status, ['disabled', 'enabled', 'degraded', 'suspended'], true), 422);
        $updated = DB::table('restaurant_saas_integrations')
            ->where('site_id', $site->getKey())
            ->where('public_id', $publicId)
            ->update(['status' => $status, 'updated_at' => now()]);
        abort_unless($updated === 1, 404);
    }

    /** @param array<string,mixed> $payload
     * @return array{id:int,duplicate:bool,status:string}
     */
    public function ingest(
        SmartOrderingSite $site,
        string $integrationPublicId,
        string $externalEventKey,
        string $eventType,
        array $payload,
        ?\DateTimeInterface $occurredAt = null,
    ): array {
        $integration = DB::table('restaurant_saas_integrations')
            ->where('site_id', $site->getKey())
            ->where('public_id', $integrationPublicId)
            ->first();
        abort_unless($integration !== null, 404);
        abort_unless($integration->status === 'enabled', 409, 'Connector is not enabled.');

        $capabilities = $this->decodeList($integration->capabilities);
        abort_unless(
            in_array('orders.ingest', $capabilities, true)
            || in_array('menu.sync', $capabilities, true)
            || in_array('availability.sync', $capabilities, true),
            403,
            'Connector does not permit inbound sync events.',
        );

        $externalEventKey = trim($externalEventKey);
        $eventType = trim($eventType);
        abort_if($externalEventKey === '' || $eventType === '', 422);

        $payloadJson = $this->canonicalJson($payload);
        $payloadHash = hash('sha256', $payloadJson);

        $existing = DB::table('restaurant_saas_integration_events')
            ->where('integration_id', $integration->id)
            ->where('direction', 'inbound')
            ->where('external_event_key', $externalEventKey)
            ->first(['id', 'payload_hash', 'status']);

        if ($existing !== null) {
            abort_if(! hash_equals((string) $existing->payload_hash, $payloadHash), 409, 'External event key was reused with a different payload.');

            return ['id' => (int) $existing->id, 'duplicate' => true, 'status' => (string) $existing->status];
        }

        $id = (int) DB::table('restaurant_saas_integration_events')->insertGetId([
            'integration_id' => (int) $integration->id,
            'direction' => 'inbound',
            'event_type' => mb_substr($eventType, 0, 120),
            'external_event_key' => mb_substr($externalEventKey, 0, 190),
            'payload_hash' => $payloadHash,
            'payload_ciphertext' => Crypt::encryptString($payloadJson),
            'status' => 'received',
            'attempts' => 0,
            'occurred_at' => $occurredAt ?? now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('restaurant_saas_integrations')->where('id', $integration->id)->update([
            'last_success_at' => now(),
            'last_error_code' => null,
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'duplicate' => false, 'status' => 'received'];
    }

    /** @param list<string> $scopes
     * @return array{public_id:string,credential:string}
     */
    public function createApiClient(
        User $actor,
        SmartOrderingSite $site,
        string $name,
        array $scopes,
        ?\DateTimeInterface $expiresAt = null,
    ): array {
        $allowed = ['restaurant.read', 'menu.read', 'orders.read', 'integrations.write'];
        $scopes = array_values(array_unique(array_intersect($allowed, $scopes)));
        abort_if($scopes === [], 422, 'At least one supported API scope is required.');

        $publicId = (string) Str::uuid();
        $secret = Str::random(64);
        DB::table('restaurant_saas_api_clients')->insert([
            'public_id' => $publicId,
            'site_id' => (int) $site->getKey(),
            'name' => mb_substr(trim($name), 0, 120),
            'token_hash' => hash('sha256', $secret),
            'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'is_active' => true,
            'expires_at' => $expiresAt,
            'created_by' => (int) $actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['public_id' => $publicId, 'credential' => $publicId.'.'.$secret];
    }

    /** @return array{id:int,site_id:int,scopes:list<string>} */
    public function authorizeCredential(string $credential, string $requiredScope): array
    {
        [$publicId, $secret] = array_pad(explode('.', trim($credential), 2), 2, '');
        abort_unless(Str::isUuid($publicId) && $secret !== '', 401);

        $client = DB::table('restaurant_saas_api_clients')->where('public_id', $publicId)->first();
        abort_unless($client !== null && (bool) $client->is_active, 401);
        abort_if($client->expires_at !== null && now()->gte($client->expires_at), 401);
        abort_unless(hash_equals((string) $client->token_hash, hash('sha256', $secret)), 401);

        $scopes = $this->decodeList($client->scopes);
        abort_unless(in_array($requiredScope, $scopes, true), 403);

        DB::table('restaurant_saas_api_clients')->where('id', $client->id)->update([
            'last_used_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => (int) $client->id, 'site_id' => (int) $client->site_id, 'scopes' => $scopes];
    }

    /** @return array{public_id:string,secret:string} */
    public function createWebhook(
        User $actor,
        SmartOrderingSite $site,
        string $targetUrl,
        array $eventTypes,
    ): array {
        $targetUrl = trim($targetUrl);
        abort_unless(Str::startsWith($targetUrl, 'https://'), 422, 'Webhook target must use HTTPS.');
        $eventTypes = array_values(array_unique(array_filter(array_map('trim', $eventTypes))));
        abort_if($eventTypes === [], 422);

        $secret = Str::random(64);
        $publicId = (string) Str::uuid();
        DB::table('restaurant_saas_webhook_subscriptions')->insert([
            'public_id' => $publicId,
            'site_id' => (int) $site->getKey(),
            'target_url' => mb_substr($targetUrl, 0, 1000),
            'event_types' => json_encode($eventTypes, JSON_THROW_ON_ERROR),
            'secret_ciphertext' => Crypt::encryptString($secret),
            'is_active' => true,
            'created_by' => (int) $actor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['public_id' => $publicId, 'secret' => $secret];
    }

    /** @return list<array<string,mixed>> */
    public function webhooks(SmartOrderingSite $site): array
    {
        return DB::table('restaurant_saas_webhook_subscriptions')
            ->where('site_id', $site->getKey())
            ->orderBy('id')
            ->limit(100)
            ->get(['public_id', 'target_url', 'event_types', 'is_active', 'created_at'])
            ->map(fn (object $row): array => [
                'public_id' => (string) $row->public_id,
                'target_url' => (string) $row->target_url,
                'event_types' => $this->decodeList($row->event_types),
                'is_active' => (bool) $row->is_active,
                'created_at' => (string) $row->created_at,
            ])
            ->all();
    }

    /** @param array<string,mixed> $payload
     * @return array{id:int,signature:string,duplicate:bool}
     */
    public function queueWebhook(
        SmartOrderingSite $site,
        string $subscriptionPublicId,
        string $eventKey,
        string $eventType,
        array $payload,
    ): array {
        $subscription = DB::table('restaurant_saas_webhook_subscriptions')
            ->where('site_id', $site->getKey())
            ->where('public_id', $subscriptionPublicId)
            ->where('is_active', true)
            ->first();
        abort_unless($subscription !== null, 404);

        $eventTypes = $this->decodeList($subscription->event_types);
        abort_unless(in_array($eventType, $eventTypes, true) || in_array('*', $eventTypes, true), 422);

        $raw = $this->canonicalJson($payload);
        $hash = hash('sha256', $raw);
        $existing = DB::table('restaurant_saas_webhook_deliveries')
            ->where('subscription_id', $subscription->id)
            ->where('event_key', $eventKey)
            ->first(['id', 'payload_hash']);
        if ($existing !== null) {
            abort_if(! hash_equals((string) $existing->payload_hash, $hash), 409, 'Webhook event key was reused with a different payload.');

            return [
                'id' => (int) $existing->id,
                'signature' => $this->signature((string) $subscription->secret_ciphertext, $raw),
                'duplicate' => true,
            ];
        }

        $id = (int) DB::table('restaurant_saas_webhook_deliveries')->insertGetId([
            'subscription_id' => (int) $subscription->id,
            'event_key' => mb_substr(trim($eventKey), 0, 190),
            'event_type' => mb_substr(trim($eventType), 0, 120),
            'payload_hash' => $hash,
            'payload_ciphertext' => Crypt::encryptString($raw),
            'status' => 'pending',
            'attempts' => 0,
            'scheduled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $id,
            'signature' => $this->signature((string) $subscription->secret_ciphertext, $raw),
            'duplicate' => false,
        ];
    }

    public function recordIntegrationEventResult(int $eventId, bool $success, ?string $errorCode = null): void
    {
        DB::transaction(function () use ($eventId, $success, $errorCode): void {
            $event = DB::table('restaurant_saas_integration_events')->where('id', $eventId)->lockForUpdate()->first();
            abort_unless($event !== null, 404);

            $attempts = (int) $event->attempts + 1;
            $status = $success ? 'processed' : ($attempts >= 5 ? 'dead_letter' : 'retry');
            DB::table('restaurant_saas_integration_events')->where('id', $eventId)->update([
                'status' => $status,
                'attempts' => $attempts,
                'last_error_code' => $success ? null : mb_substr((string) $errorCode, 0, 80),
                'updated_at' => now(),
            ]);

            DB::table('restaurant_saas_integrations')->where('id', $event->integration_id)->update([
                'last_success_at' => $success ? now() : DB::raw('last_success_at'),
                'last_failure_at' => $success ? DB::raw('last_failure_at') : now(),
                'last_error_code' => $success ? null : mb_substr((string) $errorCode, 0, 80),
                'status' => $success ? DB::raw("CASE WHEN status = 'degraded' THEN 'enabled' ELSE status END") : 'degraded',
                'updated_at' => now(),
            ]);
        }, 3);
    }

    public function recordDeliveryResult(int $deliveryId, ?int $httpStatus, ?string $errorCode): void
    {
        DB::transaction(function () use ($deliveryId, $httpStatus, $errorCode): void {
            $delivery = DB::table('restaurant_saas_webhook_deliveries')->where('id', $deliveryId)->lockForUpdate()->first();
            abort_unless($delivery !== null, 404);

            $attempts = (int) $delivery->attempts + 1;
            $success = $httpStatus !== null && $httpStatus >= 200 && $httpStatus < 300;
            $status = $success ? 'delivered' : ($attempts >= 5 ? 'dead_letter' : 'retry');

            DB::table('restaurant_saas_webhook_deliveries')->where('id', $deliveryId)->update([
                'status' => $status,
                'attempts' => $attempts,
                'http_status' => $httpStatus,
                'last_error_code' => $success ? null : mb_substr((string) $errorCode, 0, 80),
                'scheduled_at' => $success ? $delivery->scheduled_at : now()->addMinutes(min(60, 2 ** $attempts)),
                'delivered_at' => $success ? now() : null,
                'updated_at' => now(),
            ]);
        }, 3);
    }

    /** @return array<string,mixed> */
    public function health(SmartOrderingSite $site): array
    {
        $rows = DB::table('restaurant_saas_integrations')
            ->where('site_id', $site->getKey())
            ->get(['status', 'last_failure_at']);

        return [
            'installed' => $rows->count(),
            'enabled' => $rows->where('status', 'enabled')->count(),
            'degraded' => $rows->whereIn('status', ['degraded', 'suspended'])->count(),
            'dead_letters' => DB::table('restaurant_saas_webhook_deliveries as d')
                ->join('restaurant_saas_webhook_subscriptions as s', 's.id', '=', 'd.subscription_id')
                ->where('s.site_id', $site->getKey())
                ->where('d.status', 'dead_letter')
                ->count(),
        ];
    }

    private function assertBranch(SmartOrderingSite $site, int $branchId): void
    {
        abort_unless(
            DB::table('smart_ordering_branches')->where('id', $branchId)->where('site_id', $site->getKey())->exists(),
            404,
        );
    }

    /** @return list<string> */
    private function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /** @param array<string,mixed> $payload */
    private function canonicalJson(array $payload): string
    {
        ksort($payload);

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function signature(string $secretCiphertext, string $raw): string
    {
        $secret = Crypt::decryptString($secretCiphertext);

        return 'sha256='.hash_hmac('sha256', $raw, $secret);
    }
}
