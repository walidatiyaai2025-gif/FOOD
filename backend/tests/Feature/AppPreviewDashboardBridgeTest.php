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

    public function test_wholesale_driver_discovery_is_empty_after_van_fulfillment_cutover(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $admin = $this->roleUser('B2B_ADMIN', 'bridge-b2b-admin@example.test');
        $driverUser = $this->roleUser('B2B_DRIVER', 'bridge-b2b-driver@example.test');
        Driver::query()->create([
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
            ->assertJsonCount(0, 'data');
    }

    public function test_driver_discovery_is_deterministic_and_exact_store_scoped_for_auto_launch(): void
    {
        $storeA = $this->retailStore('BRIDGE-DRIVER-A');
        $storeB = $this->retailStore('BRIDGE-DRIVER-B');
        $admin = $this->storeAdmin($storeA, 'bridge-driver-admin@example.test');

        $inactiveUser = $this->roleUser('B2C_DRIVER', 'bridge-driver-inactive@example.test');
        Driver::query()->create([
            'user_id' => $inactiveUser->id,
            'store_id' => $storeA,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => false,
        ]);

        $firstUser = $this->roleUser('B2C_DRIVER', 'bridge-driver-first@example.test');
        $firstDriver = Driver::query()->create([
            'user_id' => $firstUser->id,
            'store_id' => $storeA,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        $secondUser = $this->roleUser('B2C_DRIVER', 'bridge-driver-second@example.test');
        $secondDriver = Driver::query()->create([
            'user_id' => $secondUser->id,
            'store_id' => $storeA,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        $otherStoreUser = $this->roleUser('B2C_DRIVER', 'bridge-driver-other-store@example.test');
        $otherStoreDriver = Driver::query()->create([
            'user_id' => $otherStoreUser->id,
            'store_id' => $storeB,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.app-preview.targets', [
                'target_type' => 'driver',
                'channel' => 'b2c',
                'store_id' => $storeA,
            ]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.user_id', $firstUser->id)
            ->assertJsonPath('data.0.driver_id', $firstDriver->id)
            ->assertJsonPath('data.1.user_id', $secondUser->id)
            ->assertJsonPath('data.1.driver_id', $secondDriver->id)
            ->assertJsonMissing(['driver_id' => $otherStoreDriver->id]);
    }

    public function test_dashboard_bridge_uses_ephemeral_message_handoff_and_rejects_insecure_runtime_origin(): void
    {
        $admin = $this->roleUser('B2B_ADMIN', 'bridge-runtime-admin@example.test');

        config()->set('app_preview.runtimes.customer.url', 'https://preview.example/customer');
        config()->set('app_preview.runtimes.customer.contract_version', 'shared-flutter-v1');
        config()->set('app_preview.runtimes.customer.allowed_origin', 'https://preview.example');

        $html = $this->actingAs($admin)
            ->get(route('admin.app-preview.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('foodex.preview.ready', $html);
        $this->assertStringContainsString('foodex.preview.bootstrap', $html);
        $this->assertStringContainsString('"available":true', $html);
        $this->assertStringNotContainsString('preview_token', $html);
        $this->assertStringNotContainsString('X-Foodex-Preview-Token', $html);

        preg_match(
            '/<script id="foodex-preview-bridge">(.*?)<\\/script>/s',
            $html,
            $bridgeMatch,
        );
        $this->assertArrayHasKey(1, $bridgeMatch);
        $bridgeScript = (string) $bridgeMatch[1];
        $this->assertStringNotContainsString('localStorage', $bridgeScript);
        $this->assertStringNotContainsString('sessionStorage', $bridgeScript);
        $this->assertStringNotContainsString("searchParams.set('credential'", $bridgeScript);
        $this->assertStringNotContainsString("searchParams.set('preview_token'", $bridgeScript);
        $this->assertStringNotContainsString('JSON.stringify(bootstrap)', $bridgeScript);
        $this->assertStringNotContainsString('JSON.parse(message)', $bridgeScript);
        $this->assertStringContainsString('postMessage(', $bridgeScript);
        $this->assertStringContainsString('bootstrap,', $bridgeScript);
        $this->assertStringContainsString('event.source !== runtimeFrame.contentWindow', $bridgeScript);

        config()->set('app_preview.runtimes.customer.allowed_origin', 'https://other.example');

        $mismatchedHtml = $this->actingAs($admin)
            ->get(route('admin.app-preview.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('"origin":""', $mismatchedHtml);
        $this->assertStringContainsString('"available":false', $mismatchedHtml);

        config()->set('app_preview.runtimes.customer.allowed_origin', 'https://preview.example');
        config()->set('app_preview.runtimes.customer.url', 'http://preview.example/customer');

        $insecureHtml = $this->actingAs($admin)
            ->get(route('admin.app-preview.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('"origin":""', $insecureHtml);
        $this->assertStringContainsString('"available":false', $insecureHtml);
    }

    public function test_preview_inspector_is_source_gated_sanitized_versioned_and_localized(): void
    {
        $admin = $this->roleUser('B2B_ADMIN', 'bridge-inspector-admin@example.test');

        config()->set('app_preview.runtimes.customer.url', 'https://preview.example/customer');
        config()->set('app_preview.runtimes.customer.contract_version', 'shared-flutter-v1');
        config()->set('app_preview.runtimes.customer.allowed_origin', 'https://preview.example');

        app()->setLocale('en');
        $english = $this->actingAs($admin)
            ->get(route('admin.app-preview.index'))
            ->assertOk()
            ->assertSee('Preview Inspector')
            ->assertSee('Export diagnostic JSON')
            ->getContent();

        preg_match(
            '/<script id="foodex-preview-bridge">(.*?)<\\/script>/s',
            $english,
            $bridgeMatch,
        );
        $this->assertArrayHasKey(1, $bridgeMatch);
        $bridgeScript = (string) $bridgeMatch[1];

        $gatePosition = strpos($bridgeScript, 'event.source !== runtimeFrame.contentWindow');
        $statusPosition = strpos($bridgeScript, "message.type === 'foodex.preview.status'");
        $inspectorPosition = strpos($bridgeScript, 'updateInspectorFromStatus(message)');

        $this->assertNotFalse($gatePosition);
        $this->assertNotFalse($statusPosition);
        $this->assertNotFalse($inspectorPosition);
        $this->assertLessThan($statusPosition, $gatePosition);
        $this->assertLessThan($inspectorPosition, $statusPosition);

        $start = strpos($bridgeScript, "const diagnosticSchema = 'foodex.preview.diagnostic.v1'");
        $end = strpos($bridgeScript, 'const restoreUnavailable', $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $diagnosticScript = substr($bridgeScript, $start, $end - $start);

        $this->assertStringContainsString('sanitizeInspectorStatus', $diagnosticScript);
        $this->assertStringContainsString('safeEndpoint', $diagnosticScript);
        $this->assertStringContainsString('safeCapabilities', $diagnosticScript);
        $this->assertStringContainsString('JSON.stringify(buildDiagnostic(), null, 2)', $diagnosticScript);
        $this->assertStringContainsString("download = 'foodex-preview-diagnostic.json'", $diagnosticScript);

        foreach ([
            'credential',
            'authorization',
            'password',
            'secret',
            'request_body',
            'response_body',
            'latitude',
            'longitude',
            'localStorage',
            'sessionStorage',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $diagnosticScript);
        }

        $this->assertGreaterThanOrEqual(
            3,
            substr_count($bridgeScript, "clearInspector('unavailable')"),
        );

        app()->setLocale('ar');
        $this->assertSame('فاحص المعاينة', __('admin.preview_center.inspector'));
        $this->assertSame('تصدير JSON للتشخيص', __('admin.preview_center.export_diagnostic'));
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
