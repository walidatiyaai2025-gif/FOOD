<?php

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Models\VanVisit;
use App\Services\CustomerDomainResolver;
use App\Services\VanRegistryService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VanCollectionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_van_collection_and_remittance_are_authoritative_scoped_and_retry_safe(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $actor = User::factory()->create(['is_active' => true]);
        $this->authorizeVan($actor);
        $customerUser = User::factory()->create([
            'name' => 'Van Customer',
            'email' => 'van-finance-customer@example.test',
            'is_active' => true,
        ]);
        $legacy = Customer::query()->create([
            'user_id' => $customerUser->getKey(),
            'type' => 'b2b',
            'name' => 'Van Customer',
            'email' => $customerUser->email,
        ]);
        B2bAccount::query()->create([
            'customer_id' => $legacy->getKey(),
            'company_name' => 'Van Customer Co',
            'status' => 'active',
            'credit_limit' => 500,
        ]);

        $customer = app(CustomerDomainResolver::class)->b2b($customerUser);
        $storeId = app(WholesalePrincipal::class)->storeId();

        VanVisit::query()->create([
            'actor_user_id' => $actor->getKey(),
            'customer_type' => 'b2b',
            'customer_id' => $customer->getKey(),
            'store_id' => $storeId,
            'status' => 'started',
            'started_at' => now(),
        ]);

        $invoice = Invoice::query()->create([
            'store_id' => $storeId,
            'customer_id' => $legacy->getKey(),
            'b2b_customer_id' => $customer->getKey(),
            'invoice_number' => 'VAN-COLLECT-1',
            'status' => 'issued',
            'channel' => 'b2b',
            'currency' => 'EGP',
            'total' => 100,
            'issued_at' => now()->subDay(),
            'due_at' => now()->addDay(),
        ]);
        DB::table('payments')->insert([
            'invoice_id' => $invoice->getKey(),
            'provider' => 'account',
            'provider_reference' => 'PREPAID-20',
            'status' => 'paid',
            'amount' => 20,
            'currency' => 'EGP',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($actor, ['app:van']);

        $this->getJson(
            "/api/v1/van/customers/b2b/{$customer->getKey()}/collection-context?store_id={$storeId}",
        )
            ->assertOk()
            ->assertJsonPath('data.store_id', $storeId)
            ->assertJsonPath('data.invoices.0.id', $invoice->getKey())
            ->assertJsonPath('data.invoices.0.outstanding_amount', 80)
            ->assertJsonPath('data.outstanding_total_by_currency.EGP', 80);

        $collection = $this->withHeader('Idempotency-Key', 'van-collect-test-1')
            ->postJson("/api/v1/van/customers/b2b/{$customer->getKey()}/collect", [
                'store_id' => $storeId,
                'invoice_id' => $invoice->getKey(),
                'amount' => 30,
            ])
            ->assertCreated()
            ->assertJsonPath('data.remaining_outstanding', 50)
            ->assertJsonPath('data.wallet.custody_balance', 30)
            ->assertJsonPath('data.wallet.available_to_remit', 30);

        $receiptId = (int) $collection->json('data.receipt.id');
        $accountId = (int) $collection->json('data.wallet.id');

        $this->withHeader('Idempotency-Key', 'van-collect-test-1')
            ->postJson("/api/v1/van/customers/b2b/{$customer->getKey()}/collect", [
                'store_id' => $storeId,
                'invoice_id' => $invoice->getKey(),
                'amount' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('data.receipt.id', $receiptId)
            ->assertJsonPath('data.remaining_outstanding', 50);

        $this->assertDatabaseCount('collection_transactions', 1);
        $this->assertDatabaseCount('custody_ledger_entries', 1);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->getKey(),
            'provider' => 'field_collection',
            'status' => 'paid',
            'amount' => 30,
            'currency' => 'EGP',
        ]);

        $this->withHeader('Idempotency-Key', 'van-over-collect-1')
            ->postJson("/api/v1/van/customers/b2b/{$customer->getKey()}/collect", [
                'store_id' => $storeId,
                'invoice_id' => $invoice->getKey(),
                'amount' => 60,
            ])
            ->assertUnprocessable();

        $remittance = $this->withHeader('Idempotency-Key', 'van-remit-test-1')
            ->postJson('/api/v1/van/remittances', [
                'collection_account_id' => $accountId,
                'amount' => 20,
                'method' => 'bank_deposit',
            ])
            ->assertCreated()
            ->assertJsonPath('data.wallet.custody_balance', 30)
            ->assertJsonPath('data.wallet.available_to_remit', 10);

        $remittanceId = (int) $remittance->json('data.remittance.id');

        $this->withHeader('Idempotency-Key', 'van-remit-test-1')
            ->postJson('/api/v1/van/remittances', [
                'collection_account_id' => $accountId,
                'amount' => 20,
                'method' => 'bank_deposit',
            ])
            ->assertOk()
            ->assertJsonPath('data.remittance.id', $remittanceId)
            ->assertJsonPath('data.wallet.available_to_remit', 10);

        $this->assertDatabaseCount('remittances', 1);
        $this->assertDatabaseCount('custody_ledger_entries', 1);

        $otherActor = User::factory()->create(['is_active' => true]);
        $this->authorizeVan($otherActor);
        Sanctum::actingAs($otherActor, ['app:van']);

        $this->getJson(
            "/api/v1/van/customers/b2b/{$customer->getKey()}/collection-context?store_id={$storeId}",
        )->assertNotFound();

        $this->getJson('/api/v1/van/wallet')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withHeader('Idempotency-Key', 'van-cross-wallet-1')
            ->postJson('/api/v1/van/remittances', [
                'collection_account_id' => $accountId,
                'amount' => 1,
                'method' => 'bank_deposit',
            ])
            ->assertNotFound();
    }

    public function test_driver_compatibility_session_can_collect_for_visit_owned_by_same_van_representative(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $representative = User::factory()->create(['is_active' => true]);
        $representative->roles()->attach(Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail());

        $driverUser = User::factory()->create(['is_active' => true]);
        $driverUser->roles()->attach(Role::query()->where('code', 'B2B_DRIVER')->firstOrFail());

        $storeId = app(WholesalePrincipal::class)->storeId();
        $driver = Driver::query()->create([
            'user_id' => $driverUser->getKey(),
            'store_id' => $storeId,
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'DRIVER-FINANCE-VAN']);
        $assignment = $registry->assign($representative, $van, [
            'driver_id' => $driver->getKey(),
            'representative_user_id' => $representative->getKey(),
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);

        $customerUser = User::factory()->create([
            'name' => 'Driver Van Finance Customer',
            'email' => 'driver-van-finance@example.test',
            'is_active' => true,
        ]);
        $legacy = Customer::query()->create([
            'user_id' => $customerUser->getKey(),
            'type' => 'b2b',
            'name' => 'Driver Van Finance Customer',
            'email' => $customerUser->email,
        ]);
        B2bAccount::query()->create([
            'customer_id' => $legacy->getKey(),
            'company_name' => 'Driver Van Finance Co',
            'status' => 'active',
            'credit_limit' => 500,
        ]);
        $customer = app(CustomerDomainResolver::class)->b2b($customerUser);

        VanVisit::query()->create([
            'actor_user_id' => $representative->getKey(),
            'customer_type' => 'b2b',
            'customer_id' => $customer->getKey(),
            'store_id' => $storeId,
            'status' => 'started',
            'started_at' => now(),
            'metadata' => [
                'van_id' => $van->getKey(),
                'van_assignment_id' => $assignment->getKey(),
            ],
        ]);

        $invoice = Invoice::query()->create([
            'store_id' => $storeId,
            'customer_id' => $legacy->getKey(),
            'b2b_customer_id' => $customer->getKey(),
            'invoice_number' => 'VAN-DRIVER-FINANCE-1',
            'status' => 'issued',
            'channel' => 'b2b',
            'currency' => 'EGP',
            'total' => 100,
            'issued_at' => now()->subDay(),
            'due_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($driverUser, ['app:van']);

        $this->getJson(
            "/api/v1/van/customers/b2b/{$customer->getKey()}/collection-context?store_id={$storeId}",
        )
            ->assertOk()
            ->assertJsonPath('data.invoices.0.id', $invoice->getKey());

        $this->withHeader('Idempotency-Key', 'driver-van-collect-1')
            ->postJson("/api/v1/van/customers/b2b/{$customer->getKey()}/collect", [
                'store_id' => $storeId,
                'invoice_id' => $invoice->getKey(),
                'amount' => 25,
            ])
            ->assertCreated()
            ->assertJsonPath('data.wallet.currency', 'EGP')
            ->assertJsonPath('data.wallet.custody_balance', 25);
    }

    private function authorizeVan(User $actor): void
    {
        $actor->roles()->attach(Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail());

        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'COLLECT-VAN-'.$actor->id]);
        $registry->assign($actor, $van, [
            'representative_user_id' => $actor->id,
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);
    }
}
