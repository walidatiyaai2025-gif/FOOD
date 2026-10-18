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

    public function test_retail_store_admin_cannot_see_or_open_platform_erp(): void
    {
        $user = $this->retailAdmin('nav-retail-no-erp@example.test');

        $groups = collect(app(AdminNavigation::class)->groupsFor($user))
            ->keyBy('key');

        $this->assertFalse($groups->has('erp'));
        $this->actingAs($user)
            ->get(route('admin.erp.index', [], false))
            ->assertForbidden();
        $this->actingAs($user)
            ->get(route('admin.erp.procurement.index', [], false))
            ->assertForbidden();
    }

    public function test_retail_store_admin_sidebar_excludes_platform_only_navigation(): void
    {
        $user = $this->retailAdmin('nav-retail-isolated@example.test');

        $groups = collect(app(AdminNavigation::class)->groupsFor($user));
        $items = $groups
            ->flatMap(fn (array $group) => $group['children'])
            ->values();

        $this->assertFalse($groups->contains('key', 'finance'));
        $this->assertFalse($groups->contains('key', 'administration'));

        foreach ([
            'enterprise_action_center',
            'enterprise_workspaces',
            'whatsapp_inbox',
            'treasury_master',
            'whatsapp_supervisor',
            'b2c_finance',
            'administration_hub',
        ] as $forbiddenKey) {
            $this->assertFalse(
                $items->contains('key', $forbiddenKey),
                "Retail sidebar unexpectedly exposed platform-only item [{$forbiddenKey}].",
            );
        }

        $this->assertFalse(
            $items->contains('label', 'admin.b2c_workspace.modules.finance'),
            'Retail sidebar must never render the removed B2C finance translation key.',
        );
    }

    public function test_customer_service_manager_and_agent_sidebar_hide_platform_hubs_but_keep_service_workspace(): void
    {
        foreach (['CUSTOMER_SERVICE_MANAGER', 'CUSTOMER_SERVICE_AGENT'] as $roleCode) {
            $user = $this->globalUser($roleCode, strtolower($roleCode).'@example.test');

            $groups = collect(app(AdminNavigation::class)->groupsFor($user));
            $items = $groups
                ->flatMap(fn (array $group) => $group['children'])
                ->pluck('key');

            foreach ([
                'enterprise_action_center',
                'enterprise_workspaces',
                'erp_center',
                'administration_hub',
            ] as $hiddenKey) {
                $this->assertFalse(
                    $items->contains($hiddenKey),
                    "{$roleCode} sidebar unexpectedly exposed [{$hiddenKey}].",
                );
            }

            $this->assertFalse(
                $groups->contains('key', 'administration'),
                "{$roleCode} sidebar must hide the Administration group when its hub is hidden.",
            );
            $this->assertTrue(
                $items->contains('erp_customer_service'),
                "{$roleCode} must retain the Customer Service workspace entry.",
            );
        }
    }

    public function test_global_platform_admin_keeps_platform_navigation_entries(): void
    {
        $user = $this->globalUser('B2B_ADMIN', 'nav-platform-sidebar@example.test');

        $items = collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(fn (array $group) => $group['children'])
            ->pluck('key');

        foreach ([
            'enterprise_action_center',
            'enterprise_workspaces',
            'treasury_master',
            'administration_hub',
        ] as $requiredKey) {
            $this->assertTrue(
                $items->contains($requiredKey),
                "Global platform sidebar lost expected item [{$requiredKey}].",
            );
        }
    }

    public function test_global_platform_admin_remains_eligible_for_erp(): void
    {
        $user = $this->globalUser('B2B_ADMIN', 'nav-platform-erp@example.test');

        $this->assertTrue(app(AdminNavigation::class)->canAccessPlatformErp($user));
        $this->assertTrue(
            collect(app(AdminNavigation::class)->groupsFor($user))->contains('key', 'erp'),
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
