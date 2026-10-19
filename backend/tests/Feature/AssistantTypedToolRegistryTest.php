<?php

namespace Tests\Feature;

use App\Domain\Analytics\ReadModel\AnalyticsFactMaterializer;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Security\AssistantToolExecutor;
use App\Domain\Assistant\Security\RetailerSellOutAccessPolicy;
use App\Domain\Assistant\Tools\AssistantToolRegistry;
use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class AssistantTypedToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_registry_exports_typed_provider_contracts_for_registered_read_tools(): void
    {
        $registry = app(AssistantToolRegistry::class);

        $this->assertTrue($registry->has('analytics.summary'));
        $this->assertTrue($registry->has('analytics.diagnose'));
        $this->assertTrue($registry->has('retailers.360'));
        $this->assertTrue($registry->has('support.summary'));
        $this->assertTrue($registry->has('margin.summary'));

        $definition = $registry->definition('retailers.performance');
        $this->assertSame('READ', $definition->mode);
        $this->assertSame('SELL_OUT', $definition->dataClass);
        $this->assertTrue($definition->sellOutConsent);
        $this->assertSame(['reports.view'], $definition->requiredPermissions);
        $this->assertFalse($definition->inputSchema['additionalProperties']);

        $provider = collect($registry->providerTools('ar'))
            ->firstWhere('x-foodex.tool_key', 'retailers.performance');

        $this->assertIsArray($provider);
        $this->assertSame('function', $provider['type']);
        $this->assertSame('retailers__performance', $provider['function']['name']);
        $this->assertSame('1.0', $provider['x-foodex']['schema_version']);
        $this->assertSame(1000, $provider['x-foodex']['row_limit']);
        $this->assertStringContainsString('تجار التجزئة', $provider['function']['description']);

        $diagnostic = $registry->definition('analytics.diagnose');
        $this->assertSame('DeterministicAnalyticsEngine', $diagnostic->source);
        $this->assertSame(['reports.view'], $diagnostic->requiredPermissions);

        $retailer360 = $registry->definition('retailers.360');
        $this->assertSame('READ', $retailer360->mode);
        $this->assertSame('SELL_OUT', $retailer360->dataClass);
        $this->assertTrue($retailer360->sellOutConsent);
        $this->assertSame(['reports.view'], $retailer360->requiredPermissions);
        $this->assertSame(['b2b'], $retailer360->channels);
        $this->assertSame('Retailer360IntelligenceService', $retailer360->source);

        $retailer360Provider = collect($registry->providerTools('en'))
            ->firstWhere('x-foodex.tool_key', 'retailers.360');
        $this->assertIsArray($retailer360Provider);
        $this->assertSame('retailers__360', $retailer360Provider['function']['name']);

        $forecast = $registry->definition('forecast.replenishment');
        $this->assertSame('DemandForecastReplenishmentService', $forecast->source);
        $this->assertSame('SELL_OUT', $forecast->dataClass);
        $this->assertTrue($forecast->sellOutConsent);
        $this->assertSame(['reports.view'], $forecast->requiredPermissions);
        $this->assertSame(90, $forecast->inputSchema['properties']['horizon_days']['maximum']);

        $margin = $registry->definition('margin.summary');
        $this->assertSame(['reports.view', 'catalog.view'], $margin->requiredPermissions);
        $this->assertSame('all', $margin->permissionMode);
    }

    public function test_schema_validation_rejects_malformed_unknown_and_scope_injection_before_execution(): void
    {
        $storeA = $this->store('b2c', 'AI-TYPED-A');
        $storeB = $this->store('b2c', 'AI-TYPED-B');
        $actor = $this->storeUser($storeA, 'B2C_STORE_ADMIN');
        $executor = app(AssistantToolExecutor::class);

        try {
            $executor->execute(
                'products.performance',
                $this->request($actor, $storeA, 'b2c', [
                    'from' => '2026-10-01',
                    'to' => '2026-10-18',
                    'limit' => 99,
                ]),
                'typed-malformed',
            );
            $this->fail('Malformed tool args should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('entities.limit', $exception->errors());
        }

        try {
            $executor->execute(
                'sales.summary',
                $this->request($actor, $storeA, 'b2c', [
                    'store_id' => $storeB,
                    'from' => '2026-10-18',
                    'to' => '2026-10-18',
                ]),
                'typed-scope-injection',
            );
            $this->fail('Injected store scope should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('store_id', $exception->errors());
        }

        try {
            $executor->execute(
                'analytics.summary',
                $this->request($actor, $storeA, 'b2c', [
                    'from' => '2026-10-18',
                    'to' => '2026-10-18',
                    'raw_sql' => 'select * from users',
                ]),
                'typed-sql-injection',
            );
            $this->fail('Raw SQL arguments should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('raw_sql', $exception->errors());
        }

        try {
            $executor->execute(
                'retailers.performance',
                $this->request($actor, $storeA, 'b2c', [
                    'retailer_store_ids' => range(1, 26),
                ]),
                'typed-row-budget',
            );
            $this->fail('Oversized retailer lists should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('entities.retailer_store_ids', $exception->errors());
        }

        try {
            $executor->execute(
                'not.registered',
                $this->request($actor, $storeA, 'b2c', []),
                'typed-unknown',
            );
            $this->fail('Unknown tools should be rejected before execution.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    public function test_declared_permission_is_rechecked_before_business_tool_execution(): void
    {
        $store = $this->store('b2c', 'AI-TYPED-PERM');
        $actor = $this->limitedStoreUser($store, ['assistant.use']);

        try {
            app(AssistantToolExecutor::class)->execute(
                'sales.summary',
                $this->request($actor, $store, 'b2c', [
                    'from' => '2026-10-18',
                    'to' => '2026-10-18',
                ]),
                'typed-permission-denied',
            );
            $this->fail('Tool permission metadata should deny execution.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'assistant.tool.read',
        ]);
    }

    public function test_materialized_tool_is_audited_bounded_and_returns_provenance_metadata(): void
    {
        $store = $this->store('b2c', 'AI-TYPED-READ');
        $actor = $this->storeUser($store, 'B2C_STORE_ADMIN');
        $today = CarbonImmutable::now(AnalyticsFactMaterializer::TIMEZONE)->toDateString();

        app(AnalyticsFactMaterializer::class)->rebuild($today, $today, [$store]);

        $result = app(AssistantToolExecutor::class)->execute(
            'analytics.summary',
            $this->request($actor, $store, 'b2c', [
                'from' => $today,
                'to' => $today,
            ]),
            'typed-provenance',
        );

        $this->assertSame('analytics.summary', $result->data['_tool']['key']);
        $this->assertSame('AnalyticsReadModelService', $result->data['_tool']['source']);
        $this->assertSame('READ', $result->data['_tool']['mode']);
        $this->assertFalse($result->data['_tool']['model_sql_allowed']);
        $this->assertSame(1000, $result->data['_tool']['row_limit']);
        $this->assertSame('fresh', $result->data['freshness']['state']);
        $this->assertSame([$store], $result->data['store_ids']);
        $this->assertSame('none', $result->data['sellout_feed'][0]['state']);
        $this->assertStringContainsString(
            'No actual retail sell-out feed',
            $result->data['sellout_feed'][0]['message_en'],
        );
        $this->assertFalse($result->data['sellout_feed'][0]['wholesale_purchase_inference']);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'assistant.tool.read',
        ]);
    }

    public function test_diagnostic_tool_is_audited_scoped_and_returns_computed_guardrails(): void
    {
        $store = $this->store('b2c', 'AI-DIAG');
        $actor = $this->storeUser($store, 'B2C_STORE_ADMIN');
        $today = CarbonImmutable::now(AnalyticsFactMaterializer::TIMEZONE)->startOfDay();
        $currentFrom = $today->subDays(2);
        $previousFrom = $today->subDays(5);

        app(AnalyticsFactMaterializer::class)->rebuild(
            $previousFrom->toDateString(),
            $today->toDateString(),
            [$store],
        );

        $result = app(AssistantToolExecutor::class)->execute(
            'analytics.diagnose',
            $this->request($actor, $store, 'b2c', [
                'from' => $currentFrom->toDateString(),
                'to' => $today->toDateString(),
            ]),
            'typed-diagnostic',
        );

        $this->assertSame('analytics.diagnose', $result->data['_tool']['key']);
        $this->assertSame('DeterministicAnalyticsEngine', $result->data['_tool']['source']);
        $this->assertSame('foodex.analytics.diagnostic.v1', $result->data['schema_version']);
        $this->assertSame([$store], $result->data['scope']['store_ids']);
        $this->assertSame('sufficient', $result->data['evidence']['state']);
        $this->assertFalse($result->data['guardrails']['model_arithmetic_allowed']);
        $this->assertFalse($result->data['guardrails']['model_may_override_computed_values']);
        $this->assertNull($result->data['comparison']['net_revenue']['percent_change']);
        $this->assertSame(
            'zero_previous_denominator',
            $result->data['comparison']['net_revenue']['percent_change_reason'],
        );

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'assistant.tool.read',
        ]);
    }

    public function test_retailer_performance_requires_live_sellout_consent_and_revocation_is_immediate(): void
    {
        $wholesaleStore = app(WholesalePrincipal::class)->storeId();
        $retailStore = $this->store('b2c', 'AI-TYPED-RETAIL');
        $this->linkRetailWholesaleAccount($retailStore);
        $wholesaleActor = $this->globalUser('B2B_ADMIN');
        $retailOwner = $this->storeUser($retailStore, 'B2C_STORE_ADMIN');
        $today = CarbonImmutable::now(AnalyticsFactMaterializer::TIMEZONE)->toDateString();

        app(AnalyticsFactMaterializer::class)->rebuild($today, $today, [$retailStore]);

        $request = new AssistantToolRequest(
            actorUserId: (int) $wholesaleActor->getKey(),
            locale: 'en',
            channel: 'b2b',
            storeId: $wholesaleStore,
            entities: [
                'from' => $today,
                'to' => $today,
                'retailer_store_ids' => [$retailStore],
                'wholesale_store_id' => $wholesaleStore,
            ],
            context: [
                'path' => '/admin/b2b/dashboard',
                'channel' => 'b2b',
                'store_id' => $wholesaleStore,
            ],
        );

        $this->assertSelloutDenied(fn () => app(AssistantToolExecutor::class)->execute(
            'retailers.performance',
            $request,
            'typed-consent-denied',
        ));
        $this->assertSelloutDenied(fn () => app(AssistantToolExecutor::class)->execute(
            'analytics.diagnose',
            $request,
            'diagnostic-consent-denied',
        ));

        $this->assertSelloutDenied(fn () => app(AssistantToolExecutor::class)->execute(
            'retailers.360',
            $request,
            'retailer-360-consent-denied',
        ));

        app(RetailerSellOutAccessPolicy::class)->grant(
            $retailOwner,
            $retailStore,
            $wholesaleStore,
            RetailerSellOutAccessPolicy::BASIS_OPT_IN,
            retentionDays: 30,
            expiresAt: now()->addDays(7),
        );

        $allowed = app(AssistantToolExecutor::class)->execute(
            'retailers.performance',
            $request,
            'typed-consent-allowed',
        );

        $this->assertSame('sell_out', $allowed->data['flow']);
        $this->assertSame($retailStore, $allowed->data['retailers'][0]['retailer_store_id']);
        $this->assertSame('SELL_OUT', $allowed->data['_tool']['data_class']);

        $diagnostic = app(AssistantToolExecutor::class)->execute(
            'analytics.diagnose',
            $request,
            'diagnostic-consent-allowed',
        );
        $this->assertSame('sell_out', $diagnostic->data['flow']);
        $this->assertSame([$retailStore], $diagnostic->data['scope']['store_ids']);
        $this->assertFalse($diagnostic->data['guardrails']['model_arithmetic_allowed']);

        $retailer360 = app(AssistantToolExecutor::class)->execute(
            'retailers.360',
            $request,
            'retailer-360-consent-allowed',
        );
        $this->assertSame('retailers.360', $retailer360->data['_tool']['key']);
        $this->assertSame('Retailer360IntelligenceService', $retailer360->data['_tool']['source']);
        $this->assertSame('SELL_OUT', $retailer360->data['_tool']['data_class']);
        $this->assertSame('foodex.analytics.retailer360.v1', $retailer360->data['schema_version']);
        $this->assertSame([$retailStore], $retailer360->data['account']['retailer_store_ids']);
        $this->assertFalse($retailer360->data['flows']['sell_out']['wholesale_purchase_inference']);

        app(RetailerSellOutAccessPolicy::class)->revoke(
            $retailOwner,
            $retailStore,
            $wholesaleStore,
        );

        $this->assertSelloutDenied(fn () => app(AssistantToolExecutor::class)->execute(
            'retailers.performance',
            $request,
            'typed-consent-revoked',
        ));
        $this->assertSelloutDenied(fn () => app(AssistantToolExecutor::class)->execute(
            'analytics.diagnose',
            $request,
            'diagnostic-consent-revoked',
        ));
        $this->assertSelloutDenied(fn () => app(AssistantToolExecutor::class)->execute(
            'retailers.360',
            $request,
            'retailer-360-consent-revoked',
        ));
    }

    private function store(string $channel, string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', strtoupper($channel))->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storeUser(int $storeId, string $roleCode): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-'.$storeId.'-'.uniqid().'@typed-tools.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /** @param list<string> $permissions */
    private function limitedStoreUser(int $storeId, array $permissions): User
    {
        $roleId = (int) DB::table('roles')->insertGetId([
            'code' => 'AI_TOOL_LIMITED_'.uniqid(),
            'name' => 'AI Tool Limited',
            'description' => null,
            'scope' => 'store',
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permissionIds = DB::table('permissions')->whereIn('code', $permissions)->pluck('id');
        foreach ($permissionIds as $permissionId) {
            DB::table('permission_role')->insert([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
        }

        $user = User::query()->create([
            'name' => 'AI Tool Limited',
            'email' => uniqid().'@typed-tools.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        DB::table('user_store_roles')->insert([
            'user_id' => $user->getKey(),
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function globalUser(string $roleCode): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-'.uniqid().'@typed-tools.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function linkRetailWholesaleAccount(int $retailStoreId): void
    {
        $customerId = (int) DB::table('b2b_customers')->insertGetId([
            'name' => 'Typed retail link '.$retailStoreId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('retail_wholesale_accounts')->insert([
            'retail_store_id' => $retailStoreId,
            'b2b_customer_id' => $customerId,
            'owner_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $entities */
    private function request(User $actor, int $storeId, string $channel, array $entities): AssistantToolRequest
    {
        return new AssistantToolRequest(
            actorUserId: (int) $actor->getKey(),
            locale: 'en',
            channel: $channel,
            storeId: $storeId,
            entities: $entities,
            context: [
                'path' => "/admin/{$channel}/dashboard",
                'channel' => $channel,
                'store_id' => $storeId,
            ],
        );
    }

    private function assertSelloutDenied(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected sell-out tool access to be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
