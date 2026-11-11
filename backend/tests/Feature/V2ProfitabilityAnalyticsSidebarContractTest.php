<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V2 #2645 / P315: the existing BI profitability journey must execute an
 * authorized report (including its orders measure), not merely render a link.
 */
final class V2ProfitabilityAnalyticsSidebarContractTest extends TestCase
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

    public function test_ar_en_analyst_can_navigate_and_execute_profitability_with_real_orders_measure(): void
    {
        $this->enableBi();
        $assigned = $this->store('V2-P315-OWN');
        $foreign = $this->store('V2-P315-FOREIGN');
        $actor = $this->analyst($assigned);

        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            EntitlementScope::platform(),
            true,
        );

        $this->fact($assigned, 11001, 'KWD', '90.000', '30.000', '60.000');
        $this->fact($foreign, 11002, 'KWD', '999.000', '100.000', '899.000');
        $day = now()->subDay()->toDateString();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            app()->forgetInstance(AdminNavigation::class);
            $item = $this->biItem($actor->fresh());

            $this->assertNotNull($item);
            $this->assertSame('admin.erp.bi', $item['route']);

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.bi', [
                    'report' => 'commercial.profitability',
                    'scope' => 'store:'.$assigned->id,
                    'breakdown' => 'channel',
                    'dimensions' => ['channel', 'currency'],
                    'measures' => ['orders', 'net_revenue', 'cost', 'margin', 'unvalued_lines'],
                    'filter_currency' => 'KWD',
                    'from' => $day,
                    'to' => $day,
                    'run' => 1,
                ]))
                ->assertOk()
                ->assertViewIs('admin.enterprise-bi')
                ->assertSee('dir="'.$direction.'"', false)
                ->assertViewHas('result', function (mixed $result): bool {
                    if (! is_array($result)
                        || ($result['report'] ?? null) !== 'commercial.profitability'
                        || count($result['rows'] ?? []) !== 1) {
                        return false;
                    }

                    $row = $result['rows'][0];

                    return ($row['channel'] ?? null) === 'b2c'
                        && ($row['currency'] ?? null) === 'KWD'
                        && ($row['orders'] ?? null) === 1
                        && ($row['net_revenue'] ?? null) === '90.000'
                        && ($row['cost'] ?? null) === '30.000'
                        && ($row['margin'] ?? null) === '60.000'
                        && ($row['unvalued_lines'] ?? null) === 0;
                });
        }
    }

    public function test_cross_store_scope_is_not_selectable_and_mixed_currency_keeps_unknown_cost_explicit(): void
    {
        $this->enableBi();
        $assigned = $this->store('V2-P315-CURRENCY');
        $foreign = $this->store('V2-P315-CURRENCY-OTHER');
        $actor = $this->analyst($assigned);

        app(ModuleEntitlementManager::class)->set('enterprise_bi', EntitlementScope::platform(), true);
        $this->fact($assigned, 12001, 'KWD', '48.000', '20.000', '28.000');
        $this->fact($assigned, 12002, 'USD', '70.000', null, null);
        $this->fact($foreign, 12003, 'KWD', '900.000', '10.000', '890.000');
        $day = now()->subDay()->toDateString();

        // A forged scope key must never enter another store. The existing BI
        // selector chooses the first authorized scope rather than executing it.
        $this->actingAs($actor)
            ->get(route('admin.erp.bi', [
                'report' => 'commercial.profitability',
                'scope' => 'store:'.$foreign->id,
                'breakdown' => 'channel',
                'dimensions' => ['channel', 'currency'],
                'measures' => ['orders', 'net_revenue', 'cost', 'margin', 'unvalued_lines'],
                'from' => $day,
                'to' => $day,
                'run' => 1,
            ]))
            ->assertOk()
            ->assertViewHas('selection', fn (array $selection): bool => $selection['scope_key'] === 'store:'.$assigned->id)
            ->assertViewHas('result', function (mixed $result): bool {
                if (! is_array($result) || count($result['rows'] ?? []) !== 2) {
                    return false;
                }

                $rows = collect($result['rows'])->keyBy('currency');
                $kwd = $rows->get('KWD');
                $usd = $rows->get('USD');

                return is_array($kwd) && is_array($usd)
                    && $kwd['orders'] === 1
                    && $kwd['net_revenue'] === '48.000'
                    && $kwd['margin'] === '28.000'
                    && $usd['orders'] === 1
                    && $usd['net_revenue'] === '70.000'
                    && $usd['cost'] === null
                    && $usd['margin'] === null
                    && $usd['unvalued_lines'] === 1;
            });
    }

    public function test_profitability_direct_http_rejects_unentitled_denied_and_disabled_actors(): void
    {
        $this->enableBi();
        $assigned = $this->store('V2-P315-GATES');
        $actor = $this->analyst($assigned);
        $url = route('admin.erp.bi', ['report' => 'commercial.profitability', 'run' => 1]);

        // Real role and enabled lifecycle alone are not a BI entitlement.
        $this->actingAs($actor)->get($url)->assertForbidden();

        app(ModuleEntitlementManager::class)->set('enterprise_bi', EntitlementScope::platform(), true);
        $this->actingAs($actor->fresh())->get($url)->assertOk();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'reports.view')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        app()->forgetInstance(AdminNavigation::class);
        $this->assertNull($this->biItem($actor->fresh()));
        $this->actingAs($actor->fresh())->get($url)->assertForbidden();

        $other = $this->analyst($assigned);
        config()->set('enterprise_module_readiness.certified_versions.enterprise_bi', -1);
        $this->actingAs($other)->get($url)->assertForbidden();

        config()->set(
            'enterprise_module_readiness.certified_versions.enterprise_bi',
            app(ModuleRegistry::class)->manifest('enterprise_bi')->version,
        );
        app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::DISABLED);
        $this->actingAs($other->fresh())->get($url)->assertForbidden();

        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)->get($url)->assertForbidden();
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

    private function analyst(Store $store): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'BUSINESS_ANALYST')->firstOrFail());
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor->fresh();
    }

    private function fact(
        Store $store,
        int $orderId,
        string $currency,
        string $netRevenue,
        ?string $cost,
        ?string $margin,
    ): void {
        $date = now()->subDay()->toDateString().' 12:00:00';

        DB::table('enterprise_bi_profitability_facts')->insert([
            'source_event_id' => (string) Str::uuid(),
            'source_outbox_id' => $orderId,
            'source_fingerprint' => hash('sha256', (string) $orderId),
            'store_id' => $store->id,
            'order_id' => $orderId,
            'order_item_id' => $orderId + 100000,
            'order_created_at' => $date,
            'order_status' => 'completed',
            'channel' => 'b2c',
            'customer_id' => $orderId + 200000,
            'customer_domain' => 'b2c',
            'domain_customer_id' => null,
            'warehouse_id' => null,
            'product_id' => $orderId + 300000,
            'currency' => $currency,
            'quantity' => '1.000',
            'gross_revenue' => $netRevenue,
            'discount_amount' => '0.000',
            'net_revenue' => $netRevenue,
            'unit_cost_snapshot' => $cost,
            'cost_total' => $cost,
            'margin' => $margin,
            'cost_state' => $cost === null ? 'missing' : 'valued',
            'cost_policy_id' => null,
            'cost_policy_updated_at' => null,
            'source_occurred_at' => $date,
            'created_at' => now(),
            'updated_at' => now(),
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
