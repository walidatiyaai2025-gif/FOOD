<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminSidebarManager;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 #2724 (T356R7): the existing Field Operations Overview link and
 * its rendered monetary summary must obey one real B2B store boundary.
 */
final class V2FieldOpsOverviewMigrationContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_existing_sidebar_item_reuses_canonical_field_operations_overview_without_duplicate_route(): void
    {
        $actor = $this->globalActor('FINANCE');
        $this->assertTrue($actor->hasPermission('finance.view'));

        $registry = app(AdminSidebarManager::class)->registry()['items']['field_ops_overview'];
        $this->assertSame('field_operations', $registry['group']);
        $this->assertSame('admin.field_operations.overview', $registry['label']);
        $this->assertSame('global', $registry['scope']);
        $this->assertTrue(Route::has('admin.field-operations.overview'));

        $items = $this->visibleItems($actor);
        $matches = array_values(array_filter(
            $items,
            static fn (array $item): bool => $item['key'] === 'field_ops_overview',
        ));

        $this->assertCount(1, $matches);
        $this->assertSame('admin.field-operations.overview', $matches[0]['route']);
    }

    public function test_authorized_finance_overview_custody_is_scoped_to_selected_wholesale_store_and_excludes_retail(): void
    {
        $first = $this->store('B2B', 'P2724-B2B-A');
        $second = $this->store('B2B', 'P2724-B2B-B');
        $retail = $this->store('B2C', 'P2724-B2C');
        $this->custodyEntry($first, 25);
        $this->custodyEntry($second, 5);
        $this->custodyEntry($retail, 90);

        $actor = $this->globalActor('FINANCE');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.overview', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-field-operations-dashboard', false)
                ->assertViewHas('summary', static fn (array $data): bool => (float) $data['outstanding_collections'] === 30.0);

            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.overview', ['store_id' => $first], false))
                ->assertOk()
                ->assertViewHas('dashboardFilters', static fn (array $filters): bool => $filters['store_id'] === $first)
                ->assertViewHas('summary', static fn (array $data): bool => (float) $data['outstanding_collections'] === 25.0);

            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.overview', ['store_id' => $retail], false))
                ->assertForbidden();
        }
    }

    public function test_finance_actor_without_b2b_tenant_role_sees_zero_custody_not_other_stores(): void
    {
        $wholesale = $this->store('B2B', 'P2724-FOREIGN-B2B');
        $this->custodyEntry($wholesale, 125);

        $role = Role::query()->create([
            'code' => 'P2724_CUSTOM_FINANCE_VIEW',
            'name' => 'V2 Global Finance Viewer Without B2B Scope',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach(Permission::query()->where('code', 'finance.view')->firstOrFail());
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach($role);
        $actor = $actor->fresh();

        $this->assertTrue($actor->hasPermission('finance.view'));
        $this->actingAs($actor)
            ->get(route('admin.field-operations.overview', [], false))
            ->assertOk()
            ->assertViewHas('summary', static fn (array $data): bool => (float) $data['outstanding_collections'] === 0.0);

        $this->actingAs($actor)
            ->get(route('admin.field-operations.overview', ['store_id' => $wholesale], false))
            ->assertForbidden();
    }

    public function test_store_scoped_retail_actor_cannot_browse_wholesale_field_operations_overview(): void
    {
        $retail = $this->store('B2C', 'P2724-RETAIL-ONLY');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $retail,
            'role_id' => Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actor = $actor->fresh();
        $this->assertFalse($actor->hasPermission('finance.view'));
        $this->assertFalse($actor->hasPermission('drivers.b2b.view'));
        $this->assertNotContains('field_ops_overview', array_column($this->visibleItems($actor), 'key'));

        $this->actingAs($actor)
            ->get(route('admin.field-operations.overview', [], false))
            ->assertForbidden();
    }

    private function globalActor(string $roleCode): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor->fresh();
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'business_profile' => $channel === 'B2C' ? StoreBusinessProfile::RETAIL_STORE->value : null,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function custodyEntry(int $storeId, int $amount): void
    {
        $account = (int) DB::table('collection_accounts')->insertGetId([
            'actor_type' => 'van',
            'actor_id' => 100000 + $storeId,
            'store_id' => $storeId,
            'currency' => 'EGP',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('custody_ledger_entries')->insert([
            'collection_account_id' => $account,
            'entry_type' => 'collection',
            'amount' => $amount,
            'currency' => 'EGP',
            'reference_type' => 'v2_field_ops_overview_scope',
            'reference_id' => $account,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function visibleItems(User $actor): array
    {
        return collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->values()
            ->all();
    }
}
