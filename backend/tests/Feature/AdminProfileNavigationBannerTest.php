<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StorefrontRevision;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminProfileNavigationBannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_navigation_does_not_render_retail_operational_modules(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN', 'owner@example.test');
        $keys = $this->navigationKeys($admin);

        $this->assertContains('administration_hub', $keys);
        $this->assertNotContains('profile', $keys);
        $this->assertContains('b2b_dashboard', $keys);
        $this->assertContains('retail_store_provisioning', $keys);
        $this->assertFalse(collect($keys)->contains(fn (string $key): bool => str_starts_with($key, 'b2c_')));
    }

    public function test_retail_store_admin_only_receives_authorized_retail_navigation(): void
    {
        $storeId = $this->retailStore('NAV-RETAIL');
        $admin = $this->storeAdmin($storeId, 'retail@example.test');
        $keys = $this->navigationKeys($admin);

        $this->assertContains('administration_hub', $keys);
        $this->assertNotContains('profile', $keys);
        $this->assertContains('b2c_dashboard', $keys);
        $this->assertContains('b2c_products', $keys);
        $this->assertNotContains('retail_store_provisioning', $keys);
        $this->assertFalse(collect($keys)->contains(fn (string $key): bool => str_starts_with($key, 'b2b_')));
    }

    public function test_user_can_open_profile_change_password_and_switch_language(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN', 'profile@example.test', 'OldPass1234');

        $this->actingAs($admin)
            ->get(route('admin.profile.index'))
            ->assertOk()
            ->assertSee('Profile &amp; Permissions', false)
            ->assertSee('SUPER_ADMIN');

        $this->actingAs($admin)->patch(route('admin.profile.password'), [
            'current_password' => 'OldPass1234',
            'password' => 'NewPass5678A',
            'password_confirmation' => 'NewPass5678A',
        ])->assertSessionHasNoErrors();

        $admin->refresh();
        $this->assertTrue(Hash::check('NewPass5678A', (string) $admin->getAuthPassword()));

        $this->actingAs($admin)->patch(route('admin.profile.locale'), ['locale' => 'en'])
            ->assertSessionHasNoErrors();

        $this->assertSame('en', $admin->refresh()->locale);
    }

    public function test_account_controls_render_in_header_and_not_sidebar_footer(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN', 'header-account@example.test');

        $this->actingAs($admin)
            ->get(route('admin.mobile-settings.index'))
            ->assertOk()
            ->assertSee('data-foodex-live-notifications', false)
            ->assertSee('data-foodex-account-menu', false)
            ->assertSee('data-foodex-account-trigger', false)
            ->assertSee('Account settings')
            ->assertDontSee('foodex-sidebar-footer', false)
            ->assertDontSee('foodex-sidebar-account', false)
            ->assertDontSee('foodex-sidebar-language', false)
            ->assertDontSee('foodex-sidebar-logout', false);
    }

    public function test_retail_banner_upload_replace_and_delete_stays_in_draft_and_preserves_files(): void
    {
        Storage::fake('public');
        $storeId = $this->retailStore('BANNER-STORE');
        $admin = $this->storeAdmin($storeId, 'banner@example.test');

        $this->actingAs($admin)->post(route('admin.business.banners.store'), [
            'store_id' => $storeId,
            'title' => 'Fresh weekend',
            'banner_image' => UploadedFile::fake()->image('fresh.jpg', 1200, 420),
            'sort_order' => 10,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('banners', [
            'store_id' => $storeId,
            'title' => 'Fresh weekend',
        ]);

        $draft = StorefrontRevision::query()
            ->where('store_id', $storeId)
            ->where('channel', 'b2c')
            ->where('status', 'draft')
            ->latest('id')
            ->firstOrFail();
        $banner = collect($draft->payload['banners'])->firstWhere('title', 'Fresh weekend');
        $this->assertNotNull($banner);
        $editorId = (string) $banner['editor_id'];
        $firstPath = (string) $banner['image_path'];
        $firstRelative = substr($firstPath, strlen('storage/'));
        Storage::disk('public')->assertExists($firstRelative);

        $this->actingAs($admin)->patch(route('admin.business.banners.update', $editorId), [
            'store_id' => $storeId,
            'title' => 'Fresh weekend updated',
            'banner_image' => UploadedFile::fake()->image('fresh-new.webp', 1200, 420),
            'sort_order' => 20,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $draft->refresh();
        $updated = collect($draft->payload['banners'])->firstWhere('editor_id', $editorId);
        $this->assertNotNull($updated);
        $this->assertSame('Fresh weekend updated', $updated['title']);
        $this->assertNotSame($firstPath, $updated['image_path']);
        Storage::disk('public')->assertExists($firstRelative);
        $newRelative = substr((string) $updated['image_path'], strlen('storage/'));
        Storage::disk('public')->assertExists($newRelative);

        $this->actingAs($admin)->delete(route('admin.business.banners.destroy', $editorId), [
            'store_id' => $storeId,
        ])->assertSessionHasNoErrors();

        $draft->refresh();
        $this->assertNull(collect($draft->payload['banners'])->firstWhere('editor_id', $editorId));
        $this->assertDatabaseMissing('banners', ['store_id' => $storeId]);
        Storage::disk('public')->assertExists($firstRelative);
        Storage::disk('public')->assertExists($newRelative);
    }

    /** @return list<string> */
    private function navigationKeys(User $user): array
    {
        return collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(fn (array $group) => $group['children'])
            ->pluck('key')
            ->values()
            ->all();
    }

    private function globalUser(string $roleCode, string $email, string $password = 'Password1234'): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => $password,
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function storeAdmin(int $storeId, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Retail Admin',
            'email' => $email,
            'password' => 'Password1234',
            'locale' => 'en',
            'is_active' => true,
        ]);
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
