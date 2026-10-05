<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\StorefrontRevisionService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AppPreviewGuestConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_guest_preview_resolves_draft_and_published_without_session(): void
    {
        $storeId = $this->retailStore('GUEST-CONFIG-A');
        $this->seedStorefront($storeId, '#112233');
        $admin = $this->storeAdmin($storeId, 'guest-config-a@example.test');

        $service = app(StorefrontRevisionService::class);
        $published = $service->ensurePublished($storeId, 'b2c', $admin);
        $draft = $service->createOrReuseDraft($admin, $storeId, 'b2c');
        $payload = $draft->payload;
        $payload['settings']['primary_color'] = '#445566';
        $service->updateDraft($admin, $draft, $payload);

        $this->actingAs($admin)
            ->getJson(route('admin.app-preview.storefront-configuration', [
                'channel' => 'b2c',
                'store_id' => $storeId,
                'mode' => 'published',
            ]))
            ->assertOk()
            ->assertJsonPath('data.revision_id', $published->public_id)
            ->assertJsonPath('data.payload.settings.primary_color', '#112233')
            ->assertJsonPath('data.persona', 'guest')
            ->assertJsonPath('data.read_only', true)
            ->assertJsonPath('data.mode', 'published');

        $this->actingAs($admin)
            ->getJson(route('admin.app-preview.storefront-configuration', [
                'channel' => 'b2c',
                'store_id' => $storeId,
                'mode' => 'draft',
            ]))
            ->assertOk()
            ->assertJsonPath('data.revision_id', $draft->public_id)
            ->assertJsonPath('data.payload.settings.primary_color', '#445566')
            ->assertJsonPath('data.mode', 'draft');

        $this->assertDatabaseCount('app_preview_sessions', 0);
    }

    public function test_retail_guest_preview_cannot_cross_store(): void
    {
        $storeA = $this->retailStore('GUEST-CONFIG-OWNED');
        $storeB = $this->retailStore('GUEST-CONFIG-FOREIGN');
        $this->seedStorefront($storeA, '#111111');
        $this->seedStorefront($storeB, '#222222');
        $admin = $this->storeAdmin($storeA, 'guest-config-owned@example.test');

        app(StorefrontRevisionService::class)->ensurePublished($storeB, 'b2c');

        $this->actingAs($admin)
            ->getJson(route('admin.app-preview.storefront-configuration', [
                'channel' => 'b2c',
                'store_id' => $storeB,
                'mode' => 'published',
            ]))
            ->assertForbidden();
    }

    public function test_guest_preview_missing_draft_is_explicit_and_published_still_resolves(): void
    {
        $storeId = $this->retailStore('GUEST-CONFIG-NO-DRAFT');
        $this->seedStorefront($storeId, '#334455');
        $admin = $this->storeAdmin($storeId, 'guest-config-no-draft@example.test');

        app(StorefrontRevisionService::class)->ensurePublished($storeId, 'b2c', $admin);

        $this->actingAs($admin)
            ->getJson(route('admin.app-preview.storefront-configuration', [
                'channel' => 'b2c',
                'store_id' => $storeId,
                'mode' => 'draft',
            ]))
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('state', 'draft_unavailable')
            ->assertJsonPath('mode', 'draft')
            ->assertJsonPath('read_only', true);

        $this->actingAs($admin)
            ->getJson(route('admin.app-preview.storefront-configuration', [
                'channel' => 'b2c',
                'store_id' => $storeId,
                'mode' => 'published',
            ]))
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    }

    public function test_wholesale_guest_preview_resolves_only_canonical_principal(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $admin = $this->roleUser('B2B_ADMIN', 'guest-config-b2b@example.test');

        $service = app(StorefrontRevisionService::class);
        $published = $service->ensurePublished($storeId, 'b2b', $admin);

        $this->actingAs($admin)
            ->getJson(route('admin.app-preview.storefront-configuration', [
                'channel' => 'b2b',
                'mode' => 'published',
            ]))
            ->assertOk()
            ->assertJsonPath('data.store_id', $storeId)
            ->assertJsonPath('data.channel', 'b2b')
            ->assertJsonPath('data.revision_id', $published->public_id);

        $this->actingAs($admin)
            ->getJson(route('admin.app-preview.storefront-configuration', [
                'channel' => 'b2b',
                'store_id' => $storeId + 999,
                'mode' => 'published',
            ]))
            ->assertNotFound();
    }

    public function test_super_admin_retail_guest_preview_requires_support_access_and_audits(): void
    {
        $storeId = $this->retailStore('GUEST-CONFIG-SUPPORT');
        $this->seedStorefront($storeId, '#556677');
        $admin = $this->roleUser('SUPER_ADMIN', 'guest-config-super@example.test');

        app(StorefrontRevisionService::class)->ensurePublished($storeId, 'b2c', $admin);

        $this->actingAs($admin)
            ->getJson(route('admin.app-preview.storefront-configuration', [
                'channel' => 'b2c',
                'store_id' => $storeId,
                'mode' => 'published',
            ]))
            ->assertForbidden();

        $this->actingAs($admin)
            ->getJson(route('admin.app-preview.storefront-configuration', [
                'channel' => 'b2c',
                'store_id' => $storeId,
                'mode' => 'published',
                'support_access' => 1,
            ]))
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'event' => 'tenant.support_access.entered',
            'auditable_id' => $storeId,
        ]);
    }

    public function test_guest_preview_rejects_incompatible_revision_schema(): void
    {
        $storeId = $this->retailStore('GUEST-CONFIG-SCHEMA');
        $this->seedStorefront($storeId, '#667788');
        $admin = $this->storeAdmin($storeId, 'guest-config-schema@example.test');

        $revision = app(StorefrontRevisionService::class)->ensurePublished($storeId, 'b2c', $admin);
        DB::table('storefront_revisions')
            ->where('id', $revision->id)
            ->update(['schema_version' => 999]);

        $this->actingAs($admin)
            ->getJson(route('admin.app-preview.storefront-configuration', [
                'channel' => 'b2c',
                'store_id' => $storeId,
                'mode' => 'published',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('schema_version');
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
