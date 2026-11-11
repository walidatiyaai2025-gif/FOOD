<?php

namespace Tests\Feature;

use App\Models\AdminSecuritySession;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2UserSoftArchiveRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_soft_archive_preserves_identity_and_both_role_scopes_while_revoking_every_app_session(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN');
        $target = $this->userWithGlobalRole('B2B_ADMIN');
        $email = $target->email;
        $passwordHash = $target->password;
        $roleId = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail()->id;
        $storeId = $this->retailStoreId();
        $storeRoleId = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id;

        UserStoreRole::query()->create([
            'user_id' => $target->id,
            'store_id' => $storeId,
            'role_id' => $storeRoleId,
        ]);
        $target->forceFill(['remember_token' => 'v2-old-remember-token'])->save();

        foreach (['customer', 'driver', 'van'] as $app) {
            $target->createToken('foodex-'.$app, ['app:'.$app]);
        }

        $securitySession = AdminSecuritySession::query()->create([
            'user_id' => $target->id,
            'session_hash' => hash('sha256', 'v2-archive-browser'),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.security.users.status', $target), [
                'is_active' => false,
                'reason' => 'V2 managed archival with retention',
            ])->assertRedirect();

        $target->refresh();
        $this->assertFalse((bool) $target->is_active);
        $this->assertNotNull($target->deactivated_at);
        $this->assertSame('V2 managed archival with retention', $target->deactivation_reason);
        $this->assertSame($email, $target->email);
        $this->assertSame($passwordHash, $target->password);
        $this->assertNotSame('v2-old-remember-token', $target->getRememberToken());
        $this->assertSame(0, $target->tokens()->count());
        $this->assertNotNull($securitySession->fresh()->revoked_at);
        $this->assertSame($admin->id, $securitySession->fresh()->revoked_by);
        $this->assertDatabaseHas('role_user', ['user_id' => $target->id, 'role_id' => $roleId]);
        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $target->id,
            'store_id' => $storeId,
            'role_id' => $storeRoleId,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'event' => 'user.status_changed',
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.security.users.status', $target), [
                'is_active' => true,
            ])->assertRedirect();

        $target->refresh();
        $this->assertTrue((bool) $target->is_active);
        $this->assertNull($target->deactivated_at);
        $this->assertNull($target->deactivation_reason);
        $this->assertSame($email, $target->email);
        $this->assertSame($passwordHash, $target->password);
        $this->assertSame(0, $target->tokens()->count());
        $this->assertNotNull($securitySession->fresh()->revoked_at);
        $this->assertDatabaseHas('role_user', ['user_id' => $target->id, 'role_id' => $roleId]);
        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $target->id,
            'store_id' => $storeId,
            'role_id' => $storeRoleId,
        ]);
    }

    public function test_store_scoped_manager_cannot_archive_another_identity_through_direct_http(): void
    {
        $operator = User::factory()->create(['is_active' => true]);
        $target = $this->userWithGlobalRole('B2B_ADMIN');
        $storeId = $this->retailStoreId();
        $storeRoleId = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id;

        UserStoreRole::query()->create([
            'user_id' => $operator->id,
            'store_id' => $storeId,
            'role_id' => $storeRoleId,
        ]);
        $token = $target->createToken('must-survive-unauthorized-attempt');

        $this->actingAs($operator)
            ->patch(route('admin.security.users.status', $target), [
                'is_active' => false,
                'reason' => 'Unauthorized cross-scope archive',
            ])->assertForbidden();

        $this->assertTrue((bool) $target->fresh()->is_active);
        $this->assertNull($target->fresh()->deactivated_at);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseMissing('audit_logs', [
            'user_id' => $operator->id,
            'event' => 'user.status_changed',
            'auditable_id' => $target->id,
        ]);
    }

    private function userWithGlobalRole(string $code): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail()->id);

        return $user;
    }

    private function retailStoreId(): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-ARCHIVE-'.DB::table('stores')->count(),
            'name' => 'V2 soft archival test store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
