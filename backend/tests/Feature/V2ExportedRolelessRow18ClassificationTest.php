<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #2923 / historical Table 5 row 18: zero inherited Dashboard roles is
 * not evidence that a real account is invalid or should receive admin access.
 * Fixtures intentionally contain no actual identity from the source export.
 */
final class V2ExportedRolelessRow18ClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_roleless_account_type_reclassification_does_not_implicitly_assign_privileges(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $target = User::factory()->create([
            'name' => 'V2 Row18 Synthetic Account',
            'is_active' => true,
            'is_platform_customer' => false,
            'locale' => 'en',
        ]);
        $search = [
            'section' => 'users',
            'assignment' => 'roleless',
            'q' => 'V2 Row18 Synthetic Account',
        ];

        $this->actingAs($admin)
            ->get(route('admin.security.index', $search))
            ->assertOk()
            ->assertSee('dir="ltr"', false)
            ->assertSee('data-roleless-classification="review"', false)
            ->assertViewHas('users', fn ($users): bool => $users->total() === 1);

        // Classification is a separate, verified data fact, not an RBAC role
        // assignment. Only a synthetic fixture is reclassified in this test.
        $target->forceFill(['is_platform_customer' => true])->save();
        $admin->forceFill(['locale' => 'ar'])->save();
        app()->setLocale('ar');

        $this->actingAs($admin->fresh())
            ->get(route('admin.security.index', $search))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('data-roleless-classification="customer"', false)
            ->assertViewHas('users', fn ($users): bool => $users->total() === 1);

        $target = $target->fresh();
        $this->assertSame(0, $target->roles()->count());
        $this->assertSame(0, $target->storeRoleAssignments()->count());
        $this->assertSame([], $target->inheritedPermissionCodes());
        $this->assertSame([], $target->effectivePermissionCodes());
        $this->assertFalse($target->hasPermission('users.view'));
        $this->assertFalse($target->hasPermission('roles.manage'));
        $this->assertSame([], app(AdminNavigation::class)->groupsFor($target));
        $this->assertDatabaseMissing('role_user', ['user_id' => $target->id]);
        $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $target->id]);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true]);

        $this->actingAs($target)
            ->get(route('admin.security.index', $search))
            ->assertForbidden();
        $this->actingAs($target)
            ->get(route('admin.sidebar-access.roles'))
            ->assertForbidden();
    }

    public function test_store_scoped_operator_cannot_inspect_roleless_account_or_infer_other_channel_access(): void
    {
        $retailStore = $this->store('B2C', 'V2-2923-RETAIL-ONE');
        $foreignRetail = $this->store('B2C', 'V2-2923-RETAIL-TWO');
        $wholesaleStore = $this->store('B2B', 'V2-2923-WHOLESALE');

        $storeOperator = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $storeOperator->id,
            'store_id' => $retailStore,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $target = User::factory()->create([
            'name' => 'V2 Row18 Protected Fixture',
            'is_platform_customer' => true,
            'is_active' => true,
        ]);

        $this->assertSame([], $target->inheritedPermissionCodes());
        $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($target, 'inventory.view', 'b2b'));
        $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds($target, 'inventory.view', 'b2c'));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $storeOperator->forceFill(['locale' => $locale])->save();
            $target->forceFill(['locale' => $locale])->save();

            // Store administration is not an authorization to inspect the
            // platform-wide identity inventory or a foreign user.
            $this->actingAs($storeOperator->fresh())
                ->get(route('admin.security.index', [
                    'section' => 'users',
                    'assignment' => 'roleless',
                    'q' => 'V2 Row18 Protected Fixture',
                    'store_id' => $foreignRetail,
                ]))
                ->assertForbidden();

            $this->actingAs($target->fresh())
                ->get(route('admin.b2b.module', [
                    'module' => 'inventory',
                    'store_id' => $wholesaleStore,
                ]))
                ->assertStatus(403);
            $this->assertContains(
                $this->actingAs($target->fresh())
                    ->get(route('admin.b2c.module', [
                        'module' => 'inventory',
                        'store_id' => $foreignRetail,
                    ]))->getStatusCode(),
                [403, 404],
            );
        }

        $this->assertSame(0, $target->fresh()->roles()->count());
        $this->assertSame(0, $target->fresh()->storeRoleAssignments()->count());
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true]);
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
