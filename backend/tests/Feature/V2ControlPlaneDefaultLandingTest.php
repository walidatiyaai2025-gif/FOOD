<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformOwnerProvisioner;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2ControlPlaneDefaultLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_with_concurrent_operational_role_always_lands_on_control_plane_in_both_locales(): void
    {
        $administrator = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $administrator->roles()->attach(
            Role::query()->whereIn('code', ['SUPER_ADMIN', 'CUSTOMER_SERVICE_MANAGER'])->pluck('id')->all(),
        );

        $navigation = app(AdminNavigation::class);
        $this->assertFalse($navigation->usesCustomerServiceDashboardProfile($administrator));
        $this->assertSame(
            route('admin.administration.index'),
            $navigation->defaultWorkspaceUrl($administrator, 'b2b'),
        );
        $this->assertSame(
            route('admin.administration.index'),
            $navigation->defaultWorkspaceUrl($administrator, 'b2c'),
        );

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $administrator->forceFill(['locale' => $locale])->save();

            $this->actingAs($administrator)
                ->get(route('admin.index'))
                ->assertRedirect(route('admin.administration.index'));

            $this->actingAs($administrator)
                ->get(route('admin.administration.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-admin-hub="true"', false);

            // Protected control-plane actors may inspect an explicitly requested
            // authorized Wholesale page, but it is never their automatic home.
            $this->actingAs($administrator)
                ->get(route('admin.b2b.dashboard'))
                ->assertOk();
        }

        $this->assertTrue($administrator->fresh()->hasRole('SUPER_ADMIN'));
        $this->assertTrue($administrator->fresh()->hasRole('CUSTOMER_SERVICE_MANAGER'));
        $this->assertSame(2, $administrator->fresh()->roles()->count());
    }

    public function test_registry_backed_platform_owner_uses_administration_not_retail_or_wholesale_as_default(): void
    {
        $owner = app(PlatformOwnerProvisioner::class)->provision(
            'v2-p130-control-plane@example.test',
            'V2-Owner-Strong-Secret-2026!P130',
        );
        $this->assertTrue($owner->isPlatformOwner());
        $this->assertTrue($owner->hasRole('SUPER_ADMIN'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $owner->forceFill(['locale' => $locale])->save();
            $this->actingAs($owner)
                ->get(route('admin.index'))
                ->assertRedirect(route('admin.administration.index'));

            $this->actingAs($owner)
                ->get(route('admin.administration.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->assertSame(
                route('admin.administration.index'),
                app(AdminNavigation::class)->defaultWorkspaceUrl($owner, 'b2b'),
            );
        }

        $this->assertDatabaseHas('platform_owner_registry', [
            'id' => 1,
            'user_id' => $owner->id,
        ]);

        $unregistered = User::factory()->create(['is_active' => true]);
        $unregistered->roles()->attach(Role::query()->where('code', 'PLATFORM_OWNER')->firstOrFail());
        $this->assertFalse($unregistered->isPlatformOwner());
        $this->assertFalse($unregistered->hasRole('SUPER_ADMIN'));
        $this->assertFalse($unregistered->hasPermission('platform.manage'));
    }

    public function test_explicit_retail_support_requires_store_context_even_after_privileged_default_redirect(): void
    {
        $store = $this->retailStore();
        $administrator = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $administrator->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $this->actingAs($administrator)
            ->get(route('admin.index'))
            ->assertRedirect(route('admin.administration.index'));

        $this->actingAs($administrator)
            ->get(route('admin.b2c.module', ['module' => 'inventory', 'store_id' => $store]))
            ->assertForbidden();

        $this->actingAs($administrator)
            ->get(route('admin.b2c.module', [
                'module' => 'inventory',
                'store_id' => $store,
                'support_access' => 1,
            ]))
            ->assertOk()
            ->assertViewHas('storeId', $store)
            ->assertViewHas('supportAccess', true);

        $this->actingAs($administrator)
            ->get(route('admin.index'))
            ->assertRedirect(route('admin.administration.index'));
    }

    public function test_non_privileged_wholesale_staff_keep_the_existing_daily_dashboard_entry(): void
    {
        $operator = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $operator->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        $this->actingAs($operator)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('data-foodex-dashboard-header-search', false);
    }

    private function retailStore(): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => 'V2-P130-RETAIL',
            'name' => 'V2 P130 Retail Support Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
