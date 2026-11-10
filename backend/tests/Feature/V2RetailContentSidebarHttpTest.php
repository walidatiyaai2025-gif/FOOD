<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P236: Retail Content must be a real, scoped and discoverable workspace.
 *
 * Existing Retail content uses promotions.view and the marketing domain.
 * These tests exercise HTTP controllers, not just cosmetic Sidebar visibility.
 */
final class V2RetailContentSidebarHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);

        // Test the canonical default navigation, not admin-saved presentation
        // preferences left by unrelated scenarios. These are optional display
        // overrides and must not be confused with effective route permission.
        DB::table('admin_sidebar_role_visibility')->delete();
        DB::table('admin_sidebar_items')->delete();
        DB::table('admin_sidebar_groups')->delete();
    }

    public function test_authorized_retail_content_is_discoverable_and_opens_in_arabic_and_english(): void
    {
        $storeId = $this->store('P236-OWN', 'RETAIL_STORE');
        $actor = $this->storeActor('B2C_STORE_ADMIN', $storeId, 'v2-p236-retail@example.test');
        $this->assertTrue($actor->hasPermission('promotions.view', $storeId));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $groups = app(AdminNavigation::class)->groupsFor($actor->fresh());
            $this->assertSame('marketing', config('admin_sidebar.items.b2c_content.group'));

            // Configured Sidebar layouts can nest a page beneath a domain root
            // or regroup it; the effective reachable link is the authority.
            $item = $this->navigationItem($groups, 'b2c_content');
            $this->assertIsArray($item, 'Authorized Content requires a working Sidebar entry.');
            $this->assertSame('admin.b2c.module', $item['route']);
            $this->assertSame('promotions.view', $item['permission']);
            $this->assertSame('admin.b2c_workspace.modules.content', $item['label']);
            $this->assertSame('content', $item['params']['module']);
            $this->assertSame((string) $storeId, $item['params']['store_id']);

            $this->actingAs($actor->fresh())
                ->get(route($item['route'], $item['params'], false))
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'">', false);
        }
    }

    public function test_retail_content_context_cannot_open_a_foreign_store_and_does_not_modify_data(): void
    {
        $own = $this->store('P236-OWN-BOUNDARY', 'RETAIL_STORE');
        $other = $this->store('P236-OTHER-BOUNDARY', 'RETAIL_STORE');
        $actor = $this->storeActor('B2C_STORE_ADMIN', $own, 'v2-p236-boundary@example.test');

        $productsBefore = DB::table('products')->count();
        $contentBefore = DB::table('storefront_revisions')->count();

        $this->actingAs($actor)
            ->get(route('admin.b2c.module', ['module' => 'content', 'store_id' => $own], false))
            ->assertOk();

        $this->actingAs($actor)
            ->get(route('admin.b2c.module', ['module' => 'content', 'store_id' => $other], false))
            ->assertNotFound();

        $this->assertSame($productsBefore, DB::table('products')->count());
        $this->assertGreaterThanOrEqual($contentBefore, DB::table('storefront_revisions')->count(), 'A draft-first read may initialize an immutable published revision; it must not delete prior revisions.');
    }

    public function test_roleless_inactive_and_wholesale_only_users_cannot_discover_or_open_retail_content(): void
    {
        $storeId = $this->store('P236-DENY', 'RETAIL_STORE');
        $roleless = User::query()->create([
            'name' => 'No Dashboard Roles',
            'email' => 'v2-p236-roleless@example.test',
            'password' => 'password',
            'is_active' => true,
            'locale' => 'en',
        ]);
        $inactive = $this->storeActor('B2C_STORE_ADMIN', $storeId, 'v2-p236-inactive@example.test');
        $inactive->forceFill(['is_active' => false])->save();
        $wholesale = $this->globalActor('B2B_ADMIN', 'v2-p236-b2b@example.test');

        foreach ([$roleless, $inactive, $wholesale] as $actor) {
            $this->assertNull(
                $this->navigationItem(app(AdminNavigation::class)->groupsFor($actor->fresh()), 'b2c_content'),
                'A denied actor must never see a Retail Content route in any configured Sidebar group or nested item.',
            );

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'content', 'store_id' => $storeId], false))
                ->assertForbidden();
        }
    }

    public function test_restaurant_profile_never_exposes_retail_only_content_module(): void
    {
        $storeId = $this->store('P236-RESTAURANT', 'RESTAURANT');
        $actor = $this->storeActor('RESTAURANT_DIGITAL_MANAGER', $storeId, 'v2-p236-restaurant@example.test');

        $this->assertNull(
            $this->navigationItem(app(AdminNavigation::class)->groupsFor($actor->fresh()), 'b2c_content'),
        );

        $response = $this->actingAs($actor)
            ->get(route('admin.b2c.module', ['module' => 'content', 'store_id' => $storeId], false));
        $this->assertContains($response->status(), [403, 404], 'Restaurant profile cannot open Retail Content.');
    }

    /**
     * Follow the effective two-level Dashboard Sidebar layout, including an
     * administrator-configured child placement, without treating placement as
     * a permission grant.
     *
     * @param  list<array<string,mixed>>  $groups
     * @return array<string,mixed>|null
     */
    private function navigationItem(array $groups, string $key): ?array
    {
        foreach ($groups as $group) {
            foreach ((array) ($group['children'] ?? []) as $item) {
                if (($item['key'] ?? null) === $key) {
                    return $item;
                }

                foreach ((array) ($item['children'] ?? []) as $child) {
                    if (($child['key'] ?? null) === $key) {
                        return $child;
                    }
                }
            }
        }

        return null;
    }

    private function storeActor(string $roleCode, int $storeId, string $email): User
    {
        $actor = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', $roleCode)->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function globalActor(string $roleCode, string $email): User
    {
        $actor = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }

    private function store(string $code, string $profile): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => $profile,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
