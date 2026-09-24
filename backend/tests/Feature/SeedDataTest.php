<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeedDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_reference_seed_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $firstSnapshot = $this->referenceSnapshot();

        $this->seed(DatabaseSeeder::class);
        $secondSnapshot = $this->referenceSnapshot();

        $this->assertSame($firstSnapshot, $secondSnapshot);
    }

    public function test_core_seed_contains_every_configured_role_and_permission(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            collect(array_keys((array) config('permissions.roles')))->sort()->values()->all(),
            DB::table('roles')->orderBy('code')->pluck('code')->all(),
        );

        $this->assertSame(
            collect(array_keys((array) config('permissions.abilities')))->sort()->values()->all(),
            DB::table('permissions')->orderBy('code')->pluck('code')->all(),
        );

        $this->assertSame(
            ['B2B', 'B2C'],
            DB::table('store_types')->orderBy('code')->pluck('code')->all(),
        );
    }

    public function test_core_seed_does_not_create_demo_business_records(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('stores')->count());
        $this->assertSame(0, DB::table('products')->count());
        $this->assertSame(0, DB::table('orders')->count());
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function referenceSnapshot(): array
    {
        return [
            'store_types' => DB::table('store_types')
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'created_at', 'updated_at'])
                ->map(fn ($row): array => (array) $row)
                ->all(),
            'roles' => DB::table('roles')
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'created_at', 'updated_at'])
                ->map(fn ($row): array => (array) $row)
                ->all(),
            'permissions' => DB::table('permissions')
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'created_at', 'updated_at'])
                ->map(fn ($row): array => (array) $row)
                ->all(),
            'permission_role' => DB::table('permission_role')
                ->orderBy('role_id')
                ->orderBy('permission_id')
                ->get(['role_id', 'permission_id'])
                ->map(fn ($row): array => (array) $row)
                ->all(),
        ];
    }
}
