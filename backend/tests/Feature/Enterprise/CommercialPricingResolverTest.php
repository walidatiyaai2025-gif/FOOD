<?php

namespace Tests\Feature\Enterprise;

use App\Domain\Pricing\B2bPriceResolver;
use App\Enterprise\Commercial\CommercialCreditEligibilityPolicy;
use App\Enterprise\Commercial\CommercialPricingResolver;
use App\Models\User;
use App\Services\CommerceQuoteService;
use App\Services\CustomerDomainResolver;
use App\Services\PlatformCustomerService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class CommercialPricingResolverTest extends TestCase
{
    use RefreshDatabase;

    private int $wholesale;

    private int $retailA;

    private int $retailB;

    private int $wholesaleProduct;

    private int $retailAProduct;

    private int $retailBProduct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config(['foodex.platform_wholesale_store_code' => 'WHOLESALE-MAIN']);

        $this->wholesale = $this->store('B2B', 'WHOLESALE-MAIN');
        DB::table('settings')->updateOrInsert(
            [
                'store_id' => $this->wholesale,
                'key' => 'b2b.pricing_tiers_enabled',
            ],
            [
                'value' => json_encode(true, JSON_THROW_ON_ERROR),
                'is_secret' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $this->retailA = $this->store('B2C', 'PRICING-RETAIL-A');
        $this->retailB = $this->store('B2C', 'PRICING-RETAIL-B');
        $this->wholesaleProduct = $this->product(
            $this->wholesale,
            'b2b',
            'PRICING-WHOLESALE',
            12.000,
        );
        $this->retailAProduct = $this->product(
            $this->retailA,
            'b2c',
            'PRICING-RETAIL-A',
            5.000,
        );
        $this->retailBProduct = $this->product(
            $this->retailB,
            'b2c',
            'PRICING-RETAIL-B',
            9.000,
        );
    }

    public function test_b2b_sales_and_van_origins_resolve_the_same_authoritative_price(): void
    {
        $user = $this->platformCustomer('pricing-b2b@example.test');
        $customer = app(CustomerDomainResolver::class)->b2b($user);
        $tierId = (int) DB::table('b2b_price_tiers')
            ->where('code', 'STANDARD')
            ->value('id');

        DB::table('b2b_price_rules')->insert([
            'price_tier_id' => $tierId,
            'store_id' => $this->wholesale,
            'product_id' => $this->wholesaleProduct,
            'unit_price' => 7.250,
            'minimum_quantity' => 5,
            'ordering_increment' => 5,
            'pack_size' => 12,
            'case_size' => 24,
            'pack_label' => 'Case 12',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $items = [['product_id' => $this->wholesaleProduct, 'quantity' => 5]];
        $resolver = app(CommercialPricingResolver::class);

        $b2b = $resolver->resolve('b2b', 'b2b', $this->wholesale, $customer, $items, $user);
        $sales = $resolver->resolve('sales', 'b2b', $this->wholesale, $customer, $items, $user);
        $van = $resolver->resolve('van', 'b2b', $this->wholesale, $customer, $items, $user);

        foreach ([$b2b, $sales, $van] as $quote) {
            $this->assertSame(7.25, $quote['items'][0]['unit_price']);
            $this->assertSame(36.25, $quote['subtotal']);
            $this->assertSame($tierId, $quote['customer_context']['price_tier_id']);
            $this->assertSame(CommerceQuoteService::class, $quote['pricing_contract']['authority']);
            $this->assertSame(B2bPriceResolver::class, $quote['pricing_contract']['line_authority']);
            $this->assertSame(
                'CANONICAL_B2B_PRICE_RESOLVER',
                $quote['pricing_contract']['reason_codes'][0],
            );
            $this->assertSame(
                B2bPriceResolver::class,
                $quote['pricing_snapshot']['lines'][0]['authority'],
            );
        }

        $this->assertSame($b2b['subtotal'], $sales['subtotal']);
        $this->assertSame($sales['subtotal'], $van['subtotal']);
    }

    public function test_b2c_convergence_preserves_single_promotion_application_and_store_isolation(): void
    {
        $user = $this->platformCustomer('pricing-b2c@example.test');
        $customer = app(CustomerDomainResolver::class)->b2c($user, $this->retailA);

        DB::table('promotions')->insert([
            'store_id' => $this->retailA,
            'name' => 'Ten percent',
            'type' => 'percentage',
            'value' => 10,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $items = [['product_id' => $this->retailAProduct, 'quantity' => 2]];
        $canonical = app(CommerceQuoteService::class)->quote(
            'b2c',
            $this->retailA,
            $customer,
            $items,
            $user,
        );
        $converged = app(CommercialPricingResolver::class)->resolve(
            'sales',
            'b2c',
            $this->retailA,
            $customer,
            $items,
            $user,
        );

        $this->assertSame(10.0, $converged['subtotal']);
        $this->assertSame(1.0, $converged['promotion_discount_total']);
        $this->assertSame(1.0, $converged['discount_total']);
        $this->assertSame($canonical['discount_total'], $converged['discount_total']);
        $this->assertSame($canonical['grand_total'], $converged['grand_total']);
        $this->assertContains(
            'PROMOTION_APPLIED_ONCE',
            $converged['pricing_contract']['reason_codes'],
        );
        $this->assertSame(
            $converged['discount_total'],
            $converged['pricing_snapshot']['discount_total'],
        );
        $this->assertSame(
            'store_products.price',
            $converged['pricing_contract']['line_authority'],
        );

        $this->expectException(HttpException::class);

        app(CommercialPricingResolver::class)->resolve(
            'b2c',
            'b2c',
            $this->retailA,
            $customer,
            [['product_id' => $this->retailBProduct, 'quantity' => 1]],
            $user,
        );
    }

    public function test_account_credit_remains_delegated_to_canonical_credit_policy(): void
    {
        $user = $this->platformCustomer('pricing-credit@example.test');
        $customer = app(CustomerDomainResolver::class)->b2b($user);

        DB::table('b2b_accounts')
            ->where('b2b_customer_id', $customer->id)
            ->update([
                'credit_limit' => 1.000,
                'updated_at' => now(),
            ]);

        try {
            app(CommercialPricingResolver::class)->resolve(
                'van',
                'b2b',
                $this->wholesale,
                $customer,
                [['product_id' => $this->wholesaleProduct, 'quantity' => 1]],
                $user,
                null,
                'account_credit',
            );
            $this->fail('Canonical account-credit eligibility must reject an over-limit quote.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        DB::table('b2b_accounts')
            ->where('b2b_customer_id', $customer->id)
            ->update([
                'credit_limit' => 100.000,
                'updated_at' => now(),
            ]);

        $eligible = app(CommercialPricingResolver::class)->resolve(
            'van',
            'b2b',
            $this->wholesale,
            $customer,
            [['product_id' => $this->wholesaleProduct, 'quantity' => 1]],
            $user,
            null,
            'account_credit',
        );

        $this->assertSame(
            CommercialCreditEligibilityPolicy::class,
            $eligible['pricing_contract']['credit_authority'],
        );
        $this->assertContains(
            CommercialCreditEligibilityPolicy::REASON_ELIGIBLE,
            $eligible['pricing_contract']['reason_codes'],
        );
    }

    public function test_origin_channel_pairs_and_line_count_fail_closed_before_quote_execution(): void
    {
        $user = $this->platformCustomer('pricing-bounds@example.test');
        $b2c = app(CustomerDomainResolver::class)->b2c($user, $this->retailA);
        $resolver = app(CommercialPricingResolver::class);

        try {
            $resolver->resolve(
                'van',
                'b2c',
                $this->retailA,
                $b2c,
                [['product_id' => $this->retailAProduct, 'quantity' => 1]],
                $user,
            );
            $this->fail('Van pricing must remain on the B2B commercial channel.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('channel', $exception->errors());
        }

        $tooMany = array_fill(
            0,
            CommercialPricingResolver::MAX_LINES + 1,
            ['product_id' => $this->retailAProduct, 'quantity' => 1],
        );

        $this->expectException(ValidationException::class);

        $resolver->resolve(
            'sales',
            'b2c',
            $this->retailA,
            $b2c,
            $tooMany,
            $user,
        );
    }

    private function platformCustomer(string $email): User
    {
        return app(PlatformCustomerService::class)->register([
            'name' => 'Pricing Convergence Customer',
            'email' => $email,
            'phone' => '+20100000'.random_int(1000, 9999),
            'password' => 'Password123!',
            'locale' => 'en',
            'store_id' => $this->retailA,
        ]);
    }

    private function store(string $type, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')
                ->where('code', $type)
                ->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(int $storeId, string $channel, string $sku, float $price): int
    {
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => $channel,
            'code' => 'default',
            'name' => $sku.' Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-'.$sku,
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => $sku,
            'name' => $sku,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => $price,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WH-'.$sku,
            'name' => 'Warehouse '.$sku,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 100,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $productId;
    }
}
