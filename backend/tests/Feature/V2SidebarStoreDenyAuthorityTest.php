<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use App\Support\V2SidebarMetadata;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P157: a Sidebar item is a projection of the effective, store-scoped
 * permission, not proof that any assigned role once had that permission.
 */
final class V2SidebarStoreDenyAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_partial_store_deny_keeps_only_reachable_retail_destination_in_both_locales(): void
    {
        $allowed = $this->store('V2-P157-ALLOW');
        $denied = $this->store('V2-P157-DENY');
        $actor = $this->actor([$allowed, $denied]);

        $this->assertTrue($actor->hasPermission('customers.view', $allowed));
        $this->assertTrue($actor->hasPermission('customers.view', $denied));
        $this->assertContains('customer_360', $this->keys($actor));

        $this->deny($actor, 'customers.view', 'store:'.$denied, $denied);
        $actor = $actor->fresh();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $dir) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->assertTrue($actor->hasPermission('customers.view', $allowed));
            $this->assertFalse($actor->hasPermission('customers.view', $denied));
            $this->assertSame(
                [$allowed],
                app(OperationalTenantScope::class)->allowedStoreIds($actor, 'customers.view', 'b2c'),
            );
            $this->assertContains('customer_360', $this->keys($actor));

            $this->actingAs($actor)
                ->get(route('admin.b2c.module', ['module' => 'customers', 'store_id' => $allowed]))
                ->assertOk()
                ->assertSee('dir="'.$dir.'"', false);

            $foreign = $this->actingAs($actor)->get(
                route('admin.b2c.module', ['module' => 'customers', 'store_id' => $denied]),
            );
            $this->assertContains($foreign->getStatusCode(), [403, 404]);
            $this->actingAs($actor)
                ->get(route('admin.b2b.module', ['module' => 'customers']))
                ->assertForbidden();
        }
    }

    public function test_deny_on_every_assigned_store_removes_scoped_sidebar_without_revoking_shared_role(): void
    {
        $first = $this->store('V2-P157-FIRST');
        $second = $this->store('V2-P157-SECOND');
        $actor = $this->actor([$first, $second]);
        $role = Role::query()->where('code', 'RETAIL_CUSTOMER_SUPPORT')->firstOrFail();
        $permissionsBefore = $role->permissions()->pluck('permissions.id')->all();

        $this->deny($actor, 'customers.view', 'store:'.$first, $first);
        $this->deny($actor, 'customers.view', 'store:'.$second, $second);
        $actor = $actor->fresh();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->assertSame(
                [],
                app(OperationalTenantScope::class)->allowedStoreIds($actor, 'customers.view', 'b2c'),
            );
            $this->assertNotContains('customer_360', $this->keys($actor));
            $this->assertNotContains('customer_360', $this->metadataKeys($actor));

            foreach ([$first, $second] as $storeId) {
                $this->assertFalse($actor->hasPermission('customers.view', $storeId));
                $response = $this->actingAs($actor)->get(route('admin.b2c.module', [
                    'module' => 'customers',
                    'store_id' => $storeId,
                ]));
                $this->assertContains($response->getStatusCode(), [403, 404]);
            }
        }

        $this->assertEqualsCanonicalizing(
            $permissionsBefore,
            $role->fresh()->permissions()->pluck('permissions.id')->all(),
        );
    }

    public function test_global_deny_cannot_be_reinstated_by_retail_store_role_or_sidebar_layout(): void
    {
        $storeId = $this->store('V2-P157-GLOBAL');
        $actor = $this->actor([$storeId]);

        $this->deny($actor, 'customers.view', 'global');
        $actor = $actor->fresh();

        $this->assertFalse($actor->hasPermission('customers.view', $storeId));
        $this->assertSame(
            [],
            app(OperationalTenantScope::class)->allowedStoreIds($actor, 'customers.view', 'b2c'),
        );
        $this->assertNotContains('customer_360', $this->keys($actor));

        $response = $this->actingAs($actor)->get(route('admin.b2c.module', [
            'module' => 'customers',
            'store_id' => $storeId,
        ]));
        $this->assertContains($response->getStatusCode(), [403, 404]);

        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $actor->getKey(),
            'scope_key' => 'global',
            'effect' => 'deny',
        ]);
    }

    /** @param list<int> $stores */
    private function actor(array $stores): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', 'RETAIL_CUSTOMER_SUPPORT')->firstOrFail();

        foreach ($stores as $store) {
            DB::table('user_store_roles')->insert([
                'user_id' => $actor->getKey(),
                'store_id' => $store,
                'role_id' => $role->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $actor->fresh();
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function deny(User $user, string $permission, string $scopeKey, ?int $storeId = null): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->getKey(),
            'permission_id' => Permission::query()->where('code', $permission)->firstOrFail()->getKey(),
            'scope_key' => $scopeKey,
            'store_id' => $storeId,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user->forceFill(['has_permission_overrides' => true])->save();
    }

    /** @return list<string> */
    private function keys(User $user): array
    {
        $keys = [];
        foreach (app(AdminNavigation::class)->groupsFor($user) as $group) {
            foreach ((array) $group['children'] as $item) {
                $keys[] = $item['key'];
                foreach ((array) ($item['children'] ?? []) as $child) {
                    $keys[] = $child['key'];
                }
            }
        }

        return $keys;
    }

    /** @return list<string> */
    private function metadataKeys(User $user): array
    {
        $keys = [];
        foreach (app(V2SidebarMetadata::class)->forUser($user) as $group) {
            foreach ($group['items'] as $item) {
                $keys[] = $item['item_key'];
                foreach ($item['children'] as $child) {
                    $keys[] = $child['item_key'];
                }
            }
        }

        return $keys;
    }
}
