<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class FoodexLaunchSeeder extends Seeder
{
    public const SOURCE_DUMP_SHA256 = FoodexCustomerVNextFixtureSeeder::SOURCE_SHA256;

    public function run(): void
    {
        $this->call(CoreReferenceSeeder::class);
        $this->call(FoodexLaunchCatalogSeeder::class);

        if (! app()->environment('production')) {
            $this->call(FoodexCustomerVNextFixtureSeeder::class);
        }
    }

    /**
     * @return array{
     *     ready:bool,
     *     fingerprint:string,
     *     source_dump_sha256:string,
     *     fixture_fingerprint:string,
     *     catalog_readiness:array<string,mixed>
     * }
     */
    public function preflight(): array
    {
        $catalog = app(FoodexLaunchCatalogSeeder::class)->readiness();
        $fixtureFingerprint = app(FoodexCustomerVNextFixtureSeeder::class)->fingerprint();
        $fixture = require database_path('seeders/fixtures/foodex-launch-catalog.php');

        $fingerprint = hash('sha256', json_encode([
            'source_dump_sha256' => self::SOURCE_DUMP_SHA256,
            'official_catalog_document_sha256' => $fixture['source']['document_sha256'],
            'fixture_fingerprint' => $fixtureFingerprint,
            'catalog_readiness' => $catalog,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return [
            'ready' => ($catalog['ready'] ?? false) === true,
            'fingerprint' => $fingerprint,
            'source_dump_sha256' => self::SOURCE_DUMP_SHA256,
            'fixture_fingerprint' => $fixtureFingerprint,
            'catalog_readiness' => $catalog,
        ];
    }
}
