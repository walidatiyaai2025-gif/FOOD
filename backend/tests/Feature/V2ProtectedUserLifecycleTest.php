<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Services\PlatformOwnerProvisioner;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2ProtectedUserLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'V2-secure-password!2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_and_multi_role_operator_cannot_anonymize_themselves_through_customer_api(): void
    {
        foreach ([['SUPER_ADMIN'], ['B2C_DRIVER', 'B2B_ADMIN']] as $codes) {
            $user = $this->userWithRoles($codes);
            $originalEmail = $user->email;
            $token = $user->createToken('must-remain-active');

            Sanctum::actingAs($user);

            $this->postJson('/api/v1/account-deletion', [
                'password' => self::PASSWORD,
                'confirmation' => true,
            ])->assertUnprocessable()->assertJsonValidationErrors('confirmation');

            $this->assertDatabaseMissing('account_deletion_requests', ['user_id' => $user->id]);
            $this->assertDatabaseHas('users', [
                'id' => $user->id,
                'email' => $originalEmail,
                'is_active' => 1,
            ]);
            $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
        }
    }

    public function test_registered_platform_owner_cannot_use_mobile_self_deletion(): void
    {
        $owner = app(PlatformOwnerProvisioner::class)->provision(
            'v2-protected-owner@example.test',
            self::PASSWORD,
        );
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/account-deletion', [
            'password' => self::PASSWORD,
            'confirmation' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('confirmation');

        $this->assertDatabaseHas('platform_owner_registry', ['user_id' => $owner->id]);
        $this->assertDatabaseMissing('account_deletion_requests', ['user_id' => $owner->id]);
        $this->assertTrue($owner->fresh()->is_active);
        $this->assertSame('v2-protected-owner@example.test', $owner->fresh()->email);
    }

    public function test_store_scoped_management_identity_cannot_self_delete(): void
    {
        $user = $this->userWithRoles([]);
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-USER-GUARD-STORE',
            'name' => 'V2 guarded retail store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        UserStoreRole::query()->create([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/account-deletion', [
            'password' => self::PASSWORD,
            'confirmation' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('confirmation');

        $this->assertTrue($user->fresh()->is_active);
        $this->assertDatabaseMissing('account_deletion_requests', ['user_id' => $user->id]);
        $this->assertDatabaseHas('user_store_roles', ['user_id' => $user->id, 'store_id' => $storeId]);
    }

    public function test_driver_and_van_operational_roles_are_retained_for_managed_review(): void
    {
        foreach (['B2C_DRIVER', 'B2B_DRIVER', 'VAN_OPERATOR'] as $roleCode) {
            $user = $this->userWithRoles([$roleCode]);
            $email = $user->email;
            Sanctum::actingAs($user);

            $response = $this->postJson('/api/v1/account-deletion', [
                'password' => self::PASSWORD,
                'confirmation' => true,
            ])->assertCreated();

            $response->assertJsonPath('data.app', 'driver')
                ->assertJsonPath('data.status', 'OPERATIONAL_REVIEW_REQUIRED');
            $this->assertDatabaseHas('users', [
                'id' => $user->id,
                'email' => $email,
                'is_active' => 1,
            ]);
            $this->assertDatabaseHas('account_deletion_requests', [
                'user_id' => $user->id,
                'status' => 'OPERATIONAL_REVIEW_REQUIRED',
            ]);
        }
    }

    public function test_admin_api_rejects_self_deactivation_and_the_last_super_admin(): void
    {
        $superAdmin = $this->userWithRoles(['SUPER_ADMIN']);
        Sanctum::actingAs($superAdmin);

        $this->patchJson("/api/v1/admin/security/users/{$superAdmin->id}/status", [
            'is_active' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('is_active');

        $operatorRole = Role::query()->create([
            'code' => 'V2_STATUS_OPERATOR',
            'name' => 'V2 Status Operator',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $operatorRole->permissions()->attach(
            Permission::query()->where('code', 'users.status.manage')->firstOrFail()->id,
        );
        $operator = $this->userWithRoles([]);
        $operator->roles()->attach($operatorRole);
        Sanctum::actingAs($operator);

        $this->patchJson("/api/v1/admin/security/users/{$superAdmin->id}/status", [
            'is_active' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('is_active');

        $this->assertTrue($superAdmin->fresh()->is_active);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'user.status_changed',
            'auditable_id' => $superAdmin->id,
        ]);
    }

    public function test_authorized_deactivation_of_non_last_admin_revokes_tokens_but_preserves_account_and_audit(): void
    {
        $operator = $this->userWithRoles(['SUPER_ADMIN']);
        $target = $this->userWithRoles(['SUPER_ADMIN']);
        $targetEmail = $target->email;
        $token = $target->createToken('old-admin-session');
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);

        Sanctum::actingAs($operator);

        $this->patchJson("/api/v1/admin/security/users/{$target->id}/status", [
            'is_active' => false,
            'reason' => 'V2 secure offboarding',
        ])->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'email' => $targetEmail,
            'is_active' => 0,
            'deactivation_reason' => 'V2 secure offboarding',
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.status_changed',
            'auditable_id' => $target->id,
            'user_id' => $operator->id,
        ]);
        $this->assertTrue($operator->fresh()->is_active);
    }

    /** @param list<string> $roles */
    private function userWithRoles(array $roles): User
    {
        $user = User::factory()->create([
            'password' => Hash::make(self::PASSWORD),
            'locale' => 'en',
            'is_active' => true,
        ]);
        foreach ($roles as $code) {
            $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail()->id);
        }

        return $user;
    }
}
