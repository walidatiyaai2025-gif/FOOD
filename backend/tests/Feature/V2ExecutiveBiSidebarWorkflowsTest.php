<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #2644 / P314: an executive dashboard is an authorized and scoped
 * BI workbench, not a collection of public KPI queries or another route.
 */
final class V2ExecutiveBiSidebarWorkflowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.enterprise_bi',
            app(ModuleRegistry::class)->manifest('enterprise_bi')->version,
        );
    }

    public function test_executive_bi_dashboard_is_reachable_and_store_scoped_in_arabic_and_english(): void
    {
        $this->enableBi();
        $assigned = $this->store('V2-P314-OWN');
        $foreign = $this->store('V2-P314-FOREIGN');
        $actor = $this->superAdmin();

        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            new EntitlementScope(EntitlementScope::STORE, $assigned->id),
            true,
            ['reports.read', 'reports.export'],
        );

        $day = now()->subDay()->toDateString();
        $customerId = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => 'V2 P314 Executive Buyer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->order($assigned, $customerId, 'V2-P314-OWN-ORDER', '24.000', $day);
        $this->order($foreign, $customerId, 'V2-P314-FOREIGN-ORDER', '900.000', $day);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            app()->forgetInstance(AdminNavigation::class);
            $item = $this->biItem($actor->fresh());

            $this->assertNotNull($item);
            $this->assertSame('admin.erp.bi', $item['route']);

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.bi', [
                    // Tampering with the selector cannot select an unentitled store.
                    'dashboard_store' => 'store:'.$foreign->id,
                    'dashboard_currency' => 'KWD',
                    'dashboard_from' => $day,
                    'dashboard_to' => $day,
                ]))
                ->assertOk()
                ->assertViewIs('admin.enterprise-bi')
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-enterprise-bi-overview', false)
                ->assertSee('data-bi-chart="orders"', false)
                ->assertViewHas('overview', function (array $overview) use ($assigned): bool {
                    if (($overview['selected_store']['key'] ?? null) !== 'store:'.$assigned->id
                        || ($overview['currency'] ?? null) !== 'KWD') {
                        return false;
                    }

                    $snapshot = $overview['snapshot'] ?? null;
                    if (! is_array($snapshot)) {
                        return false;
                    }

                    $cards = collect($snapshot['cards'] ?? [])->keyBy('code');
                    $orders = $cards->get('orders.overview');

                    return is_array($orders)
                        && ($orders['authorized'] ?? false) === true
                        && ($orders['available'] ?? false) === true
                        && ($orders['current']['orders'] ?? null) === 1
                        && ($orders['current']['gross_total'] ?? null) === '24.000';
                });
        }
    }

    public function test_dashboard_invalid_currency_input_is_normalized_without_exposing_other_tenants(): void
    {
        $this->enableBi();
        $assigned = $this->store('V2-P314-CURRENCY');
        $foreign = $this->store('V2-P314-CURRENCY-FOREIGN');
        $actor = $this->superAdmin();
        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            new EntitlementScope(EntitlementScope::STORE, $assigned->id),
            true,
        );

        $this->actingAs($actor)
            ->get(route('admin.erp.bi', [
                'dashboard_store' => 'store:'.$foreign->id,
                'dashboard_currency' => 'KWD<script>',
            ]))
            ->assertOk()
            ->assertViewHas('overview', function (array $overview) use ($assigned): bool {
                return ($overview['currency'] ?? null) === 'KWD'
                    && ($overview['selected_store']['key'] ?? null) === 'store:'.$assigned->id
                    && ($overview['forecast']['available'] ?? true) === false;
            });
    }

    public function test_unentitled_unready_and_roleless_actors_cannot_bypass_executive_bi_page(): void
    {
        $this->enableBi();
        $store = $this->store('V2-P314-GATES');
        $actor = $this->superAdmin();
        $url = route('admin.erp.bi', ['dashboard_store' => 'store:'.$store->id]);

        $this->assertNull($this->biItem($actor));
        $this->actingAs($actor)->get($url)->assertForbidden();

        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
        );
        app()->forgetInstance(AdminNavigation::class);
        $this->assertNotNull($this->biItem($actor->fresh()));
        $this->actingAs($actor->fresh())->get($url)->assertOk();

        config()->set('enterprise_module_readiness.certified_versions.enterprise_bi', -1);
        app()->forgetInstance(AdminNavigation::class);
        $this->assertNull($this->biItem($actor->fresh()));
        $this->actingAs($actor->fresh())->get($url)->assertForbidden();

        config()->set(
            'enterprise_module_readiness.certified_versions.enterprise_bi',
            app(ModuleRegistry::class)->manifest('enterprise_bi')->version,
        );
        app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::DISABLED);
        $this->actingAs($actor->fresh())->get($url)->assertForbidden();

        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)->get($url)->assertForbidden();
    }

    private function superAdmin(): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor->fresh();
    }

    private function enableBi(): void
    {
        app(ModuleRegistry::class)->syncManifest('enterprise_bi');
        app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::ENABLED);
    }

    private function store(string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function order(Store $store, int $customerId, string $number, string $total, string $day): void
    {
        DB::table('orders')->insert([
            'store_id' => $store->id,
            'customer_id' => $customerId,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => 'completed',
            'currency' => 'KWD',
            'subtotal' => $total,
            'discount_total' => '0.000',
            'delivery_total' => '0.000',
            'grand_total' => $total,
            'created_at' => $day.' 11:00:00',
            'updated_at' => $day.' 11:00:00',
        ]);
    }

    /** @return array<string, mixed>|null */
    private function biItem(User $actor): ?array
    {
        foreach (app(AdminNavigation::class)->groupsFor($actor) as $group) {
            foreach ($group['children'] as $item) {
                if ($item['key'] === 'erp_bi') {
                    return $item;
                }
            }
        }

        return null;
    }
}
