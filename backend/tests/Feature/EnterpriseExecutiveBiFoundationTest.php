<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Reporting\ExecutiveBiService;
use App\Enterprise\Reporting\ExecutiveKpiDefinition;
use App\Enterprise\Reporting\ExecutiveKpiRegistry;
use App\Enterprise\Reporting\ReportBuilderExecutor;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EnterpriseExecutiveBiFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_registry_composes_only_registered_w7_measures_and_scopes(): void
    {
        $registry = app(ExecutiveKpiRegistry::class);
        $kpis = $registry->all();

        $this->assertCount(5, $kpis);
        $this->assertSame('store', $kpis->get('orders.overview')?->scopeType);
        $this->assertSame(
            ExecutiveKpiDefinition::COMPARISON_PERIOD,
            $kpis->get('commercial.margin')?->comparison,
        );
        $this->assertSame('legal_entity', $kpis->get('finance.receivables')?->scopeType);
        $this->assertSame(
            ExecutiveKpiDefinition::COMPARISON_CURRENT_ONLY,
            $kpis->get('inventory.stock')?->comparison,
        );
    }

    public function test_orders_kpi_reconciles_to_registered_read_model_with_bounded_period_trend(): void
    {
        [$actor, $store] = $this->authorizedActorAndStore('BI-EXEC-ORDERS');
        $customer = $this->customer('Executive Buyer');
        $current = CarbonImmutable::today()->subDay();
        $previous = $current->subDay();

        $this->order($store->id, $customer, 'EXEC-C-001', '10.000', $current->setTime(9, 0));
        $this->order($store->id, $customer, 'EXEC-C-002', '20.000', $current->setTime(10, 0));
        $this->order($store->id, $customer, 'EXEC-P-001', '5.000', $previous->setTime(10, 0));

        $snapshot = app(ExecutiveBiService::class)->snapshot(
            $actor,
            [new EntitlementScope(EntitlementScope::STORE, $store->id)],
            $current,
            $current,
            'KWD',
        );

        $card = $this->card($snapshot, 'orders.overview');
        $this->assertTrue($card['visible']);
        $this->assertTrue($card['authorized']);
        $this->assertTrue($card['available']);
        $this->assertSame(2, $card['current']['orders']);
        $this->assertSame('30.000', $card['current']['gross_total']);
        $this->assertSame('15.000', $card['current']['average_order_value']);
        $this->assertSame(1, $card['previous']['orders']);
        $this->assertSame('5.000', $card['previous']['gross_total']);
        $this->assertSame('25.000', $card['trend']['gross_total']['delta']);
        $this->assertSame('500.000', $card['trend']['gross_total']['percent']);
        $this->assertSame('admin.operations.orders.index', $card['drill_down']['route']);
        $this->assertSame('live', $card['freshness']['state']);

        $direct = app(ReportBuilderExecutor::class)->execute(
            $actor,
            'orders.summary',
            [
                'filters' => ['currency' => 'KWD'],
                'from' => $current->toDateString(),
                'to' => $current->toDateString(),
                'dimensions' => [],
                'measures' => ['orders', 'gross_total', 'average_order_value'],
                'group_by' => [],
                'per_page' => 1,
            ],
            new EntitlementScope(EntitlementScope::STORE, $store->id),
        );

        $this->assertSame($direct['rows'][0]['orders'], $card['current']['orders']);
        $this->assertSame($direct['rows'][0]['gross_total'], $card['current']['gross_total']);

        $ar = $this->card($snapshot, 'finance.receivables');
        $this->assertFalse($ar['visible']);
        $this->assertSame('scope_unavailable', $ar['reason']);
    }

    public function test_unentitled_cards_do_not_query_registered_source_read_models(): void
    {
        $this->enableBi();
        $store = $this->createStore('BI-EXEC-DENIED');
        $actor = $this->userWithGlobalRole('SUPER_ADMIN');
        $sourceQueries = [];

        DB::listen(static function ($event) use (&$sourceQueries): void {
            $sql = strtolower((string) $event->sql);
            foreach ([
                'enterprise_bi_profitability_facts',
                'enterprise_ar_documents',
                'enterprise_ap_documents',
                'inventories',
                'stock_movements',
                ' from "orders"',
                ' from `orders`',
            ] as $source) {
                if (str_contains($sql, $source)) {
                    $sourceQueries[] = $sql;

                    return;
                }
            }
        });

        $snapshot = app(ExecutiveBiService::class)->snapshot(
            $actor,
            [new EntitlementScope(EntitlementScope::STORE, $store->id)],
            CarbonImmutable::today(),
            CarbonImmutable::today(),
            'KWD',
        );

        $this->assertSame([], $sourceQueries);

        foreach (['orders.overview', 'commercial.margin', 'inventory.stock'] as $code) {
            $card = $this->card($snapshot, $code);
            $this->assertFalse($card['visible']);
            $this->assertFalse($card['authorized']);
            $this->assertSame('not_authorized_or_entitled', $card['reason']);
        }
    }

    public function test_profitability_card_exposes_explicit_stale_projection_state(): void
    {
        [$actor, $store] = $this->authorizedActorAndStore('BI-EXEC-STALE');
        $day = CarbonImmutable::today()->subDay();
        $refreshedAt = CarbonImmutable::now('UTC')->subHours(2);

        DB::table('enterprise_bi_profitability_facts')->insert([
            'source_event_id' => (string) Str::uuid(),
            'source_outbox_id' => 9001,
            'source_fingerprint' => hash('sha256', 'executive-stale'),
            'store_id' => $store->id,
            'order_id' => 8001,
            'order_item_id' => 8101,
            'order_created_at' => $day->setTime(10, 0),
            'order_status' => 'completed',
            'channel' => 'b2c',
            'customer_id' => 8201,
            'customer_domain' => 'b2c',
            'domain_customer_id' => null,
            'warehouse_id' => null,
            'product_id' => 8301,
            'currency' => 'KWD',
            'quantity' => '1.000',
            'gross_revenue' => '30.000',
            'discount_amount' => '0.000',
            'net_revenue' => '30.000',
            'unit_cost_snapshot' => '10.000',
            'cost_total' => '10.000',
            'margin' => '20.000',
            'cost_state' => 'valued',
            'cost_policy_id' => null,
            'cost_policy_updated_at' => null,
            'source_occurred_at' => $day->setTime(10, 0),
            'created_at' => $refreshedAt,
            'updated_at' => $refreshedAt,
        ]);

        $snapshot = app(ExecutiveBiService::class)->snapshot(
            $actor,
            [new EntitlementScope(EntitlementScope::STORE, $store->id)],
            $day,
            $day,
            'KWD',
        );

        $card = $this->card($snapshot, 'commercial.margin');
        $this->assertTrue($card['available']);
        $this->assertSame('30.000', $card['current']['net_revenue']);
        $this->assertSame('10.000', $card['current']['cost']);
        $this->assertSame('20.000', $card['current']['margin']);
        $this->assertSame(0, $card['current']['unvalued_lines']);
        $this->assertSame('stale', $card['freshness']['state']);
        $this->assertTrue($card['freshness']['stale']);
        $this->assertSame('queued_projection', $card['freshness']['refresh_mode']);
        $this->assertNotNull($card['freshness']['refreshed_at']);
    }

    /** @return array{User, Store} */
    private function authorizedActorAndStore(string $code): array
    {
        $this->enableBi();
        $store = $this->createStore($code);

        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
            ['reports.read', 'reports.export'],
        );

        return [$this->userWithGlobalRole('SUPER_ADMIN'), $store];
    }

    private function enableBi(): void
    {
        app(ModuleRegistry::class)->syncManifest('enterprise_bi');

        $manager = app(ModuleLifecycleManager::class);
        $manager->transition('enterprise_bi', ModuleState::ENABLING);
        $manager->transition('enterprise_bi', ModuleState::ENABLED);
    }

    private function createStore(string $code): Store
    {
        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        $store = Store::query()->create([
            'store_type_id' => $storeTypeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);

        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function userWithGlobalRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function customer(string $name): int
    {
        return (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(
        int $store,
        int $customer,
        string $number,
        string $total,
        CarbonImmutable $createdAt,
    ): void {
        DB::table('orders')->insert([
            'store_id' => $store,
            'customer_id' => $customer,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => 'completed',
            'currency' => 'KWD',
            'subtotal' => $total,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => $total,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /** @return array<string, mixed> */
    private function card(array $snapshot, string $code): array
    {
        foreach ($snapshot['cards'] as $card) {
            if (($card['code'] ?? null) === $code) {
                return $card;
            }
        }

        $this->fail("Executive BI card [{$code}] was not returned.");
    }
}
