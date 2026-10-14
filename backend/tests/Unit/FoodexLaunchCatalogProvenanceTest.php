<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FoodexLaunchCatalogProvenanceTest extends TestCase
{
    #[Test]
    public function launch_fixture_has_no_unresolved_price_or_carton_fields(): void
    {
        $fixture = require database_path('seeders/fixtures/foodex-launch-catalog.php');

        $this->assertCount(25, $fixture['products']);

        foreach ($fixture['products'] as $product) {
            $this->assertIsNumeric($product['price']);
            $this->assertGreaterThan(0, $product['price']);
            $this->assertIsInt($product['carton_count']);
            $this->assertGreaterThan(0, $product['carton_count']);
            $this->assertEmpty($product['review_required'] ?? []);
        }
    }

    #[Test]
    public function provenance_ledger_covers_every_launch_blocking_field(): void
    {
        $ledgerPath = database_path('seeders/fixtures/foodex-launch-catalog-provenance.json');
        $ledger = json_decode((string) file_get_contents($ledgerPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('1.0.77', $ledger['release']);
        $this->assertCount(75, $ledger['entries']);

        $allowed = ['official', 'inferred', 'derived', 'reconstructed_asset'];
        $coverage = [];

        foreach ($ledger['entries'] as $entry) {
            $this->assertContains($entry['provenance_state'], $allowed);
            $this->assertNotSame('', trim((string) $entry['rationale']));
            $this->assertNotSame('', trim((string) $entry['worker_source_note']));
            $coverage[$entry['product_identifier']][$entry['field']] = true;
        }

        for ($ordinal = 1; $ordinal <= 25; $ordinal++) {
            $sku = 'FDX-LAUNCH-'.str_pad((string) $ordinal, 3, '0', STR_PAD_LEFT);
            $this->assertArrayHasKey('price', $coverage[$sku]);
            $this->assertArrayHasKey('carton_count', $coverage[$sku]);
            $this->assertArrayHasKey('image', $coverage[$sku]);
        }
    }

    #[Test]
    public function inferred_values_are_explicit_and_deterministic(): void
    {
        $fixtureA = require database_path('seeders/fixtures/foodex-launch-catalog.php');
        $fixtureB = require database_path('seeders/fixtures/foodex-launch-catalog.php');

        $this->assertSame($fixtureA, $fixtureB);
        $this->assertSame(165, $fixtureA['products'][21]['price']);
        $this->assertSame([9, 1485], [$fixtureA['products'][22]['carton_count'], $fixtureA['products'][22]['price']]);
        $this->assertSame(9, $fixtureA['products'][23]['carton_count']);
        $this->assertSame([9, 1485], [$fixtureA['products'][24]['carton_count'], $fixtureA['products'][24]['price']]);

        foreach ([21, 22, 23, 24] as $index) {
            $this->assertNotEmpty($fixtureA['products'][$index]['inferred']);
        }
    }
}
