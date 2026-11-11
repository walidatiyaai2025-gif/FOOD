<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseBusinessUnit;
use App\Models\EnterpriseCompany;
use App\Models\EnterpriseLegalEntity;
use App\Models\EnterpriseOrganization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 #2611 / P281: the existing Finance Workbench Journals tab must obey the
 * canonical General Ledger authority and preserve immutable posting boundaries.
 */
final class V2FinanceJournalsRouteAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            app(ModuleRegistry::class)->manifest(FinanceFoundationService::MODULE)->version,
        );
    }

    public function test_journal_tab_is_localized_and_viewer_cannot_create_or_post(): void
    {
        [$actor, $store, $context] = $this->operator(
            ['finance.view'],
            'V2-2611-READER',
        );
        $this->enableFinance($actor, $context);

        foreach ([
            'admin.erp.finance.index',
            'admin.erp.finance.journals.store',
            'admin.erp.finance.journals.approval',
            'admin.erp.finance.journals.post',
        ] as $route) {
            $this->assertTrue(Route::has($route), "Missing canonical journal route {$route}");
        }

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.finance.index', [
                    'store_id' => $store->id,
                    'tab' => 'journals',
                ]))
                ->assertOk()
                ->assertSee('data-finance-workbench="true"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('finance_workbench.journals.title'))
                ->assertDontSee('name="idempotency_key"', false);

            $this->actingAs($actor->fresh())
                ->post(route('admin.erp.finance.journals.store'), [
                    'store_id' => $store->id,
                    'idempotency_key' => 'v2-2611-forbidden',
                    'entry_date' => '2035-01-15',
                    'description' => 'Must not write',
                    'lines' => [],
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseMissing('enterprise_finance_journals', [
            'idempotency_key' => 'v2-2611-forbidden',
        ]);
    }

    public function test_authorized_journal_post_form_creates_one_audited_draft_and_cannot_self_post(): void
    {
        [$maker, $store, $context] = $this->operator(
            ['finance.view', 'finance.manage'],
            'V2-2611-MAKER',
        );
        $this->enableFinance($maker, $context, true);
        [$cash, $revenue] = $this->postingAccountsAndOpenPeriod($maker, $context);

        $payload = [
            'store_id' => $store->id,
            'idempotency_key' => 'v2-2611-once',
            'entry_date' => '2035-01-15',
            'description' => 'V2 approved draft authority',
            'lines' => [
                ['account_id' => $cash, 'debit' => '18.500', 'credit' => '0', 'memo' => 'Debit'],
                ['account_id' => $revenue, 'debit' => '0', 'credit' => '18.500', 'memo' => 'Credit'],
            ],
        ];

        $this->actingAs($maker)
            ->post(route('admin.erp.finance.journals.store'), $payload)
            ->assertRedirect();

        $draft = DB::table('enterprise_finance_journals')
            ->where('idempotency_key', 'v2-2611-once')
            ->first();
        $this->assertNotNull($draft);
        $this->assertSame('draft', $draft->status);
        $this->assertSame($maker->id, (int) $draft->requested_by);
        $this->assertDatabaseCount('enterprise_finance_journal_lines', 2);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.finance.journal_created',
            'auditable_id' => $draft->id,
        ]);

        $this->actingAs($maker)
            ->post(route('admin.erp.finance.journals.store'), $payload)
            ->assertRedirect();

        $this->assertDatabaseCount('enterprise_finance_journals', 1);
        $this->actingAs($maker)
            ->get(route('admin.erp.finance.index', [
                'store_id' => $store->id,
                'tab' => 'journals',
                'journal' => $draft->id,
            ]))
            ->assertOk()
            ->assertSee($draft->journal_number)
            ->assertSee(__('finance_workbench.journals.source_trace'));

        // A maker without the independent finance.post action must not post.
        $this->actingAs($maker)
            ->post(route('admin.erp.finance.journals.post', [
                'journal' => $draft->id,
            ]), ['store_id' => $store->id])
            ->assertForbidden();

        $this->assertDatabaseHas('enterprise_finance_journals', [
            'id' => $draft->id,
            'status' => 'draft',
        ]);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 0);
    }

    public function test_second_store_and_foreign_legal_entity_cannot_see_or_mutate_journal(): void
    {
        [$maker, $store, $context] = $this->operator(
            ['finance.view', 'finance.manage'],
            'V2-2611-OWNER',
        );
        $this->enableFinance($maker, $context, true);
        [$cash, $revenue] = $this->postingAccountsAndOpenPeriod($maker, $context);

        $this->actingAs($maker)->post(route('admin.erp.finance.journals.store'), [
            'store_id' => $store->id,
            'idempotency_key' => 'v2-2611-owner',
            'entry_date' => '2035-01-15',
            'description' => 'Private ledger',
            'lines' => [
                ['account_id' => $cash, 'debit' => '7.500', 'credit' => '0'],
                ['account_id' => $revenue, 'debit' => '0', 'credit' => '7.500'],
            ],
        ])->assertRedirect();

        $journal = DB::table('enterprise_finance_journals')
            ->where('idempotency_key', 'v2-2611-owner')
            ->first();
        $this->assertNotNull($journal);

        [$outsider, $otherStore, $otherContext] = $this->operator(
            ['finance.view', 'finance.manage'],
            'V2-2611-OTHER',
            true,
        );
        $this->enableFinance($outsider, $otherContext, true);

        $this->actingAs($outsider)
            ->get(route('admin.erp.finance.index', [
                'store_id' => $otherStore->id,
                'tab' => 'journals',
            ]))
            ->assertOk()
            ->assertDontSee($journal->journal_number);

        $this->actingAs($outsider)
            ->get(route('admin.erp.finance.index', [
                'store_id' => $otherStore->id,
                'tab' => 'journals',
                'journal' => $journal->id,
            ]))
            ->assertNotFound();

        $this->actingAs($outsider)
            ->post(route('admin.erp.finance.journals.approval', [
                'journal' => $journal->id,
            ]), ['store_id' => $otherStore->id])
            ->assertNotFound();

        $this->actingAs($maker)
            ->get(route('admin.erp.finance.index', [
                'store_id' => $otherStore->id,
                'tab' => 'journals',
            ]))
            ->assertNotFound();

        $this->assertDatabaseHas('enterprise_finance_journals', [
            'id' => $journal->id,
            'status' => 'draft',
        ]);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 0);
    }

    public function test_store_specific_deny_overrides_two_assigned_roles_and_read_survives(): void
    {
        [$actor, $store, $context] = $this->operator(
            ['finance.view'],
            'V2-2611-DENY',
        );
        $this->enableFinance($actor, $context, true);

        // A second active store role legitimately grants manage.
        $managerRole = $this->roleWithPermissions(
            ['finance.manage'],
            'V2-2611-SECOND',
        );
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $managerRole->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertTrue($actor->fresh()->hasPermission('finance.manage', $store->id));

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'finance.manage')->firstOrFail()->id,
            'scope_key' => 'store:'.$store->id,
            'store_id' => $store->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $this->assertFalse($actor->fresh()->hasPermission('finance.manage', $store->id));

        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', [
                'store_id' => $store->id,
                'tab' => 'journals',
            ]))
            ->assertOk();

        $this->actingAs($actor->fresh())
            ->post(route('admin.erp.finance.journals.store'), [
                'store_id' => $store->id,
                'idempotency_key' => 'v2-2611-override-denied',
                'entry_date' => '2035-01-15',
                'lines' => [],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('enterprise_finance_journals', [
            'idempotency_key' => 'v2-2611-override-denied',
        ]);
    }

    public function test_disabled_finance_module_blocks_journal_read_and_write(): void
    {
        [$actor, $store] = $this->operator(
            ['finance.view', 'finance.manage'],
            'V2-2611-DISABLED',
        );

        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', [
                'store_id' => $store->id,
                'tab' => 'journals',
            ]))
            ->assertForbidden();

        $this->actingAs($actor)
            ->post(route('admin.erp.finance.journals.store'), [
                'store_id' => $store->id,
                'idempotency_key' => 'v2-2611-module-off',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('enterprise_finance_journals', [
            'idempotency_key' => 'v2-2611-module-off',
        ]);
    }

    /** @return array{User, Store, ResolvedTenantContext} */
    private function operator(
        array $permissions,
        string $storeCode,
        bool $independentOrganization = false,
    ): array {
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $storeCode,
            'name' => $storeCode,
            'is_active' => true,
        ]);

        if ($independentOrganization) {
            $organization = EnterpriseOrganization::query()->create([
                'code' => 'v2_2611_org_'.$store->id,
                'name' => 'V2 Journal Test Organization',
                'is_active' => true,
            ]);
            $company = EnterpriseCompany::query()->create([
                'organization_id' => $organization->id,
                'code' => 'v2_2611_co_'.$store->id,
                'name' => 'V2 Journal Test Company',
                'is_active' => true,
            ]);
            $legal = EnterpriseLegalEntity::query()->create([
                'company_id' => $company->id,
                'code' => 'v2_2611_legal_'.$store->id,
                'name' => 'V2 Journal Test Legal Entity',
                'is_active' => true,
            ]);
            $unit = EnterpriseBusinessUnit::query()->create([
                'legal_entity_id' => $legal->id,
                'code' => 'v2_2611_unit_'.$store->id,
                'name' => 'V2 Journal Test Unit',
                'is_active' => true,
            ]);
            app(OrganizationHierarchyService::class)->assignStore($store, $unit);
        } else {
            app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        }

        $actor = User::factory()->create([
            'email' => strtolower($storeCode).'@example.test',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $this->grantPlatformErpAccess($actor);
        $role = $this->roleWithPermissions($permissions, $storeCode);

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$actor, $store, app(TenantContextResolver::class)->retail($actor, $store->id)];
    }

    /** @param list<string> $permissions */
    private function roleWithPermissions(array $permissions, string $code): Role
    {
        $role = Role::query()->create([
            'code' => 'V2_2611_'.str_replace('-', '_', $code),
            'name' => 'V2 Journal '.$code,
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        foreach ($permissions as $permissionCode) {
            $permission = Permission::query()->firstOrCreate(
                ['code' => $permissionCode],
                ['name' => $permissionCode],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        return $role;
    }

    private function enableFinance(
        User $actor,
        ResolvedTenantContext $context,
        bool $mayWrite = false,
    ): void {
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);

        if (! app(ModuleRegistry::class)->isEnabled(FinanceFoundationService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLED, $actor);
        }

        $capabilities = ['finance.foundation.read', 'finance.gl.read'];
        if ($mayWrite) {
            $capabilities[] = 'finance.foundation.manage';
            $capabilities[] = 'finance.gl.manage';
        }

        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
            true,
            $capabilities,
            $actor,
        );
    }

    /** @return array{int, int} */
    private function postingAccountsAndOpenPeriod(
        User $actor,
        ResolvedTenantContext $context,
    ): array {
        $finance = app(FinanceFoundationService::class);
        $cash = $finance->createAccount($actor, $context, [
            'code' => '2611-CASH',
            'name' => 'V2 Cash',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'allow_posting' => true,
        ]);
        $revenue = $finance->createAccount($actor, $context, [
            'code' => '2611-REV',
            'name' => 'V2 Revenue',
            'type' => 'revenue',
            'normal_balance' => 'credit',
            'allow_posting' => true,
        ]);
        $year = $finance->createFiscalYear($actor, $context, [
            'code' => 'FY2611',
            'name' => 'V2 Journal Year',
            'starts_on' => '2035-01-01',
            'ends_on' => '2035-12-31',
        ]);
        $finance->createFiscalPeriod($actor, $context, $year->id, [
            'sequence' => 1,
            'code' => 'P01',
            'name' => 'V2 January',
            'starts_on' => '2035-01-01',
            'ends_on' => '2035-01-31',
        ]);

        return [(int) $cash->id, (int) $revenue->id];
    }
}
