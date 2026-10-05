<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\StorefrontRevisionService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StorefrontPreviewConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_preview_resolves_draft_and_published_from_session_scope_only(): void
    {
        $storeA = $this->retailStore('PREVIEW-CONFIG-A');
        $storeB = $this->retailStore('PREVIEW-CONFIG-B');
        $this->seedStorefront($storeA, '#112233');
        $this->seedStorefront($storeB, '#999999');

        $admin = $this->storeAdmin($storeA, 'preview-config-admin@example.test');
        $customer = $this->user('Preview Config Customer', 'preview-config-customer@example.test');
        $this->retailCustomer($customer, $storeA);

        Sanctum::actingAs($admin);

        $draft = $this->postJson('/api/v1/admin/app-preview/storefront-revisions/draft', [
            'channel' => 'b2c',
            'store_id' => $storeA,
        ])->assertCreated();

        $payload = (array) $draft->json('data.payload');
        $payload['settings']['primary_color'] = '#445566';

        $this->patchJson(
            '/api/v1/admin/app-preview/storefront-revisions/'.$draft->json('data.revision_id'),
            ['payload' => $payload],
        )->assertOk();

        $token = $this->previewToken($customer, 'b2c', $storeA);
        $this->app['auth']->forgetGuards();

        $published = $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/app-preview/storefront-configuration?mode=published&store_id='.$storeB)
            ->assertOk()
            ->assertJsonPath('data.mode', 'published')
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.store_id', $storeA)
            ->assertJsonPath('data.channel', 'b2c')
            ->assertJsonPath('data.payload.settings.primary_color', '#112233')
            ->assertJsonPath('data.read_only', true);

        $draftResolved = $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/app-preview/storefront-configuration?mode=draft&store_id='.$storeB)
            ->assertOk()
            ->assertJsonPath('data.mode', 'draft')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.store_id', $storeA)
            ->assertJsonPath('data.payload.settings.primary_color', '#445566');

        $this->assertStringNotContainsString($token, (string) $published->getContent());
        $this->assertStringNotContainsString($token, (string) $draftResolved->getContent());
    }

    public function test_preview_configuration_requires_header_token_and_never_accepts_query_token(): void
    {
        $storeId = $this->retailStore('PREVIEW-CONFIG-HEADER');
        $this->seedStorefront($storeId, '#223344');

        $admin = $this->storeAdmin($storeId, 'preview-header-admin@example.test');
        $customer = $this->user('Preview Header Customer', 'preview-header-customer@example.test');
        $this->retailCustomer($customer, $storeId);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/app-preview/storefront-revisions/draft', [
            'channel' => 'b2c',
            'store_id' => $storeId,
        ])->assertCreated();

        $token = $this->previewToken($customer, 'b2c', $storeId);
        $this->app['auth']->forgetGuards();

        $this->getJson(
            '/api/v1/app-preview/storefront-configuration?mode=draft&preview_token='.$token,
        )->assertUnauthorized();

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/app-preview/storefront-configuration?mode=draft')
            ->assertOk();
    }

    public function test_missing_draft_is_explicit_and_never_falls_back_to_published(): void
    {
        $storeId = $this->retailStore('PREVIEW-CONFIG-NO-DRAFT');
        $this->seedStorefront($storeId, '#334455');

        $admin = $this->storeAdmin($storeId, 'preview-no-draft-admin@example.test');
        $customer = $this->user('Preview No Draft Customer', 'preview-no-draft-customer@example.test');
        $this->retailCustomer($customer, $storeId);

        app(StorefrontRevisionService::class)->ensurePublished($storeId, 'b2c', $admin);

        Sanctum::actingAs($admin);
        $token = $this->previewToken($customer, 'b2c', $storeId);
        $this->app['auth']->forgetGuards();

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/app-preview/storefront-configuration?mode=draft')
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('state', 'draft_unavailable')
            ->assertJsonPath('mode', 'draft')
            ->assertJsonPath('read_only', true);

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/app-preview/storefront-configuration?mode=published')
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    }

    public function test_stale_preview_sessions_cannot_resolve_configuration(): void
    {
        $storeId = $this->retailStore('PREVIEW-CONFIG-STALE');
        $this->seedStorefront($storeId, '#445566');

        $admin = $this->storeAdmin($storeId, 'preview-stale-admin@example.test');
        $customer = $this->user('Preview Stale Customer', 'preview-stale-customer@example.test');
        $this->retailCustomer($customer, $storeId);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/app-preview/storefront-revisions/draft', [
            'channel' => 'b2c',
            'store_id' => $storeId,
        ])->assertCreated();

        $expired = $this->previewToken($customer, 'b2c', $storeId);
        DB::table('app_preview_sessions')
            ->where('token_hash', hash('sha256', $expired))
            ->update(['expires_at' => now()->subMinute()]);

        $this->app['auth']->forgetGuards();
        $this->withHeader('X-Foodex-Preview-Token', $expired)
            ->getJson('/api/v1/app-preview/storefront-configuration?mode=draft')
            ->assertUnauthorized();

        Sanctum::actingAs($admin);
        $revoked = $this->previewToken($customer, 'b2c', $storeId);
        DB::table('app_preview_sessions')
            ->where('token_hash', hash('sha256', $revoked))
            ->update(['revoked_at' => now()]);

        $this->app['auth']->forgetGuards();
        $this->withHeader('X-Foodex-Preview-Token', $revoked)
            ->getJson('/api/v1/app-preview/storefront-configuration?mode=draft')
            ->assertUnauthorized();

        Sanctum::actingAs($admin);
        $permissionChanged = $this->previewToken($customer, 'b2c', $storeId);
        DB::table('user_store_roles')
            ->where('user_id', $admin->id)
            ->where('store_id', $storeId)
            ->delete();

        $this->app['auth']->forgetGuards();
        $this->withHeader('X-Foodex-Preview-Token', $permissionChanged)
            ->getJson('/api/v1/app-preview/storefront-configuration?mode=draft')
            ->assertForbidden();
    }

    public function test_b2b_preview_configuration_is_bound_to_canonical_wholesale_scope(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $admin = $this->roleUser('B2B_ADMIN', 'preview-config-b2b-admin@example.test');
        $customer = $this->user('Preview B2B Customer', 'preview-config-b2b-customer@example.test');
        $this->b2bCustomer($customer);

        $service = app(StorefrontRevisionService::class);
        $service->ensurePublished($storeId, 'b2b', $admin);
        $service->createOrReuseDraft($admin, $storeId, 'b2b');

        Sanctum::actingAs($admin);
        $token = $this->previewToken($customer, 'b2b', null);
        $this->app['auth']->forgetGuards();

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->getJson('/api/v1/app-preview/storefront-configuration?mode=draft&store_id=999999')
            ->assertOk()
            ->assertJsonPath('data.store_id', $storeId)
            ->assertJsonPath('data.channel', 'b2b')
            ->assertJsonPath('data.status', 'draft');
    }

    private function previewToken(User $customer, string $channel, ?int $storeId): string
    {
        $payload = [
            'target_user_id' => $customer->id,
            'target_type' => 'customer',
            'channel' => $channel,
        ];
        if ($storeId !== null) {
            $payload['store_id'] = $storeId;
        }

        $response = $this->postJson('/api/v1/admin/app-preview/sessions', $payload)
            ->assertCreated();

        return (string) $response->json('preview_token');
    }

    private function seedStorefront(int $storeId, string $primaryColor): void
    {
        DB::table('storefront_settings')->updateOrInsert(
            ['store_id' => $storeId],
            [
                'theme_code' => 'retail_grocery',
                'primary_color' => $primaryColor,
                'primary_dark_color' => null,
                'accent_color' => null,
                'background_color' => null,
                'header_address' => 'Kuwait',
                'branding' => json_encode(['brand_title_en' => 'Preview Store'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
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

    private function b2bCustomer(User $user): void
    {
        DB::table('b2b_customers')->insert([
            'legacy_customer_id' => null,
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
