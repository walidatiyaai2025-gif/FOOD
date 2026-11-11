<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class V2SecurityGovernanceHubAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
    }

    public function test_p339_security_governance_group_opens_real_authorized_sections_in_both_locales(): void
    {
        $user = $this->actor('SUPER_ADMIN', 'v2-p339-owner@example.test');

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $user->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($user)
                ->get(route('admin.administration.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-admin-card="security-governance"', false)
                ->assertSee('data-security-governance-navigation', false)
                ->assertSee(__('security_governance.dashboard.title'));

            foreach (['governance', 'sessions', 'audit'] as $section) {
                $response->assertSee(
                    route('admin.security.index', ['section' => $section]),
                    false,
                )->assertSee('data-security-governance-entry="'.$section.'"', false);

                $this->actingAs($user)
                    ->get(route('admin.security.index', ['section' => $section]))
                    ->assertOk()
                    ->assertViewHas('securitySection', $section);
            }
        }
    }

    public function test_p339_wholesale_operator_cannot_discover_or_open_security_governance(): void
    {
        $user = $this->actor('B2B_ADMIN', 'v2-p339-wholesale@example.test');

        $this->assertFalse(Gate::forUser($user)->allows('security.view'));
        $this->actingAs($user)
            ->get(route('admin.administration.index'))
            ->assertOk()
            ->assertDontSee('data-admin-card="security-governance"', false)
            ->assertDontSee('data-security-governance-navigation', false);

        foreach (['governance', 'sessions', 'audit'] as $section) {
            $this->actingAs($user)
                ->get(route('admin.security.index', ['section' => $section]))
                ->assertForbidden();
        }
    }

    public function test_p339_authorized_custom_global_role_works_alongside_other_role_without_extra_grants(): void
    {
        $user = $this->actor('B2B_ADMIN', 'v2-p339-multi@example.test');
        $reviewer = $this->customRole('V2_P339_SECURITY_REVIEWER', 'global');
        $reviewer->permissions()->attach(Permission::query()->where('code', 'security.view')->firstOrFail());
        $user->roles()->attach($reviewer->id);

        $this->assertTrue(Gate::forUser($user)->allows('security.view'));
        $this->assertFalse(Gate::forUser($user)->allows('users.roles.manage'));

        $this->actingAs($user)
            ->get(route('admin.administration.index'))
            ->assertOk()
            ->assertSee('data-admin-card="security-governance"', false)
            ->assertSee('data-security-governance-entry="audit"', false);

        $this->actingAs($user)
            ->get(route('admin.security.index', ['section' => 'governance']))
            ->assertOk();
    }

    public function test_p339_store_scoped_permission_does_not_create_a_platform_governance_link(): void
    {
        $user = $this->actor('B2B_ADMIN', 'v2-p339-store-scoped@example.test');
        $store = (int) DB::table('stores')->insertGetId([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-P339-STORE',
            'name' => 'V2 P339 Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $storeReviewer = $this->customRole('V2_P339_STORE_REVIEWER', 'store');
        $storeReviewer->permissions()->attach(Permission::query()->where('code', 'security.view')->firstOrFail());

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store,
            'role_id' => $storeReviewer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse(Gate::forUser($user)->allows('security.view'));
        $this->actingAs($user)
            ->get(route('admin.administration.index'))
            ->assertOk()
            ->assertDontSee('data-admin-card="security-governance"', false);
        $this->actingAs($user)
            ->get(route('admin.security.index', ['section' => 'audit']))
            ->assertForbidden();
    }

    private function actor(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => 'V2 P339 Governance Tester',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function customRole(string $code, string $scope): Role
    {
        return Role::query()->create([
            'code' => $code,
            'name' => $code,
            'scope' => $scope,
            'is_active' => true,
            'is_system' => false,
        ]);
    }
}
