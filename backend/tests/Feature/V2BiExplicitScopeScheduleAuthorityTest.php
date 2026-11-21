<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/**
 * V2 #3958: BI report selection and scheduled exports must share the same
 * effective authorized store, active global role, and profile boundary.
 */
final class V2BiExplicitScopeScheduleAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        SyntheticErpWorkflowEvidence::install();
        config()->set(
            'enterprise_module_readiness.certified_versions.enterprise_bi',
            app(ModuleRegistry::class)->manifest('enterprise_bi')->version,
        );

        app(ModuleRegistry::class)->syncManifest('enterprise_bi');
        app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::ENABLED);
    }

    public function test_store_a_allowed_and_explicit_store_b_denied_for_bi_run_and_schedule_in_both_locales(): void
    {
        $storeA = $this->store('V2-3958-A');
        $storeB = $this->store('V2-3958-B');
        $actor = $this->analyst([$storeA, $storeB]);
        $this->entitle($storeA, true);
        $this->entitle($storeB, false);
        $allowedSchedule = $this->schedulePayload($storeA);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            app()->forgetInstance(AdminNavigation::class);

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.bi', [
                    'report' => 'orders.summary',
                    'scope' => 'store:'.$storeA->id,
                    'run' => 1,
                ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.bi', [
                    'report' => 'orders.summary',
                    'scope' => 'store:'.$storeB->id,
                    'run' => 1,
                ]))
                ->assertForbidden();

            $this->actingAs($actor->fresh())
                ->post(route('admin.erp.bi.schedules.store'), $this->schedulePayload($storeB))
                ->assertForbidden();

            $this->actingAs($actor->fresh())
                ->post(route('admin.erp.bi.schedules.store'), $allowedSchedule)
                ->assertRedirect();
        }

        $this->assertSame(1, DB::table('enterprise_scheduled_reports')->where('requested_by', $actor->id)->count());
        $this->assertDatabaseHas('enterprise_scheduled_reports', [
            'requested_by' => $actor->id,
            'scope_type' => 'store',
            'scope_id' => $storeA->id,
        ]);
        $this->assertDatabaseMissing('enterprise_scheduled_reports', [
            'requested_by' => $actor->id,
            'scope_type' => 'store',
            'scope_id' => $storeB->id,
        ]);
    }

    public function test_foreign_store_entitlement_cannot_bypass_actor_store_assignment_on_direct_schedule(): void
    {
        $storeA = $this->store('V2-3958-ASSIGNED');
        $storeB = $this->store('V2-3958-FOREIGN');
        $actor = $this->analyst([$storeA]);

        // A foreign module entitlement is not a user/store assignment.
        $this->entitle($storeA, true);
        $this->entitle($storeB, true);

        $this->actingAs($actor)->get(route('admin.erp.bi', [
            'report' => 'orders.summary', 'scope' => 'store:'.$storeA->id, 'run' => 1,
        ]))->assertOk();

        $this->actingAs($actor)->get(route('admin.erp.bi', [
            'report' => 'orders.summary', 'scope' => 'store:'.$storeB->id, 'run' => 1,
        ]))->assertForbidden();

        $this->actingAs($actor)
            ->post(route('admin.erp.bi.schedules.store'), $this->schedulePayload($storeB))
            ->assertForbidden();

        $this->assertDatabaseCount('enterprise_scheduled_reports', 0);
    }

    public function test_foreign_store_scoped_schedule_update_is_denied_even_for_original_requester(): void
    {
        $storeA = $this->store('V2-3958-PATCH-A');
        $storeB = $this->store('V2-3958-PATCH-B');
        $actor = $this->analyst([$storeA]);
        $this->entitle($storeA, true);
        $this->entitle($storeB, true);

        $this->actingAs($actor)
            ->post(route('admin.erp.bi.schedules.store'), $this->schedulePayload($storeA))
            ->assertRedirect();

        $schedule = DB::table('enterprise_scheduled_reports')->where('requested_by', $actor->id)->first();
        $this->assertNotNull($schedule);

        // A previously authorized schedule can no longer be modified once its
        // scope is outside the principal's effective store membership.
        DB::table('enterprise_scheduled_reports')
            ->where('id', $schedule->id)
            ->update(['scope_id' => $storeB->id]);

        $this->actingAs($actor)
            ->patch(route('admin.erp.bi.schedules.update', ['schedule' => $schedule->id]), [
                'enabled' => false,
                'interval_minutes' => 2880,
                'next_run_at' => now()->addHours(2)->format('Y-m-d H:i:s'),
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('enterprise_scheduled_reports', [
            'id' => $schedule->id,
            'scope_id' => $storeB->id,
            'enabled' => true,
            'interval_minutes' => 1440,
        ]);
    }

    public function test_roleless_inactive_role_and_inactive_actor_cannot_enter_or_schedule_bi(): void
    {
        $store = $this->store('V2-3958-DENY');
        $this->entitle($store, true);
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->assertBiReadAndScheduleForbidden($roleless, $store);

        $actor = $this->analyst([$store]);
        $globalRole = Role::query()->where('code', 'BUSINESS_ANALYST')->firstOrFail();
        $globalRole->forceFill(['is_active' => false])->save();
        $this->assertBiReadAndScheduleForbidden($actor->fresh(), $store);

        $globalRole->forceFill(['is_active' => true])->save();
        $actor->forceFill(['is_active' => false])->save();
        $this->assertBiReadAndScheduleForbidden($actor->fresh(), $store);
        $this->assertDatabaseCount('enterprise_scheduled_reports', 0);
    }

    public function test_customer_service_profile_denies_direct_schedule_post_and_patch_even_with_reporting_role(): void
    {
        $store = $this->store('V2-3958-CS');
        $actor = $this->analyst([$store]);
        $this->entitle($store, true);

        $this->actingAs($actor)
            ->post(route('admin.erp.bi.schedules.store'), $this->schedulePayload($store))
            ->assertRedirect();

        $schedule = DB::table('enterprise_scheduled_reports')->where('requested_by', $actor->id)->first();
        $this->assertNotNull($schedule);
        $actor->roles()->attach(Role::query()->where('code', 'CUSTOMER_SERVICE_AGENT')->firstOrFail());
        app()->forgetInstance(AdminNavigation::class);

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            $this->assertBiReadAndScheduleForbidden($actor->fresh(), $store);

            $this->actingAs($actor->fresh())
                ->patch(route('admin.erp.bi.schedules.update', ['schedule' => $schedule->id]), [
                    'enabled' => false,
                    'interval_minutes' => 2880,
                    'next_run_at' => now()->addHours(2)->format('Y-m-d H:i:s'),
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseHas('enterprise_scheduled_reports', [
            'id' => $schedule->id,
            'enabled' => true,
            'interval_minutes' => 1440,
        ]);
        $this->assertDatabaseCount('enterprise_scheduled_reports', 1);
    }

    /** @param list<Store> $stores */
    private function analyst(array $stores): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'BUSINESS_ANALYST')->firstOrFail());

        foreach ($stores as $store) {
            DB::table('user_store_roles')->insert([
                'user_id' => $actor->id,
                'store_id' => $store->id,
                'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $actor->fresh();
    }

    private function store(string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function entitle(Store $store, bool $enabled): void
    {
        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            $enabled,
            ['reports.read', 'reports.export'],
        );
    }

    /** @return array<string, mixed> */
    private function schedulePayload(Store $store): array
    {
        return [
            'report' => 'orders.summary',
            'scope_type' => 'store',
            'scope_id' => $store->id,
            'from' => now()->subDay()->toDateString(),
            'to' => now()->toDateString(),
            'dimensions' => ['date'],
            'measures' => ['orders'],
            'interval_minutes' => 1440,
            'next_run_at' => now()->addHour()->format('Y-m-d H:i:s'),
        ];
    }

    private function assertBiReadAndScheduleForbidden(User $actor, Store $store): void
    {
        $this->actingAs($actor)
            ->get(route('admin.erp.bi', ['report' => 'orders.summary', 'scope' => 'store:'.$store->id]))
            ->assertForbidden();

        $this->actingAs($actor)
            ->post(route('admin.erp.bi.schedules.store'), $this->schedulePayload($store))
            ->assertForbidden();
    }
}
