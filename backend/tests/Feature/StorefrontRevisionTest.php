<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StorefrontRevision;
use App\Models\User;
use App\Services\StorefrontRevisionService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StorefrontRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_draft_is_isolated_until_publish_and_rollback_restores_deterministic_snapshot(): void
    {
        $storeId = $this->retailStore('REV-RETAIL-A');
        $this->seedStorefront($storeId, '#112233', 'Published section');
        $admin = $this->storeAdmin($storeId, 'revision-admin@example.test');

        Sanctum::actingAs($admin);

        $draftResponse = $this->postJson('/api/v1/admin/app-preview/storefront-revisions/draft', [
            'channel' => 'b2c',
            'store_id' => $storeId,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.payload.settings.primary_color', '#112233');

        $draftId = (string) $draftResponse->json('data.revision_id');
        $baseline = StorefrontRevision::query()
            ->where('store_id', $storeId)
            ->where('channel', 'b2c')
            ->where('status', 'published')
            ->firstOrFail();
        $baselineChecksum = (string) $baseline->checksum;
        $baselinePublicId = (string) $baseline->public_id;

        $payload = (array) $draftResponse->json('data.payload');
        $payload['settings']['primary_color'] = '#445566';
        $payload['sections'][0]['title_en'] = 'Draft only section';

        $this->patchJson('/api/v1/admin/app-preview/storefront-revisions/'.$draftId, [
            'payload' => $payload,
        ])->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.payload.settings.primary_color', '#445566');

        $this->assertSame(
            '#112233',
            DB::table('storefront_settings')->where('store_id', $storeId)->value('primary_color'),
        );

        $this->getJson('/api/v1/stores/'.$storeId.'/storefront?revision_id='.$draftId)
            ->assertOk()
            ->assertJsonPath('theme.primary', '#112233')
            ->assertJsonFragment(['title_en' => 'Published section']);

        $published = $this->postJson(
            '/api/v1/admin/app-preview/storefront-revisions/'.$draftId.'/publish',
        )->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.payload.settings.primary_color', '#445566');

        $publishedChecksum = (string) $published->json('data.checksum');
        $this->assertNotSame($baselineChecksum, $publishedChecksum);
        $this->assertSame(
            '#445566',
            DB::table('storefront_settings')->where('store_id', $storeId)->value('primary_color'),
        );
        $this->assertSame(
            'Draft only section',
            DB::table('storefront_sections')->where('store_id', $storeId)->value('title_en'),
        );

        $this->patchJson('/api/v1/admin/app-preview/storefront-revisions/'.$draftId, [
            'payload' => $payload,
        ])->assertStatus(409);

        $rolledBack = $this->postJson(
            '/api/v1/admin/app-preview/storefront-revisions/'.$baselinePublicId.'/rollback',
        )->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.checksum', $baselineChecksum)
            ->assertJsonPath('data.payload.settings.primary_color', '#112233');

        $this->assertSame(
            '#112233',
            DB::table('storefront_settings')->where('store_id', $storeId)->value('primary_color'),
        );
        $this->assertSame(
            'Published section',
            DB::table('storefront_sections')->where('store_id', $storeId)->value('title_en'),
        );

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'storefront.revision.published',
            'user_id' => $admin->id,
            'store_id' => $storeId,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'storefront.revision.rolled_back',
            'user_id' => $admin->id,
            'store_id' => $storeId,
        ]);
    }

    public function test_preview_token_can_resolve_exact_retail_draft_but_cannot_cross_store(): void
    {
        $storeA = $this->retailStore('REV-PREVIEW-A');
        $storeB = $this->retailStore('REV-PREVIEW-B');
        $this->seedStorefront($storeA, '#101010', 'A');
        $this->seedStorefront($storeB, '#202020', 'B');

        $admin = $this->storeAdmin($storeA, 'revision-preview-admin@example.test');
        $customer = $this->user('Preview Customer', 'revision-preview-customer@example.test');
        $this->retailCustomer($customer, $storeA);

        Sanctum::actingAs($admin);

        $draftA = $this->postJson('/api/v1/admin/app-preview/storefront-revisions/draft', [
            'channel' => 'b2c',
            'store_id' => $storeA,
        ])->assertCreated();
        $draftAId = (string) $draftA->json('data.revision_id');

        $this->postJson('/api/v1/admin/app-preview/storefront-revisions/draft', [
            'channel' => 'b2c',
            'store_id' => $storeA,
        ])->assertCreated()
            ->assertJsonPath('data.revision_id', $draftAId);

        $this->assertSame(
            1,
            StorefrontRevision::query()
                ->where('store_id', $storeA)
                ->where('channel', 'b2c')
                ->where('status', 'draft')
                ->count(),
        );

        $revisionB = app(StorefrontRevisionService::class)->ensurePublished($storeB, 'b2c');
        $draftB = StorefrontRevision::query()->create([
            'public_id' => (string) Str::uuid(),
            'store_id' => $storeB,
            'channel' => 'b2c',
            'status' => 'draft',
            'schema_version' => StorefrontRevisionService::SCHEMA_VERSION,
            'payload' => $revisionB->payload,
            'checksum' => $revisionB->checksum,
            'parent_revision_id' => $revisionB->id,
            'source_revision_id' => null,
            'created_by' => null,
            'published_by' => null,
            'published_at' => null,
        ]);

        $session = $this->postJson('/api/v1/admin/app-preview/sessions', [
            'target_user_id' => $customer->id,
            'target_type' => 'customer',
            'channel' => 'b2c',
            'store_id' => $storeA,
        ])->assertCreated();

        $token = (string) $session->json('preview_token');

        $this->postJson(
            '/api/v1/app-preview/storefront-revisions/'.$draftAId.'/resolve',
            ['preview_token' => $token],
        )->assertUnauthorized();

        $this->postJson(
            '/api/v1/app-preview/storefront-revisions/'.$draftAId.'/resolve?preview_token='.$token,
        )->assertUnauthorized();

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->postJson('/api/v1/app-preview/storefront-revisions/'.$draftAId.'/resolve')
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.store_id', $storeA)
            ->assertJsonPath('data.read_only', true);

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->postJson('/api/v1/app-preview/storefront-revisions/'.$draftB->public_id.'/resolve')
            ->assertNotFound();

        DB::table('app_preview_sessions')
            ->where('token_hash', hash('sha256', $token))
            ->update(['mode' => 'interactive']);

        $this->withHeader('X-Foodex-Preview-Token', $token)
            ->postJson('/api/v1/app-preview/storefront-revisions/'.$draftAId.'/resolve')
            ->assertForbidden();
    }

    public function test_retail_store_admin_cannot_manage_another_store_revisions(): void
    {
        $storeA = $this->retailStore('REV-ISO-A');
        $storeB = $this->retailStore('REV-ISO-B');
        $this->seedStorefront($storeA, '#111111', 'A');
        $this->seedStorefront($storeB, '#222222', 'B');
        $admin = $this->storeAdmin($storeA, 'revision-isolation@example.test');

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/app-preview/storefront-revisions/draft', [
            'channel' => 'b2c',
            'store_id' => $storeB,
        ])->assertForbidden();

        $this->getJson('/api/v1/admin/app-preview/storefront-revisions?channel=b2c&store_id='.$storeB)
            ->assertForbidden();
    }

    public function test_b2b_admin_uses_canonical_wholesale_scope_and_retail_admin_cannot_cross_channel(): void
    {
        $wholesaleStoreId = app(WholesalePrincipal::class)->storeId();
        $b2bAdmin = $this->roleUser('B2B_ADMIN', 'revision-b2b-admin@example.test');

        Sanctum::actingAs($b2bAdmin);
        $this->postJson('/api/v1/admin/app-preview/storefront-revisions/draft', [
            'channel' => 'b2b',
        ])->assertCreated()
            ->assertJsonPath('data.store_id', $wholesaleStoreId)
            ->assertJsonPath('data.channel', 'b2b')
            ->assertJsonPath('data.status', 'draft');

        $retailStoreId = $this->retailStore('REV-CROSS-CHANNEL');
        $retailAdmin = $this->storeAdmin($retailStoreId, 'revision-retail-cross@example.test');

        Sanctum::actingAs($retailAdmin);
        $this->postJson('/api/v1/admin/app-preview/storefront-revisions/draft', [
            'channel' => 'b2b',
        ])->assertForbidden();
    }

    public function test_super_admin_retail_revision_requires_explicit_support_access(): void
    {
        $storeId = $this->retailStore('REV-SUPPORT');
        $this->seedStorefront($storeId, '#445577', 'Support');
        $admin = $this->roleUser('SUPER_ADMIN', 'revision-super-admin@example.test');

        Sanctum::actingAs($admin);
        $payload = [
            'channel' => 'b2c',
            'store_id' => $storeId,
        ];

        $this->postJson('/api/v1/admin/app-preview/storefront-revisions/draft', $payload)
            ->assertForbidden();

        $this->postJson('/api/v1/admin/app-preview/storefront-revisions/draft', [
            ...$payload,
            'support_access' => true,
        ])->assertCreated()
            ->assertJsonPath('data.store_id', $storeId);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'event' => 'tenant.support_access.entered',
        ]);
    }

    public function test_unsupported_schema_cannot_be_published(): void
    {
        $storeId = $this->retailStore('REV-SCHEMA');
        $this->seedStorefront($storeId, '#333333', 'Schema');
        $admin = $this->storeAdmin($storeId, 'revision-schema@example.test');

        Sanctum::actingAs($admin);

        $draft = $this->postJson('/api/v1/admin/app-preview/storefront-revisions/draft', [
            'channel' => 'b2c',
            'store_id' => $storeId,
        ])->assertCreated();

        $revisionId = (string) $draft->json('data.revision_id');
        StorefrontRevision::query()->where('public_id', $revisionId)->update(['schema_version' => 99]);

        $this->postJson('/api/v1/admin/app-preview/storefront-revisions/'.$revisionId.'/publish')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('schema_version');
    }

    public function test_deleted_live_banner_asset_is_restorable_from_revision_archive(): void
    {
        Storage::fake('public');
        $storeId = $this->retailStore('REV-ASSET');
        $admin = $this->storeAdmin($storeId, 'revision-asset@example.test');

        $this->actingAs($admin)->post(route('admin.business.banners.store'), [
            'store_id' => $storeId,
            'title' => 'Archived Banner',
            'banner_image' => UploadedFile::fake()->image('archive.jpg', 1200, 420),
            'sort_order' => 10,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $banner = DB::table('banners')->where('store_id', $storeId)->first();
        $this->assertNotNull($banner);
        $oldPath = (string) $banner->image_path;
        $oldRelative = substr($oldPath, strlen('storage/'));
        Storage::disk('public')->assertExists($oldRelative);

        $firstPublished = StorefrontRevision::query()
            ->where('store_id', $storeId)
            ->where('channel', 'b2c')
            ->where('status', 'published')
            ->firstOrFail();

        $this->actingAs($admin)->patch(route('admin.business.banners.update', $banner->id), [
            'store_id' => $storeId,
            'title' => 'Replacement Banner',
            'banner_image' => UploadedFile::fake()->image('replacement.webp', 1200, 420),
            'sort_order' => 20,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing($oldRelative);
        $asset = DB::table('storefront_revision_assets')
            ->where('storefront_revision_id', $firstPublished->id)
            ->where('source_path', $oldPath)
            ->first();
        $this->assertNotNull($asset);
        $archiveRelative = substr((string) $asset->archive_path, strlen('storage/'));
        Storage::disk('public')->assertExists($archiveRelative);

        Sanctum::actingAs($admin);
        $this->postJson(
            '/api/v1/admin/app-preview/storefront-revisions/'.$firstPublished->public_id.'/rollback',
        )->assertOk();

        $restored = DB::table('banners')->where('store_id', $storeId)->first();
        $this->assertNotNull($restored);
        $this->assertSame((string) $asset->archive_path, (string) $restored->image_path);
        Storage::disk('public')->assertExists($archiveRelative);
    }

    public function test_semantically_identical_live_sync_is_idempotent(): void
    {
        $storeId = $this->retailStore('REV-IDEMPOTENT');
        $this->seedStorefront($storeId, '#ABCDEF', 'Stable');
        $admin = $this->storeAdmin($storeId, 'revision-idempotent@example.test');

        $service = app(StorefrontRevisionService::class);
        $first = $service->synchronizePublishedFromLive($admin, $storeId, 'b2c');
        $second = $service->synchronizePublishedFromLive($admin, $storeId, 'b2c');

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->checksum, $second->checksum);
        $this->assertSame(
            1,
            StorefrontRevision::query()
                ->where('store_id', $storeId)
                ->where('channel', 'b2c')
                ->where('status', 'published')
                ->count(),
        );
    }

    private function seedStorefront(int $storeId, string $primaryColor, string $sectionTitle): void
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
                'branding' => json_encode(['brand_title_en' => 'Revision Store'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        DB::table('storefront_sections')->insert([
            'store_id' => $storeId,
            'section_key' => 'hero',
            'section_type' => 'hero',
            'title_ar' => null,
            'title_en' => $sectionTitle,
            'sort_order' => 10,
            'config' => json_encode(['limit' => 1], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('banners')->insert([
            'store_id' => $storeId,
            'title' => 'Revision Banner',
            'image_path' => 'demo/banners/revision.webp',
            'target_url' => null,
            'target_type' => null,
            'target_id' => null,
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('store_service_zones')->insert([
            'store_id' => $storeId,
            'country_code' => 'KW',
            'city' => 'Kuwait City',
            'area' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storeAdmin(int $storeId, string $email): User
    {
        $user = $this->user('Retail Revision Admin', $email);
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
