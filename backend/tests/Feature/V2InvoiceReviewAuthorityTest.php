<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P284: authorized invoice review must never trust incomplete provenance.
 */
final class V2InvoiceReviewAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_invoice_review_is_authorized_and_localized_without_changing_invoice(): void
    {
        $store = $this->store('B2B', 'P284-WHOLESALE');
        $actor = $this->globalActor('B2B_ADMIN', 'p284-b2b@example.test');
        $invoice = $this->invoice($store, 'b2b', 'P284-B2B-INVOICE');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get(route('admin.invoices.show', ['invoice' => $invoice->id], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee($locale === 'ar' ? 'فاتورة' : 'Invoice');
        }

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'store_id' => $store,
            'channel' => 'b2b',
            'status' => 'issued',
        ]);
    }

    public function test_retail_invoice_review_is_store_and_channel_scoped(): void
    {
        $own = $this->store('B2C', 'P284-RETAIL-OWN');
        $foreign = $this->store('B2C', 'P284-RETAIL-FOREIGN');
        $b2b = $this->store('B2B', 'P284-B2B-FOREIGN');
        $actor = $this->retailActor($own);
        $ownInvoice = $this->invoice($own, 'b2c', 'P284-OWN');
        $foreignInvoice = $this->invoice($foreign, 'b2c', 'P284-FOREIGN');
        $wholesaleInvoice = $this->invoice($b2b, 'b2b', 'P284-WHOLESALE-OTHER');

        $this->actingAs($actor)
            ->get(route('admin.invoices.show', ['invoice' => $ownInvoice->id], false))
            ->assertOk();

        foreach ([$foreignInvoice, $wholesaleInvoice] as $deniedInvoice) {
            $this->actingAs($actor)
                ->get(route('admin.invoices.show', ['invoice' => $deniedInvoice->id], false))
                ->assertNotFound();

            $this->actingAs($actor)
                ->post(route('admin.invoices.reissue', ['invoice' => $deniedInvoice->id], false), [
                    'reason' => 'Not authorized to correct this invoice',
                ])
                ->assertNotFound();

            $this->assertDatabaseHas('invoices', [
                'id' => $deniedInvoice->id,
                'status' => 'issued',
            ]);
        }
    }

    public function test_orphan_invoice_provenance_does_not_become_global_finance_permission(): void
    {
        $invoice = $this->invoice(null, null, 'P284-ORPHAN');

        foreach (['B2B_ADMIN', 'SUPER_ADMIN'] as $roleCode) {
            $actor = $this->globalActor($roleCode, strtolower($roleCode).'-p284@example.test');

            $this->actingAs($actor)
                ->get(route('admin.invoices.show', ['invoice' => $invoice->id], false))
                ->assertNotFound();

            $this->actingAs($actor)
                ->get(route('admin.invoices.download', ['invoice' => $invoice->id, 'locale' => 'ar'], false))
                ->assertNotFound();

            $this->actingAs($actor)
                ->post(route('admin.invoices.reissue', ['invoice' => $invoice->id], false), [
                    'reason' => 'Unscoped invoice must stay immutable',
                ])
                ->assertNotFound();
        }

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'issued']);
        $this->assertDatabaseMissing('audit_logs', [
            'auditable_type' => Invoice::class,
            'auditable_id' => $invoice->id,
            'event' => 'invoice.voided',
        ]);
    }

    public function test_legacy_store_only_invoice_recovers_channel_from_store_master_data(): void
    {
        $own = $this->store('B2C', 'P284-LEGACY-OWN');
        $foreign = $this->store('B2C', 'P284-LEGACY-FOREIGN');
        $invoice = $this->invoice($own, null, 'P284-LEGACY');

        $this->actingAs($this->retailActor($own))
            ->get(route('admin.invoices.show', ['invoice' => $invoice->id], false))
            ->assertOk();

        $this->actingAs($this->retailActor($foreign))
            ->get(route('admin.invoices.show', ['invoice' => $invoice->id], false))
            ->assertNotFound();

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'store_id' => $own,
            'channel' => null,
        ]);
    }

    public function test_contradictory_store_channel_and_missing_store_fail_closed(): void
    {
        $store = $this->store('B2C', 'P284-CHANNEL-CONFLICT');
        $actor = $this->globalActor('B2B_ADMIN', 'p284-conflict@example.test');
        $wrongChannel = $this->invoice($store, 'b2b', 'P284-WRONG-CHANNEL');
        $missingStore = $this->invoice(null, 'b2b', 'P284-MISSING-STORE');

        foreach ([$wrongChannel, $missingStore] as $invoice) {
            $this->actingAs($actor)
                ->get(route('admin.invoices.show', ['invoice' => $invoice->id], false))
                ->assertNotFound();
        }
    }

    public function test_read_only_invoice_review_cannot_reissue_after_explicit_manage_deny(): void
    {
        $store = $this->store('B2B', 'P284-READ-ONLY');
        $invoice = $this->invoice($store, 'b2b', 'P284-NO-WRITE');
        $actor = $this->globalActor('B2B_ADMIN', 'p284-read-only@example.test');
        $permission = Permission::query()->where('code', 'finance.manage')->firstOrFail();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($actor->fresh())
            ->get(route('admin.invoices.show', ['invoice' => $invoice->id], false))
            ->assertOk()
            ->assertDontSee('Void & reissue');

        $this->actingAs($actor->fresh())
            ->post(route('admin.invoices.reissue', ['invoice' => $invoice->id], false), [
                'reason' => 'Denied finance manage attempt',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'issued']);
    }

    private function invoice(?int $storeId, ?string $channel, string $number): Invoice
    {
        return Invoice::query()->create([
            // Invoice.customer_id is mandatory even for legacy/orphan provenance fixtures.
            'customer_id' => Customer::query()->create([
                'name' => 'P284 Customer '.$number,
                'type' => $channel === 'b2b' ? 'b2b' : 'b2c',
            ])->getKey(),
            'store_id' => $storeId,
            'channel' => $channel,
            'invoice_number' => $number,
            'status' => 'issued',
            'currency' => 'KWD',
            'total' => 12.500,
            'subtotal' => 12.500,
            'store_name_snapshot' => 'P284 Store',
            'customer_name_snapshot' => 'P284 Customer',
            'issued_at' => now(),
        ]);
    }

    private function globalActor(string $role, string $email): User
    {
        $actor = User::factory()->create(['email' => $email, 'is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $actor->fresh();
    }

    private function retailActor(int $storeId): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor->fresh();
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'business_profile' => $channel === 'B2C' ? 'RETAIL_STORE' : null,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
