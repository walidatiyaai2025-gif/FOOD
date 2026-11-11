<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 #2704 / T122R24: BUSINESS_ANALYST is an entitled, read-only
 * reporting role, never an administrative or transaction-mutating grant.
 */
final class V2BusinessAnalystReportingAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_business_analyst_is_canonical_bilingual_and_has_only_two_reporting_permissions(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $definition = $catalog->definition('BUSINESS_ANALYST');

        $this->assertIsArray($definition);
        $this->assertSame('analytics', $definition['domain']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame(['enterprise_bi'], $definition['required_modules']);
        $this->assertSame(['reports.view', 'reports.export'], $definition['permissions']);

        $role = Role::query()->where('code', 'BUSINESS_ANALYST')->firstOrFail();
        $this->assertTrue((bool) $role->is_system);
        $this->assertEqualsCanonicalizing(
            ['reports.view', 'reports.export'],
            $role->permissions()->pluck('code')->all(),
        );

        foreach (['ar' => 'محلل الأعمال والتقارير', 'en' => 'Business / Reporting Analyst'] as $locale => $name) {
            app()->setLocale($locale);
            $presentation = $catalog->presentation('BUSINESS_ANALYST');

            $this->assertSame($name, $presentation['name']);
            $this->assertNotEmpty($presentation['description']);
            $this->assertNotEmpty($presentation['access_boundaries']);
        }
    }

    public function test_business_analyst_can_open_and_export_reports_only_while_bi_module_is_enabled(): void
    {
        app(ModuleRegistry::class)->syncManifest('enterprise_bi');
        $actor = $this->analyst();
        $role = Role::query()->where('code', 'BUSINESS_ANALYST')->firstOrFail();

        $this->assertFalse(app(BuiltInJobRoleCatalog::class)->isAvailable('BUSINESS_ANALYST'));
        $this->assertFalse($actor->hasPermission('reports.view'));
        $this->assertFalse($actor->hasPermission('reports.export'));

        $this->actingAs($actor)->get(route('admin.reports.index', [], false))->assertForbidden();
        $this->actingAs($actor)->get($this->exportUrl())->assertForbidden();

        $this->enableBi();

        $this->assertTrue(app(BuiltInJobRoleCatalog::class)->isAvailable('BUSINESS_ANALYST'));
        $this->assertTrue($actor->fresh()->hasPermission('reports.view'));
        $this->assertTrue($actor->fresh()->hasPermission('reports.export'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            app()->forgetInstance(AdminNavigation::class);

            $items = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->keyBy('key');

            $this->assertTrue($items->has('reports_center'), $locale.' must discover the authorized reports route');
            $this->assertTrue(Route::has($items['reports_center']['route']));
            $this->assertSame('admin.reports.index', $items['reports_center']['route']);
            $this->assertFalse($items->has('sidebar_role_access'));
            $this->assertFalse($items->has('treasury_master'));
            $this->assertFalse($items->has('order_operations'));

            $response = $this->actingAs($actor->fresh())->get(route('admin.reports.index', [], false));
            $response->assertOk()->assertSee('dir="'.$direction.'"', false);
        }

        $this->actingAs($actor->fresh())
            ->get($this->exportUrl())
            ->assertOk()
            ->assertHeader('Cache-Control', 'private, no-store');

        app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::DISABLED);

        $this->assertFalse($actor->fresh()->hasPermission('reports.view'));
        $this->assertFalse($actor->fresh()->hasPermission('reports.export'));
        $this->assertDatabaseHas('role_user', [
            'user_id' => $actor->id,
            'role_id' => $role->id,
        ]);
        app()->forgetInstance(AdminNavigation::class);
        $keys = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key');
        $this->assertNotContains('reports_center', $keys->all());
        $this->actingAs($actor->fresh())->get(route('admin.reports.index', [], false))->assertForbidden();
        $this->actingAs($actor->fresh())->get($this->exportUrl())->assertForbidden();
    }

    public function test_mutation_and_admin_routes_are_denied_and_explicit_report_denies_override_the_role(): void
    {
        $this->enableBi();
        $actor = $this->analyst();

        foreach ([
            route('admin.operations.orders.index', ['channel' => 'b2b'], false),
            route('admin.security.index', ['section' => 'users'], false),
            route('admin.erp.index', [], false),
        ] as $url) {
            $this->actingAs($actor)->get($url)->assertForbidden();
        }

        foreach (['reports.view', 'reports.export'] as $code) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->id,
                'permission_id' => Permission::query()->where('code', $code)->firstOrFail()->id,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($actor->fresh()->hasPermission('reports.view'));
        $this->assertFalse($actor->fresh()->hasPermission('reports.export'));
        app()->forgetInstance(AdminNavigation::class);
        $keys = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key');
        $this->assertNotContains('reports_center', $keys->all());
        $this->actingAs($actor->fresh())->get(route('admin.reports.index', [], false))->assertForbidden();
        $this->actingAs($actor->fresh())->get($this->exportUrl())->assertForbidden();

        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->analyst();
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive->fresh()] as $denied) {
            $this->assertSame([], app(AdminNavigation::class)->groupsFor($denied));
            $this->actingAs($denied)->get(route('admin.reports.index', [], false))->assertForbidden();
            $this->actingAs($denied)->get($this->exportUrl())->assertForbidden();
        }
    }

    private function analyst(): User
    {
        $user = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'BUSINESS_ANALYST')->firstOrFail());

        return $user->fresh();
    }

    private function exportUrl(): string
    {
        return route('admin.reports.export', ['report' => 'orders', 'format' => 'xlsx'], false);
    }

    private function enableBi(): void
    {
        $registry = app(ModuleRegistry::class);
        if ($registry->registration('enterprise_bi') === null) {
            $registry->syncManifest('enterprise_bi');
        }

        $state = $registry->state('enterprise_bi');
        if ($state === ModuleState::INSTALLED) {
            app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::DISABLED);
            $state = ModuleState::DISABLED;
        }

        if ($state === ModuleState::DISABLED) {
            app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::ENABLING);
            app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::ENABLED);
        }
    }
}
