<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Treasury\TreasuryPaymentService;
use App\Models\EnterpriseCashbox;
use App\Models\EnterpriseTreasuryPayment;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EnterpriseTreasuryPaymentFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_payment_lifecycle_is_idempotent_and_enforces_maker_approver_separation(): void
    {
        [$store, $maker, $makerContext] = $this->fixture('PAY-LIFE', 'maker@example.test');
        $approver = $this->financeActor('approver@example.test', $store);
        $approverContext = $this->context($approver, $store);
        $this->enableTreasury($maker, $makerContext);
        $this->entitleTreasury($approver, $approverContext);

        $cashbox = $this->cashbox($store);
        $payload = [
            'idempotency_key' => 'payment-001',
            'target_type' => EnterpriseTreasuryPayment::TARGET_CASHBOX,
            'target_id' => $cashbox->id,
            'amount' => '25.750',
            'value_date' => '2026-10-16',
            'reference' => 'PAY-REF-001',
            'beneficiary' => 'Supplier A',
        ];

        $service = app(TreasuryPaymentService::class);
        $draft = $service->createDraft($maker, $makerContext, $payload);
        $retry = $service->createDraft($maker, $makerContext, $payload);

        $this->assertSame($draft->id, $retry->id);
        $this->assertSame('25.750', $draft->amount);
        $this->assertDatabaseCount('enterprise_treasury_payments', 1);

        try {
            $service->post($maker, $makerContext, $draft->id);
            $this->fail('Maker must not approve the same payment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('approver', $exception->errors());
        }

        $posted = $service->post($approver, $approverContext, $draft->id);
        $postedRetry = $service->post($approver, $approverContext, $draft->id);

        $this->assertSame(EnterpriseTreasuryPayment::STATUS_POSTED, $posted->status);
        $this->assertSame($posted->id, $postedRetry->id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.treasury.payment_posted',
            'auditable_id' => $draft->id,
        ]);

        try {
            $service->createDraft($maker, $makerContext, [...$payload, 'reference' => 'DIFFERENT']);
            $this->fail('Idempotency key reuse with a different request must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }
    }

    public function test_disabled_treasury_performs_no_payment_table_query_work(): void
    {
        [$store, $actor, $context] = $this->fixture('PAY-OFF', 'off@example.test');
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            $queries[] = strtolower((string) $event->sql);
        });

        try {
            app(TreasuryPaymentService::class)->paginatePayments($actor, $context);
            $this->fail('Disabled Treasury must deny payment reads.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'enterprise_treasury_payments'),
        ));
        $this->assertTrue($store->exists);
    }

    public function test_payment_cancel_and_schema_rollback_are_safe(): void
    {
        [$store, $actor, $context] = $this->fixture('PAY-CANCEL', 'cancel@example.test');
        $this->enableTreasury($actor, $context);
        $cashbox = $this->cashbox($store);

        $service = app(TreasuryPaymentService::class);
        $draft = $service->createDraft($actor, $context, [
            'idempotency_key' => 'cancel-001',
            'target_type' => EnterpriseTreasuryPayment::TARGET_CASHBOX,
            'target_id' => $cashbox->id,
            'amount' => '3.000',
            'value_date' => '2026-10-16',
            'reference' => 'CANCEL-001',
        ]);

        $cancelled = $service->cancel($actor, $context, $draft->id);
        $again = $service->cancel($actor, $context, $draft->id);
        $this->assertSame(EnterpriseTreasuryPayment::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame($cancelled->id, $again->id);

        $migration = require database_path(
            'migrations/2026_10_16_074000_create_enterprise_treasury_payments.php',
        );
        $migration->down();
        $this->assertFalse(Schema::hasTable('enterprise_treasury_payments'));
        $this->assertTrue(Schema::hasTable('enterprise_treasury_receipts'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('enterprise_treasury_payments'));
    }

    /** @return array{Store,User,ResolvedTenantContext} */
    private function fixture(string $code, string $email): array
    {
        $store = $this->createRetailStore($code);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        $actor = $this->financeActor($email, $store);

        return [$store, $actor, $this->context($actor, $store)];
    }

    private function createRetailStore(string $code): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }

    private function financeActor(string $email, Store $store): User
    {
        $user = User::factory()->create(['email' => $email, 'is_active' => true]);
        $role = Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function context(User $actor, Store $store): ResolvedTenantContext
    {
        return app(TenantContextResolver::class)->retail($actor, $store->id);
    }

    private function enableTreasury(User $actor, ResolvedTenantContext $context): void
    {
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(TreasuryPaymentService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(TreasuryPaymentService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(TreasuryPaymentService::MODULE, ModuleState::ENABLED, $actor);
        }
        $this->entitleTreasury($actor, $context);
    }

    private function entitleTreasury(User $actor, ResolvedTenantContext $context): void
    {
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);
        app(ModuleEntitlementManager::class)->set(
            TreasuryPaymentService::MODULE,
            new EntitlementScope(EntitlementScope::STORE, $scope->storeId),
            true,
            [
                'treasury.payments.read',
                'treasury.payments.manage',
                'treasury.payments.approve',
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
            'code' => 'PAY-CASH-'.$store->id,
            'name' => 'Payment Cashbox',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => true,
        ]);
    }
}
