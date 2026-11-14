<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2LastSuperAdminMutationSerializationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_arabic_status_cannot_remove_final_admin_after_a_stale_preflight(): void
    {
        $this->assertStaleStatusIsRejected('ar');
    }

    public function test_english_status_cannot_remove_final_admin_after_a_stale_preflight(): void
    {
        $this->assertStaleStatusIsRejected('en');
    }

    private function assertStaleStatusIsRejected(string $locale): void
    {
        $first = $this->superAdmin();
        $other = $this->superAdmin();
        $operator = $this->operator();
        $operator->forceFill(['locale' => $locale])->save();

        $injected = false;
        $this->disableOtherAdminImmediatelyAfterPreflight($other, $injected);
        Sanctum::actingAs($operator);

        $this->patchJson("/api/v1/admin/security/users/{$first->id}/status", [
            'is_active' => false,
            'reason' => 'Reviewed offboarding after impact assessment',
            'impact_reviewed' => true,
            'confirm_email' => $first->email,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('is_active')
            ->assertJsonPath(
                'errors.is_active.0',
                trans('admin.security.errors.last_super_admin', [], $locale),
            );

        $this->assertTrue($injected, 'The preflight count must be reached before mutation.');
        $this->assertTrue((bool) $first->fresh()->is_active);
        $this->assertFalse((bool) $other->fresh()->is_active);
        $this->assertSame(1, $this->activeSuperAdminCount());
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'user.status_changed',
            'auditable_id' => $first->id,
        ]);
    }

    public function test_role_demotion_cannot_remove_final_admin_after_a_stale_preflight(): void
    {
        $first = $this->superAdmin();
        $other = $this->superAdmin();
        $operator = $this->operator();

        $injected = false;
        $this->disableOtherAdminImmediatelyAfterPreflight($other, $injected);

        $this->actingAs($operator)
            ->from(route('admin.security.index', ['section' => 'users']))
            ->put(route('admin.security.users.roles', $first), [
                'change_reason' => 'Reviewed role demotion after security assessment',
                'global_role_ids' => [],
                'store_role_ids' => [],
            ])->assertRedirect()
            ->assertSessionHasErrors('global_role_ids');

        $this->assertTrue($injected, 'The preflight count must be reached before role synchronization.');
        $this->assertTrue($first->fresh()->roles()->where('roles.code', 'SUPER_ADMIN')->exists());
        $this->assertSame(1, $this->activeSuperAdminCount());
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'user.roles_changed',
            'auditable_id' => $first->id,
        ]);
    }

    public function test_non_final_status_change_and_role_demotion_remain_available(): void
    {
        $first = $this->superAdmin();
        $second = $this->superAdmin();
        $third = $this->superAdmin();
        $operator = $this->operator();

        Sanctum::actingAs($operator);
        $this->patchJson("/api/v1/admin/security/users/{$first->id}/status", [
            'is_active' => false,
            'reason' => 'Reviewed offboarding after impact assessment',
            'impact_reviewed' => true,
            'confirm_email' => $first->email,
        ])->assertOk()->assertJsonPath('data.is_active', false);

        $this->actingAs($operator)
            ->put(route('admin.security.users.roles', $second), [
                'change_reason' => 'Reviewed role demotion after security assessment',
                'global_role_ids' => [],
                'store_role_ids' => [],
            ])->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertFalse((bool) $first->fresh()->is_active);
        $this->assertFalse($second->fresh()->roles()->where('roles.code', 'SUPER_ADMIN')->exists());
        $this->assertTrue((bool) $third->fresh()->is_active);
        $this->assertSame(1, $this->activeSuperAdminCount());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.status_changed',
            'auditable_id' => $first->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.roles_changed',
            'auditable_id' => $second->id,
        ]);
    }

    public function test_mysql_uses_a_transactional_super_admin_row_lock_for_both_mutations(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Actual SELECT FOR UPDATE SQL is MySQL/MariaDB-specific.');
        }

        $first = $this->superAdmin();
        $second = $this->superAdmin();
        $this->superAdmin();
        $operator = $this->operator();

        $locked = 0;
        DB::listen(function (QueryExecuted $query) use (&$locked): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'roles') && str_contains($sql, 'for update')) {
                $this->assertGreaterThan(0, DB::transactionLevel());
                $locked++;
            }
        });

        Sanctum::actingAs($operator);
        $this->patchJson("/api/v1/admin/security/users/{$first->id}/status", [
            'is_active' => false,
            'reason' => 'Reviewed offboarding after impact assessment',
            'impact_reviewed' => true,
            'confirm_email' => $first->email,
        ])->assertOk();

        $this->actingAs($operator)
            ->put(route('admin.security.users.roles', $second), [
                'change_reason' => 'Reviewed role demotion after security assessment',
                'global_role_ids' => [],
                'store_role_ids' => [],
            ])->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertGreaterThanOrEqual(2, $locked, 'Both removal paths must lock the canonical role row.');
        $this->assertSame(1, $this->activeSuperAdminCount());
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function operator(): User
    {
        $role = Role::query()->create([
            'code' => 'V2_P072_LOCK_OPERATOR_'.strtoupper(substr(bin2hex(random_bytes(6)), 0, 12)),
            'name' => 'Security lifecycle test operator',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach(
            Permission::query()
                ->whereIn('code', ['users.status.manage', 'users.roles.manage', 'security.view'])
                ->pluck('id')
                ->all(),
        );

        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach($role);

        return $user;
    }

    private function activeSuperAdminCount(): int
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', static fn ($query) => $query
                ->where('roles.code', 'SUPER_ADMIN')
                ->where('roles.is_active', true))
            ->count();
    }

    private function disableOtherAdminImmediatelyAfterPreflight(User $other, bool &$injected): void
    {
        DB::listen(static function (QueryExecuted $query) use ($other, &$injected): void {
            $sql = strtolower($query->sql);
            if ($injected || ! str_contains($sql, 'count(*)')
                || ! str_contains($sql, 'users')
                || ! str_contains($sql, 'role_user')) {
                return;
            }

            $injected = true;
            DB::table('users')->where('id', $other->id)->update(['is_active' => false]);
        });
    }
}
