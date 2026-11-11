<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Services\AdminSidebarManager;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class V2MyWorkOverviewNavigationContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_t356r1_reuses_one_canonical_overview_destination_and_registered_http_route(): void
    {
        $item = app(AdminSidebarManager::class)->registry()['items']['enterprise_action_center'];
        $this->assertSame('overview', $item['group']);
        $this->assertSame('enterprise_action_center.nav_title', $item['label']);

        $named = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => $route->getName() === 'admin.action-center.index');
        $this->assertCount(1, $named);
        $this->assertTrue(Route::has('admin.action-center.index'));

        $actor = $this->globalUser('B2B_ADMIN', 'ar');
        $groups = collect(app(AdminNavigation::class)->groupsFor($actor));
        $destination = $groups->flatMap(static fn (array $group): array => collect($group['children'])
            ->map(static fn (array $entry): array => $entry + ['group_key' => $group['key']])
            ->all())
            ->where('key', 'enterprise_action_center')
            ->values();

        $this->assertCount(1, $destination, 'Canonical My Work Sidebar key must not be registered twice.');
        $this->assertSame('overview', $destination->first()['group_key']);
        $this->assertSame('admin.action-center.index', $destination->first()['route']);

        $this->actingAs($actor)
            ->get(route('admin.action-center.index'))
            ->assertOk()
            ->assertViewHas('navContext', 'enterprise_action_center')
            ->assertSee('dir="rtl"', false)
            ->assertSee('مركز الإجراءات');
    }

    public function test_authorized_retail_store_admin_keeps_same_overview_route_and_english_direction(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-T356R1-RETAIL',
            'name' => 'V2 T356R1 Retail Store',
            'is_active' => true,
        ]);
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
        ]);

        $navigation = app(AdminNavigation::class);
        $this->assertTrue($navigation->canAccessMyWork($actor));
        $groups = collect($navigation->groupsFor($actor));
        $overview = $groups->firstWhere('key', 'overview');
        $this->assertIsArray($overview);
        $item = collect($overview['children'])->firstWhere('key', 'enterprise_action_center');
        $this->assertIsArray($item);
        $this->assertSame('admin.action-center.index', $item['route']);

        $this->actingAs($actor)
            ->get(route('admin.action-center.index'))
            ->assertOk()
            ->assertViewHas('navContext', 'enterprise_action_center')
            ->assertSee('dir="ltr"', false);
    }

    public function test_hidden_actor_cannot_open_my_work_by_direct_http_or_execute_decisions(): void
    {
        foreach ([
            $this->globalUser('CUSTOMER_SERVICE_AGENT', 'en'),
            User::factory()->create(['is_active' => true, 'locale' => 'ar']),
        ] as $actor) {
            $groups = collect(app(AdminNavigation::class)->groupsFor($actor));
            $this->assertFalse($groups->flatMap(static fn (array $group): array => $group['children'])
                ->contains('key', 'enterprise_action_center'));

            $this->actingAs($actor)->get(route('admin.action-center.index'))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.action-center.export'))->assertForbidden();
            $this->actingAs($actor)->post(route('admin.action-center.decide', [
                'workflowRequest' => 999999,
                'stepCode' => 'approval',
            ]), ['decision' => 'APPROVED'])->assertForbidden();
        }

        $inactive = $this->globalUser('B2B_ADMIN', 'en');
        $inactive->forceFill(['is_active' => false])->save();

        $this->assertFalse(app(AdminNavigation::class)->canAccessMyWork($inactive));
        $this->actingAs($inactive)
            ->get(route('admin.action-center.index'))
            ->assertForbidden();
    }

    public function test_cosmetic_saved_layout_cannot_move_or_disable_protected_my_work_navigation(): void
    {
        app(AdminSidebarManager::class)->saveLayout([], [
            'enterprise_action_center' => [
                'group_key' => 'finance',
                'parent_key' => 'erp_center',
                'is_enabled' => false,
                'sort_order' => 900,
            ],
        ]);

        $layout = app(AdminSidebarManager::class)->layoutData()['items']['enterprise_action_center'];
        $this->assertSame('overview', $layout['group_key']);
        $this->assertNull($layout['parent_key']);
        $this->assertTrue($layout['is_enabled']);

        $actor = $this->globalUser('B2B_ADMIN', 'en');
        $overview = collect(app(AdminNavigation::class)->groupsFor($actor))->firstWhere('key', 'overview');
        $this->assertIsArray($overview);
        $this->assertTrue(collect($overview['children'])->contains('key', 'enterprise_action_center'));
        $this->actingAs($actor)->get(route('admin.action-center.index'))->assertOk();
    }

    private function globalUser(string $roleCode, string $locale): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => $locale]);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }
}
