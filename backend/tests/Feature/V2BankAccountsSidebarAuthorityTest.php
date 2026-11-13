<?php

namespace Tests\Feature;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseBankAccount;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P294: the Banks business tab must remain an authorized, store-scoped
 * Treasury Master destination, never a second financial security boundary.
 */
final class V2BankAccountsSidebarAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_finance_sidebar_reaches_bank_accounts_tab_in_arabic_and_english_without_exposing_account_reference(): void
    {
        $wholesaleId = app(WholesalePrincipal::class)->storeId();
        $store = Store::query()->findOrFail($wholesaleId);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        $bank = $this->bank($store, 'V2-P294-WHOLESALE', 'KW00V2TEST1234');
        $actor = $this->globalActor('B2B_ADMIN');

        foreach ([
            'ar' => ['dir' => 'rtl', 'tab' => 'الحسابات البنكية'],
            'en' => ['dir' => 'ltr', 'tab' => 'Bank accounts'],
        ] as $locale => $expected) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            $groups = app(AdminNavigation::class)->groupsFor($actor->fresh());
            $treasury = collect($groups)->firstWhere('key', 'treasury');

            $this->assertNotNull($treasury);
            $links = collect($groups)->flatMap(static fn (array $group): array => $group['children']);
            $item = $links->firstWhere('key', 'treasury_master');
            $this->assertNotNull($item);
            $this->assertSame('admin.treasury.master.index', $item['route']);
            $this->assertContains('treasury_master', array_column($treasury['children'], 'key'));

            $this->actingAs($actor->fresh())
                ->get(route('admin.treasury.master.index', ['tab' => 'bank_accounts']))
                ->assertOk()
                ->assertSee('dir="'.$expected['dir'].'"', false)
                ->assertSee($expected['tab'])
                ->assertSee($bank->code)
                ->assertSee('•••• 1234')
                ->assertDontSee('KW00V2TEST1234')
                ->assertSee('data-foodex-mobile-web-page="treasury-master"', false);
        }
    }

    public function test_retail_banks_are_visible_only_in_authorized_store_and_foreign_account_cannot_be_edited(): void
    {
        $own = $this->retailStore('V2-P294-OWN');
        $foreign = $this->retailStore('V2-P294-FOREIGN');
        $ownBank = $this->bank($own, 'V2-P294-OWN-BANK', 'KW00TEST0001');
        $foreignBank = $this->bank($foreign, 'V2-P294-FOREIGN-BANK', 'KW00TEST0002');
        $actor = $this->retailActor($own, 'RETAIL_FINANCE');
        $this->assertTrue($actor->hasPermission('finance.manage', $own->id));

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($actor->fresh())
                ->get(route('admin.treasury.master.index', ['tab' => 'bank_accounts', 'store_id' => $own->id]))
                ->assertOk()
                ->assertSee($ownBank->code)
                ->assertDontSee($foreignBank->code);

            $this->actingAs($actor->fresh())
                ->get(route('admin.treasury.master.index', ['tab' => 'bank_accounts', 'store_id' => $foreign->id]))
                ->assertNotFound();

            $this->actingAs($actor->fresh())
                ->get(route('admin.treasury.master.index', ['tab' => 'bank_accounts', 'edit_bank_account' => $foreignBank->id]))
                ->assertNotFound();

            $this->actingAs($actor->fresh())
                ->post(route('admin.treasury.bank-accounts.store'), $this->bankPayload($foreign, 'V2-P294-FORGED-'.$locale))
                ->assertNotFound();

            $this->assertDatabaseMissing('enterprise_bank_accounts', [
                'store_id' => $foreign->id,
                'code' => 'V2-P294-FORGED-'.$locale,
            ]);
        }

        $this->assertSame('Operating bank', $foreignBank->fresh()->name);
    }

    public function test_view_only_finance_role_has_readable_bank_tab_but_no_create_or_edit_actions(): void
    {
        $store = $this->retailStore('V2-P294-VIEW');
        $bank = $this->bank($store, 'V2-P294-VIEW-BANK', 'KW00TEST9988');
        $viewerRole = Role::query()->create([
            'code' => 'V2_P294_BANK_VIEWER',
            'name' => 'Bank viewer',
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        $viewerRole->permissions()->attach(Permission::query()->where('code', 'finance.view')->firstOrFail()->id);

        $viewer = $this->retailActor($store, $viewerRole->code);
        $this->assertTrue($viewer->hasPermission('finance.view', $store->id));
        $this->assertFalse($viewer->hasPermission('finance.manage', $store->id));

        $this->actingAs($viewer)
            ->get(route('admin.treasury.master.index', ['tab' => 'bank_accounts', 'store_id' => $store->id]))
            ->assertOk()
            ->assertSee($bank->code)
            ->assertDontSee('KW00TEST9988')
            ->assertDontSee(route('admin.treasury.master.index', ['tab' => 'bank_accounts', 'create' => 'bank_account']));

        $this->actingAs($viewer)
            ->post(route('admin.treasury.bank-accounts.store'), $this->bankPayload($store, 'V2-P294-UNAUTHORIZED'))
            ->assertNotFound();

        $this->actingAs($viewer)
            ->patch(route('admin.treasury.bank-accounts.update', ['bankAccount' => $bank->id]), $this->bankPayload($store, $bank->code))
            ->assertNotFound();

        $this->assertDatabaseMissing('enterprise_bank_accounts', ['code' => 'V2-P294-UNAUTHORIZED']);
        $this->assertSame('Operating bank', $bank->fresh()->name);
    }

    public function test_explicit_store_deny_revokes_bank_read_access_and_roleless_actor_is_forbidden(): void
    {
        $store = $this->retailStore('V2-P294-DENIED');
        $actor = $this->retailActor($store, 'RETAIL_FINANCE');
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
        $this->assertFalse($actor->fresh()->hasPermission('finance.view', $store->id));

        $this->actingAs($actor->fresh())
            ->get(route('admin.treasury.master.index', ['tab' => 'bank_accounts', 'store_id' => $store->id]))
            ->assertForbidden();

        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)
            ->get(route('admin.treasury.master.index', ['tab' => 'bank_accounts']))
            ->assertForbidden();
    }

    private function retailStore(string $code): Store
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

    private function bank(Store $store, string $code, string $reference): EnterpriseBankAccount
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);

        return EnterpriseBankAccount::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => $code,
            'name' => 'Operating bank',
            'bank_name' => 'Test bank',
            'account_name' => 'FOODEX account',
            'account_reference' => $reference,
            'account_reference_last4' => substr($reference, -4),
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseBankAccount::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseBankAccount::VISIBILITY_STORE,
            'is_active' => true,
        ]);
    }

    private function retailActor(Store $store, string $roleCode): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => Role::query()->where('code', $roleCode)->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function globalActor(string $roleCode): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }

    /** @return array<string, int|string> */
    private function bankPayload(Store $store, string $code): array
    {
        return [
            'store_id' => $store->id,
            'code' => $code,
            'name' => 'Unapproved bank',
            'bank_name' => 'Unsafe test bank',
            'account_name' => 'Attempt',
            'account_reference' => 'KW00SAFE4321',
            'operator_visibility' => 'store',
            'approval_visibility' => 'store',
            'is_active' => 1,
        ];
    }
}
