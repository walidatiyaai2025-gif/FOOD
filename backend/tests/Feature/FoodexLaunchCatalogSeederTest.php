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

    public function test_official_catalog_is_deterministic_and_rerunnable_without_inventing_missing_source_values(): void
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
            3,
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

    public function test_launch_readiness_fails_closed_for_unresolved_source_values_and_missing_high_resolution_assets(): void
    {
        $seeder = app(FoodexLaunchCatalogSeeder::class);
        $readiness = $seeder->readiness();

        $this->assertFalse($readiness['ready']);
        $this->assertContains('FDX-LAUNCH-022:price', $readiness['unresolved_source_fields']);
        $this->assertContains('FDX-LAUNCH-023:price', $readiness['unresolved_source_fields']);
        $this->assertContains('FDX-LAUNCH-023:carton_count', $readiness['unresolved_source_fields']);
        $this->assertContains('FDX-LAUNCH-024:carton_count', $readiness['unresolved_source_fields']);
        $this->assertContains('FDX-LAUNCH-025:price', $readiness['unresolved_source_fields']);
        $this->assertContains('FDX-LAUNCH-025:carton_count', $readiness['unresolved_source_fields']);
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
