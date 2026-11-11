<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class V2TargetSidebarInformationArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_source_sidebar_target_inventory_is_complete_unique_and_traceable(): void
    {
        $path = base_path('../docs/v2/V2_SIDEBAR_ISSUE_MATRIX.json');
        $this->assertFileExists($path);

        $matrix = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Foodex_V2', $matrix['base']);
        $this->assertSame(2503, $matrix['parent_issue']);
        $this->assertCount(177, $matrix['items']);

        $ids = [];
        $issues = [];
        $domains = [];

        foreach ($matrix['items'] as $item) {
            $this->assertContains($item['type'], ['navigation-group', 'navigation-item']);
            $this->assertNotEmpty($item['section']);
            $this->assertNotEmpty($item['domain']);
            $this->assertNotEmpty($item['summary']);
            $this->assertNotEmpty($item['source']);
            $this->assertMatchesRegularExpression('/^P[0-9]+$/', $item['id']);
            $this->assertGreaterThan(0, $item['issue_number']);
            $this->assertSame(
                'https://github.com/walidatiyaai2025-gif/FOOD/issues/'.$item['issue_number'],
                $item['url'],
            );

            $ids[] = $item['id'];
            $issues[] = $item['issue_number'];
            $domains[] = $item['domain'];
        }

        $this->assertCount(count($ids), array_unique($ids), 'Every target IA row needs one source identity.');
        $this->assertCount(count($issues), array_unique($issues), 'Every target IA row needs one distinct Issue.');
        $this->assertCount(10, array_unique($domains), 'Target Sidebar domains are part of the source contract.');
    }

    public function test_current_registry_owns_every_declared_item_in_a_real_business_group(): void
    {
        $registry = (array) config('admin_sidebar');
        $groups = (array) ($registry['groups'] ?? []);
        $items = (array) ($registry['items'] ?? []);

        $this->assertNotEmpty($groups);
        $this->assertNotEmpty($items);

        foreach ($items as $key => $item) {
            $this->assertArrayHasKey($item['group'] ?? '', $groups, "Unknown business group for [{$key}].");
            $this->assertIsInt($item['order'] ?? null, "Sidebar item [{$key}] has no stable integer order.");
            $this->assertContains(
                $item['scope'] ?? null,
                ['global', 'both', 'store'],
                "Sidebar item [{$key}] has no explicit scope.",
            );
        }
    }

    public function test_authorized_live_projections_have_registered_routes_and_no_fourth_sidebar_level(): void
    {
        foreach (['SUPER_ADMIN', 'B2B_ADMIN', 'CUSTOMER_SERVICE_AGENT'] as $roleCode) {
            $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
            $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

            foreach (['ar', 'en'] as $locale) {
                $actor->forceFill(['locale' => $locale])->save();
                app()->setLocale($locale);
                $groups = app(AdminNavigation::class)->groupsFor($actor->fresh());
                $seen = [];

                foreach ($groups as $group) {
                    $this->assertNotEmpty($group['children'], 'Empty Sidebar groups should not be shown.');
                    foreach ($group['children'] as $page) {
                        $this->assertRouteAndUniqueKey($page, $seen, $roleCode);
                        foreach ($page['children'] ?? [] as $child) {
                            $this->assertRouteAndUniqueKey($child, $seen, $roleCode);
                            $this->assertEmpty(
                                $child['children'] ?? [],
                                'A fourth Sidebar level is forbidden by the three-level IA contract.',
                            );
                        }
                    }
                }
            }
        }
    }

    public function test_machine_sidebar_keys_cannot_grant_an_unprivileged_actor_http_access(): void
    {
        $actor = User::factory()->create(['is_active' => true]);

        $this->actingAs($actor)
            ->get(route('admin.catalog.index', [], false))
            ->assertForbidden();

        $this->actingAs($actor)
            ->get(route('admin.erp.index', [], false))
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, bool>  $seen
     */
    private function assertRouteAndUniqueKey(array $page, array &$seen, string $roleCode): void
    {
        $key = (string) ($page['key'] ?? '');
        $this->assertNotSame('', $key, 'Sidebar entries need a stable key.');
        $this->assertArrayNotHasKey($key, $seen, "Duplicate Sidebar key [{$key}] for [{$roleCode}].");
        $seen[$key] = true;

        $route = $page['route'] ?? null;
        $this->assertIsString($route, "Sidebar entry [{$key}] needs a real route.");
        $this->assertTrue(Route::has($route), "Sidebar entry [{$key}] points to a non-existent route [{$route}].");
    }
}
