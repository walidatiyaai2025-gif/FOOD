<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TreasuryMasterFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_finance_actor_manages_only_authorized_store_treasury_records_and_bank_reference_is_encrypted(): void
    {
        $storeA = $this->createStore('TREASURY-A', 'Treasury Store A');
        $storeB = $this->createStore('TREASURY-B', 'Treasury Store B');
        $this->mapEnterpriseScope($storeA);
        $this->mapEnterpriseScope($storeB);

        $actor = $this->retailFinanceActor('treasury-a@example.test', $storeA);

        $this->actingAs($actor)
            ->post('/admin/treasury/cashboxes', [
                'store_id' => $storeA,
                'code' => 'cash-01',
                'name' => 'Front Cashbox',
                'operator_visibility' => 'store',
                'approval_visibility' => 'company',
                'is_active' => 1,
            ])
            ->assertRedirect('/admin/treasury/master-data?tab=cashboxes');

        $cashboxId = (int) DB::table('enterprise_cashboxes')->value('id');
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($storeA);

        $this->assertNotNull($scope);
        $this->assertDatabaseHas('enterprise_cashboxes', [
            'id' => $cashboxId,
            'company_id' => $scope['company_id'],
            'store_id' => $storeA,
            'code' => 'CASH-01',
            'currency_code' => 'EGP',
            'operator_visibility' => 'store',
            'approval_visibility' => 'company',
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'enterprise.treasury.cashbox.created']);

        $secretReference = 'EG12 3456 7890 1234';
        $this->actingAs($actor)
            ->post('/admin/treasury/bank-accounts', [
                'store_id' => $storeA,
                'code' => 'bank-01',
                'name' => 'Settlement account',
                'bank_name' => 'Scope Bank',
                'account_name' => 'FOODEX Treasury',
                'account_reference' => $secretReference,
                'operator_visibility' => 'store',
                'approval_visibility' => 'store',
                'is_active' => 1,
            ])
            ->assertRedirect('/admin/treasury/master-data?tab=bank_accounts');

        $storedSecret = (string) DB::table('enterprise_bank_accounts')->value('account_reference');
        $this->assertNotSame($secretReference, $storedSecret);
        $this->assertStringNotContainsString('345678901234', $storedSecret);
        $this->assertDatabaseHas('enterprise_bank_accounts', [
            'store_id' => $storeA,
            'code' => 'BANK-01',
            'account_reference_last4' => '1234',
            'currency_code' => 'EGP',
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'enterprise.treasury.bank_account.created']);

        $this->actingAs($actor)
            ->get('/admin/treasury/master-data?tab=bank_accounts')
            ->assertOk()
            ->assertSee('Scope Bank')
            ->assertSee('1234')
            ->assertDontSee($secretReference)
            ->assertDontSee('345678901234')
            ->assertSee('/admin/treasury/master-data', false);

        $this->actingAs($actor)
            ->post('/admin/treasury/cashboxes', [
                'store_id' => $storeB,
                'code' => 'FORBIDDEN',
                'name' => 'Forbidden Cashbox',
                'operator_visibility' => 'store',
                'approval_visibility' => 'store',
                'is_active' => 1,
            ])
            ->assertNotFound();

        $otherActor = $this->retailFinanceActor('treasury-b@example.test', $storeB);
        $this->actingAs($otherActor)
            ->get('/admin/treasury/master-data')
            ->assertOk()
            ->assertDontSee('Front Cashbox')
            ->assertDontSee('Scope Bank');

        $this->actingAs($actor)
            ->patch('/admin/treasury/cashboxes/'.$cashboxId, [
                'store_id' => $storeA,
                'code' => 'cash-01',
                'name' => 'Front Cashbox Updated',
                'operator_visibility' => 'company',
                'approval_visibility' => 'store',
                'is_active' => 0,
            ])
            ->assertRedirect('/admin/treasury/master-data?tab=cashboxes');

        $this->assertDatabaseHas('enterprise_cashboxes', [
            'id' => $cashboxId,
            'name' => 'Front Cashbox Updated',
            'operator_visibility' => 'company',
            'approval_visibility' => 'store',
            'is_active' => 0,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'enterprise.treasury.cashbox.updated']);
    }

    public function test_treasury_surface_is_bilingual_bounded_and_does_not_expose_raw_account_reference(): void
    {
        $storeId = $this->createStore('TREASURY-UI', 'Treasury UI Store');
        $this->mapEnterpriseScope($storeId);
        $actor = $this->retailFinanceActor('treasury-ui@example.test', $storeId);

        $this->actingAs($actor)
            ->get('/admin/treasury/master-data')
            ->assertOk()
            ->assertSee('Treasury master data')
            ->assertSee('data-foodex-mobile-web-page="treasury-master"', false)
            ->assertSee('name="per_page"', false)
            ->assertSee('value="25"', false)
            ->assertSee('Finance &amp; Treasury', false);

        $actor->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($actor)
            ->get('/admin/treasury/master-data')
            ->assertOk()
            ->assertSee('البيانات الأساسية للخزينة')
            ->assertSee('المالية والخزينة');
    }

    private function createStore(string $code, string $name): int
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function mapEnterpriseScope(int $storeId): void
    {
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore(
            Store::query()->findOrFail($storeId),
        );
    }

    private function retailFinanceActor(string $email, int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Treasury Finance',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $roleId = (int) Role::query()->where('code', 'RETAIL_FINANCE')->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
