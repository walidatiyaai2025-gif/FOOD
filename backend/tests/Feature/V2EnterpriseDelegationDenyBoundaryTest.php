<?php

namespace Tests\Feature;

use App\Models\EnterpriseDelegation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\EnterpriseSecurityAccessResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 P134: no temporary delegation may override the delegate's explicit
 * user DENY, outlive a deactivated delegator, or bypass the normal scope.
 */
final class V2EnterpriseDelegationDenyBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_explicit_global_deny_overrides_live_delegation_in_both_locales(): void
    {
        $delegator = $this->b2bOperator();
        $delegate = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $grant = $this->delegation($delegator, $delegate);

        $resolver = app(EnterpriseSecurityAccessResolver::class);
        $this->assertTrue($resolver->allows($delegate, 'orders.approve', 'platform', null));

        $this->deny($delegate, 'orders.approve', 'global');

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $delegate->forceFill(['locale' => $locale])->save();
            $actor = $delegate->fresh();

            $this->assertFalse($actor->hasPermission('orders.approve'));
            $this->assertFalse($resolver->allows($actor, 'orders.approve', 'platform', null));
            $this->assertFalse($resolver->allows($actor, 'orders.edit', 'platform', null));
        }

        $this->assertDatabaseHas('enterprise_delegations', [
            'id' => $grant->getKey(),
            'delegator_id' => $delegator->getKey(),
            'delegate_id' => $delegate->getKey(),
            'permission_code' => 'orders.approve',
        ]);

        DB::table('user_permission_overrides')
            ->where('user_id', $delegate->getKey())
            ->delete();
        $delegate->forceFill(['has_permission_overrides' => false])->save();

        $this->assertTrue($resolver->allows($delegate->fresh(), 'orders.approve', 'platform', null));
        $grant->forceFill(['revoked_at' => now()])->save();
        $this->assertFalse($resolver->allows($delegate->fresh(), 'orders.approve', 'platform', null));
    }

    public function test_store_deny_blocks_only_denied_store_and_retains_correct_channel_scope(): void
    {
        $allowed = $this->store('V2-P134-ALLOWED');
        $blocked = $this->store('V2-P134-BLOCKED');
        $delegator = $this->b2bOperator();
        $delegate = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->delegation($delegator, $delegate);

        $resolver = app(EnterpriseSecurityAccessResolver::class);
        $this->assertTrue($resolver->allows($delegate, 'orders.approve', 'store', $allowed));
        $this->assertTrue($resolver->allows($delegate, 'orders.approve', 'store', $blocked));

        $this->deny($delegate, 'orders.approve', 'store:'.$blocked, $blocked);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $delegate->forceFill(['locale' => $locale])->save();
            $actor = $delegate->fresh();

            $this->assertTrue($resolver->allows($actor, 'orders.approve', 'store', $allowed));
            $this->assertFalse($resolver->allows($actor, 'orders.approve', 'store', $blocked));
            // Other contexts are independent of this store-specific DENY.
        }

        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $delegate->getKey(),
            'scope_key' => 'store:'.$blocked,
            'effect' => 'deny',
        ]);
    }

    public function test_deactivating_delegator_invalidates_grant_immediately_without_erasing_history(): void
    {
        $delegator = $this->b2bOperator();
        $delegate = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $grant = $this->delegation($delegator, $delegate);

        $resolver = app(EnterpriseSecurityAccessResolver::class);
        $this->assertTrue($resolver->allows($delegate, 'orders.approve', 'platform', null));

        $delegator->forceFill(['is_active' => false])->save();

        $this->assertFalse($resolver->allows($delegate->fresh(), 'orders.approve', 'platform', null));
        $this->assertDatabaseHas('enterprise_delegations', [
            'id' => $grant->getKey(),
            'revoked_at' => null,
        ]);

        $delegator->forceFill(['is_active' => true])->save();
        $this->assertTrue($resolver->allows($delegate->fresh(), 'orders.approve', 'platform', null));

        $delegate->forceFill(['is_active' => false])->save();
        $this->assertFalse($resolver->allows($delegate->fresh(), 'orders.approve', 'platform', null));

        $delegate->forceFill(['is_active' => true])->save();
        $grant->forceFill(['ends_at' => now()->subSecond()])->save();
        $this->assertFalse($resolver->allows($delegate->fresh(), 'orders.approve', 'platform', null));
    }

    public function test_non_security_actor_cannot_create_or_revoke_delegations_through_direct_http(): void
    {
        $delegator = $this->b2bOperator();
        $delegate = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $grant = $this->delegation($delegator, $delegate);
        $count = EnterpriseDelegation::query()->count();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $delegator->forceFill(['locale' => $locale])->save();

            $this->actingAs($delegator)->post(
                route('admin.enterprise-security.delegations.store'),
                [
                    'delegate_id' => $delegate->getKey(),
                    'permission_code' => 'orders.approve',
                    'scope_key' => 'platform:*',
                    'starts_at' => now()->subMinute()->toIso8601String(),
                    'ends_at' => now()->addHour()->toIso8601String(),
                ],
            )->assertForbidden();

            $this->actingAs($delegator)->delete(
                route('admin.enterprise-security.delegations.destroy', $grant),
            )->assertForbidden();
        }

        $this->assertSame($count, EnterpriseDelegation::query()->count());
        $this->assertNull($grant->fresh()->revoked_at);
    }

    private function b2bOperator(): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        return $user->fresh();
    }

    private function delegation(User $delegator, User $delegate): EnterpriseDelegation
    {
        return EnterpriseDelegation::query()->create([
            'delegator_id' => $delegator->getKey(),
            'delegate_id' => $delegate->getKey(),
            'permission_code' => 'orders.approve',
            'scope_type' => 'platform',
            'scope_id' => null,
            'scope_key' => 'platform:*',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
        ]);
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
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
