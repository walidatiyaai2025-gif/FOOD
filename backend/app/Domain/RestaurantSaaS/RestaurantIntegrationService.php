<?php

namespace App\Domain\RestaurantSaaS;

use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
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
            'request_count' => DB::raw('request_count + 1'),
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

    /** @return list<array<string,mixed>> */
    public function apiClients(SmartOrderingSite $site): array
    {
        return DB::table('restaurant_saas_api_clients')
            ->where('site_id', $site->getKey())
            ->orderByDesc('id')
            ->limit(200)
            ->get([
                'public_id', 'name', 'scopes', 'is_active', 'expires_at', 'last_used_at',
                'request_count', 'rate_limit_violations', 'last_rate_limit_at', 'created_at',
            ])
            ->map(function (object $row): array {
                $expired = $row->expires_at !== null && now()->gte($row->expires_at);

                return [
                    'public_id' => (string) $row->public_id,
                    'name' => (string) $row->name,
                    'scopes' => $this->decodeList($row->scopes),
                    'state' => ! (bool) $row->is_active ? 'revoked' : ($expired ? 'expired' : 'active'),
                    'expires_at' => $row->expires_at === null ? null : (string) $row->expires_at,
                    'last_used_at' => $row->last_used_at === null ? null : (string) $row->last_used_at,
                    'request_count' => (int) $row->request_count,
                    'rate_limit_violations' => (int) $row->rate_limit_violations,
                    'last_rate_limit_at' => $row->last_rate_limit_at === null ? null : (string) $row->last_rate_limit_at,
                    'created_at' => (string) $row->created_at,
                ];
            })
            ->all();
    }

    /** @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function integrationEvents(SmartOrderingSite $site, array $filters = [], int $limit = 200): array
    {
        $query = DB::table('restaurant_saas_integration_events as e')
            ->join('restaurant_saas_integrations as i', 'i.id', '=', 'e.integration_id')
            ->leftJoin('smart_ordering_branches as b', 'b.id', '=', 'i.branch_id')
            ->where('i.site_id', $site->getKey());

        $this->applyEventFilters($query, $filters);

        $rows = $query
            ->orderByDesc('e.id')
            ->limit(max(1, min(1000, $limit)))
            ->get([
                'e.id', 'e.direction', 'e.event_type', 'e.external_event_key', 'e.status',
                'e.attempts', 'e.replay_count', 'e.last_replayed_at', 'e.last_error_code',
                'e.occurred_at', 'e.created_at', 'i.public_id as integration_public_id',
                'i.provider_key', 'i.display_name', 'i.capabilities',
                'b.id as branch_id', 'b.name_ar as branch_name_ar', 'b.name_en as branch_name_en',
            ]);

        $capability = trim((string) ($filters['capability'] ?? ''));
        if ($capability !== '') {
            $rows = $rows->filter(
                fn (object $row): bool => in_array($capability, $this->decodeList($row->capabilities), true),
            );
        }

        return $rows->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'direction' => (string) $row->direction,
            'event_type' => (string) $row->event_type,
            'external_event_key' => (string) $row->external_event_key,
            'status' => (string) $row->status,
            'attempts' => (int) $row->attempts,
            'replay_count' => (int) $row->replay_count,
            'last_replayed_at' => $row->last_replayed_at === null ? null : (string) $row->last_replayed_at,
            'last_error_code' => $row->last_error_code === null ? null : (string) $row->last_error_code,
            'occurred_at' => $row->occurred_at === null ? null : (string) $row->occurred_at,
            'created_at' => (string) $row->created_at,
            'integration_public_id' => (string) $row->integration_public_id,
            'provider_key' => (string) $row->provider_key,
            'display_name' => (string) $row->display_name,
            'capabilities' => $this->decodeList($row->capabilities),
            'branch_id' => $row->branch_id === null ? null : (int) $row->branch_id,
            'branch_name_ar' => $row->branch_name_ar === null ? null : (string) $row->branch_name_ar,
            'branch_name_en' => $row->branch_name_en === null ? null : (string) $row->branch_name_en,
        ])->values()->all();
    }

    /** @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function webhookDeliveries(SmartOrderingSite $site, array $filters = [], int $limit = 200): array
    {
        $query = DB::table('restaurant_saas_webhook_deliveries as d')
            ->join('restaurant_saas_webhook_subscriptions as s', 's.id', '=', 'd.subscription_id')
            ->where('s.site_id', $site->getKey());

        if (($filters['webhook_status'] ?? '') !== '') {
            $query->where('d.status', (string) $filters['webhook_status']);
        }
        if (($filters['event_type'] ?? '') !== '') {
            $query->where('d.event_type', (string) $filters['event_type']);
        }
        if (($filters['from'] ?? '') !== '') {
            $query->where('d.created_at', '>=', (string) $filters['from'].' 00:00:00');
        }
        if (($filters['to'] ?? '') !== '') {
            $query->where('d.created_at', '<=', (string) $filters['to'].' 23:59:59');
        }
        if (($filters['q'] ?? '') !== '') {
            $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $filters['q'])).'%';
            $query->where(function ($nested) use ($needle): void {
                $nested->where('d.event_key', 'like', $needle)
                    ->orWhere('d.event_type', 'like', $needle)
                    ->orWhere('s.target_url', 'like', $needle);
            });
        }

        return $query
            ->orderByDesc('d.id')
            ->limit(max(1, min(1000, $limit)))
            ->get([
                'd.id', 'd.event_key', 'd.event_type', 'd.status', 'd.attempts', 'd.http_status',
                'd.last_error_code', 'd.scheduled_at', 'd.delivered_at', 'd.created_at',
                'd.replay_count', 'd.last_replayed_at',
                's.public_id as subscription_public_id', 's.target_url', 's.is_active',
            ])
            ->map(function (object $row): array {
                $latencyMs = null;
                if ($row->delivered_at !== null) {
                    $latencyMs = CarbonImmutable::parse((string) $row->created_at)
                        ->diffInMilliseconds(CarbonImmutable::parse((string) $row->delivered_at));
                }

                return [
                    'id' => (int) $row->id,
                    'event_key' => (string) $row->event_key,
                    'event_type' => (string) $row->event_type,
                    'status' => (string) $row->status,
                    'attempts' => (int) $row->attempts,
                    'http_status' => $row->http_status === null ? null : (int) $row->http_status,
                    'last_error_code' => $row->last_error_code === null ? null : (string) $row->last_error_code,
                    'scheduled_at' => $row->scheduled_at === null ? null : (string) $row->scheduled_at,
                    'delivered_at' => $row->delivered_at === null ? null : (string) $row->delivered_at,
                    'created_at' => (string) $row->created_at,
                    'replay_count' => (int) $row->replay_count,
                    'last_replayed_at' => $row->last_replayed_at === null ? null : (string) $row->last_replayed_at,
                    'subscription_public_id' => (string) $row->subscription_public_id,
                    'target_url' => (string) $row->target_url,
                    'subscription_active' => (bool) $row->is_active,
                    'latency_ms' => $latencyMs,
                ];
            })
            ->all();
    }

    public function revokeApiClient(SmartOrderingSite $site, string $publicId): void
    {
        $updated = DB::table('restaurant_saas_api_clients')
            ->where('site_id', $site->getKey())
            ->where('public_id', $publicId)
            ->where('is_active', true)
            ->update(['is_active' => false, 'updated_at' => now()]);

        abort_unless($updated === 1, 404);
    }

    public function setWebhookActive(SmartOrderingSite $site, string $publicId, bool $active): void
    {
        $updated = DB::table('restaurant_saas_webhook_subscriptions')
            ->where('site_id', $site->getKey())
            ->where('public_id', $publicId)
            ->update(['is_active' => $active, 'updated_at' => now()]);

        abort_unless($updated === 1, 404);
    }

    public function retryIntegrationEvent(SmartOrderingSite $site, int $eventId): void
    {
        DB::transaction(function () use ($site, $eventId): void {
            $event = DB::table('restaurant_saas_integration_events as e')
                ->join('restaurant_saas_integrations as i', 'i.id', '=', 'e.integration_id')
                ->where('i.site_id', $site->getKey())
                ->where('e.id', $eventId)
                ->lockForUpdate()
                ->first(['e.id', 'e.status']);

            abort_unless($event !== null, 404);
            abort_unless(in_array((string) $event->status, ['retry', 'dead_letter'], true), 409);

            DB::table('restaurant_saas_integration_events')->where('id', $eventId)->update([
                'status' => 'retry',
                'attempts' => 0,
                'replay_count' => DB::raw('replay_count + 1'),
                'last_replayed_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
    }

    public function replayWebhookDelivery(SmartOrderingSite $site, int $deliveryId): void
    {
        DB::transaction(function () use ($site, $deliveryId): void {
            $delivery = DB::table('restaurant_saas_webhook_deliveries as d')
                ->join('restaurant_saas_webhook_subscriptions as s', 's.id', '=', 'd.subscription_id')
                ->where('s.site_id', $site->getKey())
                ->where('d.id', $deliveryId)
                ->lockForUpdate()
                ->first(['d.id', 'd.status']);

            abort_unless($delivery !== null, 404);
            abort_unless(in_array((string) $delivery->status, ['retry', 'dead_letter'], true), 409);

            DB::table('restaurant_saas_webhook_deliveries')->where('id', $deliveryId)->update([
                'status' => 'retry',
                'attempts' => 0,
                'http_status' => null,
                'scheduled_at' => now(),
                'delivered_at' => null,
                'replay_count' => DB::raw('replay_count + 1'),
                'last_replayed_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
    }

    public function recordRateLimitViolation(string $publicId): void
    {
        if (! Str::isUuid($publicId)) {
            return;
        }

        DB::table('restaurant_saas_api_clients')
            ->where('public_id', $publicId)
            ->update([
                'rate_limit_violations' => DB::raw('rate_limit_violations + 1'),
                'last_rate_limit_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /** @return array<string,mixed> */
    public function analytics(SmartOrderingSite $site): array
    {
        $health = $this->health($site);
        $connectorRows = DB::table('restaurant_saas_integrations')
            ->where('site_id', $site->getKey())
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($value): int => (int) $value)
            ->all();

        $syncRows = DB::table('restaurant_saas_integration_events as e')
            ->join('restaurant_saas_integrations as i', 'i.id', '=', 'e.integration_id')
            ->where('i.site_id', $site->getKey())
            ->selectRaw('e.status, COUNT(*) as aggregate')
            ->groupBy('e.status')
            ->pluck('aggregate', 'status')
            ->map(fn ($value): int => (int) $value)
            ->all();

        $failureRows = DB::table('restaurant_saas_integration_events as e')
            ->join('restaurant_saas_integrations as i', 'i.id', '=', 'e.integration_id')
            ->where('i.site_id', $site->getKey())
            ->whereIn('e.status', ['retry', 'dead_letter'])
            ->selectRaw("COALESCE(e.last_error_code, 'unknown') as reason, COUNT(*) as aggregate")
            ->groupBy('reason')
            ->orderByDesc('aggregate')
            ->limit(10)
            ->pluck('aggregate', 'reason')
            ->map(fn ($value): int => (int) $value)
            ->all();

        $webhookRows = DB::table('restaurant_saas_webhook_deliveries as d')
            ->join('restaurant_saas_webhook_subscriptions as s', 's.id', '=', 'd.subscription_id')
            ->where('s.site_id', $site->getKey())
            ->selectRaw('d.status, COUNT(*) as aggregate')
            ->groupBy('d.status')
            ->pluck('aggregate', 'status')
            ->map(fn ($value): int => (int) $value)
            ->all();

        return [
            'health' => $health,
            'connector_status' => $connectorRows,
            'sync_status' => $syncRows,
            'failure_reasons' => $failureRows,
            'webhook_status' => $webhookRows,
            'api_clients' => $this->apiClients($site),
        ];
    }

    /** @return list<array<string,scalar|null>> */
    public function reportRows(SmartOrderingSite $site, string $report): array
    {
        return match ($report) {
            'connector-health' => array_map(
                fn (array $row): array => [
                    'provider' => (string) $row['provider_key'],
                    'name' => (string) $row['display_name'],
                    'status' => (string) $row['status'],
                    'last_success_at' => $row['last_success_at'] ?? null,
                    'last_failure_at' => $row['last_failure_at'] ?? null,
                    'last_error_code' => $row['last_error_code'] ?? null,
                ],
                $this->integrations($site),
            ),
            'sync-failures' => array_map(
                fn (array $row): array => [
                    'provider' => (string) $row['provider_key'],
                    'event' => (string) $row['event_type'],
                    'external_key' => (string) $row['external_event_key'],
                    'status' => (string) $row['status'],
                    'attempts' => (int) $row['attempts'],
                    'error' => $row['last_error_code'],
                    'occurred_at' => $row['occurred_at'],
                ],
                array_values(array_filter(
                    $this->integrationEvents($site, [], 1000),
                    fn (array $row): bool => in_array($row['status'], ['retry', 'dead_letter'], true),
                )),
            ),
            'api-usage' => array_map(
                fn (array $row): array => [
                    'client' => (string) $row['name'],
                    'state' => (string) $row['state'],
                    'requests' => (int) $row['request_count'],
                    'rate_limit_violations' => (int) $row['rate_limit_violations'],
                    'last_used_at' => $row['last_used_at'],
                    'last_rate_limit_at' => $row['last_rate_limit_at'],
                ],
                $this->apiClients($site),
            ),
            'webhook-performance' => array_map(
                fn (array $row): array => [
                    'event' => (string) $row['event_type'],
                    'status' => (string) $row['status'],
                    'attempts' => (int) $row['attempts'],
                    'http_status' => $row['http_status'],
                    'latency_ms' => $row['latency_ms'],
                    'error' => $row['last_error_code'],
                    'created_at' => $row['created_at'],
                ],
                $this->webhookDeliveries($site, [], 1000),
            ),
            'exceptions' => [
                ...array_map(
                    fn (array $row): array => [
                        'source' => 'sync',
                        'key' => (string) $row['external_event_key'],
                        'status' => (string) $row['status'],
                        'attempts' => (int) $row['attempts'],
                        'error' => $row['last_error_code'],
                        'created_at' => $row['created_at'],
                    ],
                    array_values(array_filter(
                        $this->integrationEvents($site, [], 1000),
                        fn (array $row): bool => in_array($row['status'], ['retry', 'dead_letter'], true),
                    )),
                ),
                ...array_map(
                    fn (array $row): array => [
                        'source' => 'webhook',
                        'key' => (string) $row['event_key'],
                        'status' => (string) $row['status'],
                        'attempts' => (int) $row['attempts'],
                        'error' => $row['last_error_code'],
                        'created_at' => $row['created_at'],
                    ],
                    array_values(array_filter(
                        $this->webhookDeliveries($site, [], 1000),
                        fn (array $row): bool => in_array($row['status'], ['retry', 'dead_letter'], true),
                    )),
                ),
            ],
            default => abort(404),
        };
    }

    /** @return array<string,mixed> */
    public function health(SmartOrderingSite $site): array
    {
        $rows = DB::table('restaurant_saas_integrations')
            ->where('site_id', $site->getKey())
            ->get(['status', 'last_success_at', 'last_failure_at']);

        $events = DB::table('restaurant_saas_integration_events as e')
            ->join('restaurant_saas_integrations as i', 'i.id', '=', 'e.integration_id')
            ->where('i.site_id', $site->getKey())
            ->selectRaw('e.status, COUNT(*) as aggregate')
            ->groupBy('e.status')
            ->pluck('aggregate', 'status');

        $deliveries = DB::table('restaurant_saas_webhook_deliveries as d')
            ->join('restaurant_saas_webhook_subscriptions as s', 's.id', '=', 'd.subscription_id')
            ->where('s.site_id', $site->getKey())
            ->selectRaw('d.status, COUNT(*) as aggregate')
            ->groupBy('d.status')
            ->pluck('aggregate', 'status');

        $clients = $this->apiClients($site);

        return [
            'installed' => $rows->count(),
            'enabled' => $rows->where('status', 'enabled')->count(),
            'degraded' => $rows->whereIn('status', ['degraded', 'suspended'])->count(),
            'sync_success' => (int) ($events['processed'] ?? 0),
            'sync_failure' => (int) ($events['retry'] ?? 0) + (int) ($events['dead_letter'] ?? 0),
            'retry_backlog' => (int) ($events['retry'] ?? 0) + (int) ($deliveries['retry'] ?? 0),
            'dead_letters' => (int) ($events['dead_letter'] ?? 0) + (int) ($deliveries['dead_letter'] ?? 0),
            'webhook_success' => (int) ($deliveries['delivered'] ?? 0),
            'webhook_failure' => (int) ($deliveries['retry'] ?? 0) + (int) ($deliveries['dead_letter'] ?? 0),
            'active_api_clients' => count(array_filter($clients, fn (array $client): bool => $client['state'] === 'active')),
            'revoked_api_clients' => count(array_filter($clients, fn (array $client): bool => $client['state'] === 'revoked')),
            'expired_api_clients' => count(array_filter($clients, fn (array $client): bool => $client['state'] === 'expired')),
            'api_requests' => array_sum(array_column($clients, 'request_count')),
            'rate_limit_violations' => array_sum(array_column($clients, 'rate_limit_violations')),
            'last_success_at' => $rows->pluck('last_success_at')->filter()->sortDesc()->first(),
        ];
    }

    /** @param Builder $query
     * @param  array<string,mixed>  $filters
     */
    private function applyEventFilters($query, array $filters): void
    {
        if (($filters['provider'] ?? '') !== '') {
            $query->where('i.provider_key', (string) $filters['provider']);
        }
        if (($filters['direction'] ?? '') !== '') {
            $query->where('e.direction', (string) $filters['direction']);
        }
        if (($filters['status'] ?? '') !== '') {
            $query->where('e.status', (string) $filters['status']);
        }
        if (($filters['branch_id'] ?? '') !== '') {
            $query->where('i.branch_id', (int) $filters['branch_id']);
        }
        if (($filters['from'] ?? '') !== '') {
            $query->where('e.created_at', '>=', (string) $filters['from'].' 00:00:00');
        }
        if (($filters['to'] ?? '') !== '') {
            $query->where('e.created_at', '<=', (string) $filters['to'].' 23:59:59');
        }
        if (($filters['q'] ?? '') !== '') {
            $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $filters['q'])).'%';
            $query->where(function ($nested) use ($needle): void {
                $nested->where('e.external_event_key', 'like', $needle)
                    ->orWhere('e.event_type', 'like', $needle)
                    ->orWhere('i.provider_key', 'like', $needle);
            });
        }
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
