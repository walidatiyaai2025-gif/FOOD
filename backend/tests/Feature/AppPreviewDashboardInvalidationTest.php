<?php

namespace Tests\Feature;

use App\Models\AppPreviewInvalidationEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AppPreviewDashboardInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_dashboard_feed_is_exact_store_scoped_and_creates_no_preview_session(): void
    {
        $storeA = $this->retailStore('GUEST-SSE-A');
        $storeB = $this->retailStore('GUEST-SSE-B');
        $admin = $this->storeAdmin($storeA, 'guest-sse-a@example.test');

        $eventA = $this->event($storeA, 'b2c', 'draft', str_repeat('a', 64));
        $this->event($storeB, 'b2c', 'draft', str_repeat('b', 64));

        $response = $this->actingAs($admin)
            ->withHeader('Accept', 'text/event-stream')
            ->get(route('admin.app-preview.events', [
                'channel' => 'b2c',
                'store_id' => $storeA,
            ]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringContainsString('id: '.$eventA->id, $content);
        $this->assertStringContainsString('"store_id":'.$storeA, $content);
        $this->assertStringNotContainsString('"store_id":'.$storeB, $content);
        foreach (['"payload"', '"email"', '"phone"', '"token"', '"credential"', '"authorization"', '"price"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($content));
        }
        $this->assertDatabaseCount('app_preview_sessions', 0);
    }

    public function test_retail_dashboard_feed_rejects_unassigned_store(): void
    {
        $storeA = $this->retailStore('GUEST-SSE-OWNED');
        $storeB = $this->retailStore('GUEST-SSE-FOREIGN');
        $admin = $this->storeAdmin($storeA, 'guest-sse-owned@example.test');

        $this->actingAs($admin)
            ->get(route('admin.app-preview.events', [
                'channel' => 'b2c',
                'store_id' => $storeB,
            ]))
            ->assertForbidden();
    }

    public function test_wholesale_dashboard_feed_is_bound_to_canonical_principal(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $admin = $this->roleUser('B2B_ADMIN', 'guest-sse-b2b@example.test');
        $event = $this->event($storeId, 'b2b', 'published', str_repeat('c', 64));

        $response = $this->actingAs($admin)
            ->get(route('admin.app-preview.events', ['channel' => 'b2b']))
            ->assertOk();

        $content = $response->streamedContent();
        $this->assertStringContainsString('id: '.$event->id, $content);
        $this->assertStringContainsString('"channel":"b2b"', $content);

        $this->actingAs($admin)
            ->get(route('admin.app-preview.events', [
                'channel' => 'b2b',
                'store_id' => $storeId + 999,
            ]))
            ->assertNotFound();
    }

    public function test_super_admin_retail_feed_requires_explicit_support_access_and_audits_it(): void
    {
        $storeId = $this->retailStore('GUEST-SSE-SUPPORT');
        $admin = $this->roleUser('SUPER_ADMIN', 'guest-sse-super@example.test');
        $this->event($storeId, 'b2c', 'draft', str_repeat('d', 64));

        $this->actingAs($admin)
            ->get(route('admin.app-preview.events', [
                'channel' => 'b2c',
                'store_id' => $storeId,
            ]))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.app-preview.events', [
                'channel' => 'b2c',
                'store_id' => $storeId,
                'support_access' => 1,
            ]))
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'event' => 'tenant.support_access.entered',
            'auditable_id' => $storeId,
        ]);
    }

    public function test_dashboard_feed_respects_last_event_id_and_requires_preview_permission(): void
    {
        $storeId = $this->retailStore('GUEST-SSE-CURSOR');
        $admin = $this->storeAdmin($storeId, 'guest-sse-cursor@example.test');
        $first = $this->event($storeId, 'b2c', 'draft', str_repeat('e', 64));
        $second = $this->event($storeId, 'b2c', 'published', str_repeat('f', 64));

        $response = $this->actingAs($admin)
            ->withHeader('Last-Event-ID', (string) $first->id)
            ->get(route('admin.app-preview.events', [
                'channel' => 'b2c',
                'store_id' => $storeId,
            ]))
            ->assertOk();

        $content = $response->streamedContent();
        $this->assertStringNotContainsString('id: '.$first->id."\n", $content);
        $this->assertStringContainsString('id: '.$second->id."\n", $content);

        $noPreviewUser = $this->user('No Preview', 'guest-sse-denied@example.test');
        $this->actingAs($noPreviewUser)
            ->get(route('admin.app-preview.events', [
                'channel' => 'b2c',
                'store_id' => $storeId,
            ]))
            ->assertForbidden();
    }

    private function event(int $storeId, string $channel, string $status, string $checksum): AppPreviewInvalidationEvent
    {
        return AppPreviewInvalidationEvent::query()->create([
            'event_name' => 'app.preview.configuration.updated',
            'channel' => $channel,
            'store_id' => $storeId,
            'revision_public_id' => fake()->uuid(),
            'revision_status' => $status,
            'checksum' => $checksum,
            'schema_version' => 1,
            'occurred_at' => now(),
        ]);
    }

    private function storeAdmin(int $storeId, string $email): User
    {
        $user = $this->user('Retail Preview Admin', $email);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
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
