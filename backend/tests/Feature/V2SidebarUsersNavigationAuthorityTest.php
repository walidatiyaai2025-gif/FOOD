<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2SidebarUsersNavigationAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_users_are_discoverable_through_existing_authorized_admin_hub_in_both_locales(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $actor = User::factory()->create([
                'locale' => $locale,
                'is_active' => true,
            ]);
            $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

            $administration = collect(app(AdminNavigation::class)->groupsFor($actor))
                ->firstWhere('key', 'administration');

            $this->assertIsArray($administration);
            $hub = collect($administration['children'])->firstWhere('key', 'administration_hub');
            $this->assertIsArray($hub);
            $this->assertSame('admin.administration.index', $hub['route']);

            $this->actingAs($actor)
                ->get(route('admin.administration.index'))
                ->assertOk()
                ->assertSee('data-admin-card="users-permissions"', false)
                ->assertSee('data-security-navigation', false)
                ->assertSee(route('admin.security.index'), false);

            $users = $this->actingAs($actor)
                ->get(route('admin.security.index', ['section' => 'users']));

            $users->assertOk()
                ->assertViewHas('securitySection', 'users')
                ->assertSee(__('admin.security.tabs.users'));

            if ($locale === 'ar') {
                $users->assertSee('dir="rtl"', false);
            }
        }
    }

    public function test_unassigned_account_cannot_read_users_or_create_a_new_account(): void
    {
        $actor = User::factory()->create([
            'locale' => 'en',
            'is_active' => true,
            'is_platform_customer' => true,
        ]);

        $visibleKeys = collect(app(AdminNavigation::class)->groupsFor($actor))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key');

        $this->assertFalse($visibleKeys->contains('administration_hub'));

        $this->actingAs($actor)
            ->get(route('admin.security.index', ['section' => 'users']))
            ->assertForbidden();

        $this->actingAs($actor)
            ->post(route('admin.security.users.store'), [
                'name' => 'Denied V2 Created Account',
                'email' => 'denied-v2-user@example.test',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', [
            'email' => 'denied-v2-user@example.test',
        ]);
    }

    public function test_driver_role_cannot_use_dashboard_user_management_route(): void
    {
        $actor = User::factory()->create([
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $actor->roles()->attach(Role::query()->where('code', 'B2C_DRIVER')->firstOrFail());

        $this->actingAs($actor)
            ->get(route('admin.security.index', ['section' => 'users']))
            ->assertForbidden();
    }
}
