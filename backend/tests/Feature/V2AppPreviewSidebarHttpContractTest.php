<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\StorefrontRevisionService;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P319: the Applications preview Sidebar entry must be an authorized projection
 * of the existing read-only Preview Center, not an alternate auth/preview flow.
 */
final class V2AppPreviewSidebarHttpContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_navigation_resolves_one_read_only_preview_and_never_substitutes_published_for_missing_draft(): void
    {
        $admin = $this->userWithGlobalRole('B2B_ADMIN');
        $storeId = app(WholesalePrincipal::class)->storeId();
        $published = app(StorefrontRevisionService::class)->ensurePublished($storeId, 'b2b', $admin);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $admin->forceFill(['locale' => $locale])->save();

            $previewItems = collect(app(AdminNavigation::class)->groupsFor($admin->fresh()))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->where('key', 'app_preview')
                ->values();

            $this->assertCount(1, $previewItems);
            $this->assertSame('admin.app-preview.index', $previewItems->first()['route']);

            $this->actingAs($admin)
                ->get(route('admin.app-preview.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('admin.app_preview'))
                ->assertSee('data-preview-safe-mode="read_only"', false)
                ->assertSee('data-preview-default-configuration="published"', false);

            $this->actingAs($admin)
                ->getJson(route('admin.app-preview.storefront-configuration', [
                    'channel' => 'b2b',
                    'mode' => 'draft',
                ]))
                ->assertOk()
                ->assertJsonPath('data', null)
                ->assertJsonPath('state', 'draft_unavailable')
                ->assertJsonPath('read_only', true);

            $this->actingAs($admin)
                ->getJson(route('admin.app-preview.storefront-configuration', [
                    'channel' => 'b2b',
                    'mode' => 'published',
                ]))
                ->assertOk()
                ->assertJsonPath('data.revision_id', $published->public_id)
                ->assertJsonPath('data.mode', 'published')
                ->assertJsonPath('data.read_only', true);
        }

        $this->assertDatabaseCount('app_preview_sessions', 0);
        $this->assertSame(
            $published->public_id,
            app(StorefrontRevisionService::class)->findCurrent($storeId, 'b2b', 'published')?->public_id,
        );
        $this->assertNull(app(StorefrontRevisionService::class)->findCurrent($storeId, 'b2b', 'draft'));
    }

    public function test_retail_sidebar_and_all_preview_entry_points_reject_a_foreign_store_without_creating_a_session(): void
    {
        $own = $this->retailStore('V2-P319-OWN');
        $foreign = $this->retailStore('V2-P319-FOREIGN');
        $admin = $this->retailAdmin($own);
        $customer = User::factory()->create(['is_active' => true]);

        DB::table('b2c_customers')->insert([
            'store_id' => $foreign,
            'user_id' => $customer->getKey(),
            'name' => $customer->name,
            'email' => $customer->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin)
                ->get(route('admin.app-preview.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('V2-P319-OWN')
                ->assertDontSee('V2-P319-FOREIGN');

            foreach (['draft', 'published'] as $mode) {
                $this->actingAs($admin)
                    ->getJson(route('admin.app-preview.storefront-configuration', [
                        'channel' => 'b2c',
                        'store_id' => $foreign,
                        'mode' => $mode,
                    ]))
                    ->assertForbidden();
            }

            $this->actingAs($admin)
                ->getJson(route('admin.app-preview.targets', [
                    'target_type' => 'customer',
                    'channel' => 'b2c',
                    'store_id' => $foreign,
                ]))
                ->assertForbidden();

            $this->actingAs($admin)
                ->postJson(route('admin.app-preview.sessions.store'), [
                    'target_user_id' => $customer->getKey(),
                    'target_type' => 'customer',
                    'channel' => 'b2c',
                    'store_id' => $foreign,
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('app_preview_sessions', 0);
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'app_preview.session.created')
            ->count());
    }

    public function test_guest_preview_view_permission_does_not_imply_target_discovery_or_impersonation(): void
    {
        $storeId = $this->retailStore('V2-P319-READ-ONLY');
        $admin = $this->retailAdmin($storeId);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $impersonation = Permission::query()->where('code', 'app_preview.impersonate_customer')->firstOrFail();

        // Isolate the view-only role without adding an override or a new access
        // path. The canonical role/permission pivot is authoritative.
        $role->permissions()->detach($impersonation->getKey());
        $admin = $admin->fresh();
        $this->assertTrue($admin->hasPermission('app_preview.view', $storeId));
        $this->assertFalse($admin->hasPermission('app_preview.impersonate_customer', $storeId));

        $this->actingAs($admin)
            ->get(route('admin.app-preview.index'))
            ->assertOk();

        $this->actingAs($admin)
            ->getJson(route('admin.app-preview.storefront-configuration', [
                'channel' => 'b2c',
                'store_id' => $storeId,
                'mode' => 'draft',
            ]))
            ->assertOk()
            ->assertJsonPath('state', 'draft_unavailable')
            ->assertJsonPath('read_only', true);

        $this->actingAs($admin)
            ->getJson(route('admin.app-preview.targets', [
                'target_type' => 'customer',
                'channel' => 'b2c',
                'store_id' => $storeId,
            ]))
            ->assertForbidden();

        $this->assertDatabaseCount('app_preview_sessions', 0);
    }

    private function userWithGlobalRole(string $code): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }

    private function retailAdmin(int $storeId): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => $role->getKey(),
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
