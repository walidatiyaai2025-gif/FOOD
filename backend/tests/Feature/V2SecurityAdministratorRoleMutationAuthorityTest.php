<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T122R2 / #2682: Security Administrator role mutations must obey the
 * canonical effective-permission, tenant, reviewer and audit boundaries.
 * #2717 already owns the separate Sidebar/GET/RTL persona regression.
 */
final class V2SecurityAdministratorRoleMutationAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_security_administrator_is_a_platform_security_persona_without_erp_business_grants(): void
    {
        $definition = app(BuiltInJobRoleCatalog::class)->definition('SECURITY_ADMINISTRATOR');
        $this->assertIsArray($definition);
        $this->assertSame('administration', $definition['domain']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame('platform', $definition['business_scope']);
        $this->assertSame([], $definition['required_modules']);
        $this->assertSame([], $definition['optional_modules']);

        $actor = $this->securityAdministrator();
        $this->assertSame(
            collect($definition['permissions'])->sort()->values()->all(),
            $actor->roles()->where('roles.code', 'SECURITY_ADMINISTRATOR')
                ->firstOrFail()->permissions->pluck('code')->sort()->values()->all(),
        );
        $this->assertFalse($actor->hasRole('SUPER_ADMIN'));
        foreach ([
            'platform.manage', 'finance.view', 'finance.manage', 'finance.post',
            'finance.approve', 'orders.manage', 'orders.dispatch', 'inventory.manage',
            'drivers.b2c.manage', 'drivers.b2b.manage', 'system.update',
        ] as $permission) {
            $this->assertFalse($actor->hasPermission($permission), $permission);
        }
    }

    public function test_security_administrator_can_reassign_only_a_lesser_security_role_with_audited_reason(): void
    {
        $actor = $this->securityAdministrator();
        $target = $this->staffMember();
        $limited = $this->customGlobalRole('V2_SECURITY_REVIEWER', ['security.view']);
        $reason = 'Security review approved least-privilege role change';

        $this->actingAs($actor)
            ->from('/admin/security?section=users')
            ->put(route('admin.security.users.roles', $target), [
                'global_role_ids' => [$limited->id],
                'change_reason' => $reason,
            ])
            ->assertRedirect('/admin/security?section=users')
            ->assertSessionHasNoErrors();

        $this->assertTrue($target->fresh()->hasRole('V2_SECURITY_REVIEWER'));
        $this->assertFalse($target->fresh()->hasRole('CUSTOMER_SERVICE_AGENT'));
        $this->assertFalse($target->fresh()->hasPermission('roles.manage'));
        $audit = AuditLog::query()->where('event', 'user.roles_changed')
            ->where('auditable_id', $target->id)->latest('id')->firstOrFail();
        $this->assertContains('CUSTOMER_SERVICE_AGENT', $audit->before['global_roles']);
        $this->assertContains('V2_SECURITY_REVIEWER', $audit->after['global_roles']);
        $this->assertSame($reason, $audit->after['change_reason']);
    }

    public function test_security_administrator_cannot_assign_super_admin_platform_owner_or_finance_privilege(): void
    {
        $actor = $this->securityAdministrator();
        $target = $this->staffMember();
        $finance = $this->customGlobalRole('V2_UNAUTHORIZED_FINANCE', ['finance.manage']);
        $auditCount = AuditLog::query()->where('event', 'user.roles_changed')->count();

        foreach ([$finance, Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail()] as $forbidden) {
            $this->actingAs($actor)
                ->from('/admin/security?section=users')
                ->put(route('admin.security.users.roles', $target), [
                    'global_role_ids' => [$forbidden->id],
                    'change_reason' => 'Attempted unauthorized privilege escalation check',
                ])
                ->assertRedirect('/admin/security?section=users')
                ->assertSessionHasErrors('roles');
            $this->assertTrue($target->fresh()->hasRole('CUSTOMER_SERVICE_AGENT'));
            $this->assertFalse($target->fresh()->hasRole($forbidden->code));
        }

        $this->actingAs($actor)
            ->put(route('admin.security.users.roles', $target), [
                'global_role_ids' => [Role::query()->where('code', 'PLATFORM_OWNER')->firstOrFail()->id],
                'change_reason' => 'Attempted protected owner role elevation check',
            ])
            ->assertForbidden();

        $this->assertSame($auditCount, AuditLog::query()->where('event', 'user.roles_changed')->count());
        $this->assertTrue($target->fresh()->hasRole('CUSTOMER_SERVICE_AGENT'));
        $this->assertDatabaseMissing('user_permission_overrides', [
            'user_id' => $target->id,
            'effect' => 'allow',
        ]);
    }

    public function test_retail_store_role_cannot_be_assigned_by_a_security_only_principal(): void
    {
        $actor = $this->securityAdministrator();
        $target = $this->staffMember();
        $retail = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'P2682-RETAIL-A',
            'name' => 'P2682 Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        $this->actingAs($actor)
            ->from('/admin/security?section=users')
            ->put(route('admin.security.users.roles', $target), [
                'global_role_ids' => [],
                'store_role_ids' => [(string) $retail => [$role->id]],
                'change_reason' => 'Unauthorized Retail store assignment boundary test',
            ])
            ->assertRedirect('/admin/security?section=users')
            ->assertSessionHasErrors('roles');

        $this->assertDatabaseMissing('user_store_roles', [
            'user_id' => $target->id,
            'store_id' => $retail,
            'role_id' => $role->id,
        ]);
    }

    public function test_explicit_mutation_deny_blocks_http_reassignment_and_keeps_security_read_access(): void
    {
        $actor = $this->securityAdministrator();
        $target = $this->staffMember();
        $limited = $this->customGlobalRole('V2_SECURITY_READER', ['security.view']);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'users.roles.manage')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $fresh = $actor->fresh();
        $this->assertTrue($fresh->hasPermission('security.view'));
        $this->assertFalse($fresh->hasPermission('users.roles.manage'));
        $this->actingAs($fresh)->get(route('admin.security.index'))->assertOk();
        $this->actingAs($fresh)
            ->put(route('admin.security.users.roles', $target), [
                'global_role_ids' => [$limited->id],
                'change_reason' => 'Denied mutation cannot bypass security reviewer',
            ])
            ->assertForbidden();
        $this->assertTrue($target->fresh()->hasRole('CUSTOMER_SERVICE_AGENT'));
        $this->assertFalse($target->fresh()->hasRole('V2_SECURITY_READER'));
    }

    private function securityAdministrator(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SECURITY_ADMINISTRATOR')->firstOrFail());

        return $actor->fresh();
    }

    private function staffMember(): User
    {
        $target = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $target->roles()->attach(Role::query()->where('code', 'CUSTOMER_SERVICE_AGENT')->firstOrFail());

        return $target->fresh();
    }

    /** @param list<string> $permissions */
    private function customGlobalRole(string $code, array $permissions): Role
    {
        $role = Role::query()->create([
            'code' => $code,
            'name' => $code,
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('code', $permissions)->pluck('id'));

        return $role;
    }
}
