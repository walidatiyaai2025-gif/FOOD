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
 * V2 P204: Supervisory reporting reuses the canonical authorized Reports Center.
 * Navigation is a projection of server access, never an independent permission.
 */
final class V2SupervisoryReportsRouteContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_supervisory_report_families_are_reachable_from_one_localized_reports_center(): void
    {
        $actor = $this->superAdmin();
        $catalog = app(ManagementReportService::class)->catalog();

        $this->assertSame('reports.families.operations', $catalog['operations']);
        $this->assertSame('reports.families.sales_exceptions', $catalog['sales_exceptions']);
        $this->assertSame('reports.families.field_operations', $catalog['field_operations']);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $analytics = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->firstWhere('key', 'analytics');
            $this->assertNotNull($analytics);

            $links = collect($analytics['children'])
                ->where('key', 'reports_center')->values();
            $this->assertCount(1, $links);
            $this->assertSame('admin.reports.index', $links->first()['route']);
            $this->assertSame('reports.view', $links->first()['permission']);

            foreach (['operations', 'sales_exceptions', 'field_operations'] as $family) {
                $this->actingAs($actor->fresh())
                    ->get(route('admin.reports.index', [
                        'report' => $family,
                        'channel' => $family === 'field_operations' ? 'b2b' : null,
                    ], false))
                    ->assertOk()
                    ->assertViewIs('admin.reports')
                    ->assertViewHas('report', $family)
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertSee(__($catalog[$family]));
            }
        }
    }

    public function test_roleless_user_cannot_discover_or_open_any_supervisory_report_or_export(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor = User::factory()->create(['locale' => $locale, 'is_active' => true]);

            $keys = collect(app(AdminNavigation::class)->groupsFor($actor))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')->all();

            $this->assertNotContains('reports_center', $keys);

            foreach (['operations', 'sales_exceptions', 'field_operations'] as $family) {
                $this->actingAs($actor)
                    ->get(route('admin.reports.index', ['report' => $family], false))
                    ->assertForbidden();
            }

            $this->actingAs($actor)
                ->get(route('admin.reports.export', [
                    'report' => 'operations',
                    'format' => 'pdf',
                ], false))
                ->assertForbidden();
        }
    }

    public function test_retail_supervisor_reports_remain_store_scoped_and_never_grant_van_channel(): void
    {
        $ownStore = $this->retailStore('V2-P204-OWN');
        $foreignStore = $this->retailStore('V2-P204-FOREIGN');
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->getKey(),
            'store_id' => $ownStore,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

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
                ->assertOk();

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', [
                    'module' => 'reports',
                    'store_id' => $foreignStore,
                ], false))
                ->assertNotFound();

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', ['report' => 'operations'], false))
                ->assertRedirect()
                ->assertSessionHasErrors('store_id');

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [
                    'report' => 'operations',
                    'store_id' => $foreignStore,
                ], false))
                ->assertForbidden();

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [
                    'report' => 'operations',
                    'store_id' => $ownStore,
                    'channel' => 'b2c',
                ], false))
                ->assertOk()
                ->assertViewHas('report', 'operations');

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [
                    'report' => 'field_operations',
                    'store_id' => $ownStore,
                    'channel' => 'b2b',
                ], false))
                ->assertForbidden();
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
