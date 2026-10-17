<?php

namespace Tests\Feature;

use App\Domain\RestaurantSaaS\RestaurantIntegrationService;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RestaurantSaaSIntegrationHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_connector_ingest_api_credentials_and_webhook_delivery_are_scoped_idempotent_and_secret_safe(): void
    {
        $actor = User::query()->create([
            'name' => 'Integration Owner',
            'email' => 'integration-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password123',
            'is_active' => true,
        ]);
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'INT-'.Str::upper(Str::random(6)),
            'name' => 'Integration Restaurant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $site = SmartOrderingSite::query()->create([
            'public_id' => (string) Str::uuid(),
            'store_id' => $storeId,
            'slug' => 'integration-'.Str::lower(Str::random(8)),
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
        ]);
        $branchId = (int) DB::table('smart_ordering_branches')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'site_id' => $site->getKey(),
            'code' => 'MAIN',
            'name_en' => 'Main',
            'timezone' => 'Asia/Kuwait',
            'is_default' => true,
            'is_active' => true,
            'accepting_orders' => true,
            'supports_dine_in' => true,
            'supports_pickup' => true,
            'supports_delivery' => true,
            'allow_scheduled' => true,
            'pickup_lead_minutes' => 0,
            'max_schedule_days' => 7,
            'service_charge' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(RestaurantIntegrationService::class);
        $connector = $service->install(
            $actor,
            $site,
            $branchId,
            'delivery_marketplace',
            'marketplace_demo',
            'Marketplace Demo',
            ['orders.ingest', 'orders.status.sync'],
            'vault://restaurants/demo',
        );
        $service->setStatus($site, $connector, 'enabled');

        $first = $service->ingest($site, $connector, 'evt-1001', 'order.accepted', ['b' => 2, 'a' => 1]);
        $duplicate = $service->ingest($site, $connector, 'evt-1001', 'order.accepted', ['b' => 2, 'a' => 1]);

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame($first['id'], $duplicate['id']);
        $this->assertDatabaseCount('restaurant_saas_integration_events', 1);

        try {
            $service->ingest($site, $connector, 'evt-1001', 'order.accepted', ['a' => 99]);
            $this->fail('Reusing an external event key with a changed payload must fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $client = $service->createApiClient($actor, $site, 'Partner app', ['restaurant.read', 'orders.read']);
        $authorized = $service->authorizeCredential($client['credential'], 'orders.read');
        $this->assertSame((int) $site->getKey(), $authorized['site_id']);
        $storedClient = DB::table('restaurant_saas_api_clients')->where('public_id', $client['public_id'])->firstOrFail();
        $this->assertStringNotContainsString(substr($client['credential'], -20), (string) $storedClient->token_hash);

        $hook = $service->createWebhook($actor, $site, 'https://partner.example.test/foodex', ['order.created']);
        $subscription = DB::table('restaurant_saas_webhook_subscriptions')->where('public_id', $hook['public_id'])->firstOrFail();
        $this->assertNotSame($hook['secret'], $subscription->secret_ciphertext);

        $delivery = $service->queueWebhook($site, $hook['public_id'], 'order:1001', 'order.created', ['order' => '1001']);
        $replay = $service->queueWebhook($site, $hook['public_id'], 'order:1001', 'order.created', ['order' => '1001']);
        $this->assertFalse($delivery['duplicate']);
        $this->assertTrue($replay['duplicate']);
        $this->assertSame($delivery['signature'], $replay['signature']);
        $this->assertStringStartsWith('sha256=', $delivery['signature']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $service->recordDeliveryResult($delivery['id'], 503, 'partner_unavailable');
        }
        $this->assertDatabaseHas('restaurant_saas_webhook_deliveries', [
            'id' => $delivery['id'],
            'status' => 'dead_letter',
            'attempts' => 5,
        ]);

        $health = $service->health($site);
        $this->assertSame(1, $health['enabled']);
        $this->assertSame(1, $health['dead_letters']);
    }

    public function test_connector_branch_scope_rejects_foreign_site_branch_and_disabled_connector_ingest(): void
    {
        $actor = User::query()->create([
            'name' => 'Integration Scope Owner',
            'email' => 'integration-scope-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password123',
            'is_active' => true,
        ]);
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeA = (int) DB::table('stores')->insertGetId(['store_type_id' => $storeTypeId, 'code' => 'INT-A', 'name' => 'A', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $storeB = (int) DB::table('stores')->insertGetId(['store_type_id' => $storeTypeId, 'code' => 'INT-B', 'name' => 'B', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $siteA = SmartOrderingSite::query()->create(['public_id' => (string) Str::uuid(), 'store_id' => $storeA, 'slug' => 'int-a', 'runtime_state' => 'ENABLED', 'master_enabled' => true]);
        $siteB = SmartOrderingSite::query()->create(['public_id' => (string) Str::uuid(), 'store_id' => $storeB, 'slug' => 'int-b', 'runtime_state' => 'ENABLED', 'master_enabled' => true]);
        $foreignBranch = (int) DB::table('smart_ordering_branches')->insertGetId([
            'public_id' => (string) Str::uuid(), 'site_id' => $siteB->getKey(), 'code' => 'FOREIGN', 'name_en' => 'Foreign',
            'timezone' => 'Asia/Kuwait', 'is_default' => true, 'is_active' => true, 'accepting_orders' => true,
            'supports_dine_in' => true, 'supports_pickup' => true, 'supports_delivery' => true, 'allow_scheduled' => true,
            'pickup_lead_minutes' => 0, 'max_schedule_days' => 7, 'service_charge' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = app(RestaurantIntegrationService::class);
        try {
            $service->install($actor, $siteA, $foreignBranch, 'generic', 'bad', 'Bad', ['orders.ingest']);
            $this->fail('Foreign site branch must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $connector = $service->install($actor, $siteA, null, 'generic', 'disabled_demo', 'Disabled', ['orders.ingest']);
        try {
            $service->ingest($siteA, $connector, 'evt-disabled', 'order.accepted', ['x' => 1]);
            $this->fail('Disabled connector ingest must fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_observability_read_models_replay_and_revocation_are_site_scoped_and_secret_safe(): void
    {
        $actor = User::query()->create([
            'name' => 'Integration Observer',
            'email' => 'integration-observer-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password123',
            'is_active' => true,
        ]);
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'OBS-'.Str::upper(Str::random(6)),
            'name' => 'Observability Restaurant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $site = SmartOrderingSite::query()->create([
            'public_id' => (string) Str::uuid(),
            'store_id' => $storeId,
            'slug' => 'obs-'.Str::lower(Str::random(8)),
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
        ]);

        $service = app(RestaurantIntegrationService::class);
        $connector = $service->install(
            $actor,
            $site,
            null,
            'generic',
            'observability_demo',
            'Observability Demo',
            ['orders.ingest', 'webhooks'],
            'vault://restaurants/observability',
        );
        $service->setStatus($site, $connector, 'enabled');

        $event = $service->ingest($site, $connector, 'evt-observe-1', 'order.accepted', ['secret_payload' => 'never-render']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $service->recordIntegrationEventResult($event['id'], false, 'provider_timeout');
        }
        $this->assertDatabaseHas('restaurant_saas_integration_events', [
            'id' => $event['id'],
            'status' => 'dead_letter',
            'attempts' => 5,
        ]);

        $service->retryIntegrationEvent($site, $event['id']);
        $this->assertDatabaseHas('restaurant_saas_integration_events', [
            'id' => $event['id'],
            'status' => 'retry',
            'attempts' => 0,
            'replay_count' => 1,
        ]);

        $client = $service->createApiClient($actor, $site, 'Observed client', ['restaurant.read']);
        $service->authorizeCredential($client['credential'], 'restaurant.read');
        $service->authorizeCredential($client['credential'], 'restaurant.read');
        $service->recordRateLimitViolation($client['public_id']);

        $clients = $service->apiClients($site);
        $this->assertSame(2, $clients[0]['request_count']);
        $this->assertSame(1, $clients[0]['rate_limit_violations']);
        $this->assertArrayNotHasKey('token_hash', $clients[0]);

        $service->revokeApiClient($site, $client['public_id']);
        $this->assertSame('revoked', $service->apiClients($site)[0]['state']);

        $hook = $service->createWebhook($actor, $site, 'https://partner.example.test/observe', ['order.created']);
        $delivery = $service->queueWebhook($site, $hook['public_id'], 'order:observe:1', 'order.created', ['private' => 'never-render']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $service->recordDeliveryResult($delivery['id'], 503, 'partner_timeout');
        }

        $service->replayWebhookDelivery($site, $delivery['id']);
        $this->assertDatabaseHas('restaurant_saas_webhook_deliveries', [
            'id' => $delivery['id'],
            'status' => 'retry',
            'attempts' => 0,
            'replay_count' => 1,
        ]);

        $service->setWebhookActive($site, $hook['public_id'], false);
        $this->assertFalse($service->webhooks($site)[0]['is_active']);

        $events = $service->integrationEvents($site, ['provider' => 'observability_demo']);
        $this->assertCount(1, $events);
        $this->assertSame('evt-observe-1', $events[0]['external_event_key']);
        $this->assertArrayNotHasKey('payload_ciphertext', $events[0]);

        $deliveries = $service->webhookDeliveries($site);
        $this->assertCount(1, $deliveries);
        $this->assertArrayNotHasKey('payload_ciphertext', $deliveries[0]);
        $this->assertArrayNotHasKey('secret_ciphertext', $deliveries[0]);

        $analytics = $service->analytics($site);
        $this->assertSame(1, $analytics['health']['installed']);
        $this->assertSame(2, $analytics['health']['retry_backlog']);
        $this->assertSame(2, $analytics['health']['api_requests']);
        $this->assertSame(1, $analytics['health']['rate_limit_violations']);

        foreach (['connector-health', 'sync-failures', 'api-usage', 'webhook-performance', 'exceptions'] as $report) {
            $rows = $service->reportRows($site, $report);
            $json = json_encode($rows, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('secret_payload', $json);
            $this->assertStringNotContainsString('never-render', $json);
            $this->assertStringNotContainsString(substr($client['credential'], -20), $json);
            $this->assertStringNotContainsString($hook['secret'], $json);
        }
    }

    public function test_observability_actions_reject_records_owned_by_another_site(): void
    {
        $actor = User::query()->create([
            'name' => 'Cross Site Observer',
            'email' => 'cross-observer-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password123',
            'is_active' => true,
        ]);
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeA = (int) DB::table('stores')->insertGetId(['store_type_id' => $storeTypeId, 'code' => 'OBS-A', 'name' => 'A', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $storeB = (int) DB::table('stores')->insertGetId(['store_type_id' => $storeTypeId, 'code' => 'OBS-B', 'name' => 'B', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $siteA = SmartOrderingSite::query()->create(['public_id' => (string) Str::uuid(), 'store_id' => $storeA, 'slug' => 'obs-a', 'runtime_state' => 'ENABLED', 'master_enabled' => true]);
        $siteB = SmartOrderingSite::query()->create(['public_id' => (string) Str::uuid(), 'store_id' => $storeB, 'slug' => 'obs-b', 'runtime_state' => 'ENABLED', 'master_enabled' => true]);

        $service = app(RestaurantIntegrationService::class);
        $client = $service->createApiClient($actor, $siteB, 'Site B', ['restaurant.read']);

        try {
            $service->revokeApiClient($siteA, $client['public_id']);
            $this->fail('Cross-site API client revocation must fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $connector = $service->install($actor, $siteB, null, 'generic', 'site_b', 'Site B', ['orders.ingest']);
        $service->setStatus($siteB, $connector, 'enabled');
        $event = $service->ingest($siteB, $connector, 'site-b-event', 'order.accepted', ['x' => 1]);
        $service->recordIntegrationEventResult($event['id'], false, 'failure');

        try {
            $service->retryIntegrationEvent($siteA, $event['id']);
            $this->fail('Cross-site event replay must fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }
}
