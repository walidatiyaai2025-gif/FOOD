<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P009: inherited role grants and individual ALLOW/DENY must resolve through
 * the same authoritative, channel- and store-scoped permission decision.
 */
final class V2EffectivePermissionScopeContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_global_and_retail_role_inheritance_respects_separate_channel_contexts(): void
    {
        $own = $this->store('B2C', 'V2-P009-OWN');
        $other = $this->store('B2C', 'V2-P009-OTHER');
        $wholesale = $this->store('B2B', 'V2-P009-WHOLESALE');
        $actor = $this->inventoryUser([$own]);

        $this->assertTrue($actor->hasPermission('inventory.view', $own));
        $this->assertFalse($actor->hasPermission('inventory.view', $other));
        $this->assertFalse($actor->hasPermission('inventory.view'));
        $this->assertContains('inventory.view', $actor->inheritedPermissionCodes($own));
        $this->assertNotContains('inventory.view', $actor->inheritedPermissionCodes($other));
        $this->assertSame($actor->inheritedPermissionCodes($own), $actor->effectivePermissionCodes($own));

        $actor->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail()->id);
        $actor = $actor->fresh();

        $this->assertTrue($actor->hasPermission('b2b.accounts.view'));
        $this->assertTrue($actor->hasPermission('b2b.accounts.view', $wholesale));
        $this->assertFalse($actor->hasPermission('b2b.accounts.view', $own));
        $this->assertFalse($actor->hasPermission('b2b.accounts.view', $other));
        $this->assertContains('b2b.accounts.view', $actor->effectivePermissionCodes($wholesale));
        $this->assertNotContains('b2b.accounts.view', $actor->effectivePermissionCodes($own));
        $this->assertTrue($actor->hasPermission('inventory.view', $own));
    }

    public function test_local_allow_and_both_deny_scopes_take_precedence_without_changing_shared_role(): void
    {
        $first = $this->store('B2C', 'V2-P009-FIRST');
        $second = $this->store('B2C', 'V2-P009-SECOND');
        $actor = $this->inventoryUser([$first, $second]);
        $role = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();

        $this->assertFalse($role->permissions()->where('code', 'orders.view')->exists());
        $this->assertFalse($actor->hasPermission('orders.view', $first));
        $this->insertOverride($actor, 'orders.view', 'store:'.$first, $first, 'allow');

        $this->assertTrue($actor->fresh()->hasPermission('orders.view', $first));
        $this->assertFalse($actor->fresh()->hasPermission('orders.view', $second));
        $this->assertContains('orders.view', $actor->fresh()->effectivePermissionCodes($first));
        $this->assertNotContains('orders.view', $actor->fresh()->effectivePermissionCodes($second));

        // Store DENY removes an inherited grant only for the selected store.
        $this->insertOverride($actor, 'inventory.view', 'store:'.$first, $first, 'deny');
        $this->assertFalse($actor->fresh()->hasPermission('inventory.view', $first));
        $this->assertTrue($actor->fresh()->hasPermission('inventory.view', $second));
        $this->assertNotContains('inventory.view', $actor->fresh()->effectivePermissionCodes($first));
        $this->assertContains('inventory.view', $actor->fresh()->effectivePermissionCodes($second));

        // A global DENY wins over an already-saved store ALLOW.
        $this->insertOverride($actor, 'orders.view', 'global', null, 'deny');
        $this->assertFalse($actor->fresh()->hasPermission('orders.view', $first));
        $this->assertNotContains('orders.view', $actor->fresh()->effectivePermissionCodes($first));
        $this->assertFalse($role->fresh()->permissions()->where('code', 'orders.view')->exists());

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $this->actingAs($actor->fresh())
                ->get('/admin/b2c/orders?store_id='.$first)
                ->assertForbidden();
        }
    }

    public function test_forged_foreign_store_and_cross_channel_overrides_never_grant_access(): void
    {
        $own = $this->store('B2C', 'V2-P009-AUTHORIZED');
        $foreign = $this->store('B2C', 'V2-P009-FOREIGN');
        $wholesale = $this->store('B2B', 'V2-P009-B2B');
        $actor = $this->inventoryUser([$own]);

        $this->insertOverride($actor, 'orders.view', 'store:'.$foreign, $foreign, 'allow');
        $this->insertOverride($actor, 'orders.view', 'store:'.$wholesale, $wholesale, 'allow');
        $this->insertOverride($actor, 'b2b.accounts.view', 'store:'.$own, $own, 'allow');

        $current = $actor->fresh();
        $this->assertFalse($current->hasPermission('orders.view', $foreign));
        $this->assertFalse($current->hasPermission('orders.view', $wholesale));
        $this->assertFalse($current->hasPermission('b2b.accounts.view', $own));
        $this->assertNotContains('orders.view', $current->effectivePermissionCodes($foreign));
        $this->assertNotContains('b2b.accounts.view', $current->effectivePermissionCodes($own));
        $this->assertTrue($current->hasPermission('inventory.view', $own));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $this->actingAs($actor->fresh())
                ->get('/admin/b2c/orders?store_id='.$foreign)
                ->assertForbidden();
            $this->actingAs($actor->fresh())
                ->get('/admin/b2b/orders')
                ->assertForbidden();
        }
    }

    /** @param list<int> $storeIds */
    private function inventoryUser(array $storeIds): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $roleId = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail()->id;

        foreach ($storeIds as $storeId) {
            DB::table('user_store_roles')->insert([
                'user_id' => $user->id,
                'store_id' => $storeId,
                'role_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user->fresh();
    }

    private function store(string $type, string $code): int
    {
        $values = [
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if ($type === 'B2C') {
            $values['business_profile'] = StoreBusinessProfile::RETAIL_STORE->value;
        }

        return (int) DB::table('stores')->insertGetId($values);
    }

    private function insertOverride(
        User $actor,
        string $permissionCode,
        string $scopeKey,
        ?int $storeId,
        string $effect,
    ): void {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', $permissionCode)->firstOrFail()->id,
            'scope_key' => $scopeKey,
            'store_id' => $storeId,
            'effect' => $effect,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
    }
}
