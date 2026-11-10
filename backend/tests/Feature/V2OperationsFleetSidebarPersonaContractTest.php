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
 * T353R6: operations and fleet persona navigation must resolve real
 * B2B-scoped HTTP surfaces without granting finance or Retail access.
 */
final class V2OperationsFleetSidebarPersonaContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_operations_persona_links_real_orders_fleet_vans_and_reports(): void
    {
        $actor = $this->globalOperationsUser();

        foreach (['orders.view', 'drivers.b2b.view', 'drivers.tracking.view', 'reports.view'] as $permission) {
            $this->assertTrue($actor->hasPermission($permission), "Missing canonical {$permission}.");
        }
        $this->assertFalse($actor->hasPermission('finance.view'));
        $this->assertFalse($actor->hasPermission('drivers.b2c.view'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $items = $this->visibleItems($actor->fresh());
            foreach ([
                'b2b_dashboard',
                'order_operations',
                'driver_live_tracking',
                'b2b_drivers',
                'field_ops_overview',
                'field_ops_fleet',
                'field_ops_vans',
                'field_ops_assignments',
                'reports_center',
            ] as $key) {
                $this->assertArrayHasKey($key, $items, "Missing {$key} in {$locale}.");
                $this->assertTrue(Route::has($items[$key]['route']), "Dead route for {$key}.");
            }

            foreach (['b2c_drivers', 'b2c_incoming_orders', 'treasury_master', 'sidebar_role_access', 'erp_finance'] as $key) {
                $this->assertArrayNotHasKey($key, $items, "Operations role leaked {$key}.");
            }

            foreach (['field_ops_overview', 'field_ops_fleet', 'field_ops_vans', 'field_ops_assignments'] as $key) {
                $item = $items[$key];
                $response = $this->actingAs($actor->fresh())
                    ->get(route($item['route'], $item['params'], false));

                $this->assertTrue(
                    $response->getStatusCode() >= 200 && $response->getStatusCode() < 400,
                    "{$key} returned HTTP {$response->getStatusCode()} in {$locale}.",
                );
                $response->assertSee('dir="'.$direction.'"', false);
            }
        }
    }

    public function test_retail_operations_store_assignment_cannot_access_wholesale_fleet_or_other_store(): void
    {
        $ownStore = $this->retailStore('P2715-RETAIL-OWN');
        $foreignStore = $this->retailStore('P2715-RETAIL-OTHER');

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $ownStore,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actor = $actor->fresh();
        $this->assertTrue($actor->hasPermission('orders.view', $ownStore));
        $this->assertFalse($actor->hasPermission('orders.view', $foreignStore));
        $this->assertFalse($actor->hasPermission('drivers.b2b.view'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $actor = $actor->fresh();

            $keys = $this->visibleItems($actor);
            $this->assertArrayHasKey('b2c_orders', $keys);
            $this->assertSame('admin.b2c.module', $keys['b2c_orders']['route']);
            $this->assertSame('orders', $keys['b2c_orders']['params']['module']);
            $this->assertSame((string) $ownStore, $keys['b2c_orders']['params']['store_id']);
            $this->assertArrayNotHasKey('b2c_incoming_orders', $keys);
            $this->assertArrayNotHasKey('field_ops_vans', $keys);
            $this->assertArrayNotHasKey('field_ops_assignments', $keys);
            $this->assertArrayNotHasKey('b2b_drivers', $keys);

            $this->actingAs($actor)
                ->get(route('admin.b2c.module', ['module' => 'orders', 'store_id' => $ownStore], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($actor)
                ->get(route('admin.b2c.module', ['module' => 'incoming_orders', 'store_id' => $ownStore], false))
                ->assertNotFound();

            $foreign = $this->actingAs($actor)->get(route('admin.b2c.module', [
                'module' => 'incoming_orders',
                'store_id' => $foreignStore,
            ], false));
            $this->assertContains($foreign->getStatusCode(), [403, 404]);
        }

        foreach (['admin.field-operations.vans', 'admin.field-operations.assignments'] as $route) {
            $this->actingAs($actor)->get(route($route, [], false))->assertForbidden();
        }
    }

    private function globalOperationsUser(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'OPERATIONS')->firstOrFail());

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
