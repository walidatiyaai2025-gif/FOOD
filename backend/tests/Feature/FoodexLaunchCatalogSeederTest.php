<?php

namespace Tests\Feature;

use Database\Seeders\CoreReferenceSeeder;
use Database\Seeders\FoodexLaunchCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FoodexLaunchCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_launch_catalog_is_deterministic_and_rerunnable_with_documented_assumptions(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $this->seed(FoodexLaunchCatalogSeeder::class);
        $this->seed(FoodexLaunchCatalogSeeder::class);

        $storeId = (int) DB::table('stores')
            ->where('code', FoodexLaunchCatalogSeeder::STORE_CODE)
            ->value('id');

        $this->assertGreaterThan(0, $storeId);
        $this->assertSame(
            25,
            DB::table('products')->where('sku', 'like', FoodexLaunchCatalogSeeder::SKU_PREFIX.'%')->count(),
        );
        $this->assertSame(
            25,
            DB::table('store_products')
                ->join('products', 'products.id', '=', 'store_products.product_id')
                ->where('store_products.store_id', $storeId)
                ->where('products.sku', 'like', FoodexLaunchCatalogSeeder::SKU_PREFIX.'%')
                ->count(),
        );
        $this->assertSame(
            0,
            DB::table('store_products')
                ->join('products', 'products.id', '=', 'store_products.product_id')
                ->where('store_products.store_id', $storeId)
                ->where('products.sku', 'like', FoodexLaunchCatalogSeeder::SKU_PREFIX.'%')
                ->whereNull('store_products.price')
                ->count(),
        );
        $this->assertSame(
            50,
            DB::table('translations')
                ->where('key', 'like', 'catalog.product.'.FoodexLaunchCatalogSeeder::SKU_PREFIX.'%')
                ->count(),
        );
    }

    public function test_launch_readiness_has_no_source_blockers_and_fails_closed_for_missing_high_resolution_assets(): void
    {
        $seeder = app(FoodexLaunchCatalogSeeder::class);
        $readiness = $seeder->readiness();

        $this->assertFalse($readiness['ready']);
        $this->assertSame([], $readiness['unresolved_source_fields']);
        $this->assertCount(25, $readiness['missing_images']);
        $this->assertFalse($readiness['banner_ready']);
    }

    public function test_fixture_tracks_the_exact_owner_sources_and_low_resolution_reference_assets(): void
    {
        $fixture = require database_path('seeders/fixtures/foodex-launch-catalog.php');

        $this->assertSame(
            '1333292c13e6bbddacc26f728485021459f914964af29ec589894086241cc523',
            $fixture['source']['document_sha256'],
        );
        $this->assertSame(25, $fixture['source']['product_count']);
        $this->assertSame(800, $fixture['source']['minimum_launch_image_px']);

        $mapped = collect($fixture['products'])->filter(
            fn (array $product): bool => is_string($product['source_image'] ?? null),
        );
        $this->assertNotEmpty($mapped);
        $this->assertTrue($mapped->every(
            fn (array $product): bool => max(
                (int) ($product['source_width'] ?? 0),
                (int) ($product['source_height'] ?? 0),
            ) < 800,
        ));
    }
}
