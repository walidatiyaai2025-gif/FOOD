<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppPreviewSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_admin_can_create_and_resolve_hashed_read_only_customer_preview(): void
    {
        $storeId = $this->retailStore('PREVIEW-A');
        $admin = $this->storeAdmin($storeId, 'preview-admin@example.test');
        $customer = $this->user('Preview Customer', 'preview-customer@example.test');
        $this->retailCustomer($customer, $storeId);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/v1/admin/app-preview/sessions', [
            'target_user_id' => $customer->id,
            'target_type' => 'customer',
            'channel' => 'b2c',
            'store_id' => $storeId,
        ])->assertCreated()
            ->assertJsonPath('data.read_only', true)
            ->assertJsonPath('data.auth_mode', 'platform_customer')
            ->assertJsonPath('data.channel', 'b2c')
            ->assertJsonPath('data.store_id', $storeId)
            ->assertJsonPath('data.commerce_context.channel', 'b2c')
            ->assertJsonPath('data.commerce_context.store_id', $storeId)
            ->assertJsonPath('data.target.user_id', $customer->id)
            ->assertJsonPath('token_type', 'Preview');

        $plainToken = (string) $response->json('preview_token');
        $this->assertNotSame('', $plainToken);

        $row = DB::table('app_preview_sessions')->first();
        $this->assertNotNull($row);
        $this->assertSame(hash('sha256', $plainToken), $row->token_hash);
        $this->assertNotSame($plainToken, $row->token_hash);

        $this->withHeader('X-Foodex-Preview-Token', $plainToken)
            ->postJson('/api/v1/app-preview/resolve')
            ->assertOk()
            ->assertJsonPath('data.target.user_id', $customer->id)
            ->assertJsonPath('data.auth_mode', 'platform_customer')
            ->assertJsonPath('data.commerce_context.channel', 'b2c')
            ->assertJsonPath('data.commerce_context.store_id', $storeId)
            ->assertJsonPath('data.read_only', true);

        // Sanctum::actingAs() stores the admin on the in-memory guard for this
        // test process. Clear that state so this request proves the opaque
        // preview token cannot authenticate a normal Sanctum route.
        $this->app['auth']->forgetGuards();

        $this->withToken($plainToken)
            ->getJson('/api/v1/profile')
            ->assertUnauthorized();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'event' => 'app_preview.session.created',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'event' => 'app_preview.session.resolved',
        ]);

        $auditJson = DB::table('audit_logs')
            ->whereIn('event', ['app_preview.session.created', 'app_preview.session.resolved'])
            ->get()
            ->map(fn (object $audit): string => json_encode($audit, JSON_THROW_ON_ERROR))
            ->implode('\n');
        $this->assertStringNotContainsString($plainToken, $auditJson);
    }

    public function test_retail_admin_cannot_create_preview_outside_assigned_store(): void
    {
        $storeA = $this->retailStore('PREVIEW-A');
        $storeB = $this->retailStore('PREVIEW-B');
        $admin = $this->storeAdmin($storeA, 'preview-isolation@example.test');
        $customer = $this->user('Other Store Customer', 'preview-other@example.test');
        $this->retailCustomer($customer, $storeB);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/app-preview/sessions', [
            'target_user_id' => $customer->id,
            'target_type' => 'customer',
            'channel' => 'b2c',
            'store_id' => $storeB,
        ])->assertForbidden();

        $this->assertDatabaseCount('app_preview_sessions', 0);
    }

    public function test_super_admin_retail_preview_requires_explicit_support_access(): void
    {
        $storeId = $this->retailStore('PREVIEW-SUPPORT');
        $admin = $this->roleUser('SUPER_ADMIN', 'preview-owner@example.test');
        $customer = $this->user('Support Customer', 'preview-support-customer@example.test');
        $this->retailCustomer($customer, $storeId);

        Sanctum::actingAs($admin);
        $payload = [
            'target_user_id' => $customer->id,
            'target_type' => 'customer',
            'channel' => 'b2c',
            'store_id' => $storeId,
        ];

        $this->postJson('/api/v1/admin/app-preview/sessions', $payload)->assertForbidden();

        $this->postJson('/api/v1/admin/app-preview/sessions', [
            ...$payload,
            'support_access' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'event' => 'tenant.support_access.entered',
        ]);
    }

    public function test_preview_session_can_be_revoked_and_then_cannot_resolve(): void
    {
        $storeId = $this->retailStore('PREVIEW-REVOKE');
        $admin = $this->storeAdmin($storeId, 'preview-revoke@example.test');
        $customer = $this->user('Revoked Customer', 'preview-revoked-customer@example.test');
        $this->retailCustomer($customer, $storeId);

        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/v1/admin/app-preview/sessions', [
            'target_user_id' => $customer->id,
            'target_type' => 'customer',
            'channel' => 'b2c',
            'store_id' => $storeId,
        ])->assertCreated();

        $token = (string) $created->json('preview_token');
        $sessionId = (int) DB::table('app_preview_sessions')->value('id');

        $this->deleteJson("/api/v1/admin/app-preview/sessions/{$sessionId}", [
            'reason' => 'finished_review',
        ])->assertNoContent();

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->postJson('/api/v1/app-preview/resolve')
            ->assertUnauthorized();

        $this->assertDatabaseHas('app_preview_sessions', [
            'id' => $sessionId,
            'revoked_reason' => 'finished_review',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'app_preview.session.revoked',
            'user_id' => $admin->id,
        ]);
    }

    public function test_expired_preview_token_cannot_resolve(): void
    {
        $storeId = $this->retailStore('PREVIEW-EXPIRED');
        $admin = $this->storeAdmin($storeId, 'preview-expired-admin@example.test');
        $customer = $this->user('Expired Customer', 'preview-expired-customer@example.test');
        $this->retailCustomer($customer, $storeId);

        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/v1/admin/app-preview/sessions', [
            'target_user_id' => $customer->id,
            'target_type' => 'customer',
            'channel' => 'b2c',
            'store_id' => $storeId,
        ])->assertCreated();

        $token = (string) $created->json('preview_token');
        DB::table('app_preview_sessions')->update(['expires_at' => now()->subMinute()]);

        $this->app['auth']->forgetGuards();
        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->postJson('/api/v1/app-preview/resolve')
            ->assertUnauthorized();
    }

    public function test_preview_resolve_rechecks_actor_access_and_target_activity(): void
    {
        $storeId = $this->retailStore('PREVIEW-REAUTH');
        $admin = $this->storeAdmin($storeId, 'preview-reauth-admin@example.test');
        $customer = $this->user('Reauth Customer', 'preview-reauth-customer@example.test');
        $this->retailCustomer($customer, $storeId);

        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/v1/admin/app-preview/sessions', [
            'target_user_id' => $customer->id,
            'target_type' => 'customer',
            'channel' => 'b2c',
            'store_id' => $storeId,
        ])->assertCreated();

        $token = (string) $created->json('preview_token');
        DB::table('user_store_roles')->where('user_id', $admin->id)->where('store_id', $storeId)->delete();

        $this->app['auth']->forgetGuards();
        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->postJson('/api/v1/app-preview/resolve')
            ->assertForbidden();

        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customer->update(['is_active' => false]);

        $this->app['auth']->forgetGuards();
        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->postJson('/api/v1/app-preview/resolve')
            ->assertUnauthorized();
    }

    public function test_driver_preview_requires_matching_active_driver_channel_and_store(): void
    {
        $wholesaleStoreId = app(WholesalePrincipal::class)->storeId();
        $admin = $this->roleUser('B2B_ADMIN', 'preview-b2b-admin@example.test');
        $driverUser = $this->roleUser('B2B_DRIVER', 'preview-b2b-driver@example.test');
        Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $wholesaleStoreId,
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/app-preview/sessions', [
            'target_user_id' => $driverUser->id,
            'target_type' => 'driver',
            'channel' => 'b2b',
        ])->assertNotFound()
            ->assertSee('Driver preview is available only for Retail (B2C) drivers.');

        $this->postJson('/api/v1/admin/app-preview/sessions', [
            'target_user_id' => $driverUser->id,
            'target_type' => 'driver',
            'channel' => 'b2c',
            'store_id' => $this->retailStore('PREVIEW-DRIVER-WRONG'),
        ])->assertForbidden();
    }

    private function storeAdmin(int $storeId, string $email): User
    {
        $user = $this->user('Retail Preview Admin', $email);
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
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
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

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

    private function retailCustomer(User $user, int $storeId): void
    {
        DB::table('b2c_customers')->insert([
            'legacy_customer_id' => null,
            'store_id' => $storeId,
            'user_id' => $user->id,
            'name' => $user->name,
            'phone' => null,
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
