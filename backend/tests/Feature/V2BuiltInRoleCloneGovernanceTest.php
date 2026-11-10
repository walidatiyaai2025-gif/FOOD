<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2BuiltInRoleCloneGovernanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_built_in_job_role_can_be_cloned_into_independent_custom_role_with_audit(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN');
        $source = Role::query()->where('code', 'ACCOUNTANT')->firstOrFail();
        $sourcePermissionIds = $source->permissions()->pluck('permissions.id')->map('intval')->sort()->values()->all();

        $this->actingAs($admin)
            ->post(route('admin.security.roles.clone', $source), [
                'code' => 'V2_ACCOUNTANT_CUSTOM',
                'name' => 'Custom accounting reviewer',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $clone = Role::query()->where('code', 'V2_ACCOUNTANT_CUSTOM')->firstOrFail();
        $this->assertFalse($clone->is_system);
        $this->assertTrue($clone->is_active);
        $this->assertNull($clone->archived_at);
        $this->assertSame($source->scope, $clone->scope);
        $this->assertSame($sourcePermissionIds, $clone->permissions()->pluck('permissions.id')->map('intval')->sort()->values()->all());
        $this->assertSame($sourcePermissionIds, $source->fresh()->permissions()->pluck('permissions.id')->map('intval')->sort()->values()->all());
        $this->assertSame(0, DB::table('role_user')->where('role_id', $clone->id)->count());
        $this->assertSame(0, DB::table('user_store_roles')->where('role_id', $clone->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'role.created',
            'user_id' => $admin->id,
            'auditable_type' => Role::class,
            'auditable_id' => $clone->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'role.cloned',
            'user_id' => $admin->id,
            'auditable_type' => Role::class,
            'auditable_id' => $clone->id,
        ]);
        $provenance = DB::table('audit_logs')
            ->where('event', 'role.cloned')
            ->where('auditable_id', $clone->id)
            ->value('before');
        $this->assertSame($source->id, json_decode((string) $provenance, true)['source_role_id']);
        $this->assertSame($source->code, json_decode((string) $provenance, true)['source_role_code']);
    }

    public function test_built_in_role_cannot_be_edited_or_archived_even_if_system_flag_drifted(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN');
        $role = Role::query()->where('code', 'ACCOUNTANT')->firstOrFail();
        $originalName = $role->name;
        $permissions = $role->permissions()->pluck('permissions.id')->sort()->values()->all();

        $this->actingAs($admin)
            ->patch(route('admin.security.roles.update', $role), [
                'name' => 'Tampered',
                'description' => 'Changed',
                'scope' => $role->scope,
                'is_active' => 0,
                'permission_ids' => [],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('role');

        // A stale/malformed is_system flag cannot turn a catalog role into
        // a user-owned role, even if the reconciler is not yet invoked.
        DB::table('roles')->where('id', $role->id)->update(['is_system' => false]);
        $this->actingAs($admin)
            ->delete(route('admin.security.roles.destroy', $role))
            ->assertRedirect()
            ->assertSessionHasErrors('role');

        $fresh = $role->fresh();
        $this->assertSame($originalName, $fresh->name);
        $this->assertNull($fresh->archived_at);
        $this->assertTrue($fresh->is_active);
        $this->assertEqualsCanonicalizing($permissions, $fresh->permissions()->pluck('permissions.id')->all());
        $this->assertDatabaseMissing('audit_logs', ['event' => 'role.archived', 'auditable_id' => $role->id]);
    }

    public function test_control_plane_source_clone_is_denied_in_both_locales_and_not_rendered_as_action(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN');
        $superRole = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $safeRole = Role::query()->where('code', 'ACCOUNTANT')->firstOrFail();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $admin->forceFill(['locale' => $locale])->save();

            $html = (string) $this->actingAs($admin->fresh())
                ->get(route('admin.security.index', ['section' => 'roles']))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->getContent();

            $this->assertStringContainsString('action="'.route('admin.security.roles.clone', $safeRole).'"', $html);
            $this->assertStringNotContainsString('action="'.route('admin.security.roles.clone', $superRole).'"', $html);

            $this->actingAs($admin->fresh())
                ->post(route('admin.security.roles.clone', $superRole), [
                    'code' => 'V2_ESCALATED_'.strtoupper($locale),
                    'name' => 'Forbidden control plane copy',
                ])
                ->assertRedirect()
                ->assertSessionHasErrors(['role' => __('v2_role_clone.privileged_source')]);

            $this->assertDatabaseMissing('roles', ['code' => 'V2_ESCALATED_'.strtoupper($locale)]);
        }
    }

    public function test_platform_owner_and_inactive_sources_cannot_be_resurrected_as_custom_roles(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN');
        $owner = Role::query()->firstOrCreate(
            ['code' => 'PLATFORM_OWNER'],
            ['name' => 'Platform Owner', 'scope' => 'global', 'is_system' => true, 'is_active' => true],
        );
        $inactive = Role::query()->create([
            'code' => 'V2_INACTIVE_ROLE',
            'name' => 'Inactive template',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => false,
        ]);
        foreach ([[$owner, 'V2_OWNER_COPY'], [$inactive, 'V2_INACTIVE_COPY']] as [$source, $code]) {
            $this->actingAs($admin)
                ->post(route('admin.security.roles.clone', $source), [
                    'code' => $code,
                    'name' => 'Forbidden copy',
                ])
                ->assertRedirect()
                ->assertSessionHasErrors('role');
            $this->assertDatabaseMissing('roles', ['code' => $code]);
        }
    }

    public function test_delegated_roles_manager_cannot_clone_permissions_it_does_not_possess(): void
    {
        $operator = User::factory()->create(['is_active' => true]);
        $operatorRole = Role::query()->create([
            'code' => 'V2_CLONE_OPERATOR',
            'name' => 'Role operator',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $operatorRole->permissions()->attach(Permission::query()->where('code', 'roles.manage')->firstOrFail()->id);
        $operator->roles()->attach($operatorRole);
        $source = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();

        $this->actingAs($operator)
            ->post(route('admin.security.roles.clone', $source), [
                'code' => 'V2_CLONE_OVERPRIVILEGED',
                'name' => 'Forbidden grant',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('permission_ids');

        $this->assertDatabaseMissing('roles', ['code' => 'V2_CLONE_OVERPRIVILEGED']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'role.cloned', 'user_id' => $operator->id]);
    }

    public function test_store_scoped_builtin_clone_remains_store_scoped_without_automatic_assignment(): void
    {
        $admin = $this->globalUser('SUPER_ADMIN');
        $source = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.security.roles.clone', $source), [
                'code' => 'V2_RETAIL_STORE_TEMPLATE',
                'name' => 'Retail store custom role',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $cloned = Role::query()->where('code', 'V2_RETAIL_STORE_TEMPLATE')->firstOrFail();
        $this->assertSame('store', $cloned->scope);
        $this->assertFalse($cloned->is_system);
        $this->assertSame(0, DB::table('user_store_roles')->where('role_id', $cloned->id)->count());

        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)
            ->post(route('admin.security.roles.clone', $source), [
                'code' => 'V2_RETAIL_UNAUTHORIZED',
                'name' => 'Unauthorized',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('roles', ['code' => 'V2_RETAIL_UNAUTHORIZED']);
    }

    private function globalUser(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
