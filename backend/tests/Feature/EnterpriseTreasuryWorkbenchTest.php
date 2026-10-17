<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Reporting\ReportRegistry;
use App\Enterprise\Treasury\TreasuryReceiptService;
use App\Models\EnterpriseBankAccount;
use App\Models\EnterpriseCashbox;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class EnterpriseTreasuryWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_workbench_fails_closed_while_treasury_module_is_disabled(): void
    {
        [$user, $store] = $this->operator('ERP-TREASURY-OFF', 'treasury-off@example.test');

        $this->actingAs($user)
            ->get(route('admin.erp.treasury.index', ['store_id' => $store->id]))
            ->assertNotFound();
    }

    public function test_authorized_operator_can_open_arabic_workbench_and_create_bounded_receipt_draft(): void
    {
        [$user, $store] = $this->operator('ERP-TREASURY-AR', 'treasury-ar@example.test', 'ar');
        $context = app(TenantContextResolver::class)->retail($user, $store->id);
        $this->enableTreasury($user, $context);
        $cashbox = $this->cashbox($store);

        $this->actingAs($user)
            ->get(route('admin.erp.treasury.index', [
                'store_id' => $store->id,
                'tab' => 'receipts',
            ]))
            ->assertOk()
            ->assertSee('مركز عمل الخزينة')
            ->assertSee($cashbox->code)
            ->assertSee(route('admin.treasury.master.index', ['store_id' => $store->id]));

        $this->actingAs($user)
            ->post(route('admin.erp.treasury.receipts.store'), [
                'store_id' => $store->id,
                '_operation_key' => 'ui-receipt-http-001',
                'target_key' => 'cashbox:'.$cashbox->id,
                'amount' => '15.250',
                'value_date' => '2026-10-16',
                'reference' => 'UI-RCPT-001',
                'description' => 'Treasury workbench proof',
            ])
            ->assertRedirect(route('admin.erp.treasury.index', [
                'tab' => 'receipts',
                'store_id' => $store->id,
            ]));

        $this->assertDatabaseHas('enterprise_treasury_receipts', [
            'store_id' => $store->id,
            'target_type' => 'cashbox',
            'target_id' => $cashbox->id,
            'amount' => '15.250',
            'reference' => 'UI-RCPT-001',
            'status' => 'draft',
        ]);
    }

    public function test_english_workbench_uses_business_readable_bank_labels_and_cross_store_scope_is_denied(): void
    {
        [$user, $store] = $this->operator('ERP-TREASURY-EN', 'treasury-en@example.test', 'en');
        [, $otherStore] = $this->operator('ERP-TREASURY-OTHER', 'treasury-other@example.test', 'en');
        $context = app(TenantContextResolver::class)->retail($user, $store->id);
        $this->enableTreasury($user, $context);
        $bank = $this->bankAccount($store);

        $this->actingAs($user)
            ->get(route('admin.erp.treasury.index', [
                'store_id' => $store->id,
                'tab' => 'cheques',
            ]))
            ->assertOk()
            ->assertSee('Treasury Workbench')
            ->assertSee($bank->code)
            ->assertSee('****1234')
            ->assertDontSee('name="bank_account_id" type="text"', false);

        $this->actingAs($user)
            ->get(route('admin.erp.treasury.index', [
                'store_id' => $otherStore->id,
                'tab' => 'receipts',
            ]))
            ->assertForbidden();
    }

    public function test_full_treasury_workbench_route_surface_is_registered(): void
    {
        foreach ([
            'admin.erp.treasury.index',
            'admin.erp.treasury.receipts.store',
            'admin.erp.treasury.receipts.post',
            'admin.erp.treasury.receipts.cancel',
            'admin.erp.treasury.payments.store',
            'admin.erp.treasury.payments.post',
            'admin.erp.treasury.payments.cancel',
            'admin.erp.treasury.cheques.store',
            'admin.erp.treasury.cheques.transition',
            'admin.erp.treasury.reconciliation.store',
            'admin.erp.treasury.reconciliation.matches.store',
            'admin.erp.treasury.reconciliation.close',
            'admin.erp.treasury.cash-closing.store',
            'admin.erp.treasury.cash-closing.close',
            'admin.erp.treasury.facilities.store',
            'admin.erp.treasury.drawdowns.store',
            'admin.erp.treasury.repayments.paid',
            'admin.erp.treasury.investments.store',
            'admin.erp.treasury.investments.settle',
            'admin.erp.treasury.reports.export',
            'admin.erp.treasury.reports.schedule',
        ] as $name) {
            $this->assertTrue(Route::has($name), 'Missing Treasury UI route: '.$name);
        }
    }

    public function test_complete_treasury_surface_composes_dashboard_financing_forecast_and_shared_reports(): void
    {
        Queue::fake();

        [$user, $store] = $this->operator('ERP-TREASURY-W5', 'treasury-w5@example.test', 'en');
        $context = app(TenantContextResolver::class)->retail($user, $store->id);
        $this->enableTreasury($user, $context);
        $this->cashbox($store);
        $this->bankAccount($store);

        $this->actingAs($user)
            ->get(route('admin.erp.treasury.index', [
                'store_id' => $store->id,
                'tab' => 'overview',
            ]))
            ->assertOk()
            ->assertSee('data-foodex-module-overview', false)
            ->assertSee('data-foodex-module-kpi', false)
            ->assertSee('data-foodex-module-chart', false)
            ->assertSee('Cash on hand')
            ->assertSee('Forecast liquidity gap')
            ->assertSee('No silent FX conversion');

        $this->actingAs($user)
            ->post(route('admin.erp.treasury.facilities.store'), [
                'store_id' => $store->id,
                '_operation_key' => 'facility-http-001',
                'code' => 'FAC-001',
                'lender_name' => 'Treasury Test Bank',
                'currency_code' => 'KWD',
                'facility_limit' => '100.000',
                'annual_rate' => '4.500000',
                'margin_rate' => '1.250000',
                'start_date' => now()->toDateString(),
                'maturity_date' => now()->addYear()->toDateString(),
            ])
            ->assertRedirect(route('admin.erp.treasury.index', [
                'tab' => 'financing',
                'store_id' => $store->id,
            ]));

        $facilityId = (int) DB::table('enterprise_treasury_facilities')
            ->where('store_id', $store->id)
            ->where('code', 'FAC-001')
            ->value('id');
        $this->assertGreaterThan(0, $facilityId);

        $this->actingAs($user)
            ->post(route('admin.erp.treasury.drawdowns.store'), [
                'store_id' => $store->id,
                '_operation_key' => 'drawdown-http-001',
                'facility_id' => $facilityId,
                'reference' => 'DD-001',
                'principal_amount' => '30.000',
                'scheduled_interest_total' => '3.000',
                'drawdown_date' => now()->toDateString(),
                'maturity_date' => now()->addMonths(6)->toDateString(),
                'installment_count' => 3,
            ])
            ->assertRedirect(route('admin.erp.treasury.index', [
                'tab' => 'financing',
                'store_id' => $store->id,
            ]));

        $drawdownId = (int) DB::table('enterprise_treasury_drawdowns')
            ->where('facility_id', $facilityId)
            ->where('reference', 'DD-001')
            ->value('id');

        $this->assertGreaterThan(0, $drawdownId);
        $this->assertSame(
            3,
            DB::table('enterprise_treasury_repayments')->where('drawdown_id', $drawdownId)->count(),
        );
        $this->assertSame(
            '30.000',
            number_format(
                (float) DB::table('enterprise_treasury_repayments')
                    ->where('drawdown_id', $drawdownId)
                    ->sum('principal_amount'),
                3,
                '.',
                '',
            ),
        );
        $this->assertSame(
            '3.000',
            number_format(
                (float) DB::table('enterprise_treasury_repayments')
                    ->where('drawdown_id', $drawdownId)
                    ->sum('interest_amount'),
                3,
                '.',
                '',
            ),
        );

        $this->actingAs($user)
            ->get(route('admin.erp.treasury.index', [
                'store_id' => $store->id,
                'tab' => 'forecast',
            ]))
            ->assertOk()
            ->assertSee('Forward cash-flow forecast')
            ->assertSee('No silent FX conversion');

        $this->actingAs($user)
            ->get(route('admin.erp.treasury.index', [
                'store_id' => $store->id,
                'tab' => 'reports',
            ]))
            ->assertOk()
            ->assertSee('Daily cash position')
            ->assertSee('Loan / facility repayment')
            ->assertSee('Investment maturity / return')
            ->assertSee('Treasury exceptions');

        $report = app(ReportRegistry::class)->require('treasury.daily_position');
        $this->assertSame('treasury', $report->moduleCode);
        $this->assertSame('treasury_native', $report->readModel);

        $this->actingAs($user)
            ->post(route('admin.erp.treasury.reports.export'), [
                'store_id' => $store->id,
                '_operation_key' => 'treasury-report-export-001',
                'report_code' => 'treasury.daily_position',
            ])
            ->assertRedirect(route('admin.erp.treasury.index', [
                'tab' => 'reports',
                'store_id' => $store->id,
            ]));
        $this->assertDatabaseHas('enterprise_report_exports', [
            'report_code' => 'treasury.daily_position',
            'scope_type' => EntitlementScope::STORE,
            'scope_id' => $store->id,
            'status' => 'queued',
        ]);

        $this->actingAs($user)
            ->post(route('admin.erp.treasury.reports.schedule'), [
                'store_id' => $store->id,
                '_operation_key' => 'treasury-report-schedule-001',
                'report_code' => 'treasury.cash_forecast',
                'next_run_at' => now()->addHour()->format('Y-m-d H:i:s'),
                'interval_minutes' => 1440,
            ])
            ->assertRedirect(route('admin.erp.treasury.index', [
                'tab' => 'reports',
                'store_id' => $store->id,
            ]));
        $this->assertDatabaseHas('enterprise_scheduled_reports', [
            'report_code' => 'treasury.cash_forecast',
            'scope_type' => EntitlementScope::STORE,
            'scope_id' => $store->id,
            'enabled' => 1,
        ]);
    }

    /** @return array{User,Store} */
    private function operator(string $storeCode, string $email, string $locale = 'ar'): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $storeCode,
            'name' => $storeCode,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        $role = Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail();
        $user = User::factory()->create([
            'email' => $email,
            'locale' => $locale,
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $store];
    }

    private function enableTreasury(User $actor, ResolvedTenantContext $context): void
    {
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(TreasuryReceiptService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(TreasuryReceiptService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(TreasuryReceiptService::MODULE, ModuleState::ENABLED, $actor);
        }

        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);
        app(ModuleEntitlementManager::class)->set(
            TreasuryReceiptService::MODULE,
            new EntitlementScope(EntitlementScope::STORE, $scope->storeId),
            true,
            [
                'treasury.receipts.read',
                'treasury.receipts.manage',
                'treasury.receipts.approve',
                'treasury.payments.read',
                'treasury.payments.manage',
                'treasury.payments.approve',
                'treasury.reconciliation.read',
                'treasury.reconciliation.manage',
                'treasury.reconciliation.approve',
                'treasury.cash_closing.read',
                'treasury.cash_closing.manage',
                'treasury.cash_closing.approve',
                'treasury.cheques.read',
                'treasury.cheques.manage',
                'treasury.cheques.approve',
            ],
            $actor,
        );
    }

    private function cashbox(Store $store): EnterpriseCashbox
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);

        return EnterpriseCashbox::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => 'UI-CASH-'.$store->id,
            'name' => 'Front Cashbox',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => true,
        ]);
    }

    private function bankAccount(Store $store): EnterpriseBankAccount
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);

        return EnterpriseBankAccount::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => 'UI-BANK-'.$store->id,
            'name' => 'Operating Bank',
            'bank_name' => 'Treasury Bank',
            'account_name' => 'FOODEX Treasury',
            'account_reference' => 'KW00TEST1234',
            'account_reference_last4' => '1234',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseBankAccount::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseBankAccount::VISIBILITY_STORE,
            'is_active' => true,
        ]);
    }
}
