<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AppPreviewDashboardBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_dashboard_target_discovery_is_exact_store_scoped(): void
    {
        $storeA = $this->retailStore('BRIDGE-A');
        $storeB = $this->retailStore('BRIDGE-B');
        $admin = $this->storeAdmin($storeA, 'bridge-admin@example.test');
        $customerA = $this->user('Bridge Customer A', 'bridge-a@example.test');
        $customerB = $this->user('Bridge Customer B', 'bridge-b@example.test');
        $this->retailCustomer($customerA, $storeA);
        $this->retailCustomer($customerB, $storeB);

        $this->actingAs($admin)
            ->getJson(route('admin.app-preview.targets', [
                'target_type' => 'customer',
                'channel' => 'b2c',
                'store_id' => $storeA,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $customerA->id)
            ->assertJsonPath('data.0.store_id', $storeA)
            ->assertJsonMissing(['user_id' => $customerB->id]);

        $this->actingAs($admin)
            ->getJson(route('admin.app-preview.targets', [
                'target_type' => 'customer',
                'channel' => 'b2c',
                'store_id' => $storeB,
            ]))
            ->assertForbidden();
    }

    public function test_dashboard_can_create_and_revoke_preview_session_without_rendering_token(): void
    {
        $storeId = $this->retailStore('BRIDGE-SESSION');
        $admin = $this->storeAdmin($storeId, 'bridge-session-admin@example.test');
        $customer = $this->user('Bridge Session Customer', 'bridge-session-customer@example.test');
        $this->retailCustomer($customer, $storeId);

        $created = $this->actingAs($admin)
            ->postJson(route('admin.app-preview.sessions.store'), [
                'target_user_id' => $customer->id,
                'target_type' => 'customer',
                'channel' => 'b2c',
                'store_id' => $storeId,
            ])
            ->assertCreated()
            ->assertJsonPath('data.target.user_id', $customer->id)
            ->assertJsonPath('data.read_only', true)
            ->assertJsonPath('credential_type', 'Preview');

        $token = (string) $created->json('credential');
        $publicId = (string) $created->json('data.session_id');

        $this->assertNotSame('', $token);
        $this->assertNotSame('', $publicId);
        $this->assertSame(
            hash('sha256', $token),
            DB::table('app_preview_sessions')->where('public_id', $publicId)->value('token_hash'),
        );

        $page = $this->actingAs($admin)
            ->get(route('admin.app-preview.index'))
            ->assertOk()
            ->assertDontSee($token, false)
            ->assertDontSee('preview_token', false);

        $this->assertStringNotContainsString($token, $page->getContent());

        $this->actingAs($admin)
            ->deleteJson(route('admin.app-preview.sessions.destroy', ['sessionId' => $publicId]), [
                'reason' => 'context_changed',
            ])
            ->assertNoContent();

        $this->assertDatabaseHas('app_preview_sessions', [
            'public_id' => $publicId,
            'revoked_reason' => 'context_changed',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'app_preview.session.revoked',
            'user_id' => $admin->id,
            'store_id' => $storeId,
        ]);
    }

    public function test_wholesale_driver_discovery_uses_active_driver_profile_and_permission(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $admin = $this->roleUser('B2B_ADMIN', 'bridge-b2b-admin@example.test');
        $driverUser = $this->roleUser('B2B_DRIVER', 'bridge-b2b-driver@example.test');
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.app-preview.targets', [
                'target_type' => 'driver',
                'channel' => 'b2b',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $driverUser->id)
            ->assertJsonPath('data.0.driver_id', $driver->id)
            ->assertJsonPath('data.0.store_id', $storeId);
    }

    public function test_dashboard_bridge_uses_ephemeral_message_handoff_and_rejects_insecure_runtime_origin(): void
    {
        $admin = $this->roleUser('B2B_ADMIN', 'bridge-runtime-admin@example.test');

        config()->set('app_preview.runtimes.customer.url', 'https://preview.example/customer');
        config()->set('app_preview.runtimes.customer.contract_version', 'shared-flutter-v1');

        $html = $this->actingAs($admin)
            ->get(route('admin.app-preview.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('foodex.preview.ready', $html);
        $this->assertStringContainsString('foodex.preview.bootstrap', $html);
        $this->assertStringContainsString('"available":true', $html);
        $this->assertStringNotContainsString('preview_token', $html);
        $this->assertStringNotContainsString('X-Foodex-Preview-Token', $html);
        $this->assertStringNotContainsString('localStorage', $html);
        $this->assertStringNotContainsString('sessionStorage', $html);

        config()->set('app_preview.runtimes.customer.url', 'http://preview.example/customer');

        $insecureHtml = $this->actingAs($admin)
            ->get(route('admin.app-preview.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('"origin":""', $insecureHtml);
        $this->assertStringContainsString('"available":false', $insecureHtml);
    }

    public function test_user_without_preview_permission_cannot_discover_or_create_targets(): void
    {
        $storeId = $this->retailStore('BRIDGE-DENIED');
        $user = $this->user('No Preview Bridge', 'bridge-denied@example.test');
        $customer = $this->user('Hidden Target', 'bridge-hidden@example.test');
        $this->retailCustomer($customer, $storeId);

        $this->actingAs($user)
            ->getJson(route('admin.app-preview.targets', [
                'target_type' => 'customer',
                'channel' => 'b2c',
                'store_id' => $storeId,
            ]))
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson(route('admin.app-preview.sessions.store'), [
                'target_user_id' => $customer->id,
                'target_type' => 'customer',
                'channel' => 'b2c',
                'store_id' => $storeId,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('app_preview_sessions', 0);
    }

    private function roleUser(string $roleCode, string $email): User
    {
        $user = $this->user($roleCode, $email);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function storeAdmin(int $storeId, string $email): User
    {
        $user = $this->user('Retail Bridge Admin', $email);
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
