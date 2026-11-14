<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * V2 #2918: historical Table 5 data row 13 reports no assigned RBAC roles.
 * Synthetic fixture only: never use archived account names or live identities.
 * An empty Dashboard RBAC snapshot does not classify the Customer identity.
 */
final class V2ExportedAccountRow13NoRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_roleless_identity_has_no_inherited_administration_or_tenant_access(): void
    {
        $actor = $this->unassignedActor();

        $this->assertSame(0, $actor->roles()->count());
        $this->assertSame(0, $actor->storeRoleAssignments()->count());
        $this->assertSame([], $actor->inheritedPermissionCodes());
        $this->assertFalse($actor->hasRole('SUPER_ADMIN'));
        $this->assertFalse($actor->isPlatformOwner());

        foreach ([
            'roles.manage', 'users.delete', 'finance.view', 'finance.post',
            'orders.view', 'inventory.view', 'deliveries.b2b.execute',
            'deliveries.b2c.execute', 'van.login',
        ] as $permission) {
            $this->assertFalse($actor->hasPermission($permission), $permission);
        }

        $scope = app(OperationalTenantScope::class);
        $this->assertSame([], $scope->allowedStoreIds($actor, 'orders.view', 'b2b'));
        $this->assertSame([], $scope->allowedStoreIds($actor, 'orders.view', 'b2c'));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $actor = $actor->fresh();

            $keys = $this->navigationKeys(app(AdminNavigation::class)->groupsFor($actor));
            $this->assertNotContains('sidebar_role_access', $keys);
            $this->assertNotContains('b2b_orders', $keys);
            $this->assertNotContains('b2c_inventory', $keys);

            $this->actingAs($actor)->get(route('admin.security.index'))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.sidebar-access.roles'))->assertForbidden();
            $this->actingAs($actor)->get('/admin/b2b/orders')->assertForbidden();
        }

        $this->assertDatabaseHas('users', ['id' => $actor->getKey(), 'is_active' => true]);
        $this->assertSame(0, $actor->fresh()->roles()->count());
        $this->assertSame(0, $actor->fresh()->storeRoleAssignments()->count());
    }

    public function test_app_token_and_login_app_parameter_do_not_grant_driver_or_van_role(): void
    {
        $actor = $this->unassignedActor();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $actor = $actor->fresh();

            foreach (['driver', 'van'] as $app) {
                $this->postJson('/api/v1/auth/login', [
                    'email' => (string) $actor->email,
                    'password' => 'test-only-credential',
                    'app' => $app,
                ])->assertForbidden();
            }

            Sanctum::actingAs($actor, ['app:driver']);
            $this->getJson('/api/v1/driver/wallet')->assertForbidden();

            Sanctum::actingAs($actor, ['app:van']);
            $this->getJson('/api/v1/van/visits')->assertForbidden();
        }

        // Forbidden attempts must not create a role, assignment or app token.
        $this->assertSame(0, $actor->fresh()->roles()->count());
        $this->assertSame(0, $actor->fresh()->storeRoleAssignments()->count());
        $this->assertSame(0, DB::table('personal_access_tokens')
            ->where('tokenable_type', $actor->getMorphClass())
            ->where('tokenable_id', $actor->getKey())->count());
    }

    private function unassignedActor(): User
    {
        return User::factory()->create([
            'name' => 'Synthetic Unassigned Identity',
            'email' => 'v2-row13-synthetic@example.test',
            'password' => Hash::make('test-only-credential'),
            'is_active' => true,
            'locale' => 'en',
        ])->fresh();
    }

    /** @return list<string> */
    private function navigationKeys(array $groups): array
    {
        $keys = [];
        foreach ($groups as $group) {
            foreach ($group['children'] as $item) {
                $keys[] = $item['key'];
                foreach ((array) ($item['children'] ?? []) as $child) {
                    $keys[] = $child['key'];
                }
            }
        }

        return $keys;
    }
}
