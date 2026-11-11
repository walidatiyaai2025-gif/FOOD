<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2ActiveUserAccountClassificationAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_current_account_inventory_filters_twenty_synthetic_accounts_without_mutation(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN');
        $globalRole = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $retailRole = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $storeId = $this->retailStoreId();
        $userIds = [];
        $inactive = [5, 6, 10, 15, 19, 20];

        // The historical 20 is a source snapshot, never a live user count.
        // These synthetic cohorts prove the current database query and classifications.
        for ($number = 1; $number <= 20; $number++) {
            $user = User::factory()->create([
                'name' => sprintf('V2 T015R01 Account %02d', $number),
                'is_active' => true,
                'is_platform_customer' => $number >= 11 && $number <= 15,
            ]);
            $userIds[] = $user->id;

            if ($number <= 6) {
                $user->roles()->attach($globalRole->id);
            } elseif ($number <= 10) {
                UserStoreRole::query()->create([
                    'user_id' => $user->id,
                    'store_id' => $storeId,
                    'role_id' => $retailRole->id,
                ]);
            }
        }

        $query = ['section' => 'users', 'q' => 'V2 T015R01 Account'];

        $this->actingAs($admin)
            ->get(route('admin.security.index', $query))
            ->assertOk()
            ->assertViewHas('users', fn ($users): bool => $users->total() === 20 && $users->count() === 15)
            ->assertSee('V2 T015R01 Account 01')
            ->assertDontSee('V2 T015R01 Account 20');

        $this->get(route('admin.security.index', [...$query, 'page' => 2]))
            ->assertOk()
            ->assertViewHas('users', fn ($users): bool => $users->total() === 20 && $users->count() === 5)
            ->assertSee('V2 T015R01 Account 20');

        // Reproduce the source snapshot's all-active cohort before proving
        // that filters follow changed current data instead of a fixed count.
        $this->get(route('admin.security.index', [...$query, 'status' => 'active']))
            ->assertOk()
            ->assertViewHas('users', fn ($users): bool => $users->total() === 20);

        $this->get(route('admin.security.index', [...$query, 'status' => 'inactive']))
            ->assertOk()
            ->assertViewHas('users', fn ($users): bool => $users->total() === 0);

        $inactiveUserIds = array_map(static fn (int $number): int => $userIds[$number - 1], $inactive);
        User::query()->whereIn('id', $inactiveUserIds)->update(['is_active' => false]);

        $this->get(route('admin.security.index', [...$query, 'status' => 'active']))
            ->assertOk()
            ->assertViewHas('users', fn ($users): bool => $users->total() === 14);

        $this->get(route('admin.security.index', [...$query, 'status' => 'inactive']))
            ->assertOk()
            ->assertViewHas('users', fn ($users): bool => $users->total() === 6);

        $this->get(route('admin.security.index', [...$query, 'assignment' => 'roleless']))
            ->assertOk()
            ->assertViewHas('users', fn ($users): bool => $users->total() === 10)
            ->assertSee('data-roleless-classification="customer"', false)
            ->assertSee('data-roleless-classification="review"', false);

        $this->get(route('admin.security.index', [
            ...$query, 'assignment' => 'roleless', 'status' => 'inactive',
        ]))
            ->assertOk()
            ->assertViewHas('users', fn ($users): bool => $users->total() === 3);

        $this->assertSame(6, DB::table('role_user')->whereIn('user_id', $userIds)->count());
        $this->assertSame(4, DB::table('user_store_roles')->whereIn('user_id', $userIds)->count());
        $this->assertSame(20, User::query()->whereIn('id', $userIds)->count());
        $this->assertSame(14, User::query()->whereIn('id', $userIds)->where('is_active', true)->count());
        $this->assertSame(0, DB::table('audit_logs')
            ->whereIn('auditable_id', $userIds)
            ->where('auditable_type', User::class)
            ->where('event', 'user.status_changed')
            ->count());
    }

    public function test_arabic_account_status_audit_reuses_authorized_rtl_view_without_privilege_grants(): void
    {
        $admin = $this->userWithRole('SUPER_ADMIN', 'ar');
        User::factory()->create([
            'name' => 'V2 T015R01 Arabic Customer',
            'is_active' => false,
            'is_platform_customer' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.security.index', [
                'section' => 'users',
                'q' => 'V2 T015R01 Arabic Customer',
                'assignment' => 'roleless',
                'status' => 'inactive',
            ]))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('V2 T015R01 Arabic Customer')
            ->assertSee('data-roleless-classification="customer"', false)
            ->assertViewHas('users', fn ($users): bool => $users->total() === 1);
    }

    public function test_store_admin_cannot_inspect_other_accounts_through_security_inventory_http_route(): void
    {
        $storeId = $this->retailStoreId();
        $operator = User::factory()->create(['is_active' => true]);
        $target = User::factory()->create([
            'name' => 'V2 T015R01 Protected User',
            'is_active' => true,
        ]);
        UserStoreRole::query()->create([
            'user_id' => $operator->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
        ]);

        $this->actingAs($operator)
            ->get(route('admin.security.index', ['section' => 'users', 'q' => 'V2 T015R01']))
            ->assertForbidden();

        $this->assertTrue((bool) $target->fresh()->is_active);
        $this->assertDatabaseMissing('role_user', ['user_id' => $target->id]);
        $this->assertDatabaseMissing('user_store_roles', ['user_id' => $target->id]);
    }

    private function userWithRole(string $code, string $locale = 'en'): User
    {
        $user = User::factory()->create(['locale' => $locale, 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail()->id);

        return $user;
    }

    private function retailStoreId(): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-T015R01-RETAIL',
            'name' => 'Synthetic audit retail store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
