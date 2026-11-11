<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 #2778: real ERP/Field Operations entry points are restored for
 * authorized platform actors without opening their HTTP boundary to Retail.
 */
final class V2ErpFieldOperationsAuthorizedRestorationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_global_operations_role_reaches_existing_erp_and_field_operations_in_both_locales(): void
    {
        $actor = $this->globalActor('OPERATIONS');

        $this->assertTrue(app(AdminNavigation::class)->canAccessPlatformErp($actor));
        $this->assertTrue($actor->hasPermission('drivers.b2b.view'));
        $this->assertTrue(Route::has('admin.erp.index'));
        $this->assertTrue(Route::has('admin.field-operations.assignments'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            $actor = $actor->fresh();
            $items = $this->visibleItems($actor);

            $this->assertSame('admin.erp.index', $items['erp_center']['route']);
            $this->assertSame('admin.field-operations.assignments', $items['field_ops_assignments']['route']);

            $this->actingAs($actor)
                ->get(route('admin.erp.index', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($actor)
                ->get(route('admin.field-operations.assignments', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_store_only_retail_role_cannot_use_hidden_platform_erp_or_wholesale_field_operations_urls(): void
    {
        $store = $this->retailStore('P2778-RETAIL');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store,
            'role_id' => Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actor = $actor->fresh();
        $this->assertFalse(app(AdminNavigation::class)->canAccessPlatformErp($actor));
        $this->assertTrue($actor->hasPermission('orders.view', $store));

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            $actor = $actor->fresh();
            $items = $this->visibleItems($actor);

            $this->assertArrayNotHasKey('erp_center', $items);
            $this->assertArrayNotHasKey('field_ops_assignments', $items);

            $this->actingAs($actor)->get(route('admin.erp.index', [], false))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.field-operations.assignments', [], false))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.field-operations.vans', [], false))->assertForbidden();
        }
    }

    public function test_roleless_user_cannot_open_erp_or_field_operations_by_direct_url(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->assertFalse(app(AdminNavigation::class)->canAccessPlatformErp($actor));
        $this->assertArrayNotHasKey('erp_center', $this->visibleItems($actor));

        $this->actingAs($actor)->get(route('admin.erp.index', [], false))->assertForbidden();
        $this->actingAs($actor)->get(route('admin.field-operations.overview', [], false))->assertForbidden();
    }

    public function test_inactive_global_role_cannot_reach_erp_or_field_operations(): void
    {
        $actor = $this->globalActor('OPERATIONS');
        $actor->forceFill(['is_active' => false])->save();
        $actor = $actor->fresh();

        $this->assertFalse(app(AdminNavigation::class)->canAccessPlatformErp($actor));
        $this->assertArrayNotHasKey('erp_center', $this->visibleItems($actor));

        $this->actingAs($actor)->get(route('admin.erp.index', [], false))->assertForbidden();
        $this->actingAs($actor)->get(route('admin.field-operations.assignments', [], false))->assertForbidden();
    }

    private function globalActor(string $roleCode): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

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
