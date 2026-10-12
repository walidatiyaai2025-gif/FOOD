<?php

namespace Tests\Feature;

use Database\Seeders\FoodexCustomerVNextFixtureSeeder;
use Database\Seeders\FoodexLaunchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FoodexCustomerVNextFixtureSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_sanitized_fixture_matches_verified_relational_shape_and_is_rerunnable(): void
    {
        $this->seed(FoodexCustomerVNextFixtureSeeder::class);
        $this->seed(FoodexCustomerVNextFixtureSeeder::class);

        $this->assertSame(3, DB::table('stores')->where('code', 'like', 'FDX-QA-%')->count());
        $this->assertSame(17, DB::table('categories')->where('slug', 'like', 'fdx-qa-c%')->count());
        $this->assertSame(39, DB::table('products')->where('sku', 'like', 'FDX-QA-P%')->count());

        $productIds = DB::table('products')->where('sku', 'like', 'FDX-QA-P%')->pluck('id');
        $this->assertSame(39, DB::table('product_images')->whereIn('product_id', $productIds)->count());
        $this->assertSame(39, DB::table('store_products')->whereIn('product_id', $productIds)->count());
        $this->assertSame(36, DB::table('product_selling_units')->whereIn('product_id', $productIds)->count());

        $orderIds = DB::table('orders')
            ->where('order_number', 'like', FoodexCustomerVNextFixtureSeeder::ORDER_PREFIX.'%')
            ->pluck('id');
        $this->assertCount(82, $orderIds);
        $this->assertSame(150, DB::table('order_items')->whereIn('order_id', $orderIds)->count());
        $this->assertSame(93, DB::table('payments')->whereIn('order_id', $orderIds)->count());
        $this->assertSame(566, DB::table('translations')->where('key', 'like', 'qa.customer-vnext.%')->count());
        $this->assertSame(10, DB::table('users')->where('email', 'like', 'foodex.qa.user.%@example.test')->count());
        $this->assertSame(
            5,
            DB::table('customers')
                ->whereIn(
                    'user_id',
                    DB::table('users')->where('email', 'like', 'foodex.qa.user.%@example.test')->pluck('id'),
                )
                ->count(),
        );
    }

    public function test_order_and_payment_business_state_distributions_match_the_approved_dump(): void
    {
        $this->seed(FoodexCustomerVNextFixtureSeeder::class);

        $orders = fn () => DB::table('orders')
            ->where('order_number', 'like', FoodexCustomerVNextFixtureSeeder::ORDER_PREFIX.'%');

        $this->assertSame(['b2b' => 79, 'b2c' => 3], $orders()
            ->selectRaw('channel, COUNT(*) AS total')
            ->groupBy('channel')
            ->pluck('total', 'channel')
            ->map(fn ($value): int => (int) $value)
            ->all());

        $this->assertSame([
            'accepted' => 1,
            'assigned' => 2,
            'cancelled' => 1,
            'confirmed' => 11,
            'delivered' => 27,
            'failed' => 1,
            'out_for_delivery' => 5,
            'pending' => 31,
            'preparing' => 3,
        ], $orders()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->orderBy('status')
            ->pluck('total', 'status')
            ->map(fn ($value): int => (int) $value)
            ->all());

        $orderIds = $orders()->pluck('id');
        $paymentCross = DB::table('payments')
            ->whereIn('order_id', $orderIds)
            ->selectRaw('provider, status, COUNT(*) AS total')
            ->groupBy('provider', 'status')
            ->get()
            ->mapWithKeys(
                fn (object $row): array => [
                    $row->provider.'|'.$row->status => (int) $row->total,
                ],
            )
            ->sortKeys()
            ->all();

        $this->assertSame([
            'account_credit|pending' => 1,
            'cash_on_delivery|paid' => 18,
            'cash_on_delivery|pending' => 63,
            'field_collection|paid' => 11,
        ], $paymentCross);
    }

    public function test_fixture_contains_only_fixed_qa_identity_and_no_provider_references(): void
    {
        $this->seed(FoodexCustomerVNextFixtureSeeder::class);

        $qaUsers = DB::table('users')
            ->where('email', 'like', 'foodex.qa.user.%@example.test')
            ->get(['email']);

        $this->assertCount(10, $qaUsers);
        $this->assertTrue($qaUsers->every(
            fn (object $user): bool => str_ends_with((string) $user->email, '@example.test'),
        ));

        $orderIds = DB::table('orders')
            ->where('order_number', 'like', FoodexCustomerVNextFixtureSeeder::ORDER_PREFIX.'%')
            ->pluck('id');

        $this->assertFalse(DB::table('payments')
            ->whereIn('order_id', $orderIds)
            ->whereNotNull('provider_reference')
            ->exists());

        $this->assertTrue(DB::table('addresses')
            ->where('label', 'like', 'FOODEX QA Address %')
            ->get(['line1'])
            ->every(fn (object $address): bool => str_starts_with((string) $address->line1, 'QA Block ')));
    }

    public function test_launch_contract_exposes_exact_source_lineage_and_fails_closed_until_catalog_assets_are_ready(): void
    {
        $preflight = app(FoodexLaunchSeeder::class)->preflight();

        $this->assertSame(
            'dd8ad844f963ffe0317a4ba4346d61fae7ce0d02909b3d05394d9497ea2b9023',
            $preflight['source_dump_sha256'],
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $preflight['fingerprint']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $preflight['fixture_fingerprint']);
        $this->assertFalse($preflight['ready']);
    }
}
