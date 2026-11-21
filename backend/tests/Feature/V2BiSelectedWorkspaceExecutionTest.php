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
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

final class V2BiSelectedWorkspaceExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Signed synthetic workflow proof is scoped to test fixtures, not ERP runtime certification.
        SyntheticErpWorkflowEvidence::install();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.enterprise_bi',
            app(ModuleRegistry::class)->manifest('enterprise_bi')->version,
        );
        app(ModuleRegistry::class)->syncManifest('enterprise_bi');
        app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::ENABLED);
    }

    public function test_only_the_selected_bi_tab_prepares_its_expensive_data_even_with_run_flag(): void
    {
        $store = $this->store('V2-4032-LAZY');
        $actor = $this->analyst($store);
        $day = now()->subDay()->toDateString();

        $customerId = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => 'BI Lazy Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('orders')->insert([
            'store_id' => $store->id,
            'customer_id' => $customerId,
            'order_number' => 'V2-4032-SOURCE-ORDER',
            'channel' => 'b2c',
            'status' => 'completed',
            'currency' => 'KWD',
            'subtotal' => '12.000',
            'discount_total' => '0.000',
            'delivery_total' => '0.000',
            'grand_total' => '12.000',
            'created_at' => $day.' 10:00:00',
            'updated_at' => $day.' 10:00:00',
        ]);
        $counts = [
            'orders' => DB::table('orders')->count(),
            'exports' => DB::table('enterprise_report_exports')->count(),
            'schedules' => DB::table('enterprise_scheduled_reports')->count(),
        ];

        $context = [
            'report' => 'orders.summary',
            'scope' => 'store:'.$store->id,
            'from' => $day,
            'to' => $day,
            'dashboard_store' => 'store:'.$store->id,
            'dashboard_from' => $day,
            'dashboard_to' => $day,
            'dashboard_currency' => 'KWD',
            'run' => '1',
        ];

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            app()->forgetInstance(AdminNavigation::class);

            foreach (['overview', 'reports', 'exports', 'schedules'] as $tab) {
                $response = $this->actingAs($actor->fresh())->get(route('admin.erp.bi', [
                    ...$context, 'bi_tab' => $tab,
                ]));

                $response->assertOk()
                    ->assertViewIs('admin.enterprise-bi')
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertSee('data-bi-workspace-panel="'.$tab.'"', false)
                    ->assertViewHas('selection', fn (array $selection): bool => $selection['scope_key'] === 'store:'.$store->id
                        && $selection['from'] === $day && $selection['to'] === $day);

                $this->assertSame(
                    1,
                    preg_match_all('/data-bi-workspace-panel="(?:overview|reports|exports|schedules)"/', $response->getContent()),
                );

                $response->assertViewHas('overview', fn (mixed $value): bool => $tab === 'overview'
                    ? is_array($value) && isset($value['analytics'])
                    : $value === null);
                $response->assertViewHas('exports', []);
                $response->assertViewHas('schedules', []);
                $response->assertViewHas('exceptions', fn (mixed $value): bool => $tab === 'exports'
                    ? is_array($value) && array_key_exists('failed_exports', $value)
                    : $value === null);

                if ($tab === 'reports') {
                    $response->assertViewHas('result', fn (mixed $value): bool => is_array($value))
                        ->assertSee('12.000');
                } else {
                    $response->assertViewHas('result', fn (mixed $value): bool => $value === null)
                        ->assertViewHas('rows', []);
                }

                $this->assertTabLinkKeepsContext($response->getContent(), 'reports', $context);
            }
        }

        $this->assertSame($counts['orders'], DB::table('orders')->count());
        $this->assertSame($counts['exports'], DB::table('enterprise_report_exports')->count());
        $this->assertSame($counts['schedules'], DB::table('enterprise_scheduled_reports')->count());
    }

    public function test_implicit_report_tab_legacy_url_and_invalid_tab_fallback_remain_compatible(): void
    {
        $store = $this->store('V2-4032-LEGACY');
        $actor = $this->analyst($store);

        $this->actingAs($actor)
            ->get(route('admin.erp.bi', ['report' => 'orders.summary', 'scope' => 'store:'.$store->id]))
            ->assertOk()
            ->assertSee('data-bi-workspace-panel="reports"', false)
            ->assertViewHas('overview', fn (mixed $value): bool => $value === null);

        $this->actingAs($actor)
            ->get(route('admin.erp.bi', ['report' => 'orders.summary', 'bi_tab' => 'wrong-tab']))
            ->assertOk()
            ->assertSee('data-bi-workspace-panel="overview"', false)
            ->assertViewHas('exports', [])
            ->assertViewHas('schedules', []);

        $this->actingAs($actor)
            ->get('/admin/erp/bi?bi_tab%5B%5D=reports&report=orders.summary')
            ->assertOk()
            ->assertSee('data-bi-workspace-panel="overview"', false)
            ->assertViewHas('result', fn (mixed $value): bool => $value === null);
    }

    public function test_hidden_tab_run_does_not_override_cross_store_or_role_module_denial(): void
    {
        $allowed = $this->store('V2-4032-OWN');
        $foreign = $this->store('V2-4032-FOREIGN');
        $actor = $this->analyst($allowed);

        foreach (['overview', 'reports', 'exports', 'schedules'] as $tab) {
            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.bi', [
                    'bi_tab' => $tab, 'report' => 'orders.summary',
                    'scope' => 'store:'.$foreign->id, 'run' => 1,
                ]))
                ->assertForbidden();
        }

        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)
            ->get(route('admin.erp.bi', ['bi_tab' => 'exports']))
            ->assertForbidden();

        $actor->forceFill(['is_active' => false])->save();
        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.bi', ['bi_tab' => 'overview']))
            ->assertForbidden();

        $this->assertDatabaseCount('enterprise_report_exports', 0);
        $this->assertDatabaseCount('enterprise_scheduled_reports', 0);
    }

    private function assertTabLinkKeepsContext(string $html, string $tab, array $context): void
    {
        $this->assertSame(
            1,
            preg_match('/id="bi-tab-'.preg_quote($tab, '/').'"[^>]*href="([^"]+)"/', $html, $matches),
        );

        parse_str((string) parse_url(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'), PHP_URL_QUERY), $query);
        foreach (['report', 'scope', 'from', 'to', 'dashboard_store', 'dashboard_from', 'dashboard_to', 'dashboard_currency'] as $key) {
            $this->assertSame((string) $context[$key], $query[$key] ?? null);
        }
        $this->assertSame($tab, $query['bi_tab'] ?? null);
    }

    private function analyst(Store $store): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'BUSINESS_ANALYST')->firstOrFail());

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor->fresh();
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

        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            ! str_contains($code, 'FOREIGN'),
            ['reports.read', 'reports.export'],
        );

        return $store;
    }
}
