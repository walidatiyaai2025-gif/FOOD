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
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 #2631 / P301: the existing Treasury Payments tab is the canonical paid
 * action and never advertises privileges the server-side entitlement denies.
 */
final class V2TreasuryPaidAuthorityTest extends TestCase
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

    public function test_paid_tab_is_discoverable_localized_and_read_only_for_a_viewer(): void
    {
        $store = $this->store('V2-P301-READER');
        $role = Role::query()->create([
            'code' => 'V2_P301_READER',
            'name' => 'V2 payments viewer',
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        $permission = Permission::query()->firstOrCreate(
            ['code' => 'finance.view'],
            ['name' => 'View finance'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $viewer = $this->actor($store, $role, 'en', 'v2-p301-viewer@example.test');
        $this->enablePayments($viewer, $store, ['treasury.payments.read']);

        foreach ([
            'admin.erp.treasury.index',
            'admin.erp.treasury.payments.store',
            'admin.erp.treasury.payments.post',
            'admin.erp.treasury.payments.cancel',
        ] as $name) {
            $this->assertTrue(Route::has($name));
        }

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $viewer->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($viewer->fresh())
                ->get(route('admin.erp.treasury.index', [
                    'store_id' => $store->id,
                    'tab' => 'payments',
                ]))
                ->assertOk()
                ->assertSee('data-erp-treasury-workbench="true"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('enterprise_treasury.tabs.payments'))
                ->assertDontSee('action="'.route('admin.erp.treasury.payments.store').'"', false);
        }

        $this->actingAs($viewer)
            ->post(route('admin.erp.treasury.payments.store'), [
                'store_id' => $store->id,
                '_operation_key' => 'v2-p301-viewer-denied',
                'target_key' => 'cashbox:1',
                'amount' => '10.000',
                'value_date' => '2026-11-12',
                'reference' => 'V2-P301-DENIED',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('enterprise_treasury_payments', 0);
    }

    public function test_payment_maker_checker_entitlements_and_posting_audit_are_enforced_on_http(): void
    {
        $store = $this->store('V2-P301-MAKER-CHECKER');
        $role = Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail();
        $maker = $this->actor($store, $role, 'ar', 'v2-p301-maker@example.test');
        $checker = $this->actor($store, $role, 'en', 'v2-p301-checker@example.test');
        $this->enablePayments($maker, $store, [
            'treasury.payments.read',
            'treasury.payments.manage',
        ]);
        $cashbox = $this->cashbox($store);

        $payload = [
            'store_id' => $store->id,
            '_operation_key' => 'v2-p301-payment-001',
            'target_key' => 'cashbox:'.$cashbox->id,
            'amount' => '28.750',
            'value_date' => '2026-11-12',
            'reference' => 'V2-P301-001',
            'beneficiary' => 'Payment supplier',
        ];
        // Match the canonical controller backTo() query parameter order.
        $redirect = route('admin.erp.treasury.index', [
            'tab' => 'payments',
            'store_id' => $store->id,
        ]);

        $this->actingAs($maker)
            ->post(route('admin.erp.treasury.payments.store'), $payload)
            ->assertRedirect($redirect);
        $this->actingAs($maker)
            ->post(route('admin.erp.treasury.payments.store'), $payload)
            ->assertRedirect($redirect);
        $this->assertDatabaseCount('enterprise_treasury_payments', 1);

        $payment = EnterpriseTreasuryPayment::query()
            ->where('reference', 'V2-P301-001')
            ->firstOrFail();
        $this->assertSame('draft', $payment->status);
        $this->assertSame($maker->id, $payment->requested_by);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.treasury.payment_created',
            'auditable_id' => $payment->id,
        ]);

        $post = route('admin.erp.treasury.payments.post', $payment);
        $cancel = route('admin.erp.treasury.payments.cancel', $payment);

        // The creator can cancel a draft, but may not self-approve it.
        $this->actingAs($maker)
            ->get($redirect)
            ->assertOk()
            ->assertSee($cashbox->name)
            ->assertDontSee('action="'.$post.'"', false)
            ->assertSee('action="'.$cancel.'"', false);

        // Payment posting has its own entitlement even with finance.manage.
        $this->actingAs($checker)
            ->get($redirect)
            ->assertOk()
            ->assertDontSee('action="'.$post.'"', false);
        $this->actingAs($checker)
            ->post($post, ['store_id' => $store->id])
            ->assertForbidden();
        $this->assertSame('draft', $payment->fresh()->status);

        $this->enablePayments($maker, $store, [
            'treasury.payments.read',
            'treasury.payments.manage',
            'treasury.payments.approve',
        ]);

        $this->actingAs($maker)
            ->post($post, ['store_id' => $store->id])
            ->assertSessionHasErrors('approver');
        $this->assertSame('draft', $payment->fresh()->status);

        $this->actingAs($checker)
            ->get($redirect)
            ->assertOk()
            ->assertSee('action="'.$post.'"', false);

        $this->actingAs($checker)
            ->post($post, ['store_id' => $store->id])
            ->assertRedirect($redirect);
        $this->assertDatabaseHas('enterprise_treasury_payments', [
            'id' => $payment->id,
            'status' => 'posted',
            'posted_by' => $checker->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.treasury.payment_posted',
            'auditable_id' => $payment->id,
        ]);

        $this->actingAs($checker)
            ->post($cancel, ['store_id' => $store->id])
            ->assertSessionHasErrors('status');
        $this->assertSame('posted', $payment->fresh()->status);
    }

    public function test_foreign_store_cannot_read_or_mutate_another_store_payment(): void
    {
        $storeA = $this->store('V2-P301-STORE-A');
        $storeB = $this->store('V2-P301-STORE-B');
        $role = Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail();
        $actorA = $this->actor($storeA, $role, 'en', 'v2-p301-a@example.test');
        $actorB = $this->actor($storeB, $role, 'en', 'v2-p301-b@example.test');

        $caps = ['treasury.payments.read', 'treasury.payments.manage', 'treasury.payments.approve'];
        $this->enablePayments($actorA, $storeA, $caps);
        $this->enablePayments($actorB, $storeB, $caps);
        $cashbox = $this->cashbox($storeA);

        $this->actingAs($actorA)
            ->post(route('admin.erp.treasury.payments.store'), [
                'store_id' => $storeA->id,
                '_operation_key' => 'v2-p301-tenant-a',
                'target_key' => 'cashbox:'.$cashbox->id,
                'amount' => '3.000',
                'value_date' => '2026-11-12',
                'reference' => 'V2-P301-A',
            ])
            ->assertRedirect();

        $payment = EnterpriseTreasuryPayment::query()->where('reference', 'V2-P301-A')->firstOrFail();

        $this->actingAs($actorB)
            ->get(route('admin.erp.treasury.index', [
                'store_id' => $storeA->id,
                'tab' => 'payments',
            ]))
            ->assertForbidden();
        $this->actingAs($actorB)
            ->post(route('admin.erp.treasury.payments.post', $payment), [
                'store_id' => $storeB->id,
            ])
            ->assertNotFound();
        $this->actingAs($actorB)
            ->post(route('admin.erp.treasury.payments.cancel', $payment), [
                'store_id' => $storeB->id,
            ])
            ->assertNotFound();

        $this->assertSame('draft', $payment->fresh()->status);
    }

    private function store(string $code): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function actor(Store $store, Role $role, string $locale, string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'locale' => $locale,
            'is_active' => true,
        ]);
        $this->grantPlatformErpAccess($user);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /** @param list<string> $capabilities */
    private function enablePayments(User $actor, Store $store, array $capabilities): void
    {
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled('treasury')) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition('treasury', ModuleState::ENABLING, $actor);
            $lifecycle->transition('treasury', ModuleState::ENABLED, $actor);
        }

        $context = app(TenantContextResolver::class)->retail($actor, $store->id);
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);
        app(ModuleEntitlementManager::class)->set(
            'treasury',
            new EntitlementScope(EntitlementScope::STORE, $scope->storeId),
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
            'code' => 'V2-P301-CASH-'.$store->id,
            'name' => 'P301 payment cashbox',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => true,
        ]);
    }
}
