<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminNavigationAuthorizationAuditTest extends TestCase
{
    use RefreshDatabase;

    private int $wholesaleStore;

    private int $retailStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        $this->wholesaleStore = $this->store('B2B', 'NAV-AUDIT-WHOLESALE');
        $this->retailStore = $this->store('B2C', 'NAV-AUDIT-RETAIL');
    }

    public function test_every_visible_super_admin_navigation_target_opens_without_authorization_or_route_errors(): void
    {
        $this->assertEveryVisibleNavigationTargetOpens(
            $this->globalUser('SUPER_ADMIN', 'nav-owner@example.test'),
        );
    }

    public function test_every_visible_b2b_admin_navigation_target_opens_without_authorization_or_route_errors(): void
    {
        $this->assertEveryVisibleNavigationTargetOpens(
            $this->globalUser('B2B_ADMIN', 'nav-wholesale@example.test'),
        );
    }

    public function test_every_visible_retail_admin_navigation_target_opens_without_authorization_or_route_errors(): void
    {
        $this->assertEveryVisibleNavigationTargetOpens(
            $this->retailAdmin('nav-retail@example.test'),
        );
    }

    private function assertEveryVisibleNavigationTargetOpens(User $user): void
    {
        $items = collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(fn (array $group) => $group['children'])
            ->values();

        $this->assertNotEmpty($items, 'The navigation audit requires at least one visible destination.');

        foreach ($items as $item) {
            $uri = route($item['route'], $item['params'], false);
            $response = $this->actingAs($user)->get($uri);
            $status = $response->getStatusCode();

            $this->assertTrue(
                $status >= 200 && $status < 400,
                sprintf(
                    'Visible admin navigation item [%s] (%s) returned HTTP %d for user %s.',
                    $item['key'],
                    $uri,
                    $status,
                    $user->email,
                ),
            );
        }
    }

    private function globalUser(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function retailAdmin(string $email): User
    {
        $user = User::query()->create([
            'name' => 'Retail Navigation Audit',
            'email' => $email,
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $this->retailStore,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function store(string $type, string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', $type)->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
