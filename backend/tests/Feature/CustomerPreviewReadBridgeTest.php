<?php

namespace Tests\Feature;

use App\Models\B2cCustomer;
use App\Models\NotificationCampaign;
use App\Models\Role;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\B2cCustomerService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerPreviewReadBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_preview_reads_are_pinned_to_exact_store_and_reuse_customer_controllers(): void
    {
        $storeA = $this->store('B2C', 'PREVIEW-READ-A');
        $storeB = $this->store('B2C', 'PREVIEW-READ-B');
        $admin = $this->storeAdmin($storeA, 'preview-read-admin@example.test');
        $target = $this->user('Preview Read Customer', 'preview-read-customer@example.test');

        $customerA = app(B2cCustomerService::class)->create($storeA, [
            'name' => 'Preview Read Customer',
            'email' => $target->email,
        ], $target);
        $customerB = app(B2cCustomerService::class)->create($storeB, [
            'name' => 'Preview Read Customer',
            'email' => $target->email,
        ], $target);

        $orderA = $this->order($storeA, $customerA, 'PREVIEW-ORDER-A');
        $this->order($storeB, $customerB, 'PREVIEW-ORDER-B');

        $token = $this->customerPreviewToken(
            $admin,
            $target,
            'b2c',
            $storeA,
        );

        $this->app['auth']->forgetGuards();

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/app-preview/customer/profile')
            ->assertOk()
            ->assertJsonPath('customer.id', $customerA->id)
            ->assertJsonPath('customer.store_id', $storeA);

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/app-preview/customer/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $orderA)
            ->assertJsonPath('data.0.store_id', $storeA)
            ->assertJsonPath('data.0.channel', 'b2c');

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/app-preview/customer/orders?store_id='.$storeB)
            ->assertNotFound();
    }

    public function test_authenticated_preview_campaigns_use_selected_persona_and_store_scope(): void
    {
        $storeA = $this->store('B2C', 'PREVIEW-CAMPAIGN-A');
        $storeB = $this->store('B2C', 'PREVIEW-CAMPAIGN-B');
        $admin = $this->storeAdmin($storeA, 'preview-campaign-admin@example.test');
        $target = $this->user('Campaign Customer', 'preview-campaign-customer@example.test');
        app(B2cCustomerService::class)->create($storeA, [
            'name' => 'Campaign Customer',
            'email' => $target->email,
        ], $target);

        $eligible = NotificationCampaign::query()->create([
            'name' => 'Preview targeted campaign',
            'type' => 'promotion',
            'title_ar' => 'حملة المعاينة',
            'title_en' => 'Preview campaign',
            'body_ar' => 'بيانات حالية',
            'body_en' => 'Current authoritative data',
            'audience' => 'customer',
            'app' => 'customer',
            'target_channel' => 'b2c',
            'store_id' => $storeA,
            'delivery_channel' => 'in_app',
            'popup_frequency' => 'once_per_session',
            'schedule_kind' => 'once',
            'timezone' => 'Asia/Kuwait',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'next_run_at' => now(),
            'status' => 'active',
        ]);
        NotificationCampaign::query()->create([
            'name' => 'Foreign preview campaign',
            'type' => 'promotion',
            'title_ar' => 'متجر آخر',
            'title_en' => 'Foreign store',
            'body_ar' => 'غير مسموح',
            'body_en' => 'Must not leak',
            'audience' => 'all',
            'app' => 'customer',
            'target_channel' => 'b2c',
            'store_id' => $storeB,
            'delivery_channel' => 'in_app',
            'popup_frequency' => 'once_per_session',
            'schedule_kind' => 'once',
            'timezone' => 'Asia/Kuwait',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'next_run_at' => now(),
            'status' => 'active',
        ]);

        $token = $this->customerPreviewToken($admin, $target, 'b2c', $storeA);
        $this->app['auth']->forgetGuards();

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson(
                '/api/v1/app-preview/customer/notification-campaign-popups'
                .'?channel=b2c&store_id='.$storeA
                .'&locale=en&install_id=preview-840',
            )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $eligible->id)
            ->assertJsonPath('data.0.title', 'Preview campaign')
            ->assertJsonPath('data.0.store_id', $storeA);

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson(
                '/api/v1/app-preview/customer/notification-campaign-popups'
                .'?channel=b2c&store_id='.$storeB
                .'&locale=en&install_id=preview-840',
            )
            ->assertNotFound();

        $this->assertDatabaseCount('notification_campaign_popup_views', 0);
    }

    public function test_preview_credential_is_header_only_read_only_and_not_normal_bearer_auth(): void
    {
        $storeId = $this->store('B2C', 'PREVIEW-READ-SAFE');
        $admin = $this->storeAdmin($storeId, 'preview-safe-admin@example.test');
        $target = $this->user('Safe Customer', 'preview-safe-customer@example.test');
        app(B2cCustomerService::class)->create($storeId, [
            'name' => 'Safe Customer',
            'email' => $target->email,
        ], $target);

        $token = $this->customerPreviewToken(
            $admin,
            $target,
            'b2c',
            $storeId,
        );
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/app-preview/customer/profile')
            ->assertUnauthorized();

        $this->getJson('/api/v1/app-preview/customer/profile?preview_token='.$token)
            ->assertUnauthorized();

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->withToken('unrelated-production-bearer')
            ->getJson('/api/v1/app-preview/customer/profile')
            ->assertUnauthorized();

        $this->withToken($token)
            ->getJson('/api/v1/profile')
            ->assertUnauthorized();

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->patchJson('/api/v1/app-preview/customer/profile', [
                'name' => 'Must Not Change',
            ])
            ->assertStatus(405);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'name' => 'Safe Customer',
        ]);
    }

    public function test_preview_read_rechecks_revocation_and_actor_permission_on_every_request(): void
    {
        $storeId = $this->store('B2C', 'PREVIEW-READ-RECHECK');
        $admin = $this->storeAdmin($storeId, 'preview-recheck-admin@example.test');
        $target = $this->user('Recheck Customer', 'preview-recheck-customer@example.test');
        app(B2cCustomerService::class)->create($storeId, [
            'name' => 'Recheck Customer',
            'email' => $target->email,
        ], $target);

        $token = $this->customerPreviewToken(
            $admin,
            $target,
            'b2c',
            $storeId,
        );
        $this->app['auth']->forgetGuards();

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/app-preview/customer/profile')
            ->assertOk();

        DB::table('user_store_roles')
            ->where('user_id', $admin->id)
            ->where('store_id', $storeId)
            ->delete();

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/app-preview/customer/profile')
            ->assertForbidden();

        $roleId = (int) Role::query()
            ->where('code', 'B2C_STORE_ADMIN')
            ->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('app_preview_sessions')->update([
            'revoked_at' => now(),
            'revoked_reason' => 'test',
        ]);

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/app-preview/customer/profile')
            ->assertUnauthorized();
    }

    public function test_preview_read_cannot_cross_b2b_and_b2c_route_contracts(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $admin = $this->roleUser('B2B_ADMIN', 'preview-b2b-read-admin@example.test');
        $target = $this->user('Wholesale Preview', 'preview-b2b-read@example.test');
        app(B2bCustomerService::class)->create([
            'name' => 'Wholesale Preview',
            'email' => $target->email,
        ], $target);

        $token = $this->customerPreviewToken(
            $admin,
            $target,
            'b2b',
            $storeId,
        );
        $this->assertDatabaseHas('b2b_customers', [
            'user_id' => $target->id,
        ]);

        $this->app['auth']->forgetGuards();

        $response = $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/b2b/app-preview/customer/profile');

        $this->assertSame(200, $response->status(), $response->getContent());
        $response->assertJsonPath('customer.type', 'b2b');

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/app-preview/customer/profile')
            ->assertForbidden();
    }

    private function customerPreviewToken(
        User $admin,
        User $target,
        string $channel,
        int $storeId,
    ): string {
        Sanctum::actingAs($admin);

        $payload = [
            'target_user_id' => $target->id,
            'target_type' => 'customer',
            'channel' => $channel,
        ];
        if ($channel === 'b2c') {
            $payload['store_id'] = $storeId;
        }

        $response = $this->postJson(
            '/api/v1/admin/app-preview/sessions',
            $payload,
        )->assertCreated();

        return (string) $response->json('preview_token');
    }

    private function storeAdmin(int $storeId, string $email): User
    {
        $user = $this->user('Preview Store Admin', $email);
        $roleId = (int) Role::query()
            ->where('code', 'B2C_STORE_ADMIN')
            ->value('id');

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function roleUser(string $roleCode, string $email): User
    {
        $user = $this->user($roleCode, $email);
        $user->roles()->attach(
            Role::query()->where('code', $roleCode)->firstOrFail(),
        );

        return $user;
    }

    private function user(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => 'Password1234',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }

    private function store(string $channel, string $code): int
    {
        $typeId = (int) DB::table('store_types')
            ->where('code', $channel)
            ->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'advertising_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(
        int $storeId,
        B2cCustomer $customer,
        string $number,
    ): int {
        $legacyCustomerId = $customer->legacy_customer_id;
        if ($legacyCustomerId === null) {
            $legacyCustomerId = (int) DB::table('customers')->insertGetId([
                'user_id' => $customer->user_id,
                'type' => 'b2c',
                'name' => $customer->name,
                'email' => $customer->email,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $customer->update(['legacy_customer_id' => $legacyCustomerId]);
        }

        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $legacyCustomerId,
            'b2c_customer_id' => $customer->id,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 1,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
