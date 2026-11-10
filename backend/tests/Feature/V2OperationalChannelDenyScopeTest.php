<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationalTenantScope;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P376: a shared operational screen must not recover denied Retail/Wholesale
 * records merely because another role permits that same permission code.
 */
final class V2OperationalChannelDenyScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_store_deny_overrides_inherited_retail_role_without_leaking_into_authorized_wholesale_in_both_locales(): void
    {
        $wholesaleStore = $this->store('B2B', 'V2-P376-WHOLESALE');
        $retailA = $this->store('B2C', 'V2-P376-RETAIL-A');
        $retailB = $this->store('B2C', 'V2-P376-RETAIL-B');

        $actor = $this->mixedActor([$retailA, $retailB], 'mixed-store-deny');
        $scope = app(OperationalTenantScope::class);

        $this->assertTrue($actor->hasPermission('orders.view', $retailA));
        $this->assertTrue($actor->hasPermission('orders.view', $retailB));
        $this->assertTrue($actor->hasPermission('orders.view', $wholesaleStore));
        $this->assertSame([$retailA, $retailB], $scope->allowedStoreIds($actor, 'orders.view', 'b2c'));
        $this->assertSame([$wholesaleStore], $scope->allowedStoreIds($actor, 'orders.view', 'b2b'));

        $this->deny($actor, 'orders.view', 'store:'.$retailB, $retailB);
        $actor = $actor->fresh();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->assertTrue($actor->hasPermission('orders.view', $retailA));
            $this->assertFalse($actor->hasPermission('orders.view', $retailB));
            $this->assertTrue($actor->hasPermission('orders.view', $wholesaleStore));
            $this->assertSame([$retailA], $scope->allowedStoreIds($actor, 'orders.view', 'b2c'));
            $this->assertSame([$wholesaleStore], $scope->allowedStoreIds($actor, 'orders.view', 'b2b'));
            $this->assertEqualsCanonicalizing(
                [$retailA, $wholesaleStore],
                $scope->allowedStoreIds($actor, 'orders.view'),
            );

            $this->actingAs($actor)->get('/admin/b2c/orders?store_id='.$retailA)->assertOk()
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false);
            $this->actingAs($actor)->get('/admin/b2c/orders?store_id='.$retailB)->assertNotFound();
            $this->actingAs($actor)->get('/admin/b2b/orders?store_id='.$wholesaleStore)->assertOk();
        }
    }

    public function test_global_deny_defeats_both_retail_and_wholesale_roles_on_shared_operations(): void
    {
        $wholesaleStore = $this->store('B2B', 'V2-P376-GLOBAL-WHOLESALE');
        $retailStore = $this->store('B2C', 'V2-P376-GLOBAL-RETAIL');
        $actor = $this->mixedActor([$retailStore], 'global-deny');

        $this->deny($actor, 'orders.view', 'global');
        $actor = $actor->fresh();

        $scope = app(OperationalTenantScope::class);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->assertFalse($actor->hasPermission('orders.view', $retailStore));
            $this->assertFalse($actor->hasPermission('orders.view', $wholesaleStore));
            $this->assertSame([], $scope->allowedStoreIds($actor, 'orders.view', 'b2c'));
            $this->assertSame([], $scope->allowedStoreIds($actor, 'orders.view', 'b2b'));
            $this->assertSame([], $scope->allowedStoreIds($actor, 'orders.view'));

            $this->actingAs($actor)->get('/admin/b2c/orders?store_id='.$retailStore)->assertForbidden();
            $this->actingAs($actor)->get('/admin/b2b/orders?store_id='.$wholesaleStore)->assertForbidden();
            $this->actingAs($actor)->get('/admin/operations/orders?channel=all')->assertForbidden();
        }
    }

    public function test_inactive_privileged_user_never_receives_store_scope_from_shortcut(): void
    {
        $this->store('B2B', 'V2-P376-SUPER-WHOLESALE');
        $this->store('B2C', 'V2-P376-SUPER-RETAIL');
        $actor = User::factory()->create(['is_active' => false, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $scope = app(OperationalTenantScope::class);
        $this->assertSame([], $scope->allowedStoreIds($actor, 'orders.view'));
        $this->assertSame([], $scope->allowedStoreIds($actor, 'orders.view', 'b2b'));
        $this->assertSame([], $scope->allowedStoreIds($actor, 'orders.view', 'b2c'));
    }

    private function mixedActor(array $retailStores, string $suffix): User
    {
        $actor = User::factory()->create([
            'name' => 'V2 P376 '.$suffix,
            'email' => 'v2-p376-'.$suffix.'@example.test',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $actor->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());
        $retailRoleId = Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail()->getKey();

        foreach ($retailStores as $storeId) {
            DB::table('user_store_roles')->insert([
                'user_id' => $actor->getKey(),
                'store_id' => $storeId,
                'role_id' => $retailRoleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $actor->fresh();
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
