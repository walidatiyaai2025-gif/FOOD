<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Treasury\TreasuryReceiptService;
use App\Models\EnterpriseCashbox;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

final class V2TreasuryTabQueryFailClosedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Signed synthetic workflow proof is scoped to test fixtures, not ERP runtime certification.
        SyntheticErpWorkflowEvidence::install();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
        // Test-only version approval; never alter real ERP readiness.
        config()->set(
            'enterprise_module_readiness.certified_versions.treasury',
            app(ModuleRegistry::class)->manifest('treasury')->version,
        );
    }

    public function test_array_nested_and_unknown_tab_queries_are_404_without_financial_writes(): void
    {
        [$actor, $store] = $this->operator('V2-4022-TAB-DENY');
        $before = $this->movementCounts();

        foreach ([
            ['payments'],
            ['receipts', 'payments'],
            ['hidden' => ['cash_closing']],
        ] as $forgedTab) {
            $this->actingAs($actor)->get(route('admin.erp.treasury.index', [
                'store_id' => $store->id,
                'tab' => $forgedTab,
            ]))->assertNotFound();
            $this->assertSame($before, $this->movementCounts());
        }

        $this->actingAs($actor)->get(route('admin.erp.treasury.index', [
            'store_id' => $store->id,
            'tab' => 'payments,receipts',
        ]))->assertNotFound();
        $this->assertSame($before, $this->movementCounts());
    }

    public function test_arabic_english_tabs_render_only_selected_post_workflow_with_store_context(): void
    {
        [$actor, $store] = $this->operator('V2-4022-TAB-VIEW');

        $forms = [
            'receipts' => 'admin.erp.treasury.receipts.store',
            'payments' => 'admin.erp.treasury.payments.store',
            'cheques' => 'admin.erp.treasury.cheques.store',
            'reconciliation' => 'admin.erp.treasury.reconciliation.store',
            'cash_closing' => 'admin.erp.treasury.cash-closing.store',
        ];

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            foreach ($forms as $tab => $action) {
                $response = $this->actingAs($actor->fresh())->get(route('admin.erp.treasury.index', [
                    'store_id' => $store->id,
                    'tab' => $tab,
                ]))->assertOk()
                    ->assertViewHas('activeTab', $tab)
                    ->assertSee('dir="'.$direction.'"', false);

                $html = html_entity_decode($response->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $this->assertSame(1, preg_match(
                    '/<nav class="foodex-tabs foodex-tabs--horizontal"[^>]*>(.*?)<\/nav>/s',
                    $html,
                    $navigation,
                ));
                $this->assertSame(1, substr_count($navigation[1], 'aria-current="page"'));
                $this->assertStringContainsString('tab='.$tab, $navigation[1]);
                $this->assertStringContainsString('store_id='.$store->id, $navigation[1]);

                $this->assertStringContainsString('action="'.route($action).'"', $html);
                foreach ($forms as $otherTab => $otherAction) {
                    if ($otherTab !== $tab) {
                        $this->assertStringNotContainsString(
                            'action="'.route($otherAction).'"',
                            $html,
                            'Hidden Treasury form leaked from '.$otherTab.' into '.$tab,
                        );
                    }
                }
            }
        }

        $this->assertSame([0, 0], $this->movementCounts());
    }

    public function test_foreign_store_and_invalid_receipt_return_to_existing_tab_without_posting(): void
    {
        [$actor, $store] = $this->operator('V2-4022-TAB-FORMS');
        $foreign = $this->createStore('V2-4022-TAB-FOREIGN');

        $this->actingAs($actor)->get(route('admin.erp.treasury.index', [
            'store_id' => $foreign->id,
            'tab' => 'payments',
        ]))->assertForbidden();

        $activeUrl = route('admin.erp.treasury.index', [
            'store_id' => $store->id,
            'tab' => 'receipts',
            'q' => 'R-4022',
        ]);

        $this->actingAs($actor)->from($activeUrl)->post(
            route('admin.erp.treasury.receipts.store'),
            [
                'store_id' => $store->id,
                '_operation_key' => 'v2-4022-invalid-receipt',
                'target_key' => '',
                'amount' => '-1.000',
                'value_date' => '2026-11-18',
                'reference' => '',
            ],
        )->assertRedirect($activeUrl)->assertSessionHasErrors(['target_key', 'amount', 'reference']);

        $this->assertSame([0, 0], $this->movementCounts());
    }

    public function test_valid_receipt_preserves_active_tab_and_store_after_save(): void
    {
        [$actor, $store] = $this->operator('V2-4022-TAB-SAVE');
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);

        $cashbox = EnterpriseCashbox::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => 'V2-4022-CASH',
            'name' => 'Treasury cashbox',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => true,
        ]);

        $this->actingAs($actor)->post(route('admin.erp.treasury.receipts.store'), [
            'store_id' => $store->id,
            '_operation_key' => 'v2-4022-valid-receipt',
            'target_key' => 'cashbox:'.$cashbox->id,
            'amount' => '1.250',
            'value_date' => '2026-11-18',
            'reference' => 'V2-4022-RECEIPT',
        ])->assertRedirect(route('admin.erp.treasury.index', [
            'tab' => 'receipts',
            'store_id' => $store->id,
        ]));

        $this->assertDatabaseHas('enterprise_treasury_receipts', [
            'store_id' => $store->id,
            'reference' => 'V2-4022-RECEIPT',
            'amount' => '1.250',
            'status' => 'draft',
        ]);
        $this->assertSame([1, 0], $this->movementCounts());
    }

    /** @return array{User,Store} */
    private function operator(string $code): array
    {
        $store = $this->createStore($code);
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($user);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $context = app(TenantContextResolver::class)->retail($user, $store->id);
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(TreasuryReceiptService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(TreasuryReceiptService::MODULE, ModuleState::ENABLING, $user);
            $lifecycle->transition(TreasuryReceiptService::MODULE, ModuleState::ENABLED, $user);
        }

        $this->assertNotNull($context->enterpriseScope);
        app(ModuleEntitlementManager::class)->set(
            TreasuryReceiptService::MODULE,
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
            [
                'treasury.receipts.read',
                'treasury.receipts.manage',
                'treasury.receipts.approve',
                'treasury.payments.read',
                'treasury.payments.manage',
                'treasury.payments.approve',
                'treasury.cheques.read',
                'treasury.cheques.manage',
                'treasury.cheques.approve',
                'treasury.reconciliation.read',
                'treasury.reconciliation.manage',
                'treasury.reconciliation.approve',
                'treasury.cash_closing.read',
                'treasury.cash_closing.manage',
                'treasury.cash_closing.approve',
            ],
            $user,
        );

        return [$user, $store];
    }

    private function createStore(string $code): Store
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

    /** @return list<int> */
    private function movementCounts(): array
    {
        return [
            DB::table('enterprise_treasury_receipts')->count(),
            DB::table('enterprise_treasury_payments')->count(),
        ];
    }
}
