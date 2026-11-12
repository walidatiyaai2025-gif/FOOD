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

final class V2PredeleteAccountRelationshipAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_seven_synthetic_roleless_identities_remain_classified_and_retained_after_a_reviewed_archive(): void
    {
        $admin = $this->superAdmin('ar');
        $identities = collect();

        for ($i = 1; $i <= 7; $i++) {
            $identities->push(User::factory()->create([
                'name' => "V2 Predelete Review {$i}",
                'is_platform_customer' => $i <= 4,
                'is_active' => true,
            ]));
        }

        $target = $identities->last();
        $originalEmail = $target->email;
        $token = $target->createToken('must-revoke-on-archive');
        $session = AdminSecuritySession::query()->create([
            'user_id' => $target->id,
            'session_hash' => hash('sha256', 'v2-p075-retained-session'),
            'last_seen_at' => now(),
        ]);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertSame(1, AdminSecuritySession::query()->where('user_id', $target->id)->count());

        $response = $this->actingAs($admin)->get(route('admin.security.index', [
            'section' => 'users',
            'assignment' => 'roleless',
        ]));

        $response->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertViewHas('users', fn ($users): bool => $users->total() === 7
                && $users->getCollection()->pluck('id')->sort()->values()->all()
                    === $identities->pluck('id')->sort()->values()->all())
            ->assertSee('بلا دور مسند — يلزم مراجعة نوع الحساب')
            ->assertSee('data-roleless-classification="customer"', false)
            ->assertSee('data-roleless-classification="review"', false);

        // The historic "seven" is only synthetic fixture data, never a production delete list.
        foreach ($identities as $identity) {
            $this->assertSame(0, $identity->roles()->count());
            $this->assertSame(0, $identity->storeRoleAssignments()->count());
            $this->assertDatabaseHas('users', ['id' => $identity->id]);
        }

        $this->actingAs($admin)->patch(route('admin.security.users.status', $target), [
            'is_active' => false,
            'reason' => 'P075 reviewed archival; retain identity and dependencies',
        ])->assertRedirect();

        $target->refresh();
        $this->assertFalse((bool) $target->is_active);
        $this->assertNotNull($target->deactivated_at);
        $this->assertSame($originalEmail, $target->email);
        $this->assertSame('P075 reviewed archival; retain identity and dependencies', $target->deactivation_reason);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'event' => 'user.status_changed',
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
        ]);

        foreach ($identities as $identity) {
            $this->assertDatabaseHas('users', ['id' => $identity->id]);
        }
    }

    public function test_store_operator_cannot_enumerate_or_archive_unassigned_identities_in_another_scope(): void
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-P075-SCOPE',
            'name' => 'V2 P075 scope store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $operator = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        UserStoreRole::query()->create([
            'user_id' => $operator->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
        ]);
        $target = User::factory()->create([
            'is_active' => true,
            'is_platform_customer' => false,
        ]);
        $email = $target->email;
        $token = $target->createToken('preserve-after-denied-action');

        $this->actingAs($operator)
            ->get(route('admin.security.index', ['section' => 'users', 'assignment' => 'roleless']))
            ->assertForbidden();

        $this->actingAs($operator)
            ->patch(route('admin.security.users.status', $target), [
                'is_active' => false,
                'reason' => 'Unauthorized cross-tenant deletion preparation',
            ])->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'email' => $email,
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseMissing('audit_logs', [
            'user_id' => $operator->id,
            'event' => 'user.status_changed',
            'auditable_id' => $target->id,
        ]);
    }

    private function superAdmin(string $locale): User
    {
        $user = User::factory()->create(['locale' => $locale, 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail()->id);

        return $user;
    }
}
