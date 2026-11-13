<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P331: the existing Users & Permissions hub is a discoverable, server-guarded
 * administration workflow, not a second permission grant.
 */
final class V2UsersPermissionsHubAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_security_hub_and_its_users_and_audit_destinations_are_reachable_in_both_locales(): void
    {
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor = User::factory()->create(['locale' => $locale, 'is_active' => true]);
            $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

            $group = collect(app(AdminNavigation::class)->groupsFor($actor))
                ->firstWhere('key', 'administration');
            $this->assertIsArray($group);
            $hub = collect($group['children'])->firstWhere('key', 'administration_hub');
            $this->assertIsArray($hub);
            $this->assertSame('admin.administration.index', $hub['route']);
            $this->assertTrue(Route::has('admin.security.index'));

            $this->actingAs($actor)
                ->get(route('admin.administration.index'))
                ->assertOk()
                ->assertViewHas('canSecurity', true)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-admin-card="users-permissions"', false)
                ->assertSee('data-security-navigation', false)
                ->assertSee(route('admin.security.index'), false)
                ->assertSee(route('admin.security.index', ['section' => 'audit']), false)
                ->assertSee(__('admin.administration_hub.users_permissions', [], $locale));

            $this->actingAs($actor)
                ->get(route('admin.security.index', ['section' => 'users']))
                ->assertOk()
                ->assertViewHas('securitySection', 'users')
                ->assertSee(__('admin.security.tabs.users', [], $locale));
        }
    }

    public function test_combining_unrelated_permissions_never_grants_users_and_permissions_access(): void
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $this->attachGlobalRoleWithPermission($actor, 'P2661_ROLE_MANAGE', 'roles.manage');
        $this->attachGlobalRoleWithPermission($actor, 'P2661_CATALOG_VIEW', 'catalog.view');

        $this->assertFalse(Gate::forUser($actor)->allows('security.view'));
        $group = collect(app(AdminNavigation::class)->groupsFor($actor))
            ->firstWhere('key', 'administration');
        $this->assertIsArray($group);
        $this->assertContains('administration_hub', collect($group['children'])->pluck('key')->all());

        $this->actingAs($actor)
            ->get(route('admin.administration.index'))
            ->assertOk()
            ->assertViewHas('canSecurity', false)
            ->assertSee('data-admin-card="users-permissions"', false)
            ->assertDontSee('data-security-navigation', false);

        $this->actingAs($actor)
            ->get(route('admin.security.index', ['section' => 'users']))
            ->assertForbidden();

        $this->actingAs($actor)
            ->get(route('admin.security.index', ['section' => 'audit']))
            ->assertForbidden();
    }

    public function test_roleless_customer_cannot_open_security_center_or_create_users(): void
    {
        $actor = User::factory()->create([
            'locale' => 'ar',
            'is_active' => true,
            'is_platform_customer' => true,
        ]);

        $keys = collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key');
        $this->assertFalse($keys->contains('administration_hub'));

        $this->actingAs($actor)
            ->get(route('admin.security.index', ['section' => 'users']))
            ->assertForbidden();

        $this->actingAs($actor)
            ->post(route('admin.security.users.store'), [
                'name' => 'P331 unauthorized account',
                'email' => 'p331-unauthorized@example.test',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'p331-unauthorized@example.test']);
    }

    private function attachGlobalRoleWithPermission(User $actor, string $code, string $permission): void
    {
        $role = Role::query()->create([
            'code' => $code,
            'name' => $code,
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach(
            Permission::query()->where('code', $permission)->firstOrFail(),
        );
        $actor->roles()->attach($role);
    }
}
