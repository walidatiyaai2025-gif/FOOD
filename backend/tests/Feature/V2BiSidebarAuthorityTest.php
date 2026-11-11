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
use Tests\TestCase;

/** V2 P312: BI Sidebar visibility must match the real certified HTTP entry points. */
final class V2BiSidebarAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_certified_entitled_bi_is_reachable_once_under_analytics_in_both_locales(): void
    {
        $actor = $this->readyPlatformReporter();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $hits = [];
            foreach (app(AdminNavigation::class)->groupsFor($actor->fresh()) as $group) {
                foreach ($group['children'] as $item) {
                    if ($item['key'] === 'erp_bi') {
                        $hits[] = [$group['key'], $item['route'], $item['permission']];
                    }
                }
            }

            $this->assertSame([['analytics', 'admin.erp.bi', null]], $hits, $locale.' BI navigation');
            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.bi'))
                ->assertOk()
                ->assertViewIs('admin.enterprise-bi')
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('enterprise_bi_ui.title'));
        }
    }

    public function test_disabled_bi_is_not_an_unprotected_direct_url_or_schedule_mutation(): void
    {
        $actor = $this->platformReporter();
        app(ModuleRegistry::class)->syncManifest('enterprise_bi');

        $keys = collect(app(AdminNavigation::class)->groupsFor($actor))->flatMap(
            static fn (array $group): array => array_column($group['children'], 'key'),
        )->all();

        $this->assertNotContains('erp_bi', $keys);
        $this->actingAs($actor)->get(route('admin.erp.bi'))->assertForbidden();
        $this->actingAs($actor)->post(route('admin.erp.bi.schedules.store'), [])->assertForbidden();
        $this->actingAs($actor)->patch(route('admin.erp.bi.schedules.update', ['schedule' => 1]), [])->assertForbidden();
        $this->assertSame(0, DB::table('enterprise_scheduled_reports')->count());
    }

    public function test_enabled_but_uncertified_bi_is_denied_before_report_or_schedule_access(): void
    {
        $actor = $this->readyPlatformReporter();
        config()->set('enterprise_module_readiness.certified_versions.enterprise_bi', null);

        $keys = collect(app(AdminNavigation::class)->groupsFor($actor))->flatMap(
            static fn (array $group): array => array_column($group['children'], 'key'),
        )->all();

        $this->assertNotContains('erp_bi', $keys);
        $this->actingAs($actor)->get(route('admin.erp.bi'))->assertForbidden();
        $this->actingAs($actor)->post(route('admin.erp.bi.schedules.store'), [])->assertForbidden();
        $this->actingAs($actor)->patch(route('admin.erp.bi.schedules.update', ['schedule' => 1]), [])->assertForbidden();
        $this->assertSame(0, DB::table('enterprise_scheduled_reports')->count());
    }

    public function test_retail_only_and_inactive_users_cannot_use_bi_route_or_gain_sidebar_link(): void
    {
        $this->readyPlatformReporter();
        $store = Store::query()->firstOrFail();
        $retail = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->id,
            'store_id' => $store->id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $keys = collect(app(AdminNavigation::class)->groupsFor($retail))->flatMap(
            static fn (array $group): array => array_column($group['children'], 'key'),
        )->all();

        $this->assertNotContains('erp_bi', $keys);
        $this->actingAs($retail)->get(route('admin.erp.bi'))->assertForbidden();

        $inactive = $this->platformReporter();
        $inactive->forceFill(['is_active' => false])->save();
        $this->actingAs($inactive)->get(route('admin.erp.bi'))->assertForbidden();
    }

    private function platformReporter(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor;
    }

    private function readyPlatformReporter(): User
    {
        $registry = app(ModuleRegistry::class);
        $registry->syncManifest('enterprise_bi');
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('enterprise_bi', ModuleState::ENABLING);
        $lifecycle->transition('enterprise_bi', ModuleState::ENABLED);
        config()->set('enterprise_module_readiness.certified_versions.enterprise_bi', $registry->manifest('enterprise_bi')->version);

        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-P312-BI',
            'name' => 'V2 P312 BI Store',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
            ['reports.read', 'reports.export'],
        );

        return $this->platformReporter();
    }
}
