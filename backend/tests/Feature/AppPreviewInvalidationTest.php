<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\StorefrontRevisionService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppPreviewInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_preview_event_feed_is_store_scoped_and_cursor_safe(): void
    {
        $storeA = $this->retailStore('SSE-A');
        $storeB = $this->retailStore('SSE-B');
        $adminA = $this->storeAdmin($storeA, 'sse-a@example.test');
        $adminB = $this->storeAdmin($storeB, 'sse-b@example.test');
        $customer = $this->user('SSE Customer', 'sse-customer@example.test');
        $this->retailCustomer($customer, $storeA);

        $service = app(StorefrontRevisionService::class);
        $this->seedStorefront($storeA, '#111111');
        $this->seedStorefront($storeB, '#222222');

        $draftA = $service->createOrReuseDraft($adminA, $storeA, 'b2c');
        $payloadA = $draftA->payload;
        $payloadA['settings']['primary_color'] = '#333333';
        $service->updateDraft($adminA, $draftA, $payloadA);

        $draftB = $service->createOrReuseDraft($adminB, $storeB, 'b2c');
        $payloadB = $draftB->payload;
        $payloadB['settings']['primary_color'] = '#444444';
        $service->updateDraft($adminB, $draftB, $payloadB);

        Sanctum::actingAs($adminA);
        $token = $this->previewToken($customer, $storeA);
        $this->app['auth']->forgetGuards();

        $response = $this->withHeaders([
            'Accept' => 'text/event-stream',
            'X-Foodex-Preview-Token' => $token,
        ])->get('/api/v1/app-preview/events')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('"store_id":'.$storeA, $content);
        $this->assertStringNotContainsString('"store_id":'.$storeB, $content);
        $this->assertStringNotContainsString($token, $content);
        $this->assertStringNotContainsString('SSE Customer', $content);
        foreach (['"payload"', '"email"', '"phone"', '"token"', '"credential"', '"authorization"', '"price"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($content));
        }

        $lastId = (int) DB::table('app_preview_invalidation_events')
            ->where('store_id', $storeA)
            ->max('id');

        $cursorResponse = $this->withHeaders([
            'Accept' => 'text/event-stream',
            'X-Foodex-Preview-Token' => $token,
            'Last-Event-ID' => (string) $lastId,
        ])->get('/api/v1/app-preview/events')
            ->assertOk();

        $cursorContent = $cursorResponse->streamedContent();
        $this->assertStringNotContainsString('id: '.$lastId."\n", $cursorContent);
        $this->assertStringContainsString(': heartbeat ', $cursorContent);
    }

    public function test_draft_update_emits_only_when_checksum_changes(): void
    {
        $storeId = $this->retailStore('SSE-CHECKSUM');
        $this->seedStorefront($storeId, '#123456');
        $admin = $this->storeAdmin($storeId, 'sse-checksum@example.test');

        $service = app(StorefrontRevisionService::class);
        $draft = $service->createOrReuseDraft($admin, $storeId, 'b2c');

        $before = DB::table('app_preview_invalidation_events')->count();
        $service->updateDraft($admin, $draft, $draft->payload);
        $this->assertSame($before, DB::table('app_preview_invalidation_events')->count());

        $payload = $draft->payload;
        $payload['settings']['primary_color'] = '#ABCDEF';
        $service->updateDraft($admin, $draft->fresh(), $payload);

        $this->assertSame($before + 1, DB::table('app_preview_invalidation_events')->count());
        $this->assertDatabaseHas('app_preview_invalidation_events', [
            'store_id' => $storeId,
            'channel' => 'b2c',
            'revision_status' => 'draft',
        ]);
    }

    public function test_preview_event_feed_rejects_query_token_and_stale_session(): void
    {
        $storeId = $this->retailStore('SSE-AUTH');
        $this->seedStorefront($storeId, '#555555');
        $admin = $this->storeAdmin($storeId, 'sse-auth@example.test');
        $customer = $this->user('SSE Auth Customer', 'sse-auth-customer@example.test');
        $this->retailCustomer($customer, $storeId);

        Sanctum::actingAs($admin);
        $token = $this->previewToken($customer, $storeId);
        $this->app['auth']->forgetGuards();

        $this->get('/api/v1/app-preview/events?preview_token='.$token)
            ->assertUnauthorized();

        DB::table('app_preview_sessions')
            ->where('token_hash', hash('sha256', $token))
            ->update(['revoked_at' => now()]);

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->get('/api/v1/app-preview/events')
            ->assertUnauthorized();
    }

    private function previewToken(User $customer, int $storeId): string
    {
        return (string) $this->postJson('/api/v1/admin/app-preview/sessions', [
            'target_user_id' => $customer->id,
            'target_type' => 'customer',
            'channel' => 'b2c',
            'store_id' => $storeId,
        ])->assertCreated()->json('preview_token');
    }

    private function seedStorefront(int $storeId, string $primary): void
    {
        DB::table('storefront_settings')->updateOrInsert(
            ['store_id' => $storeId],
            [
                'theme_code' => 'retail_grocery',
                'primary_color' => $primary,
                'primary_dark_color' => null,
                'accent_color' => null,
                'background_color' => null,
                'header_address' => 'Kuwait',
                'branding' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function storeAdmin(int $storeId, string $email): User
    {
        $user = $this->user('Retail Admin', $email);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
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
