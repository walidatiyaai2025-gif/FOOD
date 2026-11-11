<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Models\Role;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\OperationalTenantScope;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * V2 T122R23: EXECUTIVE_VIEWER sees authorized B2B reporting only, never
 * Retail stores or Finance/Procurement/Dispatch actions.
 */
final class V2ExecutiveViewerRoleAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_role_is_seeded_with_exact_read_only_analytics_grants_and_correct_channel(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $definition = $catalog->definition('EXECUTIVE_VIEWER');

        $this->assertNotNull($definition);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame('viewer', $definition['level']);
        $this->assertSame(['enterprise_bi'], $definition['required_modules']);
        $this->assertEqualsCanonicalizing(['reports.view', 'reports.export'], $definition['permissions']);

        $role = Role::query()->where('code', 'EXECUTIVE_VIEWER')->firstOrFail();
        $this->assertTrue((bool) $role->is_system);
        $this->assertEqualsCanonicalizing(
            ['reports.view', 'reports.export'],
            $role->permissions()->pluck('code')->all(),
        );
        $this->assertContains('EXECUTIVE_VIEWER', config('admin.channels.b2b.global_roles'));
        $this->assertNotContains('EXECUTIVE_VIEWER', config('admin.channels.b2c.global_roles'));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $presentation = $catalog->presentation('EXECUTIVE_VIEWER');
            $this->assertNotEmpty($presentation['name']);
            $this->assertNotEmpty($presentation['description']);
            $this->assertSame('viewer', $presentation['level']);
        }
    }

    public function test_disabled_bi_module_rejects_report_permission_and_direct_http_without_erasing_role_assignment(): void
    {
        $actor = $this->viewer();
        app(ModuleRegistry::class)->syncManifest('enterprise_bi');

        $this->assertFalse(app(BuiltInJobRoleCatalog::class)->isAvailable('EXECUTIVE_VIEWER'));
        $this->assertFalse($actor->fresh()->hasPermission('reports.view'));
        $this->assertNotContains('reports.export', $actor->fresh()->effectivePermissionCodes());

        $this->actingAs($actor)->get(route('admin.reports.index', ['channel' => 'b2b'], false))->assertForbidden();
        $this->assertFalse($this->navigationKeys($actor->fresh())->contains('reports_center'));
        $this->assertDatabaseHas('role_user', [
            'user_id' => $actor->id,
            'role_id' => Role::query()->where('code', 'EXECUTIVE_VIEWER')->value('id'),
        ]);
    }

    public function test_enabled_executive_viewer_discovers_b2b_reports_with_ar_en_and_cannot_cross_into_retail_or_sensitive_actions(): void
    {
        $wholesaleStoreId = app(WholesalePrincipal::class)->storeId();
        $retailStoreId = $this->retailStore('V2-2703-RETAIL');
        $this->enableBi();

        $actor = $this->viewer();
        $this->assertTrue($actor->hasPermission('reports.view'));
        $this->assertTrue($actor->hasPermission('reports.export'));
        $this->assertFalse($actor->hasRole('SUPER_ADMIN'));

        $scopes = app(OperationalTenantScope::class);
        $this->assertContains($wholesaleStoreId, $scopes->allowedStoreIds($actor, 'reports.view', 'b2b'));
        $this->assertSame([], $scopes->allowedStoreIds($actor, 'reports.view', 'b2c'));

        $navigation = $this->navigationKeys($actor);
        $this->assertTrue($navigation->contains('reports_center'));
        $this->assertFalse($navigation->contains('erp_finance'));
        $this->assertFalse($navigation->contains('erp_procurement'));
        $this->assertFalse($navigation->contains('treasury_master'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', ['channel' => 'b2b'], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', ['channel' => 'b2c', 'store_id' => $retailStoreId], false))
                ->assertForbidden();
        }

        foreach ([
            'reports.view' => true,
            'reports.export' => true,
            'finance.view' => false,
            'finance.manage' => false,
            'finance.approve' => false,
            'finance.post' => false,
            'procurement.purchase_orders.manage' => false,
            'orders.dispatch' => false,
            'drivers.b2b.manage' => false,
            'drivers.b2c.manage' => false,
            'users.roles.manage' => false,
            'security.approve' => false,
        ] as $ability => $expected) {
            $this->assertSame($expected, Gate::forUser($actor->fresh())->allows($ability), $ability);
        }
    }

    public function test_viewer_cannot_change_user_state_and_module_disable_revokes_existing_report_access(): void
    {
        $this->enableBi();
        $actor = $this->viewer();
        $target = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->assertTrue($actor->hasPermission('reports.view'));
        $auditBefore = DB::table('audit_logs')->where('event', 'user.status_changed')->count();

        $this->actingAs($actor)
            ->patch(route('admin.security.users.status', $target, false), [
                'is_active' => false,
                'reason' => 'Unauthorized executive role operation',
            ])
            ->assertForbidden();

        $this->assertTrue((bool) $target->fresh()->is_active);
        $this->assertSame($auditBefore, DB::table('audit_logs')->where('event', 'user.status_changed')->count());

        app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::DISABLED);
        $this->assertFalse($actor->fresh()->hasPermission('reports.view'));
        $this->actingAs($actor->fresh())
            ->get(route('admin.reports.index', ['channel' => 'b2b'], false))
            ->assertForbidden();
        $this->assertFalse($this->navigationKeys($actor->fresh())->contains('reports_center'));
    }

    private function viewer(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'EXECUTIVE_VIEWER')->firstOrFail());

        return $actor->fresh();
    }

    private function enableBi(): void
    {
        $registry = app(ModuleRegistry::class);
        $registry->syncManifest('enterprise_bi');
        if ($registry->state('enterprise_bi') === ModuleState::INSTALLED) {
            app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::DISABLED);
        }
        if ($registry->state('enterprise_bi') === ModuleState::DISABLED) {
            app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::ENABLING);
            app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::ENABLED);
        }
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return Collection<int,string> */
    private function navigationKeys(User $actor): Collection
    {
        return collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group) => $group['children'])
            ->pluck('key');
    }
}
