<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\ManagementReportService;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P307: Sales Reports reuses the authorized Reports Center rather than
 * introducing a duplicate route or an independently visible Sidebar link.
 */
final class V2SalesReportsAuthorizedNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_global_sales_reports_are_discoverable_and_rendered_in_both_locales(): void
    {
        $admin = $this->superAdmin();
        $catalog = app(ManagementReportService::class)->catalog();

        $this->assertSame('reports.families.orders', $catalog['orders']);
        $this->assertSame('reports.families.sales_exceptions', $catalog['sales_exceptions']);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $analytics = collect(app(AdminNavigation::class)->groupsFor($admin->fresh()))
                ->firstWhere('key', 'analytics');
            $this->assertNotNull($analytics);

            $items = collect($analytics['children'])
                ->where('key', 'reports_center')->values();
            $this->assertCount(1, $items);
            $this->assertSame('admin.reports.index', $items->first()['route']);
            $this->assertSame('reports.view', $items->first()['permission']);

            foreach (['orders', 'sales_exceptions'] as $family) {
                $this->actingAs($admin->fresh())
                    ->get(route('admin.reports.index', ['report' => $family], false))
                    ->assertOk()
                    ->assertViewIs('admin.reports')
                    ->assertViewHas('report', $family)
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertSee(__($catalog[$family]));
            }
        }
    }

    public function test_unprivileged_actor_gets_neither_sales_navigation_nor_direct_http_access(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor = User::factory()->create(['locale' => $locale, 'is_active' => true]);

            $keys = collect(app(AdminNavigation::class)->groupsFor($actor))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')->all();

            $this->assertNotContains('reports_center', $keys);
            $this->assertNotContains('b2c_reports', $keys);

            $this->actingAs($actor)
                ->get(route('admin.reports.index', ['report' => 'sales_exceptions'], false))
                ->assertForbidden();

            $this->actingAs($actor)
                ->get(route('admin.reports.export', [
                    'report' => 'sales_exceptions',
                    'format' => 'pdf',
                ], false))
                ->assertForbidden();
        }
    }

    public function test_retail_sales_reports_use_own_store_module_not_unscoped_reports_center(): void
    {
        $ownStore = $this->retailStore('V2-P307-OWN');
        $otherStore = $this->retailStore('V2-P307-OTHER');
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->getKey(),
            'store_id' => $ownStore,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $keys = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')->all();

            $this->assertContains('b2c_reports', $keys);
            $this->assertNotContains('reports_center', $keys);

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', [
                    'module' => 'reports',
                    'store_id' => $ownStore,
                ], false))
                ->assertOk()
                ->assertViewHas('storeId', $ownStore);

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', [
                    'module' => 'reports',
                    'store_id' => $otherStore,
                ], false))
                ->assertNotFound();

            // A store-bound actor must supply a store before entering the shared
            // report endpoint; a missing store is a validation redirect, not 403.
            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', ['report' => 'sales_exceptions'], false))
                ->assertRedirect()
                ->assertSessionHasErrors('store_id');

            // With an explicit scope, the server permits only the assigned store.
            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [
                    'report' => 'sales_exceptions',
                    'store_id' => $otherStore,
                ], false))
                ->assertForbidden();

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [
                    'report' => 'sales_exceptions',
                    'store_id' => $ownStore,
                ], false))
                ->assertOk()
                ->assertViewHas('report', 'sales_exceptions');
        }
    }

    private function superAdmin(): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor;
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
}
