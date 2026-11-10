<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\EnterpriseWorkspaceComposer;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2WorkspacesOverviewAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_wholesale_workspaces_use_canonical_overview_entry_and_localized_tabs(): void
    {
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor = $this->globalActor('B2B_ADMIN', "v2-workspaces-{$locale}@example.test", $locale);
            $groups = collect(app(AdminNavigation::class)->groupsFor($actor));
            $overview = $groups->firstWhere('key', 'overview');

            $this->assertIsArray($overview);
            $items = collect($overview['children'])->keyBy('key');
            $this->assertTrue($items->has('enterprise_workspaces'));
            $this->assertSame('admin.enterprise-workspaces.index', $items->get('enterprise_workspaces')['route']);
            $this->assertSame('enterprise_workspaces.nav_title', $items->get('enterprise_workspaces')['label']);

            $available = app(EnterpriseWorkspaceComposer::class)->availableWorkspaceKeys($actor);
            $this->assertNotEmpty($available);

            $this->actingAs($actor)
                ->get(route('admin.enterprise-workspaces.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-enterprise-workspaces="true"', false)
                ->assertSee(__('enterprise_workspaces.title'));

            foreach ($available as $key) {
                $this->actingAs($actor)
                    ->get(route('admin.enterprise-workspaces.index', ['workspace' => $key]))
                    ->assertOk()
                    ->assertSee('aria-current="page"', false)
                    ->assertSee(__('enterprise_workspaces.workspaces.'.$key));
            }
        }
    }

    public function test_workspace_tabs_and_cards_remain_permission_filtered_at_server_boundary(): void
    {
        $actor = $this->globalActor('B2B_ADMIN', 'v2-workspaces-filter@example.test');
        $composer = app(EnterpriseWorkspaceComposer::class);

        $this->assertContains('warehouse', $composer->availableWorkspaceKeys($actor));
        config()->set('enterprise_workspaces.workspaces.warehouse.access_any', ['v2.nonexistent.permission']);

        $this->assertNotContains('warehouse', $composer->availableWorkspaceKeys($actor));
        $this->actingAs($actor)
            ->get(route('admin.enterprise-workspaces.index', ['workspace' => 'warehouse']))
            ->assertForbidden();

        config()->set('enterprise_workspaces.workspaces.executive.cards', [
            [
                'key' => 'forbidden_card',
                'label' => 'enterprise_workspaces.cards.reports.title',
                'description' => 'enterprise_workspaces.cards.reports.description',
                'route' => 'admin.reports.index',
                'permission' => 'v2.nonexistent.permission',
            ],
        ]);

        $this->assertContains('executive', $composer->availableWorkspaceKeys($actor));
        $this->assertSame([], $composer->compose($actor, 'executive')['cards']);
        $this->actingAs($actor)
            ->get(route('admin.enterprise-workspaces.index', ['workspace' => 'executive']))
            ->assertOk()
            ->assertDontSee('data-workspace-card="forbidden_card"', false)
            ->assertSee(__('enterprise_workspaces.empty'));
    }

    public function test_store_scoped_workspaces_are_discoverable_without_platform_escalation_and_roleless_is_denied(): void
    {
        $storeId = $this->retailStore('V2-WORKSPACES-RETAIL');
        $retail = User::query()->create([
            'name' => 'Scoped Retail Actor',
            'email' => 'v2-workspaces-retail@example.test',
            'password' => 'password',
            'is_active' => true,
            'locale' => 'ar',
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleless = User::query()->create([
            'name' => 'No Workspace Role',
            'email' => 'v2-workspaces-roleless@example.test',
            'password' => 'password',
            'is_active' => true,
            'locale' => 'en',
        ]);

        $composer = app(EnterpriseWorkspaceComposer::class);
        $available = $composer->availableWorkspaceKeys($retail);
        $this->assertContains('sales', $available);
        $this->assertFalse($retail->hasPermission('platform.manage'));

        $overview = collect(app(AdminNavigation::class)->groupsFor($retail))->firstWhere('key', 'overview');
        $this->assertIsArray($overview);
        $items = collect($overview['children'])->keyBy('key');
        $this->assertTrue($items->has('enterprise_workspaces'));
        $this->assertSame('admin.enterprise-workspaces.index', $items->get('enterprise_workspaces')['route']);

        $this->actingAs($retail)
            ->get(route('admin.enterprise-workspaces.index'))
            ->assertOk();
        $this->actingAs($retail)
            ->get(route('admin.enterprise-workspaces.index', ['workspace' => 'sales']))
            ->assertOk();

        // A legitimate scoped entry must not grant platform-only privileged cards.
        if (in_array('executive', $available, true)) {
            $executive = $composer->compose($retail, 'executive');
            $this->assertIsArray($executive);
            $this->assertNotContains('system_health', array_column($executive['cards'], 'key'));
        }

        // Removing the effective grant still denies a direct workspace URL.
        config()->set('enterprise_workspaces.workspaces.executive.access_any', ['platform.manage']);
        $this->actingAs($retail)
            ->get(route('admin.enterprise-workspaces.index', ['workspace' => 'executive']))
            ->assertForbidden();

        $rolelessOverview = collect(app(AdminNavigation::class)->groupsFor($roleless))
            ->firstWhere('key', 'overview');
        $rolelessKeys = array_column($rolelessOverview['children'] ?? [], 'key');
        $this->assertNotContains('enterprise_workspaces', $rolelessKeys);
        $this->actingAs($roleless)
            ->get(route('admin.enterprise-workspaces.index'))
            ->assertForbidden();
        $this->actingAs($roleless)
            ->get(route('admin.enterprise-workspaces.index', ['workspace' => 'sales']))
            ->assertForbidden();
    }

    public function test_unrecognized_workspace_path_has_no_hidden_fallback(): void
    {
        $actor = $this->globalActor('B2B_ADMIN', 'v2-workspaces-unknown@example.test');
        $this->actingAs($actor)->get('/admin/workspaces/not-a-workspace')->assertNotFound();
    }

    private function globalActor(string $roleCode, string $email, string $locale = 'en'): User
    {
        $actor = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
            'locale' => $locale,
        ]);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }

    private function retailStore(string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'business_profile' => 'RETAIL_STORE',
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
