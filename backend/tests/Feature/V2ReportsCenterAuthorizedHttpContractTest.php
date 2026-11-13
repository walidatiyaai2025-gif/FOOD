<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ManagementReportService;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P305 — Reports is an actual, authorized Reports Center workflow, not merely
 * a label beneath the Analytics navigation group covered separately by P311.
 */
final class V2ReportsCenterAuthorizedHttpContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_all_seven_report_families_have_real_localized_usable_routes(): void
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $expected = [
            'orders', 'channels', 'products', 'customers', 'operations',
            'sales_exceptions', 'field_operations',
        ];
        $this->assertSame($expected, array_keys(app(ManagementReportService::class)->catalog()));
        $this->assertTrue(Route::has('admin.reports.index'));
        $this->assertTrue(Route::has('admin.reports.lookups'));
        $this->assertTrue(Route::has('admin.reports.export'));

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', ['report' => 'orders', 'per_page' => 25]))
                ->assertOk()
                ->assertViewIs('admin.reports')
                ->assertViewHas('report', 'orders')
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-foodex-utility="reports"', false)
                ->assertSee('data-report-filters', false)
                ->assertSee('data-report-product-picker', false)
                ->assertSee('data-report-customer-picker', false);

            foreach ($expected as $family) {
                $response->assertSee('report='.$family, false);
            }

            // This is a report-family workflow, not a second Sidebar policy.
            $analytics = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->firstWhere('key', 'analytics');
            $this->assertNotNull($analytics);
            $this->assertCount(1, collect($analytics['children'])->where('key', 'reports_center'));
            $this->assertNotContains('erp_bi', collect($analytics['children'])->pluck('key')->all());
        }
    }

    public function test_store_scoped_report_and_product_customer_lookups_never_cross_retail_store_or_channel(): void
    {
        $ownStore = $this->retailStore('P305-OWN', 'P305 Own Retail');
        $foreignStore = $this->retailStore('P305-FOREIGN', 'P305 Foreign Retail');
        $actor = $this->retailAdmin($ownStore);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [
                    'report' => 'orders', 'store_id' => $ownStore, 'channel' => 'b2c',
                ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('P305 Own Retail')
                ->assertDontSee('P305 Foreign Retail');

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [
                    'report' => 'orders', 'store_id' => $foreignStore, 'channel' => 'b2c',
                ]))
                ->assertForbidden();

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [
                    'report' => 'orders', 'store_id' => $ownStore, 'channel' => 'b2b',
                ]))
                ->assertForbidden();

            foreach (['products', 'customers'] as $entity) {
                $this->actingAs($actor->fresh())
                    ->get(route('admin.reports.lookups', [
                        'entity' => $entity, 'channel' => 'b2c', 'store_id' => $ownStore,
                    ]))
                    ->assertOk()
                    ->assertJsonStructure([$entity, 'meta' => ['page', 'page_size', 'has_more']]);

                $this->actingAs($actor->fresh())
                    ->get(route('admin.reports.lookups', [
                        'entity' => $entity, 'channel' => 'b2c', 'store_id' => $foreignStore,
                    ]))
                    ->assertForbidden();
            }
        }
    }

    public function test_explicit_store_export_deny_keeps_read_authorized_but_blocks_download_and_audit(): void
    {
        $storeId = $this->retailStore('P305-DENY', 'P305 Export-Deny Retail');
        $actor = $this->retailAdmin($storeId);
        $permission = Permission::query()->where('code', 'reports.export')->firstOrFail();

        $this->assertTrue($actor->hasPermission('reports.view', $storeId));
        $this->assertTrue($actor->hasPermission('reports.export', $storeId));

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'store:'.$storeId,
            'store_id' => $storeId,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $actor = $actor->fresh();

        $this->assertTrue($actor->hasPermission('reports.view', $storeId));
        $this->assertFalse($actor->hasPermission('reports.export', $storeId));

        $this->actingAs($actor)
            ->get(route('admin.reports.index', [
                'report' => 'orders', 'store_id' => $storeId, 'channel' => 'b2c',
            ]))
            ->assertOk()
            ->assertDontSee('/admin/reports/export', false);

        $auditBefore = DB::table('audit_logs')->where('event', 'report.exported')->count();
        $this->actingAs($actor)
            ->get(route('admin.reports.export', [
                'report' => 'orders', 'store_id' => $storeId,
                'channel' => 'b2c', 'format' => 'xlsx',
            ]))
            ->assertForbidden();

        $this->assertSame(
            $auditBefore,
            DB::table('audit_logs')->where('event', 'report.exported')->count(),
        );
    }

    public function test_roleless_actor_cannot_query_reports_lookups_or_export(): void
    {
        $actor = User::factory()->create(['locale' => 'ar', 'is_active' => true]);
        $this->assertNull(collect(app(AdminNavigation::class)->groupsFor($actor))
            ->firstWhere('key', 'analytics'));

        $this->actingAs($actor)->get(route('admin.reports.index'))->assertForbidden();
        $this->actingAs($actor)
            ->get(route('admin.reports.lookups', ['entity' => 'products']))
            ->assertForbidden();
        $this->actingAs($actor)
            ->get(route('admin.reports.export', ['report' => 'orders', 'format' => 'pdf']))
            ->assertForbidden();
    }

    private function retailStore(string $code, string $name): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailAdmin(int $storeId): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }
}
