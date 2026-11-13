<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceApprovalService;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Finance\GeneralLedgerService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            app(ModuleRegistry::class)->manifest('finance')->version,
        );
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

    public function test_single_accountant_can_reach_b2b_finance_workbench_only_with_matching_read_entitlement(): void
    {
        $store = $this->wholesaleStore();
        $actor = $this->accountant();
        $url = route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'accounts']);

        $this->actingAs($actor)->get($url)->assertForbidden();
        $this->enableFinance();
        $actor = $actor->fresh();

        // No second FINANCE or SUPER_ADMIN role is required.
        $this->assertContains('ACCOUNTANT', config('admin.channels.b2b.global_roles'));
        $this->assertNotContains('ACCOUNTANT', config('admin.channels.b2c.global_roles'));
        $this->assertTrue(app(AdminNavigation::class)->canAccess($actor, 'b2b'));
        $this->assertFalse(app(AdminNavigation::class)->canAccess($actor, 'b2c'));
        $this->assertTrue($actor->hasPermission('finance.view', $store->id));
        $this->actingAs($actor)->get($url)->assertForbidden();

        $this->entitle($actor, $store, ['finance.foundation.read', 'finance.gl.read']);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $this->actingAs($actor->fresh())
                ->get($url)
                ->assertOk()
                ->assertSee('data-finance-workbench="true"', false)
                ->assertSee('dir="'.$direction.'"', false);
        }

        $this->actingAs($actor->fresh())
            ->post(route('admin.erp.finance.accounts.store'), [
                'store_id' => $store->id,
                'code' => '2695-NO-MANAGE',
                'name' => 'Unentitled account',
                'type' => 'asset',
                'normal_balance' => 'debit',
                'allow_posting' => '1',
            ])->assertForbidden();
        $this->assertDatabaseMissing('enterprise_finance_accounts', ['code' => '2695-NO-MANAGE']);

        $retail = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-2695-RETAIL-FORBIDDEN', 'name' => 'Unrelated Retail', 'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($retail);
        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', ['store_id' => $retail->id, 'tab' => 'accounts']))
            ->assertNotFound();
    }

    public function test_accountant_makes_and_posts_real_journal_but_separate_manager_must_approve(): void
    {
        $store = $this->wholesaleStore();
        $maker = $this->accountant();
        $poster = $this->accountant();
        $checker = User::factory()->create(['is_active' => true]);
        $checker->roles()->attach(Role::query()->where('code', 'ACCOUNTING_MANAGER')->firstOrFail());

        $this->enableFinance();
        $this->entitle($maker, $store, [
            'finance.foundation.read', 'finance.foundation.manage',
            'finance.gl.read', 'finance.gl.manage', 'finance.gl.post',
            'finance.approvals.manage', 'finance.approvals.approve',
        ]);

        $resolver = app(TenantContextResolver::class);
        $makerContext = $resolver->wholesale($maker->fresh(), $store->id);
        $checkerContext = $resolver->wholesale($checker->fresh(), $store->id);
        $posterContext = $resolver->wholesale($poster->fresh(), $store->id);

        $finance = app(FinanceFoundationService::class);
        $cash = $finance->createAccount($maker, $makerContext, [
            'code' => '11002695', 'name' => 'Cash', 'type' => 'asset',
            'normal_balance' => 'debit', 'allow_posting' => true,
        ]);
        $revenue = $finance->createAccount($maker, $makerContext, [
            'code' => '40002695', 'name' => 'Revenue', 'type' => 'revenue',
            'normal_balance' => 'credit', 'allow_posting' => true,
        ]);
        $year = $finance->createFiscalYear($maker, $makerContext, [
            'code' => 'FY2695', 'name' => 'FY 2695',
            'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31',
        ]);
        $finance->createFiscalPeriod($maker, $makerContext, $year->id, [
            'sequence' => 1, 'code' => 'JAN',
            'name' => 'January 2027',
            'starts_on' => '2027-01-01', 'ends_on' => '2027-01-31',
        ]);

        $approvals = app(FinanceApprovalService::class);
        $approvals->defineLimit($checker, $checkerContext, [
            'policy_key' => 'v2-2695-accountant-manager-checker',
            'role_id' => Role::query()->where('code', 'ACCOUNTING_MANAGER')->firstOrFail()->id,
            'action' => FinanceApprovalService::ACTION_GL_POST,
            'max_amount' => '300.000',
            'effective_from' => now()->subMinute(),
        ]);

        $ledger = app(GeneralLedgerService::class);
        $journal = $ledger->createDraft($maker, $makerContext, [
            'idempotency_key' => 'v2-2695-journal',
            'entry_date' => '2027-01-12',
            'description' => 'Accountant maker and independent checker',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => '100.000', 'credit' => '0.000'],
                ['account_id' => $revenue->id, 'debit' => '0.000', 'credit' => '100.000'],
            ],
        ]);

        $approval = $approvals->requestApproval(
            $maker, $makerContext, FinanceApprovalService::ACTION_GL_POST,
            'enterprise_finance_journal', $journal->id, '100.000',
        );

        $this->assertFalse($maker->fresh()->hasPermission('finance.approve'));
        try {
            $approvals->approve($maker, $makerContext, $approval->id);
            $this->fail('An Accountant must not approve their own journal.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $approved = $approvals->approve($checker, $checkerContext, $approval->id);
        $this->assertSame('APPROVED', $approved->status->value);

        foreach ([
            [$maker, $makerContext],
            [$checker, $checkerContext],
        ] as [$actor, $context]) {
            try {
                $ledger->post($actor, $context, $journal->id);
                $this->fail('Maker and checker cannot post a journal they handled.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('poster', $exception->errors());
            }
        }

        $posted = $ledger->post($poster, $posterContext, $journal->id);
        $this->assertSame('posted', $posted->status);
        $this->assertSame($poster->id, $posted->posted_by);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 2);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.journal_posted',
            'auditable_id' => $journal->id,
        ]);
    }

    private function wholesaleStore(): Store
    {
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    /** @param list<string> $capabilities */
    private function entitle(User $actor, Store $store, array $capabilities): void
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);
        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope['legal_entity_id']),
            true,
            $capabilities,
            $actor,
        );
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
