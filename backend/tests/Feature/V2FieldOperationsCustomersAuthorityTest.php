<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\VanVisit;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V2 #2597 / P267: a Field Operations Customers page must use the same
 * Wholesale store boundary as Van Visits, not the global visit ledger.
 */
final class V2FieldOperationsCustomersAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_existing_customers_route_and_sidebar_reuse_authorized_field_operations_group(): void
    {
        $this->assertTrue(Route::has('admin.field-operations.customers'));
        $actor = $this->scopedViewer($this->store('B2B', 'P2597-NAV-OWN'));
        $this->assertTrue(app(AdminNavigation::class)->canUseDashboard($actor));
        $items = $this->fieldOperationsItems($actor);
        $this->assertSame('admin.field-operations.customers', $items['field_ops_customers']['route']);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.customers', [], false))
                ->assertOk()
                ->assertViewIs('admin.field-operations')
                ->assertViewHas('section', 'customers')
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_scoped_actor_only_sees_latest_b2b_visit_from_own_store_even_if_foreign_visit_is_newer(): void
    {
        $own = $this->store('B2B', 'P2597-OWN');
        $foreign = $this->store('B2B', 'P2597-FOREIGN');
        $retail = $this->store('B2C', 'P2597-RETAIL');
        $operator = User::factory()->create(['is_active' => true]);

        $ownId = $this->visit($operator, 'b2b', 2597001, $own);
        $this->visit($operator, 'b2b', 2597001, $foreign);
        $this->visit($operator, 'b2c', 2597001, $retail);
        $this->visit($operator, 'b2b', 2597002, null);
        $actor = $this->scopedViewer($own);

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.customers', [], false))
                ->assertOk()
                ->assertViewHas('relationships', static fn ($page): bool => $page->getCollection()->pluck('id')->all() === [$ownId]);
        }
    }

    public function test_warehouse_scoped_van_assignment_never_uses_foreign_store_operator_assignment(): void
    {
        $own = $this->store('B2B', 'P2597-VAN-OWN');
        $foreign = $this->store('B2B', 'P2597-VAN-FOREIGN');
        $operator = User::factory()->create(['is_active' => true]);
        $visitId = $this->visit($operator, 'b2b', 2597003, $own);
        $this->assignment($operator, $own, 'P2597-OWN-VAN', now()->subDays(2));
        $this->assignment($operator, $foreign, 'P2597-FOREIGN-VAN', now()->subDay());
        $actor = $this->scopedViewer($own);

        $this->actingAs($actor)
            ->get(route('admin.field-operations.customers', [], false))
            ->assertOk()
            ->assertViewHas('relationships', static function ($page) use ($visitId): bool {
                $visit = $page->getCollection()->first();

                return $visit instanceof VanVisit
                    && $visit->id === $visitId
                    && $visit->getRelation('servingAssignment')?->van?->code === 'P2597-OWN-VAN';
            })
            ->assertSee('P2597-OWN-VAN')
            ->assertDontSee('P2597-FOREIGN-VAN');
    }

    public function test_roleless_retail_inactive_and_missing_one_required_permission_are_denied(): void
    {
        $retail = $this->store('B2C', 'P2597-RETAIL-AUTH');
        $roleless = User::factory()->create(['is_active' => true]);
        $retailActor = User::factory()->create(['is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $retailActor->id,
            'store_id' => $retail,
            'role_id' => Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $onlyCustomers = $this->viewerWithPermissions(['customers.view', 'reports.view'], 'P2597-ONLY-CUSTOMERS');
        $onlyVan = $this->viewerWithPermissions(['drivers.b2b.view', 'reports.view'], 'P2597-ONLY-VAN');
        $inactive = $this->viewerWithPermissions(['customers.view', 'drivers.b2b.view', 'reports.view'], 'P2597-INACTIVE');
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $retailActor, $onlyCustomers, $onlyVan, $inactive] as $actor) {
            $this->assertArrayNotHasKey('field_ops_customers', $this->fieldOperationsItems($actor->fresh()));
            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.customers', [], false))
                ->assertForbidden();
        }
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            ...($channel === 'B2C' ? ['business_profile' => StoreBusinessProfile::RETAIL_STORE->value] : []),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function visit(User $operator, string $channel, int $customerId, ?int $storeId): int
    {
        return (int) VanVisit::query()->create([
            'actor_user_id' => $operator->id,
            'customer_type' => $channel,
            'customer_id' => $customerId,
            'store_id' => $storeId,
            'status' => 'planned',
            'metadata' => ['route_key' => 'P2597-TEST-ROUTE'],
        ])->getKey();
    }

    private function assignment(User $operator, int $storeId, string $vanCode, \DateTimeInterface $effectiveFrom): void
    {
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => $vanCode.'-WH',
            'name' => $vanCode.' Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $vanId = (int) DB::table('vans')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'code' => $vanCode,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('van_assignments')->insert([
            'public_id' => (string) Str::uuid(),
            'van_id' => $vanId,
            'warehouse_id' => $warehouseId,
            'representative_user_id' => $operator->id,
            'created_by' => $operator->id,
            'status' => 'active',
            'effective_from' => $effectiveFrom,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function scopedViewer(int $storeId): User
    {
        $actor = $this->viewerWithPermissions(
            ['customers.view', 'drivers.b2b.view', 'reports.view'],
            'P2597-SCOPED-READER',
        );
        $roleId = (int) $actor->roles()->firstOrFail()->id;
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor->fresh();
    }

    /** @param list<string> $permissions */
    private function viewerWithPermissions(array $permissions, string $code): User
    {
        $role = Role::query()->create([
            'code' => $code,
            'name' => 'Field Operations P267 test reader',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $permissionIds = Permission::query()->whereIn('code', $permissions)->pluck('id')->all();
        $this->assertCount(count($permissions), $permissionIds);
        $role->permissions()->attach($permissionIds);

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach($role);

        return $actor->fresh();
    }

    /** @return array<string, array<string, mixed>> */
    private function fieldOperationsItems(User $user): array
    {
        $items = [];
        foreach (app(AdminNavigation::class)->groupsFor($user) as $group) {
            foreach ($group['children'] as $item) {
                $items[$item['key']] = $item;
            }
        }

        return $items;
    }
}
