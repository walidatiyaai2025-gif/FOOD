<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Reporting\Jobs\ProcessReportExportChunk;
use App\Enterprise\Reporting\ReportBuilderExecutor;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class EnterpriseReportBuilderExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_builder_executes_allowlisted_grouped_plan_with_one_operational_query(): void
    {
        [$actor, $store] = $this->authorizedActorAndStore('BI-BUILDER-A');
        $customer = $this->customer('BI Builder Buyer');
        $day = now()->subDay()->startOfDay();

        $this->order($store->id, $customer, 'BI-RB-001', 'b2c', 'completed', '10.000', $day->copy()->addHours(9));
        $this->order($store->id, $customer, 'BI-RB-002', 'b2c', 'completed', '20.000', $day->copy()->addHours(10));
        $this->order($store->id, $customer, 'BI-RB-003', 'b2b', 'pending', '9.500', $day->copy()->addHours(11));

        $orderQueries = [];
        DB::listen(static function ($event) use (&$orderQueries): void {
            $sql = strtolower((string) $event->sql);
            if (str_contains($sql, 'from "orders"') || str_contains($sql, 'from `orders`')) {
                $orderQueries[] = $sql;
            }
        });

        $result = app(ReportBuilderExecutor::class)->execute(
            $actor,
            'orders.summary',
            [
                'from' => $day->toDateString(),
                'to' => $day->toDateString(),
                'dimensions' => ['status', 'channel'],
                'measures' => ['orders', 'gross_total'],
                'group_by' => ['status', 'channel'],
                'sort' => [
                    ['field' => 'gross_total', 'direction' => 'desc'],
                ],
                'per_page' => 10,
            ],
            new EntitlementScope(EntitlementScope::STORE, $store->id),
        );

        $this->assertSame('orders.summary', $result['report']);
        $this->assertSame(['status', 'channel'], $result['plan']['dimensions']);
        $this->assertSame(['orders', 'gross_total'], $result['plan']['measures']);
        $this->assertCount(2, $result['rows']);
        $this->assertSame([
            'status' => 'completed',
            'channel' => 'b2c',
            'orders' => 2,
            'gross_total' => '30.000',
        ], array_intersect_key(
            $result['rows'][0],
            array_flip(['status', 'channel', 'orders', 'gross_total']),
        ));
        $this->assertSame('admin.operations.orders.index', $result['rows'][0]['drill_down']['route']);
        $this->assertSame('completed', $result['rows'][0]['drill_down']['filters']['status']);
        $this->assertSame(1, count($orderQueries));
    }

    public function test_builder_rejects_unregistered_fields_and_sorts_before_operational_query(): void
    {
        [$actor, $store] = $this->authorizedActorAndStore('BI-BUILDER-INVALID');

        $orderQueries = [];
        DB::listen(static function ($event) use (&$orderQueries): void {
            $sql = strtolower((string) $event->sql);
            if (str_contains($sql, 'from "orders"') || str_contains($sql, 'from `orders`')) {
                $orderQueries[] = $sql;
            }
        });

        try {
            app(ReportBuilderExecutor::class)->execute(
                $actor,
                'orders.summary',
                [
                    'dimensions' => ['raw_sql'],
                    'measures' => ['orders'],
                ],
                new EntitlementScope(EntitlementScope::STORE, $store->id),
            );
            $this->fail('Expected an unregistered report field to be rejected.');
        } catch (ValidationException) {
            $this->assertSame([], $orderQueries);
        }

        $this->expectException(ValidationException::class);
        app(ReportBuilderExecutor::class)->execute(
            $actor,
            'orders.summary',
            [
                'dimensions' => ['status'],
                'measures' => ['orders'],
                'group_by' => ['status'],
                'sort' => [
                    ['field' => 'grand_total', 'direction' => 'desc'],
                ],
            ],
            new EntitlementScope(EntitlementScope::STORE, $store->id),
        );
    }

    public function test_builder_denies_cross_scope_execution_before_operational_query(): void
    {
        [$actor, $store] = $this->authorizedActorAndStore('BI-BUILDER-SCOPE-A');
        $otherStore = $this->createStore('BI-BUILDER-SCOPE-B');

        $orderQueries = [];
        DB::listen(static function ($event) use (&$orderQueries): void {
            $sql = strtolower((string) $event->sql);
            if (str_contains($sql, 'from "orders"') || str_contains($sql, 'from `orders`')) {
                $orderQueries[] = $sql;
            }
        });

        try {
            app(ReportBuilderExecutor::class)->execute(
                $actor,
                'orders.summary',
                [],
                new EntitlementScope(EntitlementScope::STORE, $otherStore->id),
            );
            $this->fail('Expected cross-scope report execution to be denied.');
        } catch (AuthorizationException) {
            $this->assertSame([], $orderQueries);
        }

        $this->assertNotSame($store->id, $otherStore->id);
    }

    public function test_large_builder_export_queues_and_preserves_normalized_execution_plan(): void
    {
        Queue::fake();

        [$actor, $store] = $this->authorizedActorAndStore('BI-BUILDER-EXPORT');

        $result = app(ReportBuilderExecutor::class)->requestExport(
            $actor,
            'orders.summary',
            [
                'dimensions' => ['status'],
                'measures' => ['orders', 'gross_total'],
                'group_by' => ['status'],
                'sort' => [
                    ['field' => 'gross_total', 'direction' => 'desc'],
                ],
            ],
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            5001,
            'builder-large-export',
        );

        $this->assertTrue($result['large_export']);
        $this->assertFalse($result['synchronous_request']);
        $this->assertSame('queued_chunked', $result['execution_mode']);

        $export = DB::table('enterprise_report_exports')->find($result['export_id']);
        $this->assertNotNull($export);
        $this->assertSame('queued_chunked', $export->execution_mode);
        $this->assertSame(5001, (int) $export->row_count);

        $payload = json_decode((string) $export->query_payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['status'], $payload['dimensions']);
        $this->assertSame(['orders', 'gross_total'], $payload['measures']);
        $this->assertSame(['status'], $payload['group_by']);
        $this->assertSame([
            ['field' => 'gross_total', 'direction' => 'desc'],
        ], $payload['sort']);

        Queue::assertPushed(
            ProcessReportExportChunk::class,
            fn (ProcessReportExportChunk $job): bool => $job->exportId === $result['export_id'],
        );
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
        string $channel,
        string $status,
        string $total,
        mixed $createdAt,
    ): void {
        DB::table('orders')->insert([
            'store_id' => $store,
            'customer_id' => $customer,
            'order_number' => $number,
            'channel' => $channel,
            'status' => $status,
            'currency' => 'KWD',
            'subtotal' => $total,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => $total,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
