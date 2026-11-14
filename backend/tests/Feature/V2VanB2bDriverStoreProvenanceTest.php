<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\VanAssignment;
use App\Services\VanRegistryService;
use App\Services\VanRuntimeContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class V2VanB2bDriverStoreProvenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_legacy_retail_store_driver_cannot_inherit_van_access_through_b2b_warehouse(): void
    {
        $user = $this->b2bDriverUser('retail');
        $retailStore = $this->store('B2C', 'V2-3547-RETAIL');
        $warehouseStore = $this->store('B2B', 'V2-3547-SELLER');
        $warehouse = $this->warehouse($warehouseStore, 'V2-3547-WH');
        $driverId = $this->driver($user, $retailStore);
        $assignment = $this->assignment($user, $driverId, $warehouse, 'V2-3547-LEGACY');

        $resolver = app(VanRuntimeContextResolver::class);
        $selected = $resolver->resolve($user)['selected'];

        $this->assertNotNull($selected);
        $this->assertSame($assignment->id, $selected['assignment_id']);
        $this->assertSame($warehouseStore, $selected['store_id']);
        $this->assertSame($driverId, $selected['driver_id']);
        $this->assertFalse($resolver->canUseRuntime($user, $selected));

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'app' => 'van',
        ])->assertForbidden();

        $this->assertDatabaseHas('drivers', [
            'id' => $driverId,
            'store_id' => $retailStore,
            'driver_type' => 'b2b',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('van_assignments', [
            'id' => $assignment->id,
            'driver_id' => $driverId,
            'status' => 'active',
        ]);
    }

    public function test_authorized_wholesale_driver_and_van_assignment_remain_accessible(): void
    {
        $user = $this->b2bDriverUser('valid');
        $store = $this->store('B2B', 'V2-3547-VALID');
        $driverId = $this->driver($user, $store);
        $assignment = $this->assignment(
            $user,
            $driverId,
            $this->warehouse($store, 'V2-3547-VALID-WH'),
            'V2-3547-VALID-VAN',
        );

        $resolver = app(VanRuntimeContextResolver::class);
        $selected = $resolver->resolve($user)['selected'];

        $this->assertNotNull($selected);
        $this->assertSame($assignment->id, $selected['assignment_id']);
        $this->assertTrue($resolver->canUseRuntime($user, $selected));

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'app' => 'van',
        ])->assertOk()
            ->assertJsonPath('token_type', 'Bearer');
    }

    public function test_inactive_driver_store_cannot_grant_legacy_van_authority(): void
    {
        $user = $this->b2bDriverUser('inactive');
        $driverStore = $this->store('B2B', 'V2-3547-INACTIVE', false);
        $sellerStore = $this->store('B2B', 'V2-3547-SELLER-2');
        $driverId = $this->driver($user, $driverStore);
        $this->assignment(
            $user,
            $driverId,
            $this->warehouse($sellerStore, 'V2-3547-WH-2'),
            'V2-3547-INACTIVE-VAN',
        );

        $resolver = app(VanRuntimeContextResolver::class);
        $selected = $resolver->resolve($user)['selected'];

        $this->assertNotNull($selected);
        $this->assertSame($sellerStore, $selected['store_id']);
        $this->assertFalse($resolver->canUseRuntime($user, $selected));

        DB::table('stores')->where('id', $driverStore)->update(['is_active' => true]);
        $this->assertTrue($resolver->canUseRuntime($user, $selected));

        DB::table('drivers')->where('id', $driverId)->update(['is_active' => false]);
        $this->assertFalse($resolver->canUseRuntime($user, $selected));
    }

    public function test_fallback_is_pinned_to_owned_driver_and_never_switches_profiles(): void
    {
        $user = $this->b2bDriverUser('pinned');
        $other = $this->b2bDriverUser('other');
        $store = $this->store('B2B', 'V2-3547-PINNED');
        $ownedDriver = $this->driver($user, $store);
        $foreignDriver = $this->driver($other, $store);
        $retailStore = $this->store('B2C', 'V2-3547-UNTRUSTED');
        $retailDriver = $this->driver($user, $retailStore);
        $resolver = app(VanRuntimeContextResolver::class);

        $this->assertTrue($resolver->canUseRuntime($user, ['driver_id' => $ownedDriver]));
        $this->assertFalse($resolver->canUseRuntime($user, ['driver_id' => $foreignDriver]));
        $this->assertFalse($resolver->canUseRuntime($user, ['driver_id' => $retailDriver]));
        $this->assertFalse($resolver->canUseRuntime($user, ['driver_id' => null]));
        $this->assertFalse($resolver->canUseRuntime($user, ['driver_id' => 999999999]));
    }

    private function b2bDriverUser(string $slug): User
    {
        $user = User::factory()->create([
            'name' => 'V2 Driver '.$slug,
            'email' => 'v2-3547-'.$slug.'@example.test',
            'password' => Hash::make('correct-password'),
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'B2B_DRIVER')->firstOrFail()->id);
        $this->assertFalse($user->hasPermission('van.login'));

        return $user;
    }

    private function store(string $type, string $code, bool $active = true): int
    {
        $typeId = DB::table('store_types')->where('code', $type)->value('id');
        $this->assertNotNull($typeId);

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

    private function driver(User $user, int $storeId): int
    {
        return (int) DB::table('drivers')->insertGetId([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignment(User $user, int $driverId, int $warehouseId, string $code): VanAssignment
    {
        $van = app(VanRegistryService::class)->createVan(['code' => $code]);

        // Direct fixture deliberately represents imported pre-write-guard data.
        return VanAssignment::query()->create([
            'public_id' => (string) Str::uuid(),
            'van_id' => $van->getKey(),
            'driver_id' => $driverId,
            'representative_user_id' => null,
            'warehouse_id' => $warehouseId,
            'assignment_type' => 'primary',
            'status' => 'active',
            'effective_from' => now()->subMinute(),
            'loaded_work_count' => 0,
            'created_by' => $user->getKey(),
        ]);
    }
}
