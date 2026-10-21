<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\PlatformCustomer;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\B2bAccountLedgerService;
use App\Services\CustomerDomainResolver;
use App\Services\PlatformCustomerService;
use App\Services\RetailWholesaleAccountService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardCustomer360Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        $this->store('B2B', 'WHOLESALE-MAIN', 'Main Wholesale');
        config(['foodex.platform_wholesale_store_code' => 'WHOLESALE-MAIN']);
    }

    public function test_malformed_customer_360_placeholder_redirects_to_index(): void
    {
        $admin = $this->globalAdmin('SUPER_ADMIN');

        $this->actingAs($admin)
            ->get('/admin/customer-360/%7BcustomerId')
            ->assertRedirect(route('admin.customer-360.index'));
    }

    public function test_customer_360_localizes_registration_source_filter_rows_and_access_context(): void
    {
        $storeId = $this->store('B2C', 'SOURCE-LABELS', 'Source Labels');

        $importUser = app(PlatformCustomerService::class)->register([
            'name' => 'Imported Customer',
            'email' => 'source-import@example.test',
            'phone' => '+201000000101',
            'password' => 'Password123!',
            'locale' => 'en',
            'store_id' => $storeId,
        ], 'import');
        $dashboardUser = app(PlatformCustomerService::class)->register([
            'name' => 'Dashboard Customer',
            'email' => 'source-dashboard@example.test',
            'phone' => '+201000000102',
            'password' => 'Password123!',
            'locale' => 'en',
            'store_id' => $storeId,
        ], 'dashboard');
        $unknownUser = app(PlatformCustomerService::class)->register([
            'name' => 'Unknown Source Customer',
            'email' => 'source-unknown@example.test',
            'phone' => '+201000000103',
            'password' => 'Password123!',
            'locale' => 'en',
            'store_id' => $storeId,
        ], 'customer_app');

        DB::table('platform_customers')
            ->where('user_id', $unknownUser->id)
            ->update([
                'registration_source' => 'legacy_unknown',
                'updated_at' => now(),
            ]);

        $importPlatform = PlatformCustomer::query()
            ->where('user_id', $importUser->id)
            ->firstOrFail();
        $admin = $this->globalAdmin('SUPER_ADMIN');

        $admin->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($admin)
            ->get(route('admin.customer-360.index', ['registration_source' => 'import']))
            ->assertOk()
            ->assertSee('FOODEX · المنصة')
            ->assertSee('تطبيق العميل')
            ->assertSee('لوحة الإدارة')
            ->assertSee('استيراد')
            ->assertSee('ترحيل')
            ->assertSee('value="import"', false)
            ->assertSee('source-import@example.test')
            ->assertDontSee('source-dashboard@example.test');

        $this->actingAs($admin)
            ->get(route('admin.customer-360.index'))
            ->assertOk()
            ->assertSee('غير معروف')
            ->assertDontSee('legacy_unknown');

        $this->actingAs($admin)
            ->get(route('admin.customer-360.show', ['platformCustomer' => $importPlatform->id]))
            ->assertOk()
            ->assertSee('استيراد');

        $admin->forceFill(['locale' => 'en'])->save();

        $this->actingAs($admin)
            ->get(route('admin.customer-360.index'))
            ->assertOk()
            ->assertSee('FOODEX · Platform')
            ->assertSee('Customer App')
            ->assertSee('Dashboard')
            ->assertSee('Import')
            ->assertSee('Migration')
            ->assertSee('Unknown')
            ->assertDontSee('legacy_unknown');

        $this->actingAs($admin)
            ->get(route('admin.customer-360.show', ['platformCustomer' => $importPlatform->id]))
            ->assertOk()
            ->assertSee('Import');

        $this->assertSame('dashboard', PlatformCustomer::query()
            ->where('user_id', $dashboardUser->id)
            ->value('registration_source'));
    }

    public function test_super_admin_can_provision_imported_customer_mobile_login(): void
    {
        $storeId = $this->store('B2C', 'MOBILE-CREDENTIALS', 'Mobile Credentials Store');
        $user = app(PlatformCustomerService::class)->register([
            'name' => 'Imported Mobile Customer',
            'email' => 'imported-mobile-seed@example.test',
            'phone' => '01145011964',
            'password' => 'SeedPassword123!',
            'locale' => 'ar',
            'store_id' => $storeId,
        ], 'import');
        $platform = PlatformCustomer::query()->where('user_id', $user->id)->firstOrFail();

        $user->forceFill([
            'email' => 'legacy-login-'.$user->id.'@contacts.foodex.local',
            'is_active' => false,
            'deactivated_at' => now(),
            'deactivation_reason' => 'Imported customer; mobile login not provisioned.',
        ])->save();
        $platform->forceFill(['email' => ''])->save();
        DB::table('customers')
            ->where('id', $platform->legacy_customer_id)
            ->update(['email' => null, 'updated_at' => now()]);

        $super = $this->globalAdmin('SUPER_ADMIN');

        $this->actingAs($super)
            ->patch(route('admin.customer-360.mobile-password.reset', ['platformCustomer' => $platform->id]), [
                'password' => 'BeforeEmail123!',
                'password_confirmation' => 'BeforeEmail123!',
            ])
            ->assertSessionHasErrors('password');

        $retailAdmin = $this->retailAdmin($storeId);
        $this->actingAs($retailAdmin)
            ->patch(route('admin.customer-360.identity.update', ['platformCustomer' => $platform->id]), [
                'name' => 'Blocked Update',
                'email' => 'blocked-update@example.test',
                'phone' => '01145011964',
            ])
            ->assertForbidden();

        $user->refresh()->createToken('pre-reset-mobile-session');
        $this->assertGreaterThan(0, $user->tokens()->count());

        $this->actingAs($super)
            ->patch(route('admin.customer-360.identity.update', ['platformCustomer' => $platform->id]), [
                'name' => 'Al Khaled',
                'email' => 'alkhaled.mobile@example.test',
                'phone' => '01145011964',
                'password' => 'MobileLogin95!',
                'password_confirmation' => 'MobileLogin95!',
            ])
            ->assertRedirect(route('admin.customer-360.show', ['platformCustomer' => $platform->id]).'#identity')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('platform_customers', [
            'id' => $platform->id,
            'name' => 'Al Khaled',
            'email' => 'alkhaled.mobile@example.test',
            'phone' => '01145011964',
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Al Khaled',
            'email' => 'alkhaled.mobile@example.test',
            'is_active' => 1,
            'is_platform_customer' => 1,
        ]);
        $this->assertDatabaseHas('customers', [
            'id' => $platform->legacy_customer_id,
            'name' => 'Al Khaled',
            'email' => 'alkhaled.mobile@example.test',
            'phone' => '01145011964',
        ]);

        $user->refresh();
        $platform->refresh();
        $this->assertTrue($user->is_active);
        $this->assertTrue($platform->is_active);
        $this->assertNull($user->deactivated_at);
        $this->assertNull($user->deactivation_reason);
        $this->assertSame(0, $user->tokens()->count());

        $this->postJson('/api/v1/auth/login', [
            'email' => 'alkhaled.mobile@example.test',
            'password' => 'MobileLogin95!',
            'app' => 'customer',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'alkhaled.mobile@example.test')
            ->assertJsonPath('user.platform_customer', true);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'customer360.identity_updated',
            'auditable_id' => $platform->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'customer360.mobile_password_reset',
            'auditable_id' => $user->id,
        ]);
    }

    public function test_retail_scope_hides_foreign_customer_store_and_invoice(): void
    {
        $storeA = $this->store('B2C', 'RETAIL-A', 'Retail A');
        $storeB = $this->store('B2C', 'RETAIL-B', 'Retail B');
        $user = $this->customer('Scoped Customer', 'scoped@example.test', $storeA);
        app(CustomerDomainResolver::class)->b2c($user, $storeB);
        $foreign = $this->customer('Foreign Customer', 'foreign@example.test', $storeB);
        $platform = PlatformCustomer::query()->where('user_id', $user->id)->firstOrFail();
        $foreignPlatform = PlatformCustomer::query()->where('user_id', $foreign->id)->firstOrFail();

        $storeADomain = (int) DB::table('b2c_customers')
            ->where('user_id', $user->id)
            ->where('store_id', $storeA)
            ->value('id');
        $storeBDomain = (int) DB::table('b2c_customers')
            ->where('user_id', $user->id)
            ->where('store_id', $storeB)
            ->value('id');

        $invoiceA = $this->invoice($platform, $storeADomain, $storeA, 'INV-A');
        $invoiceB = $this->invoice($platform, $storeBDomain, $storeB, 'INV-B');
        $admin = $this->retailAdmin($storeA);

        $this->actingAs($admin)
            ->get(route('admin.customer-360.index'))
            ->assertOk()
            ->assertSee('scoped@example.test')
            ->assertDontSee('foreign@example.test');

        $this->actingAs($admin)
            ->get(route('admin.customer-360.show', ['platformCustomer' => $platform->id]))
            ->assertOk()
            ->assertSee('Retail A')
            ->assertSee('#'.$invoiceA)
            ->assertDontSee('Wholesale account & finance')
            ->assertDontSee('Retail B');

        $this->actingAs($admin)
            ->get(route('admin.customer-360.show', ['platformCustomer' => $foreignPlatform->id]))
            ->assertNotFound();

        $this->actingAs($admin)
            ->get(route('admin.invoices.show', ['invoice' => $invoiceA]))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.invoices.show', ['invoice' => $invoiceB]))
            ->assertNotFound();
    }

    public function test_super_admin_sees_exact_origin_and_all_materialized_retail_stores(): void
    {
        $storeA = $this->store('B2C', 'RETAIL-A', 'Retail A');
        $storeB = $this->store('B2C', 'RETAIL-B', 'Retail B');
        $user = $this->customer('Unified Customer', 'unified@example.test', $storeA);
        app(CustomerDomainResolver::class)->b2c($user, $storeB);
        $platform = PlatformCustomer::query()->where('user_id', $user->id)->firstOrFail();
        $admin = $this->globalAdmin('SUPER_ADMIN');

        $this->actingAs($admin)
            ->get(route('admin.customer-360.show', ['platformCustomer' => $platform->id]))
            ->assertOk()
            ->assertSee('Retail A · RETAIL-A')
            ->assertSee('Retail B · RETAIL-B')
            ->assertSee('Accounts & finance');
    }

    public function test_dual_role_retail_owner_resolves_canonical_wholesale_finance_without_duplicate_identity(): void
    {
        $retailStoreId = $this->store('B2C', 'RETAIL-OWNER', 'Retail Owner Store');
        $retailStore = Store::query()->findOrFail($retailStoreId);
        $owner = User::query()->create([
            'name' => 'Dual Role Owner',
            'email' => 'dual-role-owner@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $retailRole = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $owner->id,
            'store_id' => $retailStoreId,
            'role_id' => $retailRole->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tierId = (int) DB::table('b2b_price_tiers')
            ->where('code', 'STANDARD')
            ->value('id');
        $domain = app(RetailWholesaleAccountService::class)
            ->ensureForStore($retailStore, $tierId, $owner);
        $platform = PlatformCustomer::query()
            ->where('user_id', $owner->id)
            ->firstOrFail();

        $this->assertNull($domain->user_id);
        $this->assertSame(
            $domain->id,
            app(CustomerDomainResolver::class)->existingB2b($owner)?->id,
        );

        DB::table('b2b_accounts')
            ->where('b2b_customer_id', $domain->id)
            ->update(['credit_limit' => 100, 'updated_at' => now()]);

        $principalStoreId = app(WholesalePrincipal::class)->storeId();
        app(B2bAccountLedgerService::class)->appendManual($domain, [
            'entry_type' => 'customer_credit',
            'credit' => 50,
            'debit' => 0,
            'currency' => 'EGP',
            'store_id' => $principalStoreId,
            'reference' => 'DUAL-ROLE-CREDIT',
        ], $owner);

        $beforeCustomers = DB::table('b2b_customers')->count();
        $beforeAccounts = DB::table('b2b_accounts')->count();

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/b2b/account-summary?store_id='.$principalStoreId)
            ->assertOk()
            ->assertJsonPath('data.customer_id', $domain->id)
            ->assertJsonPath('data.balance', 50)
            ->assertJsonPath('data.balance_direction', 'company_owes_customer')
            ->assertJsonPath('data.customer_credit_balance', 50);

        $this->assertSame($beforeCustomers, DB::table('b2b_customers')->count());
        $this->assertSame($beforeAccounts, DB::table('b2b_accounts')->count());

        $super = $this->globalAdmin('SUPER_ADMIN');
        $this->actingAs($super)
            ->get(route('admin.customer-360.show', ['platformCustomer' => $platform->id]))
            ->assertOk()
            ->assertSee('Accounts & finance')
            ->assertSee('Retail Owner Store')
            ->assertSee('Company owes customer')
            ->assertSee('50.00');

        $b2bAdmin = $this->globalAdmin('B2B_ADMIN');
        $this->actingAs($b2bAdmin)
            ->get(route('admin.customer-360.show', ['platformCustomer' => $platform->id]))
            ->assertOk()
            ->assertSee('Accounts & finance')
            ->assertDontSee('Retail Owner Store · RETAIL-OWNER');
    }

    public function test_super_admin_can_update_wholesale_credit_limit_and_api_summary_reflects_it(): void
    {
        $retailStoreId = $this->store('B2C', 'CREDIT-LIMIT-OWNER', 'Credit Limit Owner');
        $retailStore = Store::query()->findOrFail($retailStoreId);
        $owner = User::query()->create([
            'name' => 'Credit Limit Owner',
            'email' => 'credit-limit-owner@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $tierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');
        $domain = app(RetailWholesaleAccountService::class)->ensureForStore($retailStore, $tierId, $owner);
        $platform = PlatformCustomer::query()->where('user_id', $owner->id)->firstOrFail();
        $accountId = (int) DB::table('b2b_accounts')
            ->where('b2b_customer_id', $domain->id)
            ->value('id');

        $super = $this->globalAdmin('SUPER_ADMIN');

        $this->actingAs($super)
            ->patch(route('admin.customer-360.credit-limit.update', ['platformCustomer' => $platform->id]), [
                'credit_limit' => '12345.678',
            ])
            ->assertRedirect(route('admin.customer-360.show', ['platformCustomer' => $platform->id]).'#finance')
            ->assertSessionHas('status');

        $this->assertDatabaseHas('b2b_accounts', [
            'id' => $accountId,
            'b2b_customer_id' => $domain->id,
            'credit_limit' => '12345.678',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'customer360.credit_limit_updated',
            'auditable_id' => $accountId,
        ]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/b2b/account-summary?store_id='.app(WholesalePrincipal::class)->storeId())
            ->assertOk()
            ->assertJsonPath('data.credit_limit', 12345.678);

        $this->actingAs($super)
            ->patch(route('admin.customer-360.credit-limit.update', ['platformCustomer' => $platform->id]), [
                'credit_limit' => '-1',
            ])
            ->assertSessionHasErrors('credit_limit');

        $this->assertDatabaseHas('b2b_accounts', [
            'id' => $accountId,
            'credit_limit' => '12345.678',
        ]);
    }

    public function test_retail_admin_can_fully_manage_visible_customer_addresses_only(): void
    {
        $storeA = $this->store('B2C', 'ADDRESS-A', 'Address Store A');
        $storeB = $this->store('B2C', 'ADDRESS-B', 'Address Store B');
        $user = $this->customer('Address Customer', 'address-customer@example.test', $storeA);
        $foreign = $this->customer('Foreign Address Customer', 'address-foreign@example.test', $storeB);
        $platform = PlatformCustomer::query()->where('user_id', $user->id)->firstOrFail();
        $foreignPlatform = PlatformCustomer::query()->where('user_id', $foreign->id)->firstOrFail();
        $admin = $this->retailAdmin($storeA);

        $this->actingAs($admin)
            ->get(route('admin.customer-360.show', ['platformCustomer' => $platform->id]))
            ->assertOk()
            ->assertSee('Customer addresses')
            ->assertSee('Add new address');

        $this->actingAs($admin)
            ->from(route('admin.customer-360.show', ['platformCustomer' => $platform->id]).'#addresses')
            ->post(route('admin.customer-360.addresses.store', ['platformCustomer' => $platform->id]), [
                'label' => 'Invalid pin',
                'line1' => 'Street without coordinates',
                'city' => 'Cairo',
                'country_code' => 'EG',
                'location_source' => 'map_pin',
            ])
            ->assertSessionHasErrors(['latitude', 'longitude']);

        $this->assertDatabaseMissing('addresses', [
            'platform_customer_id' => $platform->id,
            'label' => 'Invalid pin',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.customer-360.addresses.store', ['platformCustomer' => $platform->id]), [
                'label' => 'Home',
                'recipient_name' => 'Address Customer',
                'delivery_phone' => '+201011111111',
                'line1' => 'Street 1',
                'city' => 'Cairo',
                'area' => 'Nasr City',
                'country_code' => 'EG',
                'latitude' => 30.04442,
                'longitude' => 31.235712,
                'location_source' => 'map_pin',
                'landmark' => 'Near the park',
            ])
            ->assertRedirect();

        $first = Address::query()
            ->where('platform_customer_id', $platform->id)
            ->where('label', 'Home')
            ->firstOrFail();

        $this->assertTrue((bool) $first->is_default);

        $this->actingAs($admin)
            ->patch(route('admin.customer-360.addresses.update', [
                'platformCustomer' => $platform->id,
                'address' => $first->id,
            ]), [
                'label' => 'Home updated',
                'line1' => 'Street 2',
                'city' => 'Cairo',
                'country_code' => 'EG',
                'latitude' => 30.0500000,
                'longitude' => 31.2400000,
                'location_source' => 'map_pin',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('addresses', [
            'id' => $first->id,
            'platform_customer_id' => $platform->id,
            'label' => 'Home updated',
            'line1' => 'Street 2',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.customer-360.addresses.store', ['platformCustomer' => $platform->id]), [
                'label' => 'Office',
                'line1' => 'Office Street',
                'city' => 'Cairo',
                'country_code' => 'EG',
            ])
            ->assertRedirect();

        $second = Address::query()
            ->where('platform_customer_id', $platform->id)
            ->where('label', 'Office')
            ->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.customer-360.addresses.default', [
                'platformCustomer' => $platform->id,
                'address' => $second->id,
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('addresses', ['id' => $first->id, 'is_default' => false]);
        $this->assertDatabaseHas('addresses', ['id' => $second->id, 'is_default' => true]);

        $this->actingAs($admin)
            ->delete(route('admin.customer-360.addresses.destroy', [
                'platformCustomer' => $platform->id,
                'address' => $second->id,
            ]))
            ->assertRedirect();

        $this->assertSoftDeleted('addresses', ['id' => $second->id]);
        $this->assertDatabaseHas('addresses', ['id' => $first->id, 'is_default' => true]);

        $this->actingAs($admin)
            ->post(route('admin.customer-360.addresses.store', ['platformCustomer' => $foreignPlatform->id]), [
                'line1' => 'Forbidden Street',
                'city' => 'Cairo',
                'country_code' => 'EG',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('addresses', [
            'platform_customer_id' => $foreignPlatform->id,
            'line1' => 'Forbidden Street',
        ]);
    }

    public function test_customer_360_map_search_exposes_distinct_states_and_rejects_stale_results(): void
    {
        $view = file_get_contents(resource_path('views/admin/customer-360-show.blade.php'));
        $this->assertIsString($view);

        $this->assertStringContainsString('data-address-map-search-state', $view);
        $this->assertStringContainsString('data-address-map-search-retry', $view);
        $this->assertStringContainsString('role="status" aria-live="polite"', $view);
        $this->assertStringContainsString('let searchRequestId = 0;', $view);
        $this->assertStringContainsString('let mapSessionId = 0;', $view);
        $this->assertStringContainsString('requestId === searchRequestId', $view);
        $this->assertStringContainsString('sessionId === mapSessionId', $view);
        $this->assertStringContainsString('activeForm === requestForm', $view);
        $this->assertStringContainsString('if (!response.ok) throw new Error(\'map-search-http-\' + response.status);', $view);
        $this->assertStringContainsString('runSearch(lastSearchQuery)', $view);

        $english = require lang_path('en/customer_360.php');
        $arabic = require lang_path('ar/customer_360.php');
        foreach (['map_searching', 'map_no_results', 'map_failed', 'map_retry', 'map_results_aria'] as $key) {
            $this->assertNotEmpty($english['addresses'][$key] ?? null);
            $this->assertNotEmpty($arabic['addresses'][$key] ?? null);
        }
    }

    public function test_b2b_admin_does_not_receive_exact_retail_origin_store(): void
    {
        $storeA = $this->store('B2C', 'RETAIL-A', 'Retail A');
        $user = $this->customer('Wholesale Customer', 'wholesale@example.test', $storeA);
        $platform = PlatformCustomer::query()->where('user_id', $user->id)->firstOrFail();

        $this->actingAs($this->globalAdmin('B2B_ADMIN'))
            ->get(route('admin.customer-360.show', ['platformCustomer' => $platform->id]))
            ->assertOk()
            ->assertSee('Accounts & finance')
            ->assertDontSee('Retail A · RETAIL-A');
    }

    public function test_finance_invoice_picker_searches_complete_history_and_revalidates_selected_scope(): void
    {
        $retailStoreId = $this->store('B2C', 'FINANCE-PICKER-OWNER', 'Finance Picker Owner');
        $retailStore = Store::query()->findOrFail($retailStoreId);
        $owner = User::query()->create([
            'name' => 'Finance Picker Owner',
            'email' => 'finance-picker-owner@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $tierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');
        $domain = app(RetailWholesaleAccountService::class)->ensureForStore($retailStore, $tierId, $owner);
        $platform = PlatformCustomer::query()->where('user_id', $owner->id)->firstOrFail();
        $wholesaleStoreId = app(WholesalePrincipal::class)->storeId();
        $super = $this->globalAdmin('SUPER_ADMIN');

        $invoiceIds = [];
        foreach (range(1, 30) as $sequence) {
            $invoiceIds[$sequence] = (int) DB::table('invoices')->insertGetId([
                'store_id' => $wholesaleStoreId,
                'customer_id' => $platform->legacy_customer_id,
                'platform_customer_id' => $platform->id,
                'b2b_customer_id' => $domain->id,
                'b2c_customer_id' => null,
                'invoice_number' => sprintf('FIN-PICK-%02d', $sequence),
                'status' => 'issued',
                'channel' => 'b2b',
                'currency' => 'EGP',
                'total' => 10 + $sequence,
                'issued_at' => now()->subDays($sequence),
                'created_at' => now()->subDays($sequence),
                'updated_at' => now()->subDays($sequence),
            ]);
        }

        $targetId = $invoiceIds[30];

        $this->actingAs($super)
            ->get(route('admin.customer-360.show', ['platformCustomer' => $platform->id]))
            ->assertOk()
            ->assertSee('data-finance-invoice-picker', false)
            ->assertSee('name="invoice_id"', false)
            ->assertDontSee('<select name="invoice_id"', false);

        $this->actingAs($super)
            ->getJson(route('admin.customer-360.finance-invoices', [
                'platformCustomer' => $platform->id,
                'q' => 'FIN-PICK-30',
                'entry_type' => 'payment',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('currency', 'EGP')
            ->assertJsonPath('results.0.id', $targetId)
            ->assertJsonPath('results.0.internal_number', 'FIN-PICK-30')
            ->assertJsonPath('results.0.status_label', 'Issued')
            ->assertJsonPath('results.0.outstanding', 40);

        $this->actingAs($super)
            ->getJson(route('admin.customer-360.finance-invoices', [
                'platformCustomer' => $platform->id,
                'q' => 'does-not-match',
                'selected_id' => $targetId,
            ]))
            ->assertOk()
            ->assertJsonCount(0, 'results')
            ->assertJsonPath('selected.id', $targetId);

        $this->actingAs($super)
            ->post(route('admin.customer-360.finance-entries.store', ['platformCustomer' => $platform->id]), [
                'entry_type' => 'payment',
                'direction' => 'credit',
                'amount' => '1.00',
                'invoice_id' => $targetId,
                'reference' => 'PICKER-VALID',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customer_account_ledger_entries', [
            'b2b_customer_id' => $domain->id,
            'invoice_id' => $targetId,
            'reference' => 'PICKER-VALID',
        ]);

        $foreignOwner = User::query()->create([
            'name' => 'Foreign Finance Owner',
            'email' => 'foreign-finance-picker@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $foreignRetailStoreId = $this->store('B2C', 'FINANCE-PICKER-FOREIGN', 'Foreign Finance Picker');
        $foreignDomain = app(RetailWholesaleAccountService::class)->ensureForStore(
            Store::query()->findOrFail($foreignRetailStoreId),
            $tierId,
            $foreignOwner,
        );
        $foreignPlatform = PlatformCustomer::query()->where('user_id', $foreignOwner->id)->firstOrFail();
        $foreignInvoiceId = (int) DB::table('invoices')->insertGetId([
            'store_id' => $wholesaleStoreId,
            'customer_id' => $foreignPlatform->legacy_customer_id,
            'platform_customer_id' => $foreignPlatform->id,
            'b2b_customer_id' => $foreignDomain->id,
            'b2c_customer_id' => null,
            'invoice_number' => 'FOREIGN-FINANCE-INVOICE',
            'status' => 'issued',
            'channel' => 'b2b',
            'currency' => 'EGP',
            'total' => 99,
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($super)
            ->post(route('admin.customer-360.finance-entries.store', ['platformCustomer' => $platform->id]), [
                'entry_type' => 'payment',
                'direction' => 'credit',
                'amount' => '1.00',
                'invoice_id' => $foreignInvoiceId,
                'reference' => 'PICKER-FORGED',
            ])
            ->assertSessionHasErrors('invoice_id');

        $this->assertDatabaseMissing('customer_account_ledger_entries', [
            'b2b_customer_id' => $domain->id,
            'reference' => 'PICKER-FORGED',
        ]);
    }

    public function test_customer_360_manual_finance_entry_uses_authoritative_ledger_currency_and_precision(): void
    {
        $retailStoreId = $this->store('B2C', 'FINANCE-CURRENCY-OWNER', 'Finance Currency Owner');
        $retailStore = Store::query()->findOrFail($retailStoreId);
        $owner = User::query()->create([
            'name' => 'Finance Currency Owner',
            'email' => 'finance-currency-owner@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $tierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');
        $domain = app(RetailWholesaleAccountService::class)->ensureForStore($retailStore, $tierId, $owner);
        $platform = PlatformCustomer::query()->where('user_id', $owner->id)->firstOrFail();
        $super = $this->globalAdmin('SUPER_ADMIN');

        $this->actingAs($super)
            ->get(route('admin.customer-360.show', ['platformCustomer' => $platform->id]))
            ->assertOk()
            ->assertSee('data-finance-authoritative-currency="EGP"', false)
            ->assertSee('step="0.01"', false)
            ->assertSee('Amounts are recorded in EGP with up to 2 decimal places.')
            ->assertDontSee('name="currency"', false);

        $this->actingAs($super)
            ->post(route('admin.customer-360.finance-entries.store', ['platformCustomer' => $platform->id]), [
                'entry_type' => 'customer_credit',
                'direction' => 'credit',
                'amount' => '12.34',
                'currency' => 'KWD',
                'reference' => 'AUTHORITATIVE-CURRENCY',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customer_account_ledger_entries', [
            'b2b_customer_id' => $domain->id,
            'entry_type' => 'customer_credit',
            'reference' => 'AUTHORITATIVE-CURRENCY',
            'credit' => 12.34,
            'currency' => 'EGP',
            'source' => 'dashboard_customer_360',
        ]);

        $this->actingAs($super)
            ->post(route('admin.customer-360.finance-entries.store', ['platformCustomer' => $platform->id]), [
                'entry_type' => 'customer_credit',
                'direction' => 'credit',
                'amount' => '1.001',
                'reference' => 'TOO-PRECISE',
            ])
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseMissing('customer_account_ledger_entries', [
            'b2b_customer_id' => $domain->id,
            'reference' => 'TOO-PRECISE',
        ]);

        try {
            app(B2bAccountLedgerService::class)->appendManual($domain, [
                'entry_type' => 'customer_credit',
                'credit' => 1,
                'debit' => 0,
                'currency' => 'KWD',
                'reference' => 'FORGED-SERVICE-CURRENCY',
            ], $super);
            $this->fail('The ledger service must reject a caller-supplied currency that differs from the account currency.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('currency', $exception->errors());
        }

        $this->assertDatabaseMissing('customer_account_ledger_entries', [
            'b2b_customer_id' => $domain->id,
            'reference' => 'FORGED-SERVICE-CURRENCY',
        ]);
    }

    private function customer(string $name, string $email, int $storeId): User
    {
        return app(PlatformCustomerService::class)->register([
            'name' => $name,
            'email' => $email,
            'phone' => '+201000000000',
            'password' => 'Password123!',
            'locale' => 'en',
            'store_id' => $storeId,
        ]);
    }

    private function store(string $type, string $code, string $name): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailAdmin(int $storeId): User
    {
        $user = User::query()->create([
            'name' => 'Retail Admin',
            'email' => "retail-{$storeId}@example.test",
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function globalAdmin(string $roleCode): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function invoice(
        PlatformCustomer $platform,
        int $domainId,
        int $storeId,
        string $number,
    ): int {
        return (int) DB::table('invoices')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $platform->legacy_customer_id,
            'b2c_customer_id' => $domainId,
            'platform_customer_id' => $platform->id,
            'invoice_number' => $number,
            'status' => 'issued',
            'channel' => 'b2c',
            'currency' => 'EGP',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'tax_total' => 0,
            'total' => 10,
            'issued_at' => now(),
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
