<?php

namespace Tests\Feature;

use App\Services\CustomerDomainResolver;
use App\Services\PlatformCustomerService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformCustomerUnifiedOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config(['foodex.platform_wholesale_store_code' => 'WHOLESALE-MAIN']);
    }

    public function test_platform_customer_orders_aggregate_wholesale_and_retail_with_store_provenance(): void
    {
        $wholesale = $this->store('B2B', 'WHOLESALE-MAIN', 'Main Wholesale');
        $retailA = $this->store('B2C', 'RETAIL-A', 'Retail A', 'storage/stores/retail-a.png');
        $retailB = $this->store('B2C', 'RETAIL-B', 'Retail B');

        $user = app(PlatformCustomerService::class)->register([
            'name' => 'Unified Shopper',
            'email' => 'unified-shopper@example.test',
            'phone' => '+201000000201',
            'password' => 'Password123!',
            'locale' => 'ar',
            'store_id' => $retailA,
        ]);

        $resolver = app(CustomerDomainResolver::class);
        $b2b = $resolver->b2b($user);
        $b2cA = $resolver->b2c($user, $retailA);
        $b2cB = $resolver->b2c($user, $retailB);
        $legacyId = (int) DB::table('platform_customers')
            ->where('user_id', $user->id)
            ->value('legacy_customer_id');

        $wholesaleOrder = $this->order(
            $wholesale,
            $legacyId,
            'b2b',
            b2bCustomerId: (int) $b2b->id,
            orderNumber: 'ORD-WHOLESALE',
            total: 120,
        );
        $retailAOrder = $this->order(
            $retailA,
            $legacyId,
            'b2c',
            b2cCustomerId: (int) $b2cA->id,
            orderNumber: 'ORD-RETAIL-A',
            total: 45,
        );
        $retailBOrder = $this->order(
            $retailB,
            $legacyId,
            'b2c',
            b2cCustomerId: (int) $b2cB->id,
            orderNumber: 'ORD-RETAIL-B',
            total: 65,
        );

        $foreign = app(PlatformCustomerService::class)->register([
            'name' => 'Foreign Shopper',
            'email' => 'foreign-shopper@example.test',
            'phone' => '+201000000202',
            'password' => 'Password123!',
            'store_id' => $retailA,
        ]);
        $foreignB2c = $resolver->b2c($foreign, $retailA);
        $foreignLegacyId = (int) DB::table('platform_customers')
            ->where('user_id', $foreign->id)
            ->value('legacy_customer_id');
        $foreignOrder = $this->order(
            $retailA,
            $foreignLegacyId,
            'b2c',
            b2cCustomerId: (int) $foreignB2c->id,
            orderNumber: 'ORD-FOREIGN',
            total: 999,
        );

        $response = $this->actingAs($user)->getJson('/api/v1/orders?per_page=20');

        $response
            ->assertOk()
            ->assertJsonPath('meta.scope', 'platform_customer')
            ->assertJsonPath('meta.total', 3);

        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertEqualsCanonicalizing(
            [$wholesaleOrder, $retailAOrder, $retailBOrder],
            $ids,
        );
        $this->assertNotContains($foreignOrder, $ids);

        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertSame('Main Wholesale', $rows[$wholesaleOrder]['store']['name']);
        $this->assertSame('b2b', $rows[$wholesaleOrder]['channel']);
        $this->assertSame('Retail A', $rows[$retailAOrder]['store']['name']);
        $this->assertSame(url('/storage/stores/retail-a.png'), $rows[$retailAOrder]['store']['logo_url']);
        $this->assertSame('b2c', $rows[$retailBOrder]['channel']);
    }

    public function test_platform_customer_order_detail_rejects_foreign_customer_and_store_channel_tampering(): void
    {
        $wholesale = $this->store('B2B', 'WHOLESALE-MAIN', 'Main Wholesale');
        $retailA = $this->store('B2C', 'RETAIL-A', 'Retail A');
        $retailB = $this->store('B2C', 'RETAIL-B', 'Retail B');

        $user = app(PlatformCustomerService::class)->register([
            'name' => 'Owner Shopper',
            'email' => 'owner-shopper@example.test',
            'phone' => '+201000000203',
            'password' => 'Password123!',
            'store_id' => $retailA,
        ]);
        $resolver = app(CustomerDomainResolver::class);
        $b2cA = $resolver->b2c($user, $retailA);
        $legacyId = (int) DB::table('platform_customers')->where('user_id', $user->id)->value('legacy_customer_id');

        $orderId = $this->order(
            $retailA,
            $legacyId,
            'b2c',
            b2cCustomerId: (int) $b2cA->id,
            orderNumber: 'ORD-OWNER',
            total: 22,
        );

        $this->actingAs($user)
            ->getJson("/api/v1/orders/{$orderId}?store_id={$retailA}&channel=b2c")
            ->assertOk()
            ->assertJsonPath('store_id', $retailA)
            ->assertJsonPath('channel', 'b2c');

        $this->actingAs($user)
            ->getJson("/api/v1/orders/{$orderId}?store_id={$retailB}&channel=b2c")
            ->assertNotFound();

        $this->actingAs($user)
            ->getJson("/api/v1/orders/{$orderId}?store_id={$retailA}&channel=b2b")
            ->assertNotFound();

        $foreign = app(PlatformCustomerService::class)->register([
            'name' => 'Other Shopper',
            'email' => 'other-shopper@example.test',
            'phone' => '+201000000204',
            'password' => 'Password123!',
            'store_id' => $retailA,
        ]);

        $this->actingAs($foreign)
            ->getJson("/api/v1/orders/{$orderId}")
            ->assertNotFound();

        $this->assertDatabaseHas('stores', ['id' => $wholesale, 'code' => 'WHOLESALE-MAIN']);
    }

    private function store(
        string $typeCode,
        string $code,
        string $name,
        ?string $logoPath = null,
    ): int {
        $typeId = (int) DB::table('store_types')->where('code', $typeCode)->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $name,
            'logo_path' => $logoPath,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(
        int $storeId,
        int $legacyCustomerId,
        string $channel,
        ?int $b2bCustomerId = null,
        ?int $b2cCustomerId = null,
        string $orderNumber = 'ORD-1',
        float $total = 10,
    ): int {
        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $legacyCustomerId,
            'b2b_customer_id' => $b2bCustomerId,
            'b2c_customer_id' => $b2cCustomerId,
            'address_id' => null,
            'order_number' => $orderNumber,
            'channel' => $channel,
            'status' => 'pending',
            'currency' => 'EGP',
            'subtotal' => $total,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => $total,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
