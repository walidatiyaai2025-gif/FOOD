<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #2695 / T122R15: accountant permissions must remain bounded by Finance
 * lifecycle and server-side deny decisions, never by Sidebar visibility alone.
 */
final class V2AccountantRoleAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_accountant_catalog_is_bilingual_and_excludes_approval_treasury_and_admin_grants(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $definition = $catalog->definition('ACCOUNTANT');

        $this->assertIsArray($definition);
        $this->assertSame('finance', $definition['domain']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame(['finance'], $definition['required_modules']);
        $this->assertEqualsCanonicalizing(
            ['finance.view', 'finance.manage', 'finance.post', 'reports.view'],
            $definition['permissions'],
        );

        $role = Role::query()->where('code', 'ACCOUNTANT')->firstOrFail();
        $this->assertTrue((bool) $role->is_system);
        $this->assertEqualsCanonicalizing(
            $definition['permissions'],
            $role->permissions()->pluck('code')->all(),
        );

        foreach (['ar' => 'محاسب', 'en' => 'Accountant'] as $locale => $name) {
            app()->setLocale($locale);
            $presentation = $catalog->presentation('ACCOUNTANT');

            $this->assertSame($name, $presentation['name']);
            $this->assertNotEmpty($presentation['description']);
            $this->assertNotEmpty($presentation['access_boundaries']);
        }

        foreach (['finance.approve', 'reports.export', 'security.view', 'roles.manage', 'system.update'] as $forbidden) {
            $this->assertNotContains($forbidden, $definition['permissions']);
        }
    }

    public function test_accountant_loses_permissions_while_finance_is_disabled_without_losing_audit_assignment(): void
    {
        $registry = app(ModuleRegistry::class);
        $registry->syncManifest('finance');
        $catalog = app(BuiltInJobRoleCatalog::class);
        $actor = $this->accountant();
        $role = Role::query()->where('code', 'ACCOUNTANT')->firstOrFail();

        $this->assertFalse($catalog->isAvailable('ACCOUNTANT'));
        $this->assertFalse($actor->hasPermission('finance.view'));
        $this->assertFalse($actor->hasPermission('finance.post'));

        $this->enableFinance();

        $this->assertTrue($catalog->isAvailable('ACCOUNTANT'));
        $this->assertTrue($actor->fresh()->hasPermission('finance.view'));
        $this->assertTrue($actor->fresh()->hasPermission('finance.manage'));
        $this->assertTrue($actor->fresh()->hasPermission('finance.post'));
        $this->assertTrue($actor->fresh()->hasPermission('reports.view'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.approve'));
        $this->assertFalse($actor->fresh()->hasPermission('reports.export'));

        $this->actingAs($actor->fresh())
            ->get(route('admin.security.index', ['section' => 'roles'], false))
            ->assertForbidden();

        app(ModuleLifecycleManager::class)->transition('finance', ModuleState::DISABLED);

        $this->assertFalse($catalog->isAvailable('ACCOUNTANT'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.view'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.post'));
        $this->assertDatabaseHas('role_user', [
            'user_id' => $actor->id,
            'role_id' => $role->id,
        ]);

        $this->enableFinance();

        $this->assertTrue($actor->fresh()->hasPermission('finance.post'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.approve'));
        $this->assertDatabaseHas('role_user', [
            'user_id' => $actor->id,
            'role_id' => $role->id,
        ]);
    }

    public function test_explicit_post_deny_overrides_accountant_role_without_revoking_read_access(): void
    {
        $this->enableFinance();
        $actor = $this->accountant();

        $this->assertTrue($actor->hasPermission('finance.post'));

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'finance.post')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($actor->fresh()->hasPermission('finance.post'));
        $this->assertTrue($actor->fresh()->hasPermission('finance.view'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.approve'));
        $this->assertDatabaseHas('role_user', [
            'user_id' => $actor->id,
            'role_id' => Role::query()->where('code', 'ACCOUNTANT')->firstOrFail()->id,
        ]);
    }

    public function test_inactive_accountant_role_revokes_finance_grants_but_keeps_auditable_assignment(): void
    {
        $this->enableFinance();
        $actor = $this->accountant();
        $role = Role::query()->where('code', 'ACCOUNTANT')->firstOrFail();

        $this->assertTrue($actor->fresh()->hasPermission('finance.post'));

        $role->forceFill(['is_active' => false])->save();

        $this->assertFalse($actor->fresh()->hasPermission('finance.view'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.post'));
        $this->assertDatabaseHas('role_user', [
            'user_id' => $actor->id,
            'role_id' => $role->id,
        ]);

        $role->forceFill(['is_active' => true])->save();

        $this->assertTrue($actor->fresh()->hasPermission('finance.view'));
        $this->assertTrue($actor->fresh()->hasPermission('finance.post'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.approve'));
    }

    public function test_global_accountant_grant_does_not_cross_into_retail_even_with_unrelated_store_role(): void
    {
        $this->enableFinance();
        $actor = $this->accountant();
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-ACCOUNTANT-RETAIL',
            'name' => 'Accountant retail scope boundary',
            'is_active' => true,
        ]);
        $retailOperations = Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $retailOperations->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($actor->fresh()->hasPermission('finance.view'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.view', $store->id));
        $this->assertFalse($actor->fresh()->hasPermission('finance.post', $store->id));

        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id]))
            ->assertForbidden();
    }

    private function accountant(): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'ACCOUNTANT')->firstOrFail());

        return $actor->fresh();
    }

    private function enableFinance(): void
    {
        $registry = app(ModuleRegistry::class);
        if ($registry->registration('finance') === null) {
            $registry->syncManifest('finance');
        }

        if (! $registry->isEnabled('finance')) {
            app(ModuleLifecycleManager::class)->transition('finance', ModuleState::ENABLING);
            app(ModuleLifecycleManager::class)->transition('finance', ModuleState::ENABLED);
        }
    }
}
