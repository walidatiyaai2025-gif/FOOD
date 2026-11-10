<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\SmartOrderingQrManager;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P326: Restaurant QR is a real scoped workspace tab, not a second
 * permissions system. Both navigation and direct HTTP must honor STORE DENY.
 */
final class V2RestaurantQrSidebarContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_enabled_restaurant_qr_is_a_discoverable_bilingual_authorized_workflow(): void
    {
        $owned = $this->restaurant('V2-P326-OWN');
        $foreign = $this->restaurant('V2-P326-FOREIGN');
        $actor = $this->storeOperator([$owned]);
        app(SmartOrderingQrManager::class)->create($this->qrPayload($owned, 'Owned QR'));
        app(SmartOrderingQrManager::class)->create($this->qrPayload($foreign, 'Foreign QR'));

        $sidebar = collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group): array => $group['children']);
        $this->assertTrue($sidebar->contains('key', 'smart_ordering'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($actor->fresh())
                ->get(route('admin.smart-ordering.index', ['store_id' => $owned]))
                ->assertOk()
                ->assertSee(route('admin.smart-ordering.qr.index', ['store_id' => $owned]), false);

            $this->actingAs($actor->fresh())
                ->get(route('admin.smart-ordering.qr.index', ['store_id' => $owned]))
                ->assertOk()
                ->assertViewIs('admin.smart-ordering-qr')
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('smart_ordering_qr.heading'))
                ->assertSee('data-restaurant-saas-workspace-nav', false)
                ->assertSee('aria-current="page"', false)
                ->assertSee('Owned QR')
                ->assertDontSee('Foreign QR');
        }

        $this->actingAs($actor->fresh())
            ->get(route('admin.smart-ordering.qr.index', ['store_id' => $foreign]))
            ->assertNotFound();
    }

    public function test_qr_tab_reflects_selected_store_deny_and_direct_http_is_not_bypassed(): void
    {
        $allowed = $this->restaurant('V2-P326-ALLOWED');
        $denied = $this->restaurant('V2-P326-DENIED');
        $actor = $this->storeOperator([$allowed, $denied]);
        $this->deny($actor, 'smart_ordering.qr.view', 'store:'.$denied, $denied);

        $this->assertTrue($actor->fresh()->hasPermission('smart_ordering.qr.view', $allowed));
        $this->assertFalse($actor->fresh()->hasPermission('smart_ordering.qr.view', $denied));
        $this->assertTrue($actor->fresh()->hasPermission('smart_ordering.view', $denied));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $this->actingAs($actor->fresh())
                ->get(route('admin.smart-ordering.index', ['store_id' => $denied]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertDontSee(route('admin.smart-ordering.qr.index', ['store_id' => $denied]), false);

            $this->actingAs($actor->fresh())
                ->get(route('admin.smart-ordering.index', ['store_id' => $allowed]))
                ->assertOk()
                ->assertSee(route('admin.smart-ordering.qr.index', ['store_id' => $allowed]), false);

            $this->actingAs($actor->fresh())
                ->get(route('admin.smart-ordering.qr.index', ['store_id' => $denied]))
                ->assertNotFound();
        }

        $this->deny($actor, 'smart_ordering.qr.view', 'global');
        $this->actingAs($actor->fresh())
            ->get(route('admin.smart-ordering.qr.index', ['store_id' => $allowed]))
            ->assertForbidden();
    }

    public function test_qr_viewer_cannot_create_or_mutate_even_when_tab_is_visible(): void
    {
        $store = $this->restaurant('V2-P326-READONLY');
        $actor = $this->storeOperator([$store]);
        $qr = app(SmartOrderingQrManager::class)->create($this->qrPayload($store, 'Read-only QR'))['qr'];
        $this->deny($actor, 'smart_ordering.qr.manage', 'store:'.$store, $store);

        $this->actingAs($actor->fresh())
            ->get(route('admin.smart-ordering.qr.index', ['store_id' => $store]))
            ->assertOk()
            ->assertSee('Read-only QR')
            ->assertDontSee('data-open-dialog="so-qr-create"', false);

        $before = DB::table('smart_ordering_qr_codes')->count();
        $this->actingAs($actor->fresh())
            ->post(route('admin.smart-ordering.qr.store'), $this->qrPayload($store, 'Forbidden QR'))
            ->assertNotFound();
        $this->actingAs($actor->fresh())
            ->post(route('admin.smart-ordering.qr.toggle', $qr))
            ->assertNotFound();

        $this->assertSame($before, DB::table('smart_ordering_qr_codes')->count());
        $this->assertTrue((bool) $qr->fresh()->is_enabled);
    }

    public function test_unentitled_restaurant_and_wholesale_actor_cannot_open_qr_management(): void
    {
        $store = $this->restaurant('V2-P326-OFF');
        $actor = $this->storeOperator([$store]);
        DB::table('enterprise_module_entitlements')
            ->where('scope_key', 'store:'.$store)
            ->where('module_code', 'smart_ordering')
            ->update(['is_enabled' => false]);

        $this->assertFalse(collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->contains('key', 'smart_ordering'));
        $this->actingAs($actor)
            ->get(route('admin.smart-ordering.qr.index', ['store_id' => $store]))
            ->assertForbidden();

        $this->enableRestaurantSaasForTestStore($store);
        $wholesale = User::factory()->create(['is_active' => true]);
        $wholesale->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());
        $this->actingAs($wholesale)
            ->get(route('admin.smart-ordering.qr.index', ['store_id' => $store]))
            ->assertForbidden();
    }

    private function restaurant(string $code): int
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        $this->enableRestaurantSaasForTestStore((int) $store->id);

        return (int) $store->id;
    }

    /** @param list<int> $storeIds */
    private function storeOperator(array $storeIds): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        foreach ($storeIds as $storeId) {
            DB::table('user_store_roles')->insert([
                'user_id' => $actor->getKey(),
                'store_id' => $storeId,
                'role_id' => $role->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $actor->fresh();
    }

    /** @return array<string,mixed> */
    private function qrPayload(int $storeId, string $label): array
    {
        return [
            'store_id' => $storeId,
            'type' => 'generic',
            'label' => $label,
            'target_path' => '/menu',
            'source' => 'qr',
            'medium' => 'offline',
            'campaign' => null,
            'branch_reference' => null,
            'table_reference' => null,
            'promotion_id' => null,
            'valid_from' => null,
            'valid_until' => null,
            'is_enabled' => true,
        ];
    }

    private function deny(User $actor, string $permission, string $scopeKey, ?int $storeId = null): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->getKey(),
            'permission_id' => Permission::query()->where('code', $permission)->firstOrFail()->getKey(),
            'scope_key' => $scopeKey,
            'store_id' => $storeId,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
    }
}
