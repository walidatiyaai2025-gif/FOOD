<?php

namespace Database\Seeders;

use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Procurement\SupplierDirectory;
use App\Models\EnterpriseBankAccount;
use App\Models\EnterpriseCashbox;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContextResolver;
use App\Services\PlatformCustomerService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class ScreenshotEvidenceSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('ScreenshotEvidenceSeeder is forbidden in production.');
        }

        $this->call(DashboardDemoSeeder::class);

        $now = now();

        DB::table('whats_app_module_states')
            ->where('id', 1)
            ->update([
                'lifecycle' => 'INTERNAL_TEST',
                'emergency_stop' => true,
                'capabilities' => json_encode(['agent_inbox' => true], JSON_THROW_ON_ERROR),
                'test_numbers' => '[]',
                'revision' => 1,
                'updated_at' => $now,
            ]);

        $superAdminId = (int) DB::table('users')->insertGetId([
            'name' => 'FOODEX Screenshot Admin',
            'email' => 'screenshots@foodex.test',
            'email_verified_at' => $now,
            'password' => Hash::make('Evidence123!'),
            'locale' => 'ar',
            'is_active' => true,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $superRoleId = (int) DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id');
        DB::table('role_user')->insert(['role_id' => $superRoleId, 'user_id' => $superAdminId]);

        $englishAdminId = (int) DB::table('users')->insertGetId([
            'name' => 'FOODEX Screenshot Admin EN',
            'email' => 'screenshots.en@foodex.test',
            'email_verified_at' => $now,
            'password' => Hash::make('Evidence123!'),
            'locale' => 'en',
            'is_active' => true,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('role_user')->insert(['role_id' => $superRoleId, 'user_id' => $englishAdminId]);

        DB::table('notifications')->insert([
            'user_id' => null,
            'created_by' => $superAdminId,
            'channel' => 'both',
            'type' => 'evidence',
            'audience' => 'van',
            'app' => 'van',
            'target_channel' => 'all',
            'status' => 'draft',
            'title' => 'إشعار فان تجريبي',
            'title_ar' => 'إشعار فان تجريبي',
            'title_en' => 'Van evidence notification',
            'body' => 'إشعار مخصص لإثبات واجهة الإدارة.',
            'body_ar' => 'إشعار مخصص لإثبات واجهة الإدارة.',
            'body_en' => 'Dashboard runtime evidence notification for Van.',
            'data' => json_encode(['evidence' => true], JSON_THROW_ON_ERROR),
            'published_at' => null,
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('notification_campaigns')->insert([
            'name' => 'Van Runtime Evidence Campaign',
            'type' => 'promotion',
            'title_ar' => 'حملة فان تجريبية',
            'title_en' => 'Van runtime evidence campaign',
            'body_ar' => 'حملة مخصصة لإثبات إجراءات السجل في لوحة الإدارة.',
            'body_en' => 'Campaign used to prove Dashboard record actions for Van.',
            'audience' => 'van',
            'app' => 'van',
            'target_channel' => 'all',
            'delivery_channel' => 'both',
            'popup_frequency' => 'once_per_session',
            'popup_cta_label_ar' => 'فتح',
            'popup_cta_label_en' => 'Open',
            'popup_cta_target' => '/orders',
            'user_id' => null,
            'store_id' => null,
            'created_by' => $superAdminId,
            'schedule_kind' => 'once',
            'timezone' => 'Asia/Kuwait',
            'starts_at' => $now->addHour(),
            'interval_value' => null,
            'interval_unit' => null,
            'ends_at' => null,
            'max_runs' => 1,
            'run_count' => 0,
            'next_run_at' => $now->addHour(),
            'last_run_at' => null,
            'last_notification_id' => null,
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $b2bType = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2bType,
            'code' => 'FOODEX-EVIDENCE-B2B',
            'name' => 'FOODEX Wholesale Evidence Branch',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'FOODEX-EVIDENCE-B2B-WH',
            'name' => 'FOODEX Wholesale Warehouse',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $evidenceStore = Store::query()->findOrFail($storeId);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($evidenceStore);
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('procurement', ModuleState::ENABLING);
        $lifecycle->transition('procurement', ModuleState::ENABLED);

        $wmsRegistry = app(ModuleRegistry::class);
        $wmsRegistry->syncManifest('inventory_wms');
        if ($wmsRegistry->state('inventory_wms') !== ModuleState::ENABLED) {
            if ($wmsRegistry->state('inventory_wms') !== ModuleState::DISABLED) {
                $lifecycle->transition('inventory_wms', ModuleState::DISABLED);
            }

            $lifecycle->transition('inventory_wms', ModuleState::ENABLING);
            $lifecycle->transition('inventory_wms', ModuleState::ENABLED);
        }
        app(ModuleEntitlementManager::class)->set(
            'inventory_wms',
            new EntitlementScope(EntitlementScope::STORE, $storeId),
            true,
        );
        $procurementActor = User::query()->findOrFail($superAdminId);
        $procurementContext = app(TenantContextResolver::class)->wholesale($procurementActor, $storeId);
        $financeScope = $procurementContext->enterpriseScope;
        if ($financeScope === null) {
            throw new RuntimeException('Finance screenshot fixture requires an Enterprise legal-entity scope.');
        }

        $financeRegistry = app(ModuleRegistry::class);
        $financeRegistry->syncManifest(FinanceFoundationService::MODULE);
        if ($financeRegistry->state(FinanceFoundationService::MODULE) !== ModuleState::ENABLED) {
            if ($financeRegistry->state(FinanceFoundationService::MODULE) !== ModuleState::DISABLED) {
                $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::DISABLED, $procurementActor);
            }

            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLING, $procurementActor);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLED, $procurementActor);
        }
        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $financeScope->legalEntityId),
            true,
            [
                'finance.foundation.read',
                'finance.gl.read',
                'finance.opening_balances.read',
                'finance.ar.read',
                'finance.ap.read',
                'finance.credit_terms.read',
            ],
            $procurementActor,
        );

        app(SupplierDirectory::class)->create($procurementActor, $procurementContext, [
            'scope_type' => 'store',
            'code' => 'EVID-SUP-01',
            'name_en' => 'FOODEX Evidence Supplier',
            'name_ar' => 'مورد فودكس التجريبي',
            'contact_name' => 'Evidence Procurement',
            'email' => 'supplier.evidence@foodex.test',
            'payment_terms_days' => 30,
        ]);

        $treasuryActor = User::query()->findOrFail($superAdminId);
        $treasuryContext = app(TenantContextResolver::class)->wholesale($treasuryActor, $storeId);
        $treasuryScope = $treasuryContext->enterpriseScope;
        if ($treasuryScope === null) {
            throw new RuntimeException('Treasury screenshot fixture requires an Enterprise store scope.');
        }

        $treasuryLifecycle = app(ModuleLifecycleManager::class);
        $treasuryLifecycle->transition('treasury', ModuleState::ENABLING, $treasuryActor);
        $treasuryLifecycle->transition('treasury', ModuleState::ENABLED, $treasuryActor);
        app(ModuleEntitlementManager::class)->set(
            'treasury',
            new EntitlementScope(EntitlementScope::STORE, $treasuryScope->storeId),
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
            $treasuryActor,
        );

        $hierarchy = app(OrganizationHierarchyService::class)->scopeForStore($storeId);
        if ($hierarchy === null) {
            throw new RuntimeException('Treasury screenshot fixture could not resolve company scope.');
        }

        $treasuryEvidenceCashbox = EnterpriseCashbox::query()->create([
            'company_id' => $hierarchy['company_id'],
            'store_id' => $storeId,
            'code' => 'EVID-CASH-01',
            'name' => 'Main Evidence Cashbox',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => true,
        ]);
        $treasuryEvidenceBank = EnterpriseBankAccount::query()->create([
            'company_id' => $hierarchy['company_id'],
            'store_id' => $storeId,
            'code' => 'EVID-BANK-01',
            'name' => 'Evidence Operating Bank',
            'bank_name' => 'FOODEX Evidence Bank',
            'account_name' => 'FOODEX Treasury Evidence',
            'account_reference' => 'KW00EVIDENCE1234',
            'account_reference_last4' => '1234',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseBankAccount::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseBankAccount::VISIBILITY_STORE,
            'is_active' => true,
        ]);


        foreach ([
            [
                'receipt_number' => 'EVID-RCPT-CASH',
                'idempotency_key' => 'evidence-treasury-receipt-cash',
                'target_type' => 'cashbox',
                'target_id' => $treasuryEvidenceCashbox->id,
                'amount' => '1850.000',
                'reference' => 'EVID-CASH-SALES',
                'description' => 'Evidence cash collections',
                'value_date' => $now->toDateString(),
            ],
            [
                'receipt_number' => 'EVID-RCPT-BANK',
                'idempotency_key' => 'evidence-treasury-receipt-bank',
                'target_type' => 'bank_account',
                'target_id' => $treasuryEvidenceBank->id,
                'amount' => '6200.000',
                'reference' => 'EVID-BANK-COLLECTION',
                'description' => 'Evidence bank collection',
                'value_date' => $now->toDateString(),
            ],
            [
                'receipt_number' => 'EVID-RCPT-FUTURE',
                'idempotency_key' => 'evidence-treasury-receipt-future',
                'target_type' => 'bank_account',
                'target_id' => $treasuryEvidenceBank->id,
                'amount' => '2400.000',
                'reference' => 'EVID-FUTURE-INFLOW',
                'description' => 'Evidence forward committed receipt',
                'value_date' => $now->copy()->addDays(7)->toDateString(),
            ],
        ] as $receiptFixture) {
            DB::table('enterprise_treasury_receipts')->insert([
                'organization_id' => $treasuryScope->organizationId,
                'company_id' => $treasuryScope->companyId,
                'store_id' => $storeId,
                ...$receiptFixture,
                'request_hash' => hash('sha256', $receiptFixture['idempotency_key']),
                'currency_code' => 'KWD',
                'status' => 'posted',
                'requested_by' => $treasuryActor->id,
                'posted_at' => $now,
                'posted_by' => $treasuryActor->id,
                'cancelled_at' => null,
                'cancelled_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ([
            [
                'payment_number' => 'EVID-PAY-BANK',
                'idempotency_key' => 'evidence-treasury-payment-bank',
                'target_type' => 'bank_account',
                'target_id' => $treasuryEvidenceBank->id,
                'amount' => '1725.000',
                'reference' => 'EVID-SUPPLIER-PAY',
                'beneficiary' => 'Evidence Supplier',
                'description' => 'Evidence supplier settlement',
                'value_date' => $now->toDateString(),
            ],
            [
                'payment_number' => 'EVID-PAY-FUTURE',
                'idempotency_key' => 'evidence-treasury-payment-future',
                'target_type' => 'bank_account',
                'target_id' => $treasuryEvidenceBank->id,
                'amount' => '1300.000',
                'reference' => 'EVID-FUTURE-OUTFLOW',
                'beneficiary' => 'Evidence Landlord',
                'description' => 'Evidence scheduled forward payment',
                'value_date' => $now->copy()->addDays(14)->toDateString(),
            ],
        ] as $paymentFixture) {
            DB::table('enterprise_treasury_payments')->insert([
                'organization_id' => $treasuryScope->organizationId,
                'company_id' => $treasuryScope->companyId,
                'store_id' => $storeId,
                ...$paymentFixture,
                'request_hash' => hash('sha256', $paymentFixture['idempotency_key']),
                'currency_code' => 'KWD',
                'status' => 'posted',
                'requested_by' => $treasuryActor->id,
                'workflow_request_id' => null,
                'posted_at' => $now,
                'posted_by' => $treasuryActor->id,
                'cancelled_at' => null,
                'cancelled_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $evidenceFacilityId = (int) DB::table('enterprise_treasury_facilities')->insertGetId([
            'organization_id' => $treasuryScope->organizationId,
            'company_id' => $treasuryScope->companyId,
            'legal_entity_id' => $treasuryScope->legalEntityId,
            'store_id' => $storeId,
            'code' => 'EVID-FAC-01',
            'lender_name' => 'FOODEX Evidence Bank',
            'currency_code' => 'KWD',
            'facility_limit' => '25000.000',
            'annual_rate' => '4.250000',
            'margin_rate' => '1.000000',
            'start_date' => $now->copy()->subMonths(2)->toDateString(),
            'maturity_date' => $now->copy()->addYear()->toDateString(),
            'status' => 'active',
            'idempotency_key' => 'evidence-facility-01',
            'request_hash' => hash('sha256', 'evidence-facility-01'),
            'finance_journal_id' => null,
            'created_by' => $treasuryActor->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $evidenceDrawdownId = (int) DB::table('enterprise_treasury_drawdowns')->insertGetId([
            'facility_id' => $evidenceFacilityId,
            'reference' => 'EVID-DD-01',
            'idempotency_key' => 'evidence-drawdown-01',
            'request_hash' => hash('sha256', 'evidence-drawdown-01'),
            'principal_amount' => '12000.000',
            'scheduled_interest_total' => '600.000',
            'drawdown_date' => $now->copy()->subMonth()->toDateString(),
            'maturity_date' => $now->copy()->addMonths(11)->toDateString(),
            'installment_count' => 4,
            'status' => 'active',
            'finance_journal_id' => null,
            'created_by' => $treasuryActor->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([30, 120, 210, 300] as $index => $days) {
            DB::table('enterprise_treasury_repayments')->insert([
                'drawdown_id' => $evidenceDrawdownId,
                'installment_number' => $index + 1,
                'due_date' => $now->copy()->addDays($days)->toDateString(),
                'principal_amount' => '3000.000',
                'interest_amount' => '150.000',
                'status' => 'scheduled',
                'finance_journal_id' => null,
                'created_by' => $treasuryActor->id,
                'paid_at' => null,
                'paid_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        DB::table('enterprise_treasury_investments')->insert([
            'organization_id' => $treasuryScope->organizationId,
            'company_id' => $treasuryScope->companyId,
            'legal_entity_id' => $treasuryScope->legalEntityId,
            'store_id' => $storeId,
            'code' => 'EVID-INV-01',
            'institution' => 'FOODEX Evidence Bank',
            'instrument_type' => 'Term Deposit',
            'currency_code' => 'KWD',
            'principal_amount' => '5000.000',
            'annual_yield_rate' => '3.750000',
            'expected_return_amount' => '187.500',
            'placement_date' => $now->copy()->subDays(45)->toDateString(),
            'maturity_date' => $now->copy()->addDays(45)->toDateString(),
            'status' => 'active',
            'idempotency_key' => 'evidence-investment-01',
            'request_hash' => hash('sha256', 'evidence-investment-01'),
            'settlement_reference' => null,
            'finance_journal_id' => null,
            'created_by' => $treasuryActor->id,
            'settled_at' => null,
            'settled_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $retailStoreId = (int) DB::table('stores')
            ->where('code', 'FOODEX-DEMO-B2C')
            ->value('id');

        $retailEvidenceStore = Store::query()->findOrFail($retailStoreId);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($retailEvidenceStore);
        app(ModuleEntitlementManager::class)->set(
            'smart_ordering',
            new EntitlementScope(EntitlementScope::STORE, $retailStoreId),
            true,
            null,
            $procurementActor,
        );
        $retailFinanceHierarchy = app(OrganizationHierarchyService::class)->scopeForStore($retailStoreId);
        if ($retailFinanceHierarchy === null) {
            throw new RuntimeException('Finance screenshot fixture requires a retail legal-entity scope.');
        }
        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $retailFinanceHierarchy['legal_entity_id']),
            true,
            [
                'finance.foundation.read',
                'finance.gl.read',
                'finance.opening_balances.read',
                'finance.ar.read',
                'finance.ap.read',
                'finance.credit_terms.read',
            ],
            $procurementActor,
        );


        $smartOrderingSite = app(SmartOrderingSiteService::class)->ensureForStore($retailStoreId);
        DB::table('smart_ordering_entitlements')
            ->where('site_id', $smartOrderingSite->getKey())
            ->where('scope_key', 'site')
            ->where('module', 'foodex_supply_intelligence')
            ->update([
                'enabled' => true,
                'updated_at' => $now,
            ]);

        DB::table('smart_ordering_entitlements')->updateOrInsert(
            [
                'site_id' => $smartOrderingSite->getKey(),
                'scope_key' => 'site',
                'module' => 'reservations',
            ],
            [
                'branch_id' => null,
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        DB::table('smart_ordering_branches')->updateOrInsert(
            ['site_id' => $smartOrderingSite->getKey(), 'code' => 'EVID-DINING'],
            [
                'public_id' => (string) Str::uuid(),
                'name_ar' => 'فرع المطعم التجريبي',
                'name_en' => 'Evidence Dining Branch',
                'timezone' => 'Asia/Kuwait',
                'is_default' => true,
                'is_active' => true,
                'accepting_orders' => true,
                'supports_dine_in' => true,
                'supports_pickup' => true,
                'supports_delivery' => true,
                'allow_scheduled' => true,
                'pickup_lead_minutes' => 10,
                'max_schedule_days' => 7,
                'service_charge' => 0,
                'max_active_orders' => 0,
                'capacity_window_minutes' => 30,
                'dine_in_promise_minutes' => 20,
                'pickup_promise_minutes' => 25,
                'delivery_promise_minutes' => 45,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
        $restaurantEvidenceBranchId = (int) DB::table('smart_ordering_branches')
            ->where('site_id', $smartOrderingSite->getKey())
            ->where('code', 'EVID-DINING')
            ->value('id');

        DB::table('smart_ordering_floor_areas')->updateOrInsert(
            ['branch_id' => $restaurantEvidenceBranchId, 'name_en' => 'Main Dining Room'],
            [
                'public_id' => (string) Str::uuid(),
                'name_ar' => 'الصالة الرئيسية',
                'sort_order' => 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
        DB::table('smart_ordering_floor_areas')->updateOrInsert(
            ['branch_id' => $restaurantEvidenceBranchId, 'name_en' => 'Terrace'],
            [
                'public_id' => (string) Str::uuid(),
                'name_ar' => 'التراس',
                'sort_order' => 20,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
        $mainDiningFloorId = (int) DB::table('smart_ordering_floor_areas')
            ->where('branch_id', $restaurantEvidenceBranchId)
            ->where('name_en', 'Main Dining Room')
            ->value('id');
        $terraceFloorId = (int) DB::table('smart_ordering_floor_areas')
            ->where('branch_id', $restaurantEvidenceBranchId)
            ->where('name_en', 'Terrace')
            ->value('id');

        foreach ([
            ['label' => 'T01', 'floor' => $mainDiningFloorId, 'capacity' => 2, 'x' => 80, 'y' => 120, 'w' => 150, 'h' => 105, 'shape' => 'round', 'active' => true],
            ['label' => 'T02', 'floor' => $mainDiningFloorId, 'capacity' => 4, 'x' => 390, 'y' => 120, 'w' => 180, 'h' => 120, 'shape' => 'rectangle', 'active' => true],
            ['label' => 'T03', 'floor' => $mainDiningFloorId, 'capacity' => 6, 'x' => 680, 'y' => 440, 'w' => 210, 'h' => 130, 'shape' => 'rectangle', 'active' => true],
            ['label' => 'TR1', 'floor' => $terraceFloorId, 'capacity' => 4, 'x' => 180, 'y' => 180, 'w' => 170, 'h' => 110, 'shape' => 'round', 'active' => true],
            ['label' => 'TR2', 'floor' => $terraceFloorId, 'capacity' => 2, 'x' => 610, 'y' => 500, 'w' => 150, 'h' => 100, 'shape' => 'rectangle', 'active' => false],
        ] as $tableFixture) {
            DB::table('smart_ordering_tables')->updateOrInsert(
                ['branch_id' => $restaurantEvidenceBranchId, 'label' => $tableFixture['label']],
                [
                    'public_id' => (string) Str::uuid(),
                    'floor_area_id' => $tableFixture['floor'],
                    'capacity' => $tableFixture['capacity'],
                    'position_x' => $tableFixture['x'],
                    'position_y' => $tableFixture['y'],
                    'visual_width' => $tableFixture['w'],
                    'visual_height' => $tableFixture['h'],
                    'shape' => $tableFixture['shape'],
                    'qr_reference' => (string) Str::uuid(),
                    'token_hash' => hash('sha256', 'evidence-table-'.$tableFixture['label']),
                    'is_active' => $tableFixture['active'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $t02 = (int) DB::table('smart_ordering_tables')
            ->where('branch_id', $restaurantEvidenceBranchId)->where('label', 'T02')->value('id');
        $t03 = (int) DB::table('smart_ordering_tables')
            ->where('branch_id', $restaurantEvidenceBranchId)->where('label', 'T03')->value('id');
        DB::table('smart_ordering_table_combinations')->updateOrInsert(
            ['branch_id' => $restaurantEvidenceBranchId, 'primary_table_id' => $t02, 'member_table_id' => $t03, 'split_at' => null],
            [
                'public_id' => (string) Str::uuid(),
                'combined_by' => null,
                'split_by' => null,
                'combined_at' => $now->copy()->subMinutes(12),
                'note' => 'Evidence family seating',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $t01 = (int) DB::table('smart_ordering_tables')
            ->where('branch_id', $restaurantEvidenceBranchId)->where('label', 'T01')->value('id');
        DB::table('smart_ordering_reservations')->updateOrInsert(
            ['branch_id' => $restaurantEvidenceBranchId, 'guest_name' => 'Noura Al Salem'],
            [
                'public_id' => (string) Str::uuid(),
                'customer_id' => null,
                'table_id' => $t02,
                'guest_phone' => '+96550001001',
                'guest_email' => 'noura.reservation@example.test',
                'party_size' => 4,
                'service_at' => $now->copy()->addHours(4),
                'status' => 'CONFIRMED',
                'notes' => 'Window seating preferred',
                'created_by' => null,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
        DB::table('smart_ordering_reservations')->updateOrInsert(
            ['branch_id' => $restaurantEvidenceBranchId, 'guest_name' => 'Omar Haddad'],
            [
                'public_id' => (string) Str::uuid(),
                'customer_id' => null,
                'table_id' => $t01,
                'guest_phone' => '+96550001002',
                'guest_email' => null,
                'party_size' => 2,
                'service_at' => $now->copy()->addHours(6),
                'status' => 'BOOKED',
                'notes' => null,
                'created_by' => null,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
        DB::table('smart_ordering_waitlist_entries')->updateOrInsert(
            ['branch_id' => $restaurantEvidenceBranchId, 'guest_name' => 'Mariam Youssef'],
            [
                'public_id' => (string) Str::uuid(),
                'customer_id' => null,
                'table_id' => null,
                'reservation_id' => null,
                'guest_phone' => '+96550001003',
                'guest_email' => null,
                'party_size' => 3,
                'arrived_at' => $now->copy()->subMinutes(12),
                'quoted_wait_minutes' => 20,
                'priority' => 2,
                'priority_reason' => 'Young child',
                'status' => 'WAITING',
                'notes' => 'Prefers indoor seating',
                'created_by' => null,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
        DB::table('smart_ordering_waitlist_entries')->updateOrInsert(
            ['branch_id' => $restaurantEvidenceBranchId, 'guest_name' => 'Fahad Al Mutairi'],
            [
                'public_id' => (string) Str::uuid(),
                'customer_id' => null,
                'table_id' => null,
                'reservation_id' => null,
                'guest_phone' => '+96550001004',
                'guest_email' => null,
                'party_size' => 2,
                'arrived_at' => $now->copy()->subMinutes(5),
                'quoted_wait_minutes' => 10,
                'priority' => 0,
                'priority_reason' => null,
                'status' => 'CONTACTED',
                'contacted_at' => $now->copy()->subMinute(),
                'notes' => null,
                'created_by' => null,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $platformUser = app(PlatformCustomerService::class)->register([
            'name' => 'FOODEX Address Evidence Customer',
            'email' => 'evidence.address@foodex.test',
            'phone' => '+96555509999',
            'password' => 'Evidence123!',
            'locale' => 'ar',
            'store_id' => $retailStoreId,
        ], 'dashboard');

        $platformCustomer = DB::table('platform_customers')
            ->where('user_id', $platformUser->id)
            ->first();

        if ($platformCustomer === null) {
            throw new RuntimeException('Platform Customer screenshot fixture was not created.');
        }

        $whatsAppConversationId = (int) DB::table('whats_app_conversations')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'platform_customer_id' => (int) $platformCustomer->id,
            'store_id' => $retailStoreId,
            'channel' => 'whatsapp',
            'status' => 'WAITING_FOR_AGENT',
            'primary_agent_id' => null,
            'priority' => 1,
            'unread_count' => 1,
            'waiting_since' => $now,
            'first_inbound_at' => $now,
            'last_inbound_at' => $now,
            'lock_version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('whats_app_messages')->insert([
            'conversation_id' => $whatsAppConversationId,
            'direction' => 'inbound',
            'actor_type' => 'customer',
            'actor_user_id' => null,
            'body' => 'Please help me with my latest order.',
            'attachment_metadata' => null,
            'provider_message_ref' => null,
            'delivery_status' => 'received',
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $addressId = (int) DB::table('addresses')->insertGetId([
            'customer_id' => (int) $platformCustomer->legacy_customer_id,
            'platform_customer_id' => (int) $platformCustomer->id,
            'b2b_customer_id' => null,
            'b2c_customer_id' => null,
            'label' => 'Home',
            'recipient_name' => 'FOODEX Address Evidence Customer',
            'delivery_phone' => '+96555509999',
            'line1' => 'Block 1, Street 5, Building 12',
            'line2' => null,
            'city' => 'Kuwait City',
            'area' => 'Bayan',
            'country_code' => 'KW',
            'country' => 'Kuwait',
            'governorate' => 'Hawalli',
            'block' => '1',
            'street' => 'Street 5',
            'avenue' => null,
            'building' => '12',
            'floor' => '2',
            'apartment' => '7',
            'landmark' => 'Near Bayan Co-op',
            'delivery_notes' => 'Call on arrival',
            'latitude' => 29.3031000,
            'longitude' => 48.0489000,
            'location_accuracy_meters' => 6.0,
            'location_source' => 'map_pin',
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('address_quality_reviews')->insert([
            'public_id' => (string) Str::uuid(),
            'subject_type' => 'platform_customer_address',
            'subject_id' => $addressId,
            'status' => 'unmapped',
            'quality_class' => 'review_required',
            'confidence' => 0.7200,
            'territory_key' => null,
            'resolution_source' => null,
            'reason' => 'Deterministic Field Operations address-quality evidence.',
            'resolved_by' => null,
            'resolved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $b2cCustomerId = DB::table('b2c_customers')
            ->where('user_id', $platformUser->id)
            ->where('store_id', $retailStoreId)
            ->value('id');

        $deliverySnapshot = [
            'version' => 1,
            'address_id' => $addressId,
            'label' => 'Home',
            'recipient_name' => 'FOODEX Address Evidence Customer',
            'delivery_phone' => '+96555509999',
            'line1' => 'Block 1, Street 5, Building 12',
            'line2' => null,
            'city' => 'Kuwait City',
            'area' => 'Bayan',
            'country_code' => 'KW',
            'country' => 'Kuwait',
            'governorate' => 'Hawalli',
            'block' => '1',
            'street' => 'Street 5',
            'avenue' => null,
            'building' => '12',
            'floor' => '2',
            'apartment' => '7',
            'landmark' => 'Near Bayan Co-op',
            'delivery_notes' => 'Call on arrival',
            'latitude' => 29.3031,
            'longitude' => 48.0489,
            'location_accuracy_meters' => 6.0,
            'location_source' => 'map_pin',
        ];

        DB::table('orders')->insert([
            'store_id' => $retailStoreId,
            'customer_id' => (int) $platformCustomer->legacy_customer_id,
            'b2c_customer_id' => $b2cCustomerId === null ? null : (int) $b2cCustomerId,
            'address_id' => $addressId,
            'delivery_address_snapshot' => json_encode($deliverySnapshot, JSON_THROW_ON_ERROR),
            'delivery_latitude' => 29.3031000,
            'delivery_longitude' => 48.0489000,
            'order_number' => 'FOODEX-EVID-LOC-1',
            'channel' => 'b2c',
            'status' => 'confirmed',
            'currency' => 'EGP',
            'subtotal' => 12.500,
            'discount_total' => 0,
            'delivery_total' => 1.000,
            'grand_total' => 13.500,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $categoryId = (int) DB::table('categories')->insertGetId([
            'name' => 'FOODEX Wholesale',
            'slug' => 'foodex-evidence-wholesale',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EVID-CS',
            'name' => 'Case',
            'decimal_places' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2b',
            'code' => 'evidence-wholesale',
            'name' => 'FOODEX Wholesale Evidence Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'sku' => 'FOODEX-B2B-001',
            'name' => 'FOODEX Wholesale Tomato Case',
            'description' => 'Deterministic screenshot evidence product.',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => 7.250,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 240,
            'reserved_quantity' => 20,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $tierId = (int) DB::table('b2b_price_tiers')->insertGetId([
            'code' => 'GOLD-EVIDENCE',
            'name' => 'Gold Evidence',
            'priority' => 100,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $customerId = (int) DB::table('customers')->insertGetId([
            'type' => 'b2b',
            'name' => 'FOODEX Business Customer',
            'phone' => '+96555500001',
            'email' => 'buyer@foodex.test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('b2b_accounts')->insert([
            'customer_id' => $customerId,
            'price_tier_id' => $tierId,
            'company_name' => 'FOODEX Business Demo',
            'status' => 'active',
            'tax_number' => 'TX-EVID-001',
            'credit_limit' => 5000,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('b2b_price_rules')->insert([
            'price_tier_id' => $tierId,
            'store_id' => $storeId,
            'product_id' => $productId,
            'unit_price' => 6.950,
            'minimum_quantity' => 5,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $orderId = (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'order_number' => 'FOODEX-B2B-EVID-1001',
            'channel' => 'b2b',
            'status' => 'processing',
            'currency' => 'EGP',
            'subtotal' => 69.500,
            'discount_total' => 0,
            'delivery_total' => 5,
            'grand_total' => 74.500,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $productId,
            'sku_snapshot' => 'FOODEX-B2B-001',
            'name_snapshot' => 'FOODEX Wholesale Tomato Case',
            'quantity' => 10,
            'unit_price' => 6.950,
            'line_total' => 69.500,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $driverUserId = (int) DB::table('users')->insertGetId([
            'name' => 'سالم · سائق FOODEX أعمال',
            'email' => 'driver.b2b@foodex.test',
            'email_verified_at' => $now,
            'password' => Hash::make('Evidence123!'),
            'locale' => 'ar',
            'is_active' => true,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $driverRoleId = (int) DB::table('roles')->where('code', 'B2B_DRIVER')->value('id');
        DB::table('role_user')->insert(['role_id' => $driverRoleId, 'user_id' => $driverUserId]);
        $driverId = (int) DB::table('drivers')->insertGetId([
            'user_id' => $driverUserId,
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('driver_assignments')->insert([
            'driver_id' => $driverId,
            'order_id' => $orderId,
            'assignment_type' => 'b2b',
            'status' => 'assigned',
            'assigned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $driverStaleAt = $now->copy()->subSeconds(55);
        DB::table('driver_current_locations')->insert([
            'driver_id' => $driverId,
            'store_id' => $storeId,
            'channel' => 'b2b',
            'latitude' => 29.3768000,
            'longitude' => 47.9822000,
            'accuracy' => 8.0,
            'speed' => 0.0,
            'heading' => 90.0,
            'captured_at' => $driverStaleAt,
            'received_at' => $driverStaleAt,
            'app_version' => '1.0.60',
            'is_mocked' => false,
            'active_assignment_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $vanOperatorId = (int) DB::table('users')->insertGetId([
            'name' => 'نورة · مشغلة فان FOODEX',
            'email' => 'van.operator@foodex.test',
            'email_verified_at' => $now,
            'password' => Hash::make('Evidence123!'),
            'locale' => 'ar',
            'is_active' => true,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $vanId = (int) DB::table('vans')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'code' => 'VAN-EVID-01',
            'plate_number' => 'FOODEX-801',
            'vehicle_type' => 'sales_van',
            'status' => 'active',
            'capacity_units' => 120,
            'capacity_weight' => 1500,
            'home_warehouse_id' => $warehouseId,
            'notes' => 'Deterministic mixed live-tracking visual evidence.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $vanAssignmentId = (int) DB::table('van_assignments')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'van_id' => $vanId,
            'driver_id' => null,
            'representative_user_id' => $vanOperatorId,
            'warehouse_id' => $warehouseId,
            'territory_key' => 'KW-EVIDENCE',
            'van_pool_key' => 'EVIDENCE',
            'assignment_type' => 'primary',
            'status' => 'active',
            'effective_from' => $now->copy()->subHour(),
            'effective_until' => null,
            'loaded_work_count' => 12,
            'transferred_to_van_id' => null,
            'transfer_reason' => null,
            'created_by' => $superAdminId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('fleet_current_locations')->insert([
            'actor_type' => 'van',
            'actor_id' => $vanId,
            'vehicle_id' => $vanId,
            'assignment_id' => $vanAssignmentId,
            'route_key' => 'ROUTE-EVID-01',
            'store_id' => $storeId,
            'channel' => 'b2b',
            'latitude' => 29.3826000,
            'longitude' => 47.9894000,
            'accuracy' => 5.0,
            'speed' => 3.2,
            'heading' => 180.0,
            'captured_at' => $now,
            'received_at' => $now,
            'source_app' => 'van',
            'app_version' => '1.0.60',
            'is_mocked' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $collectionAccountId = (int) DB::table('collection_accounts')->insertGetId([
            'actor_type' => 'van',
            'actor_id' => $vanId,
            'store_id' => $storeId,
            'currency' => 'EGP',
            'status' => 'active',
            'custody_limit' => 500,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $collectionTransactionId = (int) DB::table('collection_transactions')->insertGetId([
            'collection_account_id' => $collectionAccountId,
            'payment_id' => null,
            'idempotency_key' => 'EVIDENCE-VAN-COLLECTION-001',
            'type' => 'collection',
            'status' => 'posted',
            'amount' => 87.500,
            'currency' => 'EGP',
            'source' => 'cash_on_delivery',
            'created_by' => $superAdminId,
            'created_at' => $now->copy()->subMinutes(18),
            'updated_at' => $now->copy()->subMinutes(18),
        ]);

        DB::table('custody_ledger_entries')->insert([
            'collection_account_id' => $collectionAccountId,
            'entry_type' => 'collection',
            'amount' => 87.500,
            'currency' => 'EGP',
            'reference_type' => 'collection_transaction',
            'reference_id' => $collectionTransactionId,
            'created_by' => $superAdminId,
            'created_at' => $now->copy()->subMinutes(18),
            'updated_at' => $now->copy()->subMinutes(18),
        ]);

        DB::table('remittances')->insert([
            'collection_account_id' => $collectionAccountId,
            'idempotency_key' => 'EVIDENCE-VAN-REMIT-001',
            'amount' => 25.000,
            'currency' => 'EGP',
            'method' => 'bank_transfer',
            'reference' => 'VAN-REMIT-EVID-001',
            'status' => 'pending',
            'note' => 'Deterministic Van finance support runtime evidence.',
            'submitted_by' => $vanOperatorId,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'created_at' => $now->copy()->subMinutes(8),
            'updated_at' => $now->copy()->subMinutes(8),
        ]);
    }
}
