<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2RoleCodeHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_custom_role_archive_reserves_code_and_keeps_original_permissions_and_audit(): void
    {
        $admin = $this->superAdmin();
        $role = $this->customRole('V2_HISTORICAL_ROLE_2836');
        $permissionId = Permission::query()->where('code', 'orders.view')->firstOrFail()->getKey();
        $role->permissions()->attach($permissionId);

        $this->assertTrue(Schema::hasColumn('roles', 'archived_at'));
        $this->assertNull($role->archived_at);

        Sanctum::actingAs($admin);
        $this->deleteJson('/api/v1/admin/security/roles/'.$role->id)->assertNoContent();

        $archived = $role->fresh();
        $this->assertNotNull($archived);
        $this->assertNotNull($archived->archived_at);
        $this->assertFalse($archived->is_active);
        $this->assertSame('V2_HISTORICAL_ROLE_2836', $archived->code);
        $this->assertSame('global', $archived->scope);
        $this->assertTrue($archived->permissions()->whereKey($permissionId)->exists());

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'role.archived',
            'user_id' => $admin->getKey(),
            'auditable_type' => Role::class,
            'auditable_id' => $role->getKey(),
        ]);

        $this->getJson('/api/v1/admin/security/roles')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $role->getKey(),
                'code' => 'V2_HISTORICAL_ROLE_2836',
                'is_active' => false,
                'archived_at' => $archived->archived_at->toIso8601String(),
            ]);

        $this->postJson('/api/v1/admin/security/roles', [
            'code' => $role->code,
            'name' => 'Reused identity',
            'scope' => 'store',
            'is_active' => true,
            'permission_ids' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->patchJson('/api/v1/admin/security/roles/'.$role->id, [
            'name' => 'Reactivated',
            'scope' => 'global',
            'is_active' => true,
            'permission_ids' => [$permissionId],
        ])->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->postJson('/api/v1/admin/security/roles/'.$role->id.'/clone', [
            'code' => 'V2_HISTORICAL_ROLE_2836_COPY',
            'name' => 'Forbidden archived clone',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->deleteJson('/api/v1/admin/security/roles/'.$role->id)
            ->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->assertSame(1, Role::query()->where('code', 'V2_HISTORICAL_ROLE_2836')->count());
        $this->assertSame(1, Role::query()->whereNotNull('archived_at')->whereKey($role->id)->count());
    }

    public function test_role_code_and_scope_cannot_be_repurposed_even_when_role_is_unassigned(): void
    {
        $admin = $this->superAdmin();
        $role = $this->customRole('V2_IMMUTABLE_ROLE_2836');
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/admin/security/roles/'.$role->id, [
            'name' => 'Different business meaning',
            'scope' => 'store',
            'is_active' => true,
            'permission_ids' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('scope');

        $this->assertSame('global', $role->fresh()->scope);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'role.updated',
            'auditable_id' => $role->id,
        ]);

        try {
            $role->forceFill(['code' => 'V2_REPURPOSED_ROLE_2836'])->save();
            $this->fail('Role code changes must fail closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }

        $this->assertSame('V2_IMMUTABLE_ROLE_2836', $role->fresh()->code);
        $this->assertDatabaseMissing('roles', ['code' => 'V2_REPURPOSED_ROLE_2836']);
    }

    public function test_assigned_role_and_system_role_cannot_be_archived(): void
    {
        $admin = $this->superAdmin();
        $role = $this->customRole('V2_ASSIGNED_ROLE_2836');
        $member = User::factory()->create(['is_active' => true]);
        $member->roles()->attach($role);
        Sanctum::actingAs($admin);

        $this->deleteJson('/api/v1/admin/security/roles/'.$role->id)
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertTrue($role->fresh()->is_active);
        $this->assertNull($role->fresh()->archived_at);
        $this->assertTrue($member->fresh()->hasRole($role->code));

        $system = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $this->deleteJson('/api/v1/admin/security/roles/'.$system->id)
            ->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->assertNull($system->fresh()->archived_at);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'role.archived',
            'auditable_id' => $role->id,
        ]);
    }

    public function test_dashboard_shows_readonly_archive_state_in_both_languages(): void
    {
        $admin = $this->superAdmin();
        $role = $this->customRole('V2_BILINGUAL_HISTORY_2836');
        Sanctum::actingAs($admin);
        $this->deleteJson('/api/v1/admin/security/roles/'.$role->id)->assertNoContent();

        foreach ([
            'en' => ['Archived', 'Archived roles remain visible for audit history'],
            'ar' => ['مؤرشف', 'الأدوار المؤرشفة تبقى للسجل التاريخي'],
        ] as $locale => $texts) {
            $admin->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($admin->fresh())
                ->get(route('admin.security.index', ['section' => 'roles']));

            $response->assertOk()
                ->assertSee('data-role-archived="1"', false)
                ->assertSee($texts[0])
                ->assertSee($texts[1])
                ->assertDontSee('action="'.route('admin.security.roles.update', $role).'"', false)
                ->assertDontSee('action="'.route('admin.security.roles.destroy', $role).'"', false);
        }
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function customRole(string $code): Role
    {
        return Role::query()->create([
            'code' => $code,
            'name' => 'Historical role',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
    }
}
