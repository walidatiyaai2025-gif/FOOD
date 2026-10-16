<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Commercial\CommercialCreditEligibilityPolicy;
use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CustomerDomainResolver;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class CommercialCreditEligibilityPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_policy_uses_authoritative_ledger_snapshot_and_preserves_store_scope(): void
    {
        [, $legacyCustomer, $customer, $storeId] = $this->account(100);
        $otherStoreId = $this->otherStore($storeId);

        $this->invoice($legacyCustomer->id, $customer->id, $storeId, 40);
        $this->invoice($legacyCustomer->id, $customer->id, $otherStoreId, 90);

        $policy = app(CommercialCreditEligibilityPolicy::class);

        $local = $policy->evaluate($customer, $storeId, 55);
        $this->assertTrue($local['eligible']);
        $this->assertSame(CommercialCreditEligibilityPolicy::REASON_ELIGIBLE, $local['reason']);
        $this->assertSame('EGP', $local['currency']);
        $this->assertSame(55.0, $local['proposed_amount']);
        $this->assertSame(100.0, $local['credit_limit']);
        $this->assertSame(40.0, $local['outstanding_receivable']);
        $this->assertSame(0.0, $local['customer_credit_balance']);
        $this->assertSame(60.0, $local['purchasing_power']);
        $this->assertSame(0.0, $local['shortfall']);

        $foreign = $policy->evaluate($customer, $otherStoreId, 55);
        $this->assertFalse($foreign['eligible']);
        $this->assertSame(
            CommercialCreditEligibilityPolicy::REASON_PURCHASING_POWER_EXCEEDED,
            $foreign['reason'],
        );
        $this->assertSame(90.0, $foreign['outstanding_receivable']);
        $this->assertSame(10.0, $foreign['purchasing_power']);
        $this->assertSame(45.0, $foreign['shortfall']);
    }

    public function test_assert_eligible_preserves_existing_over_limit_checkout_contract(): void
    {
        [, $legacyCustomer, $customer, $storeId] = $this->account(50);
        $this->invoice($legacyCustomer->id, $customer->id, $storeId, 30);

        try {
            app(CommercialCreditEligibilityPolicy::class)->assertEligible($customer, $storeId, 25);
            $this->fail('Expected over-limit account credit to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame(
                'The order exceeds the available B2B purchasing power.',
                $exception->getMessage(),
            );
        }
    }

    public function test_policy_rejects_invalid_amount_before_reading_credit_state(): void
    {
        [, , $customer, $storeId] = $this->account(100);

        $this->expectException(ValidationException::class);

        app(CommercialCreditEligibilityPolicy::class)->evaluate($customer, $storeId, 0);
    }

    public function test_policy_fails_closed_when_the_b2b_account_is_inactive(): void
    {
        [, , $customer, $storeId] = $this->account(100);

        B2bAccount::query()
            ->where('b2b_customer_id', $customer->getKey())
            ->update(['status' => 'inactive']);

        $this->expectException(ModelNotFoundException::class);

        app(CommercialCreditEligibilityPolicy::class)->evaluate($customer, $storeId, 10);
    }

    /** @return array{0:User,1:Customer,2:B2bCustomer,3:int} */
    private function account(float $creditLimit): array
    {
        $user = User::query()->create([
            'name' => 'Commercial Credit Buyer',
            'email' => uniqid('commercial-credit-', true).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $legacyCustomer = Customer::query()->create([
            'user_id' => $user->getKey(),
            'type' => 'b2b',
            'name' => 'Commercial Credit Buyer',
            'email' => $user->email,
        ]);
        B2bAccount::query()->create([
            'customer_id' => $legacyCustomer->getKey(),
            'company_name' => 'Commercial Credit Co',
            'status' => 'active',
            'credit_limit' => $creditLimit,
        ]);

        $customer = app(CustomerDomainResolver::class)->b2b($user);
        $storeId = app(WholesalePrincipal::class)->storeId();

        return [$user, $legacyCustomer, $customer, $storeId];
    }

    private function otherStore(int $storeId): int
    {
        $storeTypeId = (int) DB::table('stores')->where('id', $storeId)->value('store_type_id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'COMM-CREDIT-OTHER',
            'name' => 'Commercial Credit Other Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function invoice(
        int $legacyCustomerId,
        int $customerId,
        int $storeId,
        float $total,
    ): Invoice {
        return Invoice::query()->create([
            'store_id' => $storeId,
            'customer_id' => $legacyCustomerId,
            'b2b_customer_id' => $customerId,
            'invoice_number' => uniqid('COMM-CREDIT-INV-', true),
            'status' => 'issued',
            'channel' => 'b2b',
            'currency' => 'EGP',
            'total' => $total,
            'issued_at' => now()->subDay(),
            'due_at' => now()->addWeek(),
        ]);
    }
}
