<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 T019R03: a historical export indicates overlapping customer-service
 * assignments. Synthetic actors prove safe, audited consolidation without
 * claiming to have inspected or changed the historical account in production.
 */
final class V2CustomerServiceRoleOverlapAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_consolidation_removes_excess_permissions_without_touching_other_users(): void
    {
        $admin = $this->actorWithRoles(['SUPER_ADMIN']);
        $target = $this->actorWithRoles(['CUSTOMER_SERVICE_AGENT', 'CUSTOMER_SUPPORT']);
        $peer = $this->actorWithRoles(['CUSTOMER_SUPPORT']);
        $agentRole = Role::query()->where('code', 'CUSTOMER_SERVICE_AGENT')->firstOrFail();
        $supportRole = Role::query()->where('code', 'CUSTOMER_SUPPORT')->firstOrFail();

        $this->assertTrue($target->hasPermission('support.manage'));
        $this->assertTrue($target->hasPermission('orders.create'));
        $this->assertTrue($peer->hasPermission('support.manage'));

        $this->actingAs($admin)
            ->put(route('admin.security.users.roles', $target), [
                'global_role_ids' => [(int) $agentRole->getKey()],
                'change_reason' => 'Reviewed duplicate customer support assignments',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $target = $target->fresh();
        $peer = $peer->fresh();

        $this->assertEqualsCanonicalizing(
            ['CUSTOMER_SERVICE_AGENT'],
            $target->roles()->pluck('code')->all(),
        );
        $this->assertDatabaseHas('role_user', [
            'user_id' => $target->getKey(),
            'role_id' => $agentRole->getKey(),
        ]);
        $this->assertDatabaseMissing('role_user', [
            'user_id' => $target->getKey(),
            'role_id' => $supportRole->getKey(),
        ]);
        $this->assertFalse($target->hasPermission('support.manage'));
        $this->assertTrue($target->hasPermission('orders.create'));
        $this->assertFalse($target->hasPermission('roles.manage'));
        $this->assertTrue((bool) $target->is_active);

        // Consolidation is scoped to the selected account, not a shared role edit.
        $this->assertTrue($peer->hasRole('CUSTOMER_SUPPORT'));
        $this->assertTrue($peer->hasPermission('support.manage'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.roles_changed',
            'user_id' => $admin->getKey(),
            'auditable_type' => User::class,
            'auditable_id' => $target->getKey(),
        ]);
    }

    public function test_customer_service_actor_cannot_change_another_users_roles_in_either_locale(): void
    {
        $target = $this->actorWithRoles(['CUSTOMER_SERVICE_AGENT', 'CUSTOMER_SUPPORT']);
        $agentRole = Role::query()->where('code', 'CUSTOMER_SERVICE_AGENT')->firstOrFail();
        $original = $target->roles()->pluck('code')->sort()->values()->all();

        foreach (['ar', 'en'] as $locale) {
            $actor = $this->actorWithRoles(['CUSTOMER_SERVICE_AGENT'], $locale);
            app()->setLocale($locale);

            $this->actingAs($actor)
                ->put(route('admin.security.users.roles', $target), [
                    'global_role_ids' => [(int) $agentRole->getKey()],
                    'change_reason' => 'Attempted unauthorized role consolidation',
                ])
                ->assertForbidden();

            $this->assertEqualsCanonicalizing(
                $original,
                $target->fresh()->roles()->pluck('code')->all(),
            );
            $this->assertDatabaseMissing('audit_logs', [
                'event' => 'user.roles_changed',
                'user_id' => $actor->getKey(),
                'auditable_id' => $target->getKey(),
            ]);
        }
    }

    public function test_bilingual_security_center_remains_admin_only_during_role_review(): void
    {
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $admin = $this->actorWithRoles(['SUPER_ADMIN'], $locale);
            $agent = $this->actorWithRoles(['CUSTOMER_SERVICE_AGENT'], $locale);

            $this->actingAs($admin)
                ->get(route('admin.security.index', ['section' => 'users']))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($agent)
                ->get(route('admin.security.index', ['section' => 'users']))
                ->assertForbidden();
        }
    }


    public function test_authorized_consolidation_preserves_explicit_retail_scope_and_removes_stale_permission_overrides(): void
    {
        $admin = $this->actorWithRoles(['SUPER_ADMIN']);
        $target = $this->actorWithRoles(['CUSTOMER_SERVICE_AGENT', 'CUSTOMER_SUPPORT']);
        $agentRole = Role::query()->where('code', 'CUSTOMER_SERVICE_AGENT')->firstOrFail();
        $retailRole = Role::query()->where('code', 'RETAIL_CUSTOMER_SUPPORT')->firstOrFail();

        $retailStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-2769-RETAIL-OWNED',
            'name' => 'V2 2769 Scoped Retail Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_store_roles')->insert([
            'user_id' => $target->getKey(),
            'store_id' => $retailStoreId,
            'role_id' => $retailRole->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_permission_overrides')->insert([
            'user_id' => $target->getKey(),
            'permission_id' => DB::table('permissions')->where('code', 'support.manage')->value('id'),
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $target->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($admin)->put(route('admin.security.users.roles', $target), [
            'global_role_ids' => [(int) $agentRole->getKey()],
            'store_roles' => [
                ['store_id' => $retailStoreId, 'role_id' => $retailRole->getKey()],
            ],
            'change_reason' => 'Reviewed role consolidation preserving an approved Retail assignment',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $target = $target->fresh();

        $this->assertEqualsCanonicalizing(
            ['CUSTOMER_SERVICE_AGENT'],
            $target->roles()->pluck('code')->all(),
        );
        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $target->getKey(),
            'store_id' => $retailStoreId,
            'role_id' => $retailRole->getKey(),
        ]);
        $this->assertSame(1, $target->storeRoleAssignments()->count());
        $this->assertSame(0, DB::table('user_permission_overrides')->where('user_id', $target->getKey())->count());
        $this->assertFalse((bool) $target->has_permission_overrides);
        $this->assertFalse($target->hasPermission('support.manage'));
        $this->assertTrue($target->hasPermission('orders.create'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.roles_changed',
            'user_id' => $admin->getKey(),
            'auditable_type' => User::class,
            'auditable_id' => $target->getKey(),
        ]);
    }

    /** @param list<string> $roleCodes */
    private function actorWithRoles(array $roleCodes, string $locale = 'en'): User
    {
        $actor = User::factory()->create([
            'is_active' => true,
            'locale' => $locale,
        ]);

        $ids = Role::query()->whereIn('code', $roleCodes)->pluck('id')->all();
        $this->assertCount(count($roleCodes), $ids);
        $actor->roles()->attach($ids);

        return $actor->fresh();
    }
}
