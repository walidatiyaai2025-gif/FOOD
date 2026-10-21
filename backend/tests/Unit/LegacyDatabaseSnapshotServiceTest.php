<?php

namespace Tests\Unit;

use App\Services\LegacyDatabaseSnapshotService;
use ReflectionMethod;
use Tests\TestCase;

final class LegacyDatabaseSnapshotServiceTest extends TestCase
{
    public function test_binary_source_values_are_preserved_as_base64(): void
    {
        $service = new LegacyDatabaseSnapshotService;
        $value = "\x00\xFF\x10legacy";

        $encoded = $this->invokePrivate($service, 'normalizeValue', [$value, 'varbinary']);

        $this->assertSame('base64', $encoded['__encoding']);
        $this->assertSame($value, base64_decode($encoded['data'], true));
    }

    public function test_utf8_text_values_remain_text(): void
    {
        $service = new LegacyDatabaseSnapshotService;

        $this->assertSame(
            'فودكس',
            $this->invokePrivate($service, 'normalizeValue', ['فودكس', 'nvarchar']),
        );
    }

    public function test_full_database_one_shot_command_is_registered(): void
    {
        $routes = file_get_contents(base_path('routes/legacy_import.php'));

        $this->assertIsString($routes);
        $this->assertStringContainsString('foodex:legacy-one-shot', $routes);
        $this->assertStringContainsString('Snapshot every legacy Foodex base table and row', $routes);
    }

    public function test_one_shot_covers_every_existing_semantic_phase(): void
    {
        $source = file_get_contents(app_path('Services/LegacyDatabaseOneShotService.php'));

        $this->assertIsString($source);
        foreach ([
            'masters',
            'extended-masters',
            'stock',
            'sales-history',
            'purchase-history',
            'operational-history',
            'finance-history',
            'finance-assets',
            'customer360',
        ] as $phase) {
            $this->assertStringContainsString($phase, $source);
        }
    }

    private function invokePrivate(object $object, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }
}
