<?php

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class B2bFinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_b2b_customer_can_view_owned_invoice_and_statement_with_audit(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$user, $customer] = $this->buyer('finance-buyer@example.test', 'active');
        $invoice = Invoice::query()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-58-1', 'status' => 'issued', 'currency' => 'KWD', 'total' => 12.500, 'issued_at' => now()]);
        DB::table('invoice_items')->insert(['invoice_id' => $invoice->id, 'description' => 'Wholesale item', 'quantity' => 1, 'unit_price' => 12.500, 'line_total' => 12.500, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payments')->insert(['invoice_id' => $invoice->id, 'provider' => 'account', 'provider_reference' => 'PAY-58', 'status' => 'paid', 'amount' => 5, 'currency' => 'KWD', 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/b2b/invoices')->assertOk()->assertJsonPath('data.0.invoice_number', 'INV-58-1');
        $this->getJson('/api/v1/b2b/invoices/'.$invoice->id)->assertOk()->assertJsonPath('data.items.0.description', 'Wholesale item');
        $this->getJson('/api/v1/b2b/account-statement')->assertOk()->assertJsonPath('data.total_debits', 12.5)->assertJsonPath('data.total_credits', 5)->assertJsonPath('data.balance', 7.5);
        $this->assertDatabaseHas('audit_logs', ['event' => 'b2b.finance.invoice_viewed']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'b2b.finance.statement_viewed']);
    }

    public function test_invoice_ownership_and_account_approval_are_enforced(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$owner, $ownerCustomer] = $this->buyer('finance-owner@example.test', 'active');
        $invoice = Invoice::query()->create(['customer_id' => $ownerCustomer->id, 'invoice_number' => 'INV-58-2', 'status' => 'issued', 'currency' => 'KWD', 'total' => 2, 'issued_at' => now()]);
        [$other] = $this->buyer('finance-other@example.test', 'active');
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/b2b/invoices/'.$invoice->id)->assertNotFound();

        [$pending] = $this->buyer('finance-pending@example.test', 'pending');
        Sanctum::actingAs($pending);
        $this->getJson('/api/v1/b2b/account-statement')->assertForbidden();
    }

    private function buyer(string $email, string $status): array
    {
        $user = User::query()->create(['name' => 'B2B Buyer', 'email' => $email, 'password' => 'password', 'is_active' => true]);
        $customer = Customer::query()->create(['user_id' => $user->id, 'type' => 'b2b', 'name' => 'B2B Buyer', 'email' => $email]);
        B2bAccount::query()->create(['customer_id' => $customer->id, 'company_name' => 'Buyer Co', 'status' => $status]);

        return [$user, $customer];
    }
}
