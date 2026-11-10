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
 * V2 #3070: Sidebar projection must never promise an Assignments route
 * that the authoritative FieldOperationsController rejects.
 */
final class V2FieldOperationsAssignmentsSidebarAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_registry_assignments_permission_matches_the_actual_http_guard(): void
    {
        $items = app(AdminSidebarManager::class)->registry()['items'];

        $this->assertSame(
            ['drivers.b2b.view'],
            $items['field_ops_assignments']['permissions'],
        );
        $this->assertSame('global', $items['field_ops_assignments']['scope']);
        $this->assertTrue(Route::has('admin.field-operations.assignments'));
    }

    public function test_field_ops_only_actor_can_open_overview_but_not_b2b_assignments_in_arabic_and_english(): void
    {
        $actor = $this->fieldOperationsOnlyActor();
        $this->assertTrue($actor->hasPermission('field_ops.manage'));
        $this->assertFalse($actor->hasPermission('drivers.b2b.view'));
        $this->assertTrue(app(AdminNavigation::class)->canUseDashboard($actor));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            $items = $this->visibleItems($actor->fresh());
            $this->assertArrayHasKey('field_ops_overview', $items);
            $this->assertArrayNotHasKey('field_ops_assignments', $items);
            $this->assertArrayNotHasKey('field_ops_vans', $items);

            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.overview', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.assignments', [], false))
                ->assertForbidden();
        }
    }

    public function test_authorized_wholesale_operations_actor_has_a_working_assignments_link_in_both_locales(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'OPERATIONS')->firstOrFail());
        $actor = $actor->fresh();

        $this->assertTrue($actor->hasPermission('drivers.b2b.view'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            $items = $this->visibleItems($actor->fresh());

            $this->assertArrayHasKey('field_ops_assignments', $items);
            $this->assertSame(
                'admin.field-operations.assignments',
                $items['field_ops_assignments']['route'],
            );

            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.assignments', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_retail_store_operator_cannot_see_or_open_wholesale_assignments(): void
    {
        $ownStoreId = $this->retailStore('P3070-OWN');
        $foreignStoreId = $this->retailStore('P3070-FOREIGN');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $ownStoreId,
            'role_id' => Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actor = $actor->fresh();
        $this->assertTrue($actor->hasPermission('orders.view', $ownStoreId));
        $this->assertFalse($actor->hasPermission('orders.view', $foreignStoreId));
        $this->assertFalse($actor->hasPermission('drivers.b2b.view'));

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            $items = $this->visibleItems($actor->fresh());

            $this->assertArrayNotHasKey('field_ops_assignments', $items);
            $this->assertArrayNotHasKey('field_ops_vans', $items);

            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.assignments', [], false))
                ->assertForbidden();
        }
    }

    private function fieldOperationsOnlyActor(): User
    {
        $role = Role::query()->create([
            'code' => 'P3070_FIELD_OPS_ONLY',
            'name' => 'V2 Field Operations Only',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach(
            Permission::query()->where('code', 'field_ops.manage')->firstOrFail(),
        );

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach($role);

        return $actor->fresh();
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function visibleItems(User $actor): array
    {
        $items = [];

        foreach (app(AdminNavigation::class)->groupsFor($actor) as $group) {
            foreach ($group['children'] as $item) {
                $items[$item['key']] = $item;
                foreach ((array) ($item['children'] ?? []) as $child) {
                    $items[$child['key']] = $child;
                }
            }
        }

        return $items;
    }
}
