<?php

namespace Tests\Feature;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseBankAccount;
use App\Models\EnterpriseCashbox;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2TreasuryMasterLiteralSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_cashbox_and_bank_account_filters_match_literal_symbols_and_bilingual_text_with_store_authority(): void
    {
        $store = $this->store('V2-LITERAL-TREASURY-A');
        $foreignStore = $this->store('V2-LITERAL-TREASURY-B');
        $actor = $this->financeActor($store);

        $cashAr = $this->cashbox($store, 'CASH-AR', 'خزينة %_! العربية');
        $cashEn = $this->cashbox($store, 'CASH-EN', 'Cash %_! English');
        $cashBang = $this->cashbox($store, 'CASH-BANG', 'Flag!_Cash');
        $cashCode = $this->cashbox($store, 'CASH_TEST', 'Ordinary treasury');
        $this->cashbox($store, 'CASH-LOOKALIKE', 'CashXY! unrelated');
        $cashInactive = $this->cashbox($store, 'CASH-INACTIVE', 'Inactive %_! cash', false);
        $this->cashbox($foreignStore, 'CASH-FOREIGN', 'Foreign %_! cash');

        $bankAr = $this->bank($store, 'BANK-AR', 'حساب عربي', 'بنك %_! العربية');
        $bankEn = $this->bank($store, 'BANK-EN', 'English Bank', 'Bank %_! English');
        $bankBang = $this->bank($store, 'BANK-BANG', 'Flag!_Bank', 'Regional Bank', true, 'Account!_literal');
        $bankCode = $this->bank($store, 'BANK_TEST', 'Ordinary account', 'Regular Bank');
        $this->bank($store, 'BANK-LOOKALIKE', 'Unrelated bank', 'BankXY! unrelated');
        $bankInactive = $this->bank($store, 'BANK-INACTIVE', 'Inactive %_! Bank', 'Local Bank', false);
        $this->bank($foreignStore, 'BANK-FOREIGN', 'Foreign %_! Bank', 'Other Bank');

        $cashboxCount = DB::table('enterprise_cashboxes')->count();
        $bankCount = DB::table('enterprise_bank_accounts')->count();
        $auditCount = DB::table('audit_logs')->count();

        foreach ([
            ['locale' => 'ar', 'q' => '%_!', 'status' => 'active', 'cash' => [$cashAr->id, $cashEn->id], 'bank' => [$bankAr->id, $bankEn->id]],
            ['locale' => 'en', 'q' => '%_!', 'status' => 'inactive', 'cash' => [$cashInactive->id], 'bank' => [$bankInactive->id]],
            ['locale' => 'en', 'q' => '%', 'status' => 'active', 'cash' => [$cashAr->id, $cashEn->id], 'bank' => [$bankAr->id, $bankEn->id]],
            ['locale' => 'ar', 'q' => '!_', 'status' => 'active', 'cash' => [$cashBang->id], 'bank' => [$bankBang->id]],
            ['locale' => 'en', 'q' => 'CASH_', 'status' => 'active', 'cash' => [$cashCode->id], 'bank' => []],
            ['locale' => 'en', 'q' => 'BANK_', 'status' => 'active', 'cash' => [], 'bank' => [$bankCode->id]],
            ['locale' => 'ar', 'q' => 'العربية', 'status' => 'active', 'cash' => [$cashAr->id], 'bank' => [$bankAr->id]],
            ['locale' => 'en', 'q' => 'English', 'status' => 'active', 'cash' => [$cashEn->id], 'bank' => [$bankEn->id]],
            ['locale' => 'en', 'q' => 'nothing%_!', 'status' => 'active', 'cash' => [], 'bank' => []],
        ] as $case) {
            $actor->forceFill(['locale' => $case['locale']])->save();
            app()->setLocale($case['locale']);

            $response = $this->actingAs($actor->fresh())->get(route('admin.treasury.master.index', [
                'store_id' => $store->id,
                'tab' => 'bank_accounts',
                'q' => $case['q'],
                'status' => $case['status'],
            ]))->assertOk();

            $response->assertSee('dir="'.($case['locale'] === 'ar' ? 'rtl' : 'ltr').'"', false);
            $this->assertPageIds($response->viewData('cashboxes'), $case['cash']);
            $this->assertPageIds($response->viewData('bankAccounts'), $case['bank']);
            $response->assertDontSee('KW00SYNTHBANK-AR', false);
        }

        $this->actingAs($actor->fresh())
            ->get(route('admin.treasury.master.index', ['store_id' => $foreignStore->id, 'q' => '%_!']))
            ->assertNotFound();

        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)
            ->get(route('admin.treasury.master.index', ['store_id' => $store->id, 'q' => '%_!']))
            ->assertForbidden();

        $permission = Permission::query()->where('code', 'finance.view')->firstOrFail();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'store:'.$store->id,
            'store_id' => $store->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $this->actingAs($actor->fresh())
            ->get(route('admin.treasury.master.index', ['store_id' => $store->id, 'q' => '%_!']))
            ->assertForbidden();

        $this->assertSame($cashboxCount, DB::table('enterprise_cashboxes')->count());
        $this->assertSame($bankCount, DB::table('enterprise_bank_accounts')->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_literal_cashbox_search_retains_status_store_and_independent_page_query(): void
    {
        $store = $this->store('V2-LITERAL-PAGING');
        $actor = $this->financeActor($store);
        for ($n = 0; $n < 26; $n++) {
            $this->cashbox($store, 'BATCH-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'Batch! cash '.(string) $n);
        }
        $this->cashbox($store, 'BATCH-LOOKALIKE', 'BatchX cash');
        $this->bank($store, 'BANK-NOT-RELEVANT', 'No bank match', 'Unrelated bank');

        $filters = ['tab' => 'cashboxes', 'store_id' => $store->id, 'q' => 'Batch!', 'status' => 'active', 'per_page' => 25];
        $pageOne = $this->actingAs($actor)
            ->get(route('admin.treasury.master.index', $filters))
            ->assertOk()->viewData('cashboxes');

        $this->assertSame(26, $pageOne->total());
        $this->assertCount(25, $pageOne->items());

        parse_str((string) parse_url((string) $pageOne->nextPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('Batch!', $query['q'] ?? null);
        $this->assertSame((string) $store->id, $query['store_id'] ?? null);
        $this->assertSame('active', $query['status'] ?? null);
        $this->assertSame('25', $query['per_page'] ?? null);

        $pageTwo = $this->actingAs($actor)
            ->get(route('admin.treasury.master.index', [...$filters, 'cashboxes_page' => 2]))
            ->assertOk();

        $this->assertSame(26, $pageTwo->viewData('cashboxes')->total());
        $this->assertCount(1, $pageTwo->viewData('cashboxes')->items());
        $this->assertSame(0, $pageTwo->viewData('bankAccounts')->total());
    }

    /** @param list<int> $expected */
    private function assertPageIds($page, array $expected): void
    {
        $actual = $page->getCollection()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $expected = array_map('intval', $expected);
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual);
    }

    private function store(string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function financeActor(Store $store): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function cashbox(Store $store, string $code, string $name, bool $active = true): EnterpriseCashbox
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);

        return EnterpriseCashbox::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => $code,
            'name' => $name,
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => $active,
        ]);
    }

    private function bank(
        Store $store,
        string $code,
        string $name,
        string $bankName,
        bool $active = true,
        string $accountName = 'FOODEX account',
    ): EnterpriseBankAccount {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);
        $reference = 'KW00SYNTH'.$code;

        return EnterpriseBankAccount::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => $code,
            'name' => $name,
            'bank_name' => $bankName,
            'account_name' => $accountName,
            'account_reference' => $reference,
            'account_reference_last4' => substr($reference, -4),
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseBankAccount::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseBankAccount::VISIBILITY_STORE,
            'is_active' => $active,
        ]);
    }
}
