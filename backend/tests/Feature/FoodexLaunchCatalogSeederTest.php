<?php

namespace Tests\Feature;

use Database\Seeders\CoreReferenceSeeder;
use Database\Seeders\FoodexLaunchCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FoodexLaunchCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_launch_catalog_is_deterministic_and_rerunnable_with_documented_assumptions(): void
    {
        Storage::fake('public');

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

    public function test_launch_product_images_are_manifest_verified_while_banner_remains_a_separate_gate(): void
    {
        Storage::fake('public');

        $seeder = app(FoodexLaunchCatalogSeeder::class);
        $readiness = $seeder->readiness();

        $this->assertFalse($readiness['ready']);
        $this->assertSame([], $readiness['unresolved_source_fields']);
        $this->assertSame([], $readiness['missing_images']);
        $this->assertSame([], $readiness['low_resolution_images']);
        $this->assertSame([], $readiness['checksum_mismatches']);
        $this->assertSame([], $readiness['image_provenance_errors']);
        $this->assertTrue($readiness['product_images_ready']);
        $this->assertSame(
            '530930173a3893ffa7b555af187ae38350add0a61afbb76d38b282a4a3b44816',
            $readiness['image_bundle_sha256'],
        );
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $readiness['image_manifest_sha256']);
        $this->assertFalse($readiness['banner_ready']);
    }

    public function test_launch_image_manifest_covers_all_products_with_checksums_and_explicit_source_missing_provenance(): void
    {
        $manifest = app(FoodexLaunchCatalogSeeder::class)->imageManifest();
        $entries = collect($manifest['entries']);

        $this->assertSame('1.0.77', $manifest['release']);
        $this->assertSame(
            '1333292c13e6bbddacc26f728485021459f914964af29ec589894086241cc523',
            $manifest['source_document']['sha256'],
        );
        $this->assertSame(1, $manifest['bundle']['part_count']);
        $this->assertSame(
            '530930173a3893ffa7b555af187ae38350add0a61afbb76d38b282a4a3b44816',
            $manifest['bundle']['sha256'],
        );
        $this->assertSame(25, $manifest['summary']['products']);
        $this->assertSame(19, $manifest['summary']['source_backed_products']);
        $this->assertSame(6, $manifest['summary']['source_missing_placeholders']);
        $this->assertCount(25, $entries);
        $this->assertSame(19, $entries->where('method', 'content_preserving_upscale_lanczos_unsharp')->count());
        $this->assertSame(6, $entries->where('method', 'source_missing_placeholder')->count());

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^FDX-LAUNCH-\\d{3}$/', $entry['product_identifier']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $entry['sha256']);
            $this->assertSame(1200, $entry['derivative_dimensions']['width']);
            $this->assertSame(1200, $entry['derivative_dimensions']['height']);
            $this->assertSame(
                'foodex-launch/products/'.$entry['bundle_key'].'.webp',
                $entry['public_path'],
            );
        }
    }

    public function test_seeding_stages_exact_manifest_verified_product_assets(): void
    {
        Storage::fake('public');

        $this->seed(CoreReferenceSeeder::class);
        $this->seed(FoodexLaunchCatalogSeeder::class);

        $manifest = app(FoodexLaunchCatalogSeeder::class)->imageManifest();

        foreach ($manifest['entries'] as $entry) {
            $publicPath = $entry['public_path'];

            Storage::disk('public')->assertExists($publicPath);
            $this->assertSame(
                $entry['sha256'],
                hash('sha256', (string) Storage::disk('public')->get($publicPath)),
            );
            $this->assertTrue(
                DB::table('product_images')
                    ->where('path', 'storage/'.$publicPath)
                    ->where('is_primary', true)
                    ->exists(),
            );
        }
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
