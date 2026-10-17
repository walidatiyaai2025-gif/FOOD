<?php

namespace Tests\Feature;

use App\Domain\RestaurantSaaS\RestaurantGrowthScaleService;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class RestaurantSaaSGrowthScaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_growth_scale_contract_is_idempotent_consent_safe_locked_and_secret_safe(): void
    {
        Storage::fake('local');

        $actor = User::query()->create([
            'name' => 'Restaurant Growth Owner',
            'email' => 'growth-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password123',
            'is_active' => true,
        ]);
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'GROWTH-'.Str::upper(Str::random(6)),
            'name' => 'Growth Restaurant',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        $service = app(RestaurantGrowthScaleService::class);

        $issued = $service->issueGiftCard($store, $actor, 10.000, 'KWD', 'issue-1001', 'location');
        $replayed = $service->issueGiftCard($store, $actor, 10.000, 'KWD', 'issue-1001', 'location');

        $this->assertSame($issued['public_id'], $replayed['public_id']);
        $this->assertSame($issued['code'], $replayed['code']);
        $this->assertSame(10.0, $replayed['balance']);
        $this->assertDatabaseCount('restaurant_saas_gift_cards', 1);

        try {
            $service->issueGiftCard($store, $actor, 20.000, 'KWD', 'issue-1001', 'location');
            $this->fail('Changed parameters must not reuse a gift-card issuance idempotency key.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $service->setGiftCardStatus($store, $issued['public_id'], 'suspended');
        $this->assertDatabaseHas('restaurant_saas_gift_cards', [
            'public_id' => $issued['public_id'],
            'status' => 'suspended',
        ]);
        try {
            $service->redeemGiftCard($store, $actor, $issued['code'], 1.000, 'redeem-suspended');
            $this->fail('Suspended gift cards must fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $service->setGiftCardStatus($store, $issued['public_id'], 'active');

        $redeemed = $service->redeemGiftCard($store, $actor, $issued['code'], 2.500, 'redeem-1001');
        $duplicateRedeem = $service->redeemGiftCard($store, $actor, $issued['code'], 2.500, 'redeem-1001');

        $this->assertFalse($redeemed['duplicate']);
        $this->assertTrue($duplicateRedeem['duplicate']);
        $this->assertSame(7.5, $duplicateRedeem['balance']);

        try {
            $service->redeemGiftCard($store, $actor, $issued['code'], 8.000, 'redeem-too-much');
            $this->fail('Gift-card balance must never go below zero.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $journey = $service->createJourney(
            $store,
            $actor,
            'Win back',
            'guest.lapsed',
            'email',
            true,
            'lapsed-30d',
            [],
            'location',
        );
        $suppressed = $service->triggerJourney($store, $journey, 'event-1001', 'customer:1001', false);
        $suppressedReplay = $service->triggerJourney($store, $journey, 'event-1001', 'customer:1001', true);

        $this->assertSame('suppressed', $suppressed['status']);
        $this->assertFalse($suppressed['duplicate']);
        $this->assertSame('suppressed', $suppressedReplay['status']);
        $this->assertTrue($suppressedReplay['duplicate']);

        $service->setFranchisePolicy($store, $actor, 'location', 'menu.price_override', 'local', false);
        $service->setFranchisePolicy($store, $actor, 'organization', 'menu.price_override', 'hq', true);

        $effective = $service->effectivePolicy($store, 'menu.price_override');
        $this->assertNotNull($effective);
        $this->assertSame('hq', $effective['value']);
        $this->assertTrue($effective['locked']);
        $this->assertStringStartsWith('organization:', $effective['source']);

        try {
            $service->setFranchisePolicy($store, $actor, 'location', 'menu.price_override', 'changed', false);
            $this->fail('A locked parent franchise policy must reject local override.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $analytics = $service->analytics($store, now()->subDay(), now()->addDay());
        $this->assertCount(1, $analytics['locations']);
        $this->assertArrayHasKey('average_ticket', $analytics['formulas']);

        try {
            $service->analytics($store, now()->subDays(367), now());
            $this->fail('Reporting windows must remain bounded.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $customerId = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => 'Portable Guest',
            'phone' => '+96555500000',
            'email' => 'portable@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('orders')->insert([
            'store_id' => $store->getKey(),
            'customer_id' => $customerId,
            'order_number' => 'PORTABLE-1001',
            'channel' => 'smart_ordering',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 1,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $export = $service->exportOrganization($store, $actor);
        $json = Storage::disk('local')->get($export['file_path']);
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(hash('sha256', $json), $export['checksum']);
        $this->assertArrayHasKey('menus', $payload);
        $this->assertArrayHasKey('menu_items', $payload);
        $this->assertArrayHasKey('customers', $payload);
        $this->assertArrayHasKey('orders', $payload);
        $this->assertSame('portable@example.test', $payload['customers'][0]['email']);
        $this->assertSame('PORTABLE-1001', $payload['orders'][0]['order_number']);
        $this->assertStringNotContainsString($issued['code'], $json);
        $this->assertStringNotContainsString('code_ciphertext', $json);
        $this->assertStringNotContainsString('code_hash', $json);
        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString('token', $json);
    }

    public function test_growth_admin_route_contract_is_registered(): void
    {
        foreach ([
            'admin.smart-ordering.growth.index',
            'admin.smart-ordering.growth.gift-cards.issue',
            'admin.smart-ordering.growth.gift-cards.redeem',
            'admin.smart-ordering.growth.gift-cards.status',
            'admin.smart-ordering.growth.journeys.store',
            'admin.smart-ordering.growth.policies.store',
            'admin.smart-ordering.growth.exports.store',
            'admin.smart-ordering.growth.accounting.download',
            'admin.smart-ordering.growth.exports.download',
        ] as $routeName) {
            $this->assertTrue(Route::has($routeName), 'Missing route: '.$routeName);
        }
    }
}
