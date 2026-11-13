<?php

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Services\B2bAccountLedgerService;
use App\Services\CustomerDomainResolver;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P290: settle a selected open AR invoice through the canonical ledger,
 * not through the unrelated P297 bank-reconciliation workflow.
 */
final class V2ReceivableOpenItemSettlementAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_selected_wholesale_invoice_is_partially_and_fully_settled_with_real_ledger_and_audit(): void
    {
        [$invoice, $domain] = $this->invoice('P290-ITEM', 60);
        $admin = $this->superAdmin();
        $ledger = app(B2bAccountLedgerService::class);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin->fresh())
                ->get(route('admin.invoices.show', $invoice))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(route('admin.invoices.settle', ['invoice' => $invoice->id]), false);
        }

        $this->actingAs($admin->fresh())
            ->post(route('admin.invoices.settle', ['invoice' => $invoice->id]), [
                'amount' => '20.000',
                'reference' => 'P290-PARTIAL',
            ])
            ->assertRedirect(route('admin.invoices.show', ['invoice' => $invoice->id]));

        $this->assertDatabaseHas('customer_account_ledger_entries', [
            'b2b_customer_id' => $domain->id,
            'invoice_id' => $invoice->id,
            'source' => 'dashboard_invoice_settlement',
            'entry_type' => 'payment',
            'reference' => 'P290-PARTIAL',
            'credit' => 20,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'invoice.settled',
            'auditable_id' => $invoice->id,
        ]);
        $this->assertSame(40.0, $ledger->invoiceAmounts($invoice)['outstanding_amount']);

        $before = DB::table('customer_account_ledger_entries')
            ->where('invoice_id', $invoice->id)
            ->where('source', 'dashboard_invoice_settlement')
            ->count();

        $this->actingAs($admin->fresh())
            ->post(route('admin.invoices.settle', ['invoice' => $invoice->id]), [
                'amount' => '40.001',
                'reference' => 'P290-OVERPAY',
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame($before, DB::table('customer_account_ledger_entries')
            ->where('invoice_id', $invoice->id)
            ->where('source', 'dashboard_invoice_settlement')
            ->count());

        $this->actingAs($admin->fresh())
            ->post(route('admin.invoices.settle', ['invoice' => $invoice->id]), [
                'amount' => '40.000',
                'reference' => 'P290-FULL',
            ])
            ->assertRedirect(route('admin.invoices.show', ['invoice' => $invoice->id]));

        $this->assertSame(0.0, $ledger->invoiceAmounts($invoice)['outstanding_amount']);
        $this->actingAs($admin->fresh())
            ->get(route('admin.invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee('id="invoice-settlement-dialog"', false);

        $this->actingAs($admin->fresh())
            ->post(route('admin.invoices.settle', ['invoice' => $invoice->id]), [
                'amount' => '1.000',
                'reference' => 'P290-PAID-AGAIN',
            ])
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseMissing('customer_account_ledger_entries', [
            'reference' => 'P290-PAID-AGAIN',
        ]);
    }

    public function test_roleless_actor_cannot_settle_wholesale_open_item_or_access_its_invoice(): void
    {
        [$invoice] = $this->invoice('P290-DENY', 50);
        $unprivileged = User::factory()->create(['is_active' => true]);

        $this->actingAs($unprivileged)
            ->get(route('admin.invoices.show', $invoice))
            ->assertForbidden();

        $this->actingAs($unprivileged)
            ->post(route('admin.invoices.settle', $invoice), [
                'amount' => '10',
                'reference' => 'P290-UNAUTHORIZED',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('customer_account_ledger_entries', [
            'reference' => 'P290-UNAUTHORIZED',
        ]);
    }

    /** @return array{Invoice, B2bCustomer} */
    private function invoice(string $code, float $amount): array
    {
        $buyer = User::factory()->create(['is_active' => true]);
        $legacy = Customer::query()->create([
            'user_id' => $buyer->id,
            'type' => 'b2b',
            'name' => 'P290 '.$code,
            'email' => $buyer->email,
        ]);
        B2bAccount::query()->create([
            'customer_id' => $legacy->id,
            'company_name' => 'P290 Settlement Co',
            'status' => 'active',
            'credit_limit' => 1000,
        ]);
        $domain = app(CustomerDomainResolver::class)->b2b($buyer);

        $invoice = Invoice::query()->create([
            'store_id' => app(WholesalePrincipal::class)->storeId(),
            'customer_id' => $legacy->id,
            'b2b_customer_id' => $domain->id,
            'invoice_number' => 'P290-'.$code,
            'status' => 'issued',
            'channel' => 'b2b',
            'currency' => 'EGP',
            'total' => $amount,
            'issued_at' => now()->subDay(),
        ]);

        return [$invoice, $domain];
    }

    private function superAdmin(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor;
    }
}
