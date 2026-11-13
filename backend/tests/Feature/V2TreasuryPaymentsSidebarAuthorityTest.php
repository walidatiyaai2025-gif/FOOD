<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseCashbox;
use App\Models\EnterpriseTreasuryPayment;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P296 (native P285): the real Treasury payments tab is the authorized
 * disbursement destination; neither Sidebar visibility nor finance.view
 * is independent authority to mutate money.
 */
final class V2TreasuryPaymentsSidebarAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.treasury',
            app(ModuleRegistry::class)->manifest('treasury')->version,
        );
    }

    public function test_one_canonical_payment_tab_is_readable_in_both_languages_and_creates_a_real_draft(): void
    {
        [$maker, $store] = $this->operator('V2-P296-AR', 'p296-maker@example.test');
        $this->enableTreasury($maker, $store);
        $cashbox = $this->cashbox($store);
        $url = route('admin.erp.treasury.index', [
            'tab' => 'payments',
            'store_id' => $store->id,
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $maker->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($maker)->get($url)
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-erp-treasury-workbench="true"', false)
                ->assertSee(__('enterprise_treasury.tabs.payments'))
                ->assertSee($cashbox->code)
                ->assertSee('name="target_key"', false)
                ->assertSee(route('admin.erp.treasury.payments.store'));
        }

        $payload = [
            'store_id' => $store->id,
            '_operation_key' => 'p296-disbursement-001',
            'target_key' => 'cashbox:'.$cashbox->id,
            'amount' => '25.750',
            'value_date' => '2026-10-16',
            'reference' => 'P296-REFERENCE',
            'beneficiary' => 'Authorized Supplier',
        ];

        $this->actingAs($maker)
            ->post(route('admin.erp.treasury.payments.store'), $payload)
            ->assertRedirect($url);
        $this->actingAs($maker)
            ->post(route('admin.erp.treasury.payments.store'), $payload)
            ->assertRedirect($url);

        $this->assertDatabaseCount('enterprise_treasury_payments', 1);
        $this->assertDatabaseHas('enterprise_treasury_payments', [
            'store_id' => $store->id,
            'target_type' => 'cashbox',
            'target_id' => $cashbox->id,
            'amount' => '25.750',
            'status' => 'draft',
            'requested_by' => $maker->id,
        ]);
    }

    public function test_independent_authorized_checker_posts_once_and_maker_cannot_self_approve(): void
    {
        [$maker, $store] = $this->operator('V2-P296-SOD', 'p296-sod-maker@example.test');
        [$checker] = $this->operatorForStore($store, 'p296-sod-checker@example.test');
        $this->enableTreasury($maker, $store);
        $this->entitleTreasury($checker, $store);
        $cashbox = $this->cashbox($store);

        $this->actingAs($maker)->post(route('admin.erp.treasury.payments.store'), [
            'store_id' => $store->id,
            '_operation_key' => 'p296-sod-001',
            'target_key' => 'cashbox:'.$cashbox->id,
            'amount' => '7.125',
            'value_date' => '2026-10-16',
            'reference' => 'P296-SOD',
        ])->assertRedirect();

        $payment = EnterpriseTreasuryPayment::query()->where('reference', 'P296-SOD')->firstOrFail();
        $postRoute = route('admin.erp.treasury.payments.post', $payment);
        $this->actingAs($maker)->post($postRoute, ['store_id' => $store->id])
            ->assertSessionHasErrors('approver');

        $this->assertSame(EnterpriseTreasuryPayment::STATUS_DRAFT, $payment->fresh()->status);

        foreach ([1, 2] as $attempt) {
            $this->actingAs($checker)->post($postRoute, ['store_id' => $store->id])
                ->assertRedirect(route('admin.erp.treasury.index', [
                    'tab' => 'payments',
                    'store_id' => $store->id,
                ]));
        }

        $this->assertSame(EnterpriseTreasuryPayment::STATUS_POSTED, $payment->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.treasury.payment_posted',
            'auditable_id' => $payment->id,
        ]);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.treasury.payment_posted')
            ->where('auditable_id', $payment->id)
            ->count());
    }

    public function test_read_only_payment_entitlement_hides_create_and_denies_direct_mutation(): void
    {
        [$reader, $store] = $this->operator('V2-P296-READ', 'p296-reader@example.test');
        $this->enableTreasury($reader, $store, ['treasury.payments.read']);
        $cashbox = $this->cashbox($store);

        $this->actingAs($reader)->get(route('admin.erp.treasury.index', [
            'tab' => 'payments',
            'store_id' => $store->id,
        ]))
            ->assertOk()
            ->assertDontSee('name="target_key"', false)
            ->assertDontSee('action="'.route('admin.erp.treasury.payments.store').'"', false);

        $this->actingAs($reader)->post(route('admin.erp.treasury.payments.store'), [
            'store_id' => $store->id,
            '_operation_key' => 'p296-denied-write',
            'target_key' => 'cashbox:'.$cashbox->id,
            'amount' => '1.000',
            'value_date' => '2026-10-16',
            'reference' => 'DENIED',
        ])->assertForbidden();

        $this->assertDatabaseCount('enterprise_treasury_payments', 0);
    }

    public function test_foreign_store_and_roleless_actor_cannot_read_or_create_disbursements(): void
    {
        [$actor, $store] = $this->operator('V2-P296-OWN', 'p296-own@example.test');
        [$other, $foreign] = $this->operator('V2-P296-FOREIGN', 'p296-foreign@example.test');
        $this->enableTreasury($actor, $store);
        $this->entitleTreasury($other, $foreign);
        $cashbox = $this->cashbox($store);
        $roleless = User::factory()->create(['is_active' => true]);

        foreach ([$other, $roleless] as $denied) {
            $this->actingAs($denied)->get(route('admin.erp.treasury.index', [
                'tab' => 'payments',
                'store_id' => $store->id,
            ]))->assertForbidden();

            $this->actingAs($denied)->post(route('admin.erp.treasury.payments.store'), [
                'store_id' => $store->id,
                '_operation_key' => 'p296-foreign-attempt-'.$denied->id,
                'target_key' => 'cashbox:'.$cashbox->id,
                'amount' => '1.000',
                'value_date' => '2026-10-16',
                'reference' => 'FOREIGN',
            ])->assertForbidden();
        }

        $this->assertDatabaseCount('enterprise_treasury_payments', 0);
    }

    /** @return array{User, Store} */
    private function operator(string $code, string $email): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        [$actor] = $this->operatorForStore($store, $email);

        return [$actor, $store];
    }

    /** @return array{User} */
    private function operatorForStore(Store $store, string $email): array
    {
        $actor = User::factory()->create([
            'email' => $email,
            'locale' => 'en',
            'is_active' => true,
        ]);
        $this->grantPlatformErpAccess($actor);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$actor];
    }

    /** @param list<string> $capabilities */
    private function enableTreasury(
        User $actor,
        Store $store,
        array $capabilities = [
            'treasury.payments.read',
            'treasury.payments.manage',
            'treasury.payments.approve',
        ],
    ): void {
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled('treasury')) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition('treasury', ModuleState::ENABLING, $actor);
            $lifecycle->transition('treasury', ModuleState::ENABLED, $actor);
        }

        $this->entitleTreasury($actor, $store, $capabilities);
    }

    /** @param list<string> $capabilities */
    private function entitleTreasury(
        User $actor,
        Store $store,
        array $capabilities = [
            'treasury.payments.read',
            'treasury.payments.manage',
            'treasury.payments.approve',
        ],
    ): void {
        $context = app(TenantContextResolver::class)->retail($actor, $store->id);
        $this->assertNotNull($context->enterpriseScope);
        app(ModuleEntitlementManager::class)->set(
            'treasury',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
            $capabilities,
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
            'code' => 'P296-CASH-'.$store->id,
            'name' => 'Authorized Cashbox',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => true,
        ]);
    }
}
