<?php

namespace Tests\Deployment;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class V2ProfitabilityRuleMigrationSafetyTest extends TestCase
{
    private const TABLE = 'commercial_profitability_rule_revisions';

    private const MIGRATION = '2026_11_16_000001_create_commercial_profitability_rule_revisions.php';

    private bool $disposableDatabaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        $safeDatabase = getenv('FOODEX_DISPOSABLE_SERVICES') === 'true'
            && DB::connection()->getDriverName() === 'mysql'
            && DB::connection()->getDatabaseName() === 'foodex_acceptance';

        if (! $safeDatabase) {
            if (getenv('GITHUB_ACTIONS') === 'true') {
                $this->fail(
                    'The P229 MySQL deployment test must run only against the configured disposable foodex_acceptance database.',
                );
            }

            $this->markTestSkipped(
                'Requires the disposable MySQL acceptance database; no database reset was attempted.',
            );
        }

        $version = (string) (DB::selectOne('select version() as version')->version ?? '');
        $this->assertStringStartsWith('8.0.', $version, 'P229 migration safety must be exercised on MySQL 8.0.');

        $this->assertSame(
            0,
            Artisan::call('migrate:fresh', ['--force' => true]),
            Artisan::output(),
        );

        $this->disposableDatabaseReady = true;
    }

    protected function tearDown(): void
    {
        if ($this->disposableDatabaseReady
            && getenv('FOODEX_DISPOSABLE_SERVICES') === 'true'
            && DB::connection()->getDriverName() === 'mysql'
            && DB::connection()->getDatabaseName() === 'foodex_acceptance') {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }

        parent::tearDown();
    }

    public function test_mysql_rollback_refuses_to_drop_non_empty_profitability_history(): void
    {
        [$makerId, $storeId, $productId] = $this->fixture();
        $requestId = (string) Str::uuid();
        $canonical = [
            'store_id' => $storeId,
            'product_id' => $productId,
            'minimum_margin_basis_points' => 1234,
            'effective_from' => now()->addDay()->toDateString(),
            'effective_until' => now()->addMonth()->toDateString(),
        ];

        DB::table(self::TABLE)->insert([
            ...$canonical,
            'revision' => 1,
            'status' => 'DRAFT',
            'request_id' => $requestId,
            'content_sha256' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)),
            'proposed_by' => $makerId,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exception = null;

        try {
            $this->runMigrationMethod('down');
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertSame(
            'Refusing to drop commercial_profitability_rule_revisions because immutable proposal/review history exists. Archive it explicitly before rollback.',
            $exception->getMessage(),
        );
        $this->assertTrue(Schema::hasTable(self::TABLE));
        $this->assertSame(1, DB::table(self::TABLE)->count());
        $this->assertDatabaseHas(self::TABLE, [
            'store_id' => $storeId,
            'product_id' => $productId,
            'revision' => 1,
            'status' => 'DRAFT',
            'minimum_margin_basis_points' => 1234,
            'request_id' => $requestId,
            'proposed_by' => $makerId,
        ]);
    }

    public function test_mysql_rollback_can_drop_and_recreate_an_empty_profitability_history_table(): void
    {
        $this->assertTrue(Schema::hasTable(self::TABLE));
        $this->assertSame(0, DB::table(self::TABLE)->count());

        $this->runMigrationMethod('down');

        $this->assertFalse(Schema::hasTable(self::TABLE));

        $this->runMigrationMethod('up');

        $this->assertTrue(Schema::hasTable(self::TABLE));
        $this->assertSame(0, DB::table(self::TABLE)->count());
    }

    /** @return array{int, int, int} */
    private function fixture(): array
    {
        $suffix = strtoupper(str_replace('-', '', (string) Str::uuid()));
        $now = now();

        $storeTypeId = (int) DB::table('store_types')->insertGetId([
            'code' => 'B2B',
            'name' => 'Wholesale',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'P229-MYSQL-'.$suffix,
            'name' => 'P229 MySQL Acceptance',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'P229-MYSQL-UNIT-'.$suffix,
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2b',
            'code' => 'p229-mysql-'.$suffix,
            'name' => 'P229 MySQL Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $categoryId = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalogId,
            'name' => 'P229 MySQL Category',
            'slug' => 'p229-mysql-'.$suffix,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'sku' => 'P229-MYSQL-'.$suffix,
            'name' => 'P229 MySQL Product',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var User $maker */
        $maker = User::factory()->create(['locale' => 'en', 'is_active' => true]);

        return [(int) $maker->getKey(), $storeId, $productId];
    }

    private function runMigrationMethod(string $method): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        $callable = [$migration, $method];

        if (! is_callable($callable)) {
            throw new RuntimeException('P229 migration does not expose '.$method.'().');
        }

        call_user_func($callable);
    }
}
