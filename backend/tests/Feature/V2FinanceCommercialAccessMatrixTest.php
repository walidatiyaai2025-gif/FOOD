<?php

namespace Tests\Feature;

use App\Enterprise\Commercial\CommercialCreditEligibilityPolicy;
use App\Models\B2bAccount;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\CustomerDomainResolver;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * V2 P144: one HTTP/database security matrix across the customer-service,
 * wholesale credit, procurement supplier and Finance approval boundaries.
 *
 * Detailed positive Finance maker/checker and supplier lifecycles remain
 * covered by their canonical Enterprise foundation suites.
 */
final class V2FinanceCommercialAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_inventory_actor_cannot_write_b2b_discounts_supplier_or_finance_records(): void
    {
        $retailStore = $this->store('B2C', 'V2-P144-RETAIL');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->assignStoreRole($actor, 'RETAIL_INVENTORY', $retailStore);

        $wholesaleOrder = $this->wholesaleOrder();
        $financeBefore = DB::table('enterprise_finance_journals')->count();
        $suppliersBefore = DB::table('enterprise_suppliers')->count();
        $discountsBefore = DB::table('order_customer_service_discounts')->count();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $discount = $this->actingAs($actor)->post(
                route('admin.operations.orders.customer-service-discount', ['order' => $wholesaleOrder]),
                [
                    'percentage' => '15',
                    'reason' => 'Cross-channel unauthorized concession',
                    'idempotency_key' => (string) Str::uuid(),
                ],
            );
            $this->assertContains($discount->getStatusCode(), [403, 404]);

            $supplier = $this->actingAs($actor)->post(
                route('admin.erp.procurement.suppliers.store'),
                [
                    'store_id' => $retailStore,
                    'scope_type' => 'store',
                    'code' => 'V2-P144-FORGED',
                    'name_en' => 'Unauthorized Supplier',
                ],
            );
            $this->assertContains($supplier->getStatusCode(), [403, 404]);

            $finance = $this->actingAs($actor)->post(
                route('admin.erp.finance.journals.store'),
                ['store_id' => $retailStore, 'description' => 'Unauthorized journal'],
            );
            $this->assertContains($finance->getStatusCode(), [403, 404]);
        }

        $this->assertSame($financeBefore, DB::table('enterprise_finance_journals')->count());
        $this->assertSame($suppliersBefore, DB::table('enterprise_suppliers')->count());
        $this->assertSame($discountsBefore, DB::table('order_customer_service_discounts')->count());
        $this->assertDatabaseHas('orders', ['id' => $wholesaleOrder, 'discount_total' => 1, 'grand_total' => 9]);
        $this->assertDatabaseMissing('enterprise_suppliers', ['code' => 'V2-P144-FORGED']);
    }

    public function test_wholesale_credit_rejects_over_limit_without_mutating_orders_and_api_enforces_account_status(): void
    {
        $buyer = User::query()->create([
            'name' => 'V2 P144 Buyer',
            'email' => 'v2-p144-buyer@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'user_id' => $buyer->getKey(),
            'type' => 'b2b',
            'name' => 'V2 P144 Buyer',
            'email' => $buyer->email,
        ]);
        $account = B2bAccount::query()->create([
            'customer_id' => $customer->getKey(),
            'company_name' => 'V2 P144 Buyer Company',
            'status' => 'active',
            'credit_limit' => 5,
        ]);

        $domainCustomer = app(CustomerDomainResolver::class)->b2b($buyer);
        $storeId = app(WholesalePrincipal::class)->storeId();
        $ordersBefore = DB::table('orders')->count();

        try {
            app(CommercialCreditEligibilityPolicy::class)->assertEligible($domainCustomer, $storeId, 10);
            $this->fail('Credit limit must be enforced before any wholesale order mutation.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame($ordersBefore, DB::table('orders')->count());
        $this->assertDatabaseHas('b2b_accounts', ['id' => $account->getKey(), 'credit_limit' => 5]);

        Sanctum::actingAs($buyer);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();

            $this->getJson('/api/v1/b2b/account-summary')->assertOk();
        }

        $account->forceFill(['status' => 'pending'])->save();
        $this->getJson('/api/v1/b2b/account-statement')->assertForbidden();
        $this->assertSame($ordersBefore, DB::table('orders')->count());
    }

    public function test_non_finance_operator_cannot_bypass_disabled_finance_and_procurement_module_guards(): void
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        $supplierCount = DB::table('enterprise_suppliers')->count();
        $journalsCount = DB::table('enterprise_finance_journals')->count();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();

            $financePage = $this->actingAs($user)->get(route('admin.erp.finance.index'));
            $this->assertContains($financePage->getStatusCode(), [403, 404]);

            $procurementPage = $this->actingAs($user)->get(route('admin.erp.procurement.index'));
            $this->assertContains($procurementPage->getStatusCode(), [403, 404]);

            $approval = $this->actingAs($user)->post(
                route('admin.erp.finance.journals.approval', ['journal' => 999999]),
                ['store_id' => 999999],
            );
            $this->assertContains($approval->getStatusCode(), [403, 404]);
        }

        $this->assertSame($supplierCount, DB::table('enterprise_suppliers')->count());
        $this->assertSame($journalsCount, DB::table('enterprise_finance_journals')->count());
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignStoreRole(User $user, string $role, int $store): void
    {
        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'role_id' => Role::query()->where('code', $role)->firstOrFail()->getKey(),
            'store_id' => $store,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function wholesaleOrder(): int
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $customer = app(B2bCustomerService::class)->create(['name' => 'V2 P144 Discount Buyer']);

        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => (int) $customer->legacy_customer_id,
            'b2b_customer_id' => (int) $customer->getKey(),
            'order_number' => 'V2-P144-CROSS-DOMAIN',
            'channel' => 'b2b',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 1,
            'delivery_total' => 0,
            'tax_total' => 0,
            'grand_total' => 9,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
