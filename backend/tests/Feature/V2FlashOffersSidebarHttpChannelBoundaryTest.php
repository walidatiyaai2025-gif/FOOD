<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sidebar P232: existing Wholesale Flash Offers and Retail Promotions routes
 * must follow effective per-channel and per-store HTTP authority.
 */
final class V2FlashOffersSidebarHttpChannelBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_and_retail_actors_discover_their_real_offers_workflow_in_both_locales(): void
    {
        $wholesaleStore = $this->store('B2B', 'P232-WHOLESALE');
        $retailStore = $this->store('B2C', 'P232-RETAIL');
        $wholesale = $this->globalActor('B2B_ADMIN', 'wholesale');
        $retail = $this->retailActor($retailStore, 'retail');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $wholesale->forceFill(['locale' => $locale])->save();
            $retail->forceFill(['locale' => $locale])->save();

            $wholesaleItem = $this->navigationItem($wholesale->fresh(), 'commercial_flash_offers');
            $this->assertNotNull($wholesaleItem);
            $this->assertSame('admin.commercial.wholesale-flash-offers', $wholesaleItem['route']);
            $this->assertSame('promotions.view', $wholesaleItem['permission']);

            $this->actingAs($wholesale)
                ->get(route($wholesaleItem['route'], ['store_id' => $wholesaleStore], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-flash-product-builder', false);

            // A Retail store-scoped role cannot borrow global wholesale access.
            $this->assertNull($this->navigationItem($retail->fresh(), 'commercial_flash_offers'));
            $retailItem = $this->navigationItem($retail->fresh(), 'b2c_promotions');
            $this->assertNotNull($retailItem);
            $this->assertSame('admin.b2c.module', $retailItem['route']);
            $this->assertSame('promotions', $retailItem['params']['module']);
            $this->assertSame((string) $retailStore, $retailItem['params']['store_id']);

            $this->actingAs($retail)
                ->get(route($retailItem['route'], $retailItem['params'], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-commercial-admin-surface="canonical-flash-offer"', false);

            $this->actingAs($retail)
                ->get(route('admin.commercial.flash-offers', ['store_id' => $retailStore], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-flash-product-builder', false);
        }
    }

    public function test_forbidden_channel_or_foreign_store_cannot_create_flash_offers_or_cross_migrate_to_van(): void
    {
        $wholesaleStore = $this->store('B2B', 'P232-CHANNEL-B2B');
        $retailStoreA = $this->store('B2C', 'P232-CHANNEL-A');
        $retailStoreB = $this->store('B2C', 'P232-CHANNEL-B');
        $wholesale = $this->globalActor('B2B_ADMIN', 'channel');
        $retail = $this->retailActor($retailStoreA, 'channel');

        // Wrong route/channel is rejected before input validation or DB writes.
        $this->actingAs($retail)
            ->get(route('admin.commercial.wholesale-flash-offers', ['store_id' => $wholesaleStore], false))
            ->assertNotFound();
        $this->actingAs($retail)
            ->post(route('admin.commercial.wholesale-flash-offers.save', ['store_id' => $wholesaleStore], false), [])
            ->assertNotFound();

        $this->actingAs($wholesale)
            ->get(route('admin.commercial.wholesale-flash-offers', ['store_id' => $retailStoreA], false))
            ->assertNotFound();
        $this->actingAs($wholesale)
            ->post(route('admin.commercial.flash-offers.save', ['store_id' => $wholesaleStore], false), [])
            ->assertNotFound();

        $this->actingAs($retail)
            ->post(route('admin.commercial.flash-offers.save', ['store_id' => $retailStoreB], false), [])
            ->assertNotFound();
        $this->actingAs($retail)
            ->post(route('admin.commercial.wholesale-flash-offers.save', ['store_id' => $retailStoreA], false), [])
            ->assertNotFound();

        $this->assertDatabaseCount('flash_offers', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'commercial.flash_offer.action')->count());
    }

    public function test_explicit_global_deny_prevents_wholesale_offer_navigation_and_direct_http_action(): void
    {
        $storeId = $this->store('B2B', 'P232-DENIED-B2B');
        $actor = $this->globalActor('B2B_ADMIN', 'denied');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $permission = Permission::query()->where('code', 'promotions.view')->firstOrFail();

        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->id,
            'item_key' => 'commercial_flash_offers',
            'is_visible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->assertFalse($actor->fresh()->hasPermission('promotions.view'));
            $this->assertNull($this->navigationItem($actor->fresh(), 'commercial_flash_offers'));
            $this->actingAs($actor)
                ->get(route('admin.commercial.wholesale-flash-offers', ['store_id' => $storeId], false))
                ->assertNotFound();
        }

        $this->assertDatabaseHas('admin_sidebar_role_visibility', [
            'role_id' => $role->id,
            'item_key' => 'commercial_flash_offers',
            'is_visible' => true,
        ]);
        $this->assertDatabaseCount('flash_offers', 0);

        $inactive = $this->globalActor('B2B_ADMIN', 'inactive');
        $role->forceFill(['is_active' => false])->save();
        $this->assertNull($this->navigationItem($inactive->fresh(), 'commercial_flash_offers'));
        $response = $this->actingAs($inactive)
            ->get(route('admin.commercial.wholesale-flash-offers', ['store_id' => $storeId], false));
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    private function globalActor(string $role, string $suffix): User
    {
        $actor = User::query()->create([
            'name' => 'P232 '.$suffix,
            'email' => 'v2-p232-global-'.$suffix.'@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $actor->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $actor;
    }

    private function retailActor(int $storeId, string $suffix): User
    {
        $actor = User::query()->create([
            'name' => 'P232 Retail '.$suffix,
            'email' => 'v2-p232-retail-'.$suffix.'@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function store(string $type, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'business_profile' => $type === 'B2C' ? 'RETAIL_STORE' : 'WHOLESALE_PRINCIPAL',
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed>|null */
    private function navigationItem(User $actor, string $itemKey): ?array
    {
        foreach (app(AdminNavigation::class)->groupsFor($actor) as $group) {
            foreach ($group['children'] as $item) {
                if ($item['key'] === $itemKey) {
                    return $item;
                }
            }
        }

        return null;
    }
}
