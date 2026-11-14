<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Van;
use App\Models\VanAssignment;
use App\Services\VanRegistryService;
use App\Services\VanRuntimeContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class V2VanRetailStoreRuntimeScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_retail_assignment_is_filtered_while_wholesale_remains_available(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $operator = User::factory()->create(['is_active' => true]);
        $b2bStore = $this->store('V2-RUNTIME-B2B', 'B2B');
        $b2cStore = $this->store('V2-RUNTIME-B2C', 'B2C');
        $b2bWarehouse = $this->warehouse($b2bStore, 'V2-B2B-WH');
        $retailWarehouse = $this->warehouse($b2cStore, 'V2-B2C-WH');
        $registry = app(VanRegistryService::class);
        $goodVan = $registry->createVan(['code' => 'V2-RUNTIME-GOOD', 'home_warehouse_id' => $b2bWarehouse]);
        $badVan = $registry->createVan(['code' => 'V2-RUNTIME-BAD', 'home_warehouse_id' => $b2bWarehouse]);
        $good = $this->legacyAssignment($operator, $goodVan, $b2bWarehouse);
        $bad = $this->legacyAssignment($operator, $badVan, $retailWarehouse);

        $resolver = app(VanRuntimeContextResolver::class);
        $context = $resolver->resolve($operator);

        $this->assertCount(1, $context['available']);
        $this->assertSame($good->id, $context['selected']['assignment_id']);
        $this->assertSame($b2bStore, $context['selected']['store_id']);
        $this->assertNotSame($bad->id, $context['selected']['assignment_id']);

        $preferred = $resolver->resolve($operator, $badVan->id);
        $this->assertNull($preferred['selected']);
        $this->assertSame('preferred_van_not_authorized', $preferred['selection_reason']);
        $this->assertCount(1, $preferred['available']);

        // Security filtering does not rewrite or purge historical assignments.
        $this->assertDatabaseHas('van_assignments', ['id' => $bad->id, 'warehouse_id' => $retailWarehouse]);
    }

    public function test_retail_assignment_does_not_fall_back_to_b2b_home_store(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $operator = User::factory()->create(['is_active' => true]);
        $b2bWarehouse = $this->warehouse($this->store('V2-HOME-B2B', 'B2B'), 'V2-HOME-WH');
        $retailWarehouse = $this->warehouse($this->store('V2-ASSIGN-B2C', 'B2C'), 'V2-ASSIGN-WH');
        $van = app(VanRegistryService::class)->createVan([
            'code' => 'V2-RUNTIME-RETAIL-ONLY',
            'home_warehouse_id' => $b2bWarehouse,
        ]);
        $this->legacyAssignment($operator, $van, $retailWarehouse);

        $context = app(VanRuntimeContextResolver::class)->resolve($operator);

        $this->assertSame([], $context['available']);
        $this->assertNull($context['selected']);
        $this->assertSame('no_effective_assignment', $context['selection_reason']);
    }

    public function test_inactive_wholesale_store_does_not_become_van_seller(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $operator = User::factory()->create(['is_active' => true]);
        $inactiveStore = $this->store('V2-INACTIVE-B2B', 'B2B', false);
        $warehouse = $this->warehouse($inactiveStore, 'V2-INACTIVE-WH');
        $van = app(VanRegistryService::class)->createVan(['code' => 'V2-RUNTIME-INACTIVE']);
        $this->legacyAssignment($operator, $van, $warehouse);

        $context = app(VanRuntimeContextResolver::class)->resolve($operator);

        $this->assertNull($context['selected']);
        $this->assertSame([], $context['available']);
        $this->assertSame('no_effective_assignment', $context['selection_reason']);
    }

    public function test_active_b2b_driver_store_fallback_remains_authorized(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $operator = User::factory()->create(['is_active' => true]);
        $b2bStore = $this->store('V2-DRIVER-B2B', 'B2B');
        $driverId = (int) DB::table('drivers')->insertGetId([
            'user_id' => $operator->id,
            'store_id' => $b2bStore,
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $van = app(VanRegistryService::class)->createVan(['code' => 'V2-RUNTIME-DRIVER-FALLBACK']);
        $assignment = $this->legacyAssignment($operator, $van, null, $driverId);

        $context = app(VanRuntimeContextResolver::class)->resolve($operator);

        $this->assertSame($assignment->id, $context['selected']['assignment_id']);
        $this->assertSame($b2bStore, $context['selected']['store_id']);
    }

    public function test_representative_only_without_seller_store_keeps_null_context(): void
    {
        $operator = User::factory()->create(['is_active' => true]);
        $van = app(VanRegistryService::class)->createVan(['code' => 'V2-RUNTIME-REP-ONLY']);
        $assignment = $this->legacyAssignment($operator, $van);

        $context = app(VanRuntimeContextResolver::class)->resolve($operator);

        $this->assertSame($assignment->id, $context['selected']['assignment_id']);
        $this->assertNull($context['selected']['store_id']);
        $this->assertCount(1, $context['available']);
    }

    private function store(string $code, string $type, bool $active = true): int
    {
        $typeId = DB::table('store_types')->where('code', $type)->value('id');
        $this->assertNotNull($typeId, 'Core reference store type must exist: '.$type);

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function warehouse(int $storeId, string $code): int
    {
        return (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function legacyAssignment(
        User $operator,
        Van $van,
        ?int $warehouseId = null,
        ?int $driverId = null,
    ): VanAssignment {
        return VanAssignment::query()->create([
            'public_id' => (string) Str::uuid(),
            'van_id' => $van->id,
            'representative_user_id' => $operator->id,
            'driver_id' => $driverId,
            'warehouse_id' => $warehouseId,
            'assignment_type' => 'primary',
            'status' => 'active',
            'effective_from' => now()->subMinute(),
            'loaded_work_count' => 0,
            'created_by' => $operator->id,
        ]);
    }
}
