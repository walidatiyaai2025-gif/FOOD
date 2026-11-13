<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #2922, historical export row 17. Use synthetic identities only.
 */
final class V2ExportedRolelessRow17BoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_roleless_customer_cannot_read_or_mutate_wholesale_or_retail_orders(): void
    {
        $wholesale = $this->store('B2B', 'V2-2922-WHOLESALE');
        $retail = $this->store('B2C', 'V2-2922-RETAIL');
        $customer = User::factory()->create([
            'name' => 'V2 Row17 Synthetic Customer',
            'is_platform_customer' => true,
            'is_active' => true,
            'locale' => 'en',
        ]);

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $customer->forceFill(['locale' => $locale])->save();

            $this->actingAs($customer->fresh())
                ->get(route('admin.b2b.orders.canonical', ['store_id' => $wholesale]))
                ->assertForbidden();
            $this->actingAs($customer->fresh())
                ->get(route('admin.b2c.orders.canonical', ['store_id' => $retail]))
                ->assertForbidden();

            // Spoofing a real store ID cannot create administrative order rights.
            $this->actingAs($customer->fresh())
                ->post(route('admin.b2b.orders.store'), ['store_id' => $wholesale])
                ->assertForbidden();
            $this->actingAs($customer->fresh())
                ->post(route('admin.b2c.orders.store'), ['store_id' => $retail])
                ->assertForbidden();
        }

        $customer = $customer->fresh();
        $this->assertTrue((bool) $customer->is_platform_customer);
        $this->assertTrue((bool) $customer->is_active);
        $this->assertSame(0, $customer->roles()->count());
        $this->assertSame(0, $customer->storeRoleAssignments()->count());
        $this->assertSame([], $customer->inheritedPermissionCodes());
        $this->assertSame([], $customer->effectivePermissionCodes());
        $this->assertFalse($customer->hasPermission('orders.view'));
        $this->assertFalse($customer->hasPermission('orders.dispatch'));
        $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($customer, 'orders.view', 'b2b'));
        $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($customer, 'orders.view', 'b2c'));
        $this->assertSame([], app(AdminNavigation::class)->groupsFor($customer));
        $this->assertDatabaseMissing('role_user', ['user_id' => $customer->id]);
        $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $customer->id]);
        $this->assertDatabaseHas('users', ['id' => $customer->id, 'is_active' => true]);
    }

    public function test_security_inventory_classifies_roleless_customer_without_granting_dashboard_role(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $customer = User::factory()->create([
            'name' => 'V2 Row17 Customer Classification Fixture',
            'is_platform_customer' => true,
            'is_active' => true,
        ]);

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin->fresh())
                ->get(route('admin.security.index', [
                    'section' => 'users',
                    'assignment' => 'roleless',
                    'q' => 'V2 Row17 Customer Classification Fixture',
                ]))
                ->assertOk()
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                ->assertSee('data-roleless-classification="customer"', false)
                ->assertViewHas('users', fn ($users): bool => $users->total() === 1);
        }

        $this->actingAs($customer)
            ->get(route('admin.security.index', ['section' => 'users']))
            ->assertForbidden();
        $this->actingAs($customer)
            ->get(route('admin.sidebar-access.roles'))
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $customer->id, 'is_active' => true]);
        $this->assertDatabaseMissing('role_user', ['user_id' => $customer->id]);
        $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $customer->id]);
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
