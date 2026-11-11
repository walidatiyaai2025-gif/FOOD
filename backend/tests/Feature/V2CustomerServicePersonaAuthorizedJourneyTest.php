<?php

namespace Tests\Feature;

use App\Domain\WhatsApp\RuntimePolicy;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationalTenantScope;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * T353R2 / #2711: Customer Service persona is a real connected set of
 * separately authorized destinations, never a role-name-only Sidebar mask.
 */
final class V2CustomerServicePersonaAuthorizedJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_customer_service_manager_has_real_wholesale_customer_order_reporting_and_service_destinations_in_both_locales(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $manager = $this->manager();

        $this->assertContains(
            $storeId,
            app(OperationalTenantScope::class)->allowedStoreIds($manager, 'orders.view', 'b2b'),
        );
        $this->assertSame(
            [],
            app(OperationalTenantScope::class)->allowedStoreIds($manager, 'orders.view', 'b2c'),
        );

        $required = [
            'overview' => ['erp_customer_service' => 'admin.erp.customer-service'],
            'accounts' => ['customer_360' => 'admin.customer-360.index'],
            'operations' => ['order_operations' => 'admin.operations.orders.index'],
            'analytics' => ['reports_center' => 'admin.reports.index'],
        ];

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $manager->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            app()->forgetInstance(AdminNavigation::class);

            $navigation = app(AdminNavigation::class);
            $groups = collect($navigation->groupsFor($manager->fresh()))->keyBy('key');

            foreach ($required as $groupKey => $items) {
                $group = $groups->get($groupKey);
                $this->assertIsArray($group, $locale.' missing '.$groupKey);

                foreach ($items as $itemKey => $routeName) {
                    $matches = collect($group['children'])->where('key', $itemKey);
                    $this->assertCount(1, $matches, $locale.' must have one '.$itemKey);
                    $this->assertSame($routeName, $matches->first()['route']);
                    $this->assertTrue(Route::has($routeName));
                }
            }

            $keys = $groups->values()
                ->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')->all();

            foreach (['erp_center', 'enterprise_action_center', 'administration_hub', 'driver_live_tracking', 'field_ops_overview', 'b2c_orders'] as $forbidden) {
                $this->assertNotContains($forbidden, $keys);
            }

            $inboxVisible = in_array('whatsapp_inbox', $keys, true);
            $this->assertSame(
                app(RuntimePolicy::class)->allowsAgentInbox()
                    && $manager->fresh()->hasPermission('whatsapp.inbox.view'),
                $inboxVisible,
                $locale.' WhatsApp navigation must respect live runtime capability',
            );

            foreach ([
                route('admin.erp.customer-service', [], false),
                route('admin.customer-360.index', [], false),
                route('admin.operations.orders.index', ['channel' => 'b2b'], false),
                route('admin.reports.index', [], false),
            ] as $url) {
                $this->actingAs($manager->fresh())
                    ->get($url)
                    ->assertOk()
                    ->assertSee('dir="'.$direction.'"', false);
            }
        }
    }

    public function test_customer_service_manager_cannot_cross_retail_or_administration_by_direct_http(): void
    {
        app(WholesalePrincipal::class)->storeId();
        $manager = $this->manager();

        $this->actingAs($manager)
            ->get(route('admin.operations.orders.index', ['channel' => 'b2c'], false))
            ->assertForbidden();
        $this->actingAs($manager)
            ->get(route('admin.b2c.module', ['module' => 'customers'], false))
            ->assertForbidden();
        $this->actingAs($manager)
            ->get(route('admin.security.index', ['section' => 'roles'], false))
            ->assertForbidden();
        $this->assertFalse($manager->hasPermission('finance.approve'));
        $this->assertFalse($manager->hasPermission('platform.manage'));
    }

    public function test_explicit_reporting_and_whatsapp_denies_remove_authority_not_just_sidebar_visibility(): void
    {
        app(WholesalePrincipal::class)->storeId();
        $manager = $this->manager();

        foreach (['reports.view', 'whatsapp.inbox.view', 'whatsapp.conversations.view'] as $code) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $manager->id,
                'permission_id' => Permission::query()->where('code', $code)->firstOrFail()->id,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $manager->forceFill(['has_permission_overrides' => true])->save();
        app()->forgetInstance(AdminNavigation::class);

        $keys = collect(app(AdminNavigation::class)->groupsFor($manager->fresh()))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')->all();
        $this->assertNotContains('reports_center', $keys);
        $this->assertNotContains('whatsapp_inbox', $keys);
        $this->assertContains('erp_customer_service', $keys);
        $this->assertContains('order_operations', $keys);

        $this->actingAs($manager->fresh())
            ->get(route('admin.reports.index', [], false))
            ->assertForbidden();
        $this->actingAs($manager->fresh())
            ->get(route('admin.whatsapp-inbox.index', [], false))
            ->assertForbidden();
        $this->actingAs($manager->fresh())
            ->get(route('admin.erp.customer-service', [], false))
            ->assertOk();
        $this->actingAs($manager->fresh())
            ->get(route('admin.operations.orders.index', ['channel' => 'b2b'], false))
            ->assertOk();
    }

    public function test_roleless_and_inactive_accounts_cannot_use_customer_service_pages(): void
    {
        app(WholesalePrincipal::class)->storeId();
        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->manager();
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive->fresh()] as $actor) {
            $this->actingAs($actor)
                ->get(route('admin.erp.customer-service', [], false))
                ->assertForbidden();
            $this->actingAs($actor)
                ->get(route('admin.operations.orders.index', ['channel' => 'b2b'], false))
                ->assertForbidden();
            $this->actingAs($actor)
                ->get(route('admin.reports.index', [], false))
                ->assertForbidden();
        }
    }

    private function manager(): User
    {
        $manager = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $manager->roles()->attach(Role::query()->where('code', 'CUSTOMER_SERVICE_MANAGER')->firstOrFail());

        return $manager->fresh();
    }
}
