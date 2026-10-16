<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Commercial\CommercialCustomer360Service;
use App\Models\B2bAccount;
use App\Models\B2cCustomer;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PlatformCustomer;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Models\WhatsAppModuleState;
use App\Services\B2bAccountLedgerService;
use App\Services\CustomerDomainResolver;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class CommercialCustomer360ConvergenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_canonical_retail_identity_orders_are_bounded_and_read_only(): void
    {
        [$actor, $buyer, $store, $customer] = $this->retail();
        for ($i = 1; $i <= 105; $i++) {
            DB::table('orders')->insert([
                'store_id' => $store, 'customer_id' => $customer->legacy_customer_id, 'b2c_customer_id' => $customer->id,
                'order_number' => 'CRM-'.$i, 'channel' => 'b2c', 'status' => 'pending', 'currency' => 'EGP',
                'grand_total' => 10, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $snapshot = app(CommercialCustomer360Service::class)->snapshot($actor, $store, ' B2C ', $buyer->id, 999);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame((int) $customer->id, $snapshot['identity']['domain_id']);
        $this->assertSame('Retail Buyer', $snapshot['profile']['name']);
        $this->assertSame('not_applicable', $snapshot['credit']['state']);
        $this->assertCount(100, $snapshot['orders']['items']);
        $next = app(CommercialCustomer360Service::class)->snapshot($actor, $store, 'b2c', $buyer->id, 100, $snapshot['orders']['next_before_id']);
        $this->assertCount(5, $next['orders']['items']);
        $this->assertNull($next['orders']['next_before_id']);
        $this->assertStringContainsString('order=', $snapshot['orders']['items'][0]['url']);
        $this->assertLessThanOrEqual(25, count($queries));
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^(insert|update|delete|replace)\b/i', $query['query']);
            $this->assertStringNotContainsString('enterprise_finance', $query['query']);
            $this->assertStringNotContainsString('whats_app_conversations', $query['query']);
        }
    }

    public function test_domain_identity_supports_customers_without_login_users_and_matches_user_lookup(): void
    {
        [$actor, $buyer, $store, $customer] = $this->retail();
        $service = app(CommercialCustomer360Service::class);
        $byUser = $service->snapshot($actor, $store, 'b2c', $buyer->id);
        $byDomain = $service->forDomain($actor, $store, 'b2c', $customer->id);
        $this->assertSame($byUser['identity'], $byDomain['identity']);
        $guest = B2cCustomer::query()->create(['store_id' => $store, 'name' => 'No login customer']);
        $snapshot = $service->forDomain($actor, $store, 'b2c', $guest->id);
        $this->assertNull($snapshot['identity']['user_id']);
        $this->assertSame('No login customer', $snapshot['profile']['name']);
        $this->assertSame([], $snapshot['orders']['items']);
        $this->assertSame(2, B2cCustomer::query()->count());
    }

    public function test_cross_store_and_channel_denial_and_no_identity_materialization(): void
    {
        [$actor, $buyer, $store] = $this->retail();
        $foreign = $this->store('B2C', 'FOREIGN');
        $foreignBuyer = $this->user('Foreign');
        B2cCustomer::query()->create(['user_id' => $foreignBuyer->id, 'store_id' => $foreign, 'name' => 'Private']);
        foreach ([[$foreign, 'b2c', $buyer->id], [$store, 'b2b', $buyer->id], [$store, 'b2c', $foreignBuyer->id]] as [$storeId, $channel, $userId]) {
            try {
                app(CommercialCustomer360Service::class)->snapshot($actor, $storeId, $channel, $userId);
                $this->fail('Expected scope denial.');
            } catch (NotFoundHttpException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(2, B2cCustomer::query()->count());
        $this->assertSame(0, DB::table('platform_customers')->count());
        $this->assertSame(0, DB::table('b2b_customers')->count());
    }

    public function test_sections_without_permissions_do_not_query_their_sources(): void
    {
        [$actor, $buyer, $store] = $this->retail();
        $role = Role::query()->create(['code' => 'CRM_PROFILE', 'name' => 'CRM profile', 'scope' => 'store', 'is_active' => true]);
        DB::table('permission_role')->insert(['role_id' => $role->id, 'permission_id' => DB::table('permissions')->where('code', 'customers.view')->value('id')]);
        DB::table('user_store_roles')->where('user_id', $actor->id)->update(['role_id' => $role->id]);
        $actor = $actor->fresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $snapshot = app(CommercialCustomer360Service::class)->snapshot($actor, $store, 'b2c', $buyer->id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame('forbidden', $snapshot['orders']['state']);
        $this->assertSame([], $snapshot['orders']['items']);
        $this->assertSame('forbidden', $snapshot['communications']['state']);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('from "orders"', $query['query']);
            $this->assertStringNotContainsString('whats_app_', $query['query']);
        }
    }

    public function test_credit_matches_canonical_ledger_and_foreign_wholesale_relationship_is_denied(): void
    {
        $buyer = $this->user('Wholesale Buyer');
        $legacy = Customer::query()->create(['user_id' => $buyer->id, 'type' => 'b2b', 'name' => 'Wholesale Buyer']);
        B2bAccount::query()->create(['customer_id' => $legacy->id, 'company_name' => 'Buyer', 'status' => 'active', 'credit_limit' => 100]);
        $customer = app(CustomerDomainResolver::class)->b2b($buyer);
        $store = app(WholesalePrincipal::class)->storeId();
        $actor = $this->user('Wholesale Admin');
        $actor->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->value('id'));
        Invoice::query()->create([
            'store_id' => $store, 'customer_id' => $legacy->id, 'b2b_customer_id' => $customer->id,
            'invoice_number' => 'CRM-INV', 'status' => 'issued', 'channel' => 'b2b', 'currency' => 'EGP',
            'total' => 40, 'issued_at' => now()->subDay(), 'due_at' => now()->addWeek(),
        ]);
        $snapshot = app(CommercialCustomer360Service::class)->snapshot($actor, $store, 'b2b', $buyer->id);
        $canonical = app(B2bAccountLedgerService::class)->summary($customer, $store);
        $this->assertSame('current_read', $snapshot['credit']['state']);
        $this->assertSame((float) $canonical['credit_limit'], $snapshot['credit']['snapshot']['credit_limit']);
        $this->assertSame((float) $canonical['outstanding_receivable'], $snapshot['credit']['snapshot']['aggregate_outstanding']);
        $this->assertSame((float) $canonical['customer_credit_balance'], $snapshot['credit']['snapshot']['customer_credit_balance']);
        $retailStore = $this->store('B2C', 'SAME-BUYER-RETAIL');
        B2cCustomer::query()->create(['user_id' => $buyer->id, 'store_id' => $retailStore, 'name' => 'Same buyer retail profile']);
        UserStoreRole::query()->create(['user_id' => $actor->id, 'store_id' => $retailStore, 'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id')]);
        $retailSnapshot = app(CommercialCustomer360Service::class)->snapshot($actor->fresh(), $retailStore, 'b2c', $buyer->id);
        $this->assertSame($snapshot['identity']['user_id'], $retailSnapshot['identity']['user_id']);
        $this->assertSame('b2c', $retailSnapshot['identity']['domain_type']);
        $this->expectException(NotFoundHttpException::class);
        app(CommercialCustomer360Service::class)->snapshot($actor, $this->store('B2B', 'FOREIGN-WHOLESALE'), 'b2b', $buyer->id);
    }

    public function test_optional_communications_are_disabled_without_source_queries_and_operational_references_are_scoped(): void
    {
        [$actor, $buyer, $store, $customer] = $this->retail();
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->value('id'));
        $actor = $actor->fresh();
        $service = app(CommercialCustomer360Service::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $disabled = $service->snapshot($actor, $store, 'b2c', $buyer->id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame('disabled', $disabled['communications']['state']);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('whats_app_conversations', $query['query']);
            $this->assertStringNotContainsString('platform_customers', $query['query']);
        }
        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST', 'capabilities' => ['agent_inbox' => true],
        ])->save();
        $missing = $service->snapshot($actor, $store, 'b2c', $buyer->id);
        $this->assertSame('missing', $missing['communications']['state']);
        $platform = PlatformCustomer::query()->create([
            'user_id' => $buyer->id, 'legacy_customer_id' => $customer->legacy_customer_id,
            'name' => 'Buyer', 'email' => $buyer->email, 'phone' => '+96550000000', 'origin_channel' => 'b2c', 'origin_store_id' => $store,
            'registration_source' => 'dashboard', 'registered_at' => now(), 'is_active' => true,
        ]);
        $foreign = $this->store('B2C', 'FOREIGN-COMMS');
        foreach ([$store, $foreign] as $storeId) {
            DB::table('whats_app_conversations')->insert([
                'public_id' => (string) Str::uuid(),
                'store_id' => $storeId, 'platform_customer_id' => $platform->id,
                'status' => 'WAITING_FOR_AGENT', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $snapshot = $service->snapshot($actor, $store, 'b2c', $buyer->id);
        $this->assertCount(1, $snapshot['communications']['items']);
        $this->assertStringContainsString('conversation=', $snapshot['communications']['items'][0]['url']);
        $actor->roles()->detach(Role::query()->where('code', 'SUPER_ADMIN')->value('id'));
        DB::table('permission_role')->insertOrIgnore([
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
            'permission_id' => DB::table('permissions')->where('code', 'whatsapp.conversations.view')->value('id'),
        ]);
        DB::table('whats_app_conversations')->where('store_id', $store)->update(['primary_agent_id' => $buyer->id]);
        $private = $service->snapshot($actor->fresh(), $store, 'b2c', $buyer->id);
        $this->assertSame([], $private['communications']['items']);
        // Missing optional source cannot corrupt the profile/order read or fabricate live data.
        Schema::drop('whats_app_conversations');
        $degraded = $service->snapshot($actor, $store, 'b2c', $buyer->id);
        $this->assertSame('current_read', $degraded['state']);
        $this->assertSame('unavailable', $degraded['communications']['state']);
        $this->assertSame([], $degraded['communications']['items']);
    }

    /** @return array{0:User,1:User,2:int,3:B2cCustomer} */
    private function retail(): array
    {
        $store = $this->store('B2C', 'CRM-RETAIL');
        $actor = $this->user('Retail Admin');
        UserStoreRole::query()->create(['user_id' => $actor->id, 'store_id' => $store, 'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id')]);
        $buyer = $this->user('Retail Buyer');
        $legacy = Customer::query()->create(['user_id' => $buyer->id, 'type' => 'b2c', 'name' => 'Retail Buyer']);
        $customer = B2cCustomer::query()->create(['user_id' => $buyer->id, 'store_id' => $store, 'legacy_customer_id' => $legacy->id, 'name' => 'Retail Buyer']);

        return [$actor, $buyer, $store, $customer];
    }

    private function user(string $name): User
    {
        return User::query()->create(['name' => $name, 'email' => strtolower(str_replace(' ', '-', $name)).'@crm.example.test', 'password' => 'password', 'is_active' => true]);
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'code' => $code, 'name' => $code, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
