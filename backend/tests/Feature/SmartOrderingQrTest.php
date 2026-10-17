<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\SmartOrderingAttributionService;
use App\Services\SmartOrderingQrManager;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SmartOrderingQrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_stable_dynamic_qr_redirects_to_updated_target_without_reprint(): void
    {
        [$store] = $this->stores();
        $manager = app(SmartOrderingQrManager::class);
        $created = $manager->create($this->payload($store, ['target_path' => '/menu?mode=dine-in']));

        $url = $manager->publicUrlFromRaw($created['token']);
        $first = $this->get($url)->assertRedirect();
        $this->assertStringContainsString('/menu?mode=dine-in&so_ctx=', (string) $first->headers->get('Location'));

        $manager->update($created['qr'], $this->payload($store, ['target_path' => '/menu/v2']));
        $second = $this->get($url)->assertRedirect();
        $this->assertStringContainsString('/menu/v2?so_ctx=', (string) $second->headers->get('Location'));
    }

    public function test_disabled_expired_and_forged_tokens_fail_closed(): void
    {
        [$store] = $this->stores();
        $manager = app(SmartOrderingQrManager::class);
        $created = $manager->create($this->payload($store));
        $url = $manager->publicUrlFromRaw($created['token']);

        $manager->setEnabled($created['qr'], false);
        $this->get($url)->assertNotFound();

        $qr = $manager->setEnabled($created['qr'], true);
        $qr->forceFill(['valid_until' => now()->subSecond()])->save();
        $this->get($url)->assertNotFound();

        $this->get($manager->publicUrlFromRaw($created['token'].'x'))->assertNotFound();
    }

    public function test_rotation_keeps_deployed_print_alive_by_default_and_can_hard_revoke(): void
    {
        [$store] = $this->stores();
        $manager = app(SmartOrderingQrManager::class);
        $created = $manager->create($this->payload($store));
        $oldUrl = $manager->publicUrlFromRaw($created['token']);

        $newToken = $manager->rotate($created['qr']);
        $this->get($oldUrl)->assertRedirect();
        $this->get($manager->publicUrlFromRaw($newToken))->assertRedirect();

        $manager->rotate($created['qr']->fresh(), null, false);
        $this->get($manager->publicUrlFromRaw($newToken))->assertNotFound();
    }

    public function test_undecryptable_token_keeps_public_qr_alive_and_admin_fails_safe(): void
    {
        [$store] = $this->stores();
        $manager = app(SmartOrderingQrManager::class);
        $created = $manager->create($this->payload($store));
        $rawUrl = $manager->publicUrlFromRaw($created['token']);

        DB::table('smart_ordering_qr_tokens')
            ->where('qr_code_id', $created['qr']->id)
            ->where('state', 'active')
            ->update(['token_value' => 'invalid-encrypted-payload']);

        $qr = $created['qr']->fresh(['activeToken']);
        $this->assertNull($manager->publicUrlFor($qr));

        $admin = $this->storeAdmin($store, 'en');
        $this->actingAs($admin)
            ->get('/admin/smart-ordering/qr')
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.smart-ordering.qr.svg', ['qr' => $qr]))
            ->assertStatus(409);
        $this->actingAs($admin)
            ->get(route('admin.smart-ordering.qr.pdf', ['qr' => $qr]))
            ->assertStatus(409);

        $this->assertDatabaseHas('smart_ordering_qr_tokens', [
            'qr_code_id' => $created['qr']->id,
            'token_hash' => hash('sha256', $created['token']),
            'state' => 'active',
            'revoked_at' => null,
        ]);

        $this->get($rawUrl)->assertRedirect();
    }

    public function test_signed_table_and_campaign_context_cannot_be_forged(): void
    {
        [$store] = $this->stores();
        $promotionId = (int) DB::table('promotions')->insertGetId([
            'store_id' => $store,
            'name' => 'Server-owned lunch offer',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manager = app(SmartOrderingQrManager::class);
        $attribution = app(SmartOrderingAttributionService::class);
        $created = $manager->create($this->payload($store, [
            'type' => 'table',
            'branch_reference' => 'Salmiya',
            'table_reference' => 'T-12',
            'campaign' => 'lunch',
            'promotion_id' => $promotionId,
        ]));
        $resolved = $manager->resolve($created['token']);
        $visit = $attribution->startLanding(
            $resolved['qr'],
            $resolved['token'],
            'visitor-abcdefghijklmnopqrstuvwxyz-123456',
            'https://campaign.example/landing',
        );
        $context = $attribution->signedContext($visit);

        $server = $attribution->serverContext($context, $store);
        $this->assertSame('T-12', $server['table_reference']);
        $this->assertSame('Salmiya', $server['branch_reference']);
        $this->assertSame($promotionId, $server['promotion_id']);
        $this->assertSame('https://campaign.example/landing', $visit->fresh()->referrer_snapshot);

        $cart = Cart::query()->create(['store_id' => $store, 'guest_token' => 'promo-cart', 'channel' => 'b2c']);
        $attribution->attachCart($context, $cart);
        $this->assertSame($promotionId, $attribution->promotionIdForCart($cart));

        $tamperedContext = substr($context, 0, -1).(str_ends_with($context, '0') ? '1' : '0');
        $this->expectException(ValidationException::class);
        $attribution->serverContext($tamperedContext, $store);
    }

    public function test_attribution_survives_cart_checkout_and_order_with_server_owned_revenue(): void
    {
        [$store] = $this->stores();
        $manager = app(SmartOrderingQrManager::class);
        $attribution = app(SmartOrderingAttributionService::class);
        $created = $manager->create($this->payload($store));
        $resolved = $manager->resolve($created['token']);
        $visit = $attribution->startLanding($resolved['qr'], $resolved['token'], 'visitor-abcdefghijklmnopqrstuvwxyz-123456');
        $context = $attribution->signedContext($visit);

        $attribution->markStorefrontViewed($context, $store);
        $cart = Cart::query()->create(['store_id' => $store, 'guest_token' => 'guest-1', 'channel' => 'b2c']);
        $attribution->attachCart($context, $cart);
        $attribution->markCheckoutStartedForCart($cart);

        $customerUser = User::query()->create(['name' => 'QR Customer', 'email' => 'qr-customer@example.test', 'password' => 'password']);
        $customer = Customer::query()->create(['user_id' => $customerUser->id, 'type' => 'b2c', 'name' => 'QR Customer']);
        $order = Order::query()->create([
            'store_id' => $store,
            'customer_id' => $customer->id,
            'order_number' => 'SO-QR-1001',
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 25,
            'discount_total' => 5,
            'delivery_total' => 0,
            'grand_total' => 20,
        ]);

        $converted = $attribution->attachOrderFromCart($cart, $order);
        $this->assertNotNull($converted);
        $this->assertSame($cart->id, $converted->cart_id);
        $this->assertSame($order->id, $converted->order_id);
        $this->assertSame('20.000', $converted->revenue);

        $cart->delete();
        $converted = $converted->fresh();
        $this->assertNull($converted->cart_id);

        $metrics = $attribution->metricsForQrIds([$created['qr']->id]);
        $this->assertSame(1, $metrics[$created['qr']->id]['carts']);
        $this->assertSame(1, $metrics[$created['qr']->id]['orders']);
        $this->assertSame(20.0, $metrics[$created['qr']->id]['revenue']);

        foreach (['qr_scanned', 'storefront_viewed', 'cart_started', 'checkout_started', 'order_created'] as $event) {
            $this->assertDatabaseHas('smart_ordering_qr_events', [
                'qr_visit_id' => $visit->id,
                'event_name' => $event,
            ]);
        }
    }

    public function test_cross_tenant_cart_attribution_is_rejected(): void
    {
        [$storeA, $storeB] = $this->stores();
        $manager = app(SmartOrderingQrManager::class);
        $attribution = app(SmartOrderingAttributionService::class);
        $created = $manager->create($this->payload($storeA));
        $resolved = $manager->resolve($created['token']);
        $visit = $attribution->startLanding($resolved['qr'], $resolved['token'], 'visitor-abcdefghijklmnopqrstuvwxyz-123456');
        $context = $attribution->signedContext($visit);
        $otherCart = Cart::query()->create(['store_id' => $storeB, 'guest_token' => 'other', 'channel' => 'b2c']);

        $this->expectException(ValidationException::class);
        $attribution->attachCart($context, $otherCart);
    }

    public function test_guest_cart_attribution_moves_to_authenticated_cart_without_overwrite(): void
    {
        [$store] = $this->stores();
        $manager = app(SmartOrderingQrManager::class);
        $attribution = app(SmartOrderingAttributionService::class);
        $created = $manager->create($this->payload($store));
        $resolved = $manager->resolve($created['token']);
        $visit = $attribution->startLanding($resolved['qr'], $resolved['token'], 'visitor-merge-abcdefghijklmnopqrstuvwxyz');
        $context = $attribution->signedContext($visit);

        $guest = Cart::query()->create(['store_id' => $store, 'guest_token' => 'merge-source', 'channel' => 'b2c']);
        $target = Cart::query()->create(['store_id' => $store, 'guest_token' => null, 'channel' => 'b2c']);

        $attribution->attachCart($context, $guest);
        $attribution->transferCartAttribution($guest, $target);

        $this->assertSame($target->id, $visit->fresh()->cart_id);
        $this->assertNotNull($visit->fresh()->cart_started_at);
    }

    public function test_duplicate_scan_retry_does_not_corrupt_conversion_metrics(): void
    {
        [$store] = $this->stores();
        $manager = app(SmartOrderingQrManager::class);
        $attribution = app(SmartOrderingAttributionService::class);
        $created = $manager->create($this->payload($store));
        $resolved = $manager->resolve($created['token']);

        $a = $attribution->startLanding($resolved['qr'], $resolved['token'], 'visitor-abcdefghijklmnopqrstuvwxyz-123456');
        $b = $attribution->startLanding($resolved['qr'], $resolved['token'], 'visitor-abcdefghijklmnopqrstuvwxyz-123456');

        $this->assertSame($a->id, $b->id);
        $metrics = $attribution->metricsForQrIds([$created['qr']->id]);
        $this->assertSame(1, $metrics[$created['qr']->id]['scans']);
        $this->assertSame(1, $metrics[$created['qr']->id]['unique_visits']);
        $this->assertSame(0.0, $metrics[$created['qr']->id]['conversion']);
        $this->assertDatabaseCount('smart_ordering_qr_events', 1);
    }

    public function test_store_admin_manager_is_scoped_and_uses_business_controls(): void
    {
        [$storeA, $storeB] = $this->stores();
        $manager = app(SmartOrderingQrManager::class);
        $manager->create($this->payload($storeA, ['label' => 'Allowed table']));
        $foreign = $manager->create($this->payload($storeB, ['label' => 'Foreign table']))['qr'];

        $admin = $this->storeAdmin($storeA, 'en');
        $this->actingAs($admin)
            ->get('/admin/smart-ordering/qr')
            ->assertOk()
            ->assertSee('data-smart-ordering-qr-manager="true"', false)
            ->assertSee('Allowed table')
            ->assertDontSee('Foreign table')
            ->assertSee('What is this QR for?')
            ->assertSee('Main Menu QR')
            ->assertSee('Table QR')
            ->assertSee('Campaign / Offer QR')
            ->assertSee('data-qr-preset', false)
            ->assertSee('Advanced settings')
            ->assertSee('name="store_id"', false)
            ->assertSee('name="promotion_id"', false)
            ->assertDontSee('name="store_id" type="number"', false);

        $this->actingAs($admin)
            ->post('/admin/smart-ordering/qr/'.$foreign->public_id.'/toggle')
            ->assertNotFound();
    }

    /** @return array{0:int,1:int} */
    private function stores(): array
    {
        $type = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $a = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type, 'code' => 'SO-A', 'name' => 'Restaurant A',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $b = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type, 'code' => 'SO-B', 'name' => 'Restaurant B',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->enableRestaurantSaasForTestStore($a);
        $this->enableRestaurantSaasForTestStore($b);

        return [$a, $b];
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(int $storeId, array $overrides = []): array
    {
        return $overrides + [
            'store_id' => $storeId,
            'type' => 'generic',
            'label' => 'Main QR',
            'target_path' => '/menu',
            'source' => 'qr',
            'medium' => 'offline',
            'campaign' => null,
            'branch_reference' => null,
            'table_reference' => null,
            'promotion_id' => null,
            'valid_from' => null,
            'valid_until' => null,
            'is_enabled' => true,
        ];
    }

    private function storeAdmin(int $storeId, string $locale): User
    {
        $user = User::query()->create([
            'name' => 'QR Store Admin',
            'email' => 'qr-store-admin@example.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
