<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Reporting\Jobs\ProcessReportExportChunk;
use App\Enterprise\Reporting\ReportAccessResolver;
use App\Enterprise\Reporting\ReportExportManager;
use App\Enterprise\Reporting\ReportQuery;
use App\Enterprise\Reporting\ReportRegistry;
use App\Enterprise\Reporting\ScheduledReportDispatcher;
use App\Enterprise\Reporting\ScheduledReportManager;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class EnterpriseReportExportFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_large_export_is_always_queued_with_bounded_scope_snapshot(): void
    {
        Queue::fake();

        [$actor, $store] = $this->authorizedActorAndStore('BI-EXPORT-LARGE');
        $report = app(ReportRegistry::class)->require('orders.summary');
        $query = ReportQuery::fromArray($report, ['filters' => ['status' => 'completed']]);

        $id = app(ReportExportManager::class)->request(
            $actor,
            $query,
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            5001,
            'large-export',
        );

        $row = DB::table('enterprise_report_exports')->find($id);
        $this->assertNotNull($row);
        $this->assertSame('queued_chunked', $row->execution_mode);
        $this->assertSame(5001, (int) $row->row_count);
        $this->assertSame($report->exportChunkSize, (int) $row->chunk_size);
        $this->assertSame('queued', $row->status);

        $snapshot = json_decode((string) $row->query_payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('store', $snapshot['scope_type']);
        $this->assertSame($store->id, $snapshot['scope_id']);
        $this->assertSame(ReportExportManager::QUEUE, $snapshot['queue']);

        Queue::assertPushed(
            ProcessReportExportChunk::class,
            fn (ProcessReportExportChunk $job): bool => $job->exportId === $id,
        );
    }

    public function test_chunk_progress_is_resumable_and_duplicate_execution_is_idempotent(): void
    {
        Queue::fake();

        [$actor, $store] = $this->authorizedActorAndStore('BI-EXPORT-RESUME');
        $report = app(ReportRegistry::class)->require('orders.summary');
        $query = ReportQuery::fromArray($report, []);

        $id = app(ReportExportManager::class)->request(
            $actor,
            $query,
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            1100,
            'resume-export',
        );

        $job = new ProcessReportExportChunk($id);
        $job->handle(app(ReportRegistry::class), app(ReportAccessResolver::class));

        $first = DB::table('enterprise_report_exports')->find($id);
        $this->assertSame(500, (int) $first->processed_rows);
        $this->assertSame(1, (int) $first->chunks_completed);
        $this->assertSame('running', $first->status);

        $job->handle(app(ReportRegistry::class), app(ReportAccessResolver::class));
        $job->handle(app(ReportRegistry::class), app(ReportAccessResolver::class));

        $complete = DB::table('enterprise_report_exports')->find($id);
        $this->assertSame(1100, (int) $complete->processed_rows);
        $this->assertSame(3, (int) $complete->chunks_completed);
        $this->assertSame('completed', $complete->status);

        $job->handle(app(ReportRegistry::class), app(ReportAccessResolver::class));
        $duplicate = DB::table('enterprise_report_exports')->find($id);
        $this->assertSame(1100, (int) $duplicate->processed_rows);
        $this->assertSame(3, (int) $duplicate->chunks_completed);

        $manifest = json_decode(
            (string) $duplicate->artifact_metadata,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertTrue($manifest['complete']);
        $this->assertTrue($manifest['resumable']);
        $this->assertFalse($manifest['synchronous_request']);
    }

    public function test_export_execution_revalidates_authorization_before_progress(): void
    {
        Queue::fake();

        [$actor, $store] = $this->authorizedActorAndStore('BI-EXPORT-REVOKE');
        $report = app(ReportRegistry::class)->require('orders.summary');
        $query = ReportQuery::fromArray($report, []);

        $id = app(ReportExportManager::class)->request(
            $actor,
            $query,
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            6000,
            'revoke-export',
        );

        $actor->forceFill(['is_active' => false])->save();

        (new ProcessReportExportChunk($id))->handle(
            app(ReportRegistry::class),
            app(ReportAccessResolver::class),
        );

        $row = DB::table('enterprise_report_exports')->find($id);
        $this->assertSame('failed', $row->status);
        $this->assertSame('authorization_revoked', $row->failure_code);
        $this->assertSame(0, (int) $row->processed_rows);
    }

    public function test_cross_store_export_is_rejected_before_persistence(): void
    {
        Queue::fake();

        [$actor, $store] = $this->authorizedActorAndStore('BI-EXPORT-A');
        $otherStore = $this->createStore('BI-EXPORT-B');
        $report = app(ReportRegistry::class)->require('orders.summary');
        $query = ReportQuery::fromArray($report, []);

        try {
            app(ReportExportManager::class)->request(
                $actor,
                $query,
                new EntitlementScope(EntitlementScope::STORE, $otherStore->id),
                10,
                'wrong-store',
            );
            $this->fail('Expected cross-store export authorization to fail.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('enterprise_report_exports')->count());
        }

        $this->assertNotSame($store->id, $otherStore->id);
    }

    public function test_scheduled_dispatch_uses_same_export_pipeline_and_disabled_bi_short_circuits(): void
    {
        Queue::fake();

        [$actor, $store] = $this->authorizedActorAndStore('BI-SCHEDULE');
        $report = app(ReportRegistry::class)->require('orders.summary');
        $query = ReportQuery::fromArray($report, []);

        $scheduleId = app(ScheduledReportManager::class)->schedule(
            $actor,
            $query,
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            7500,
            now()->subMinute(),
            60,
            'hourly-orders-summary',
        );

        app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::DISABLED);

        $this->assertSame(0, app(ScheduledReportDispatcher::class)->dispatchDue());
        $this->assertSame(0, DB::table('enterprise_report_exports')->count());

        app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::ENABLED);

        $this->assertSame(1, app(ScheduledReportDispatcher::class)->dispatchDue(500));

        $export = DB::table('enterprise_report_exports')
            ->where('scheduled_report_id', $scheduleId)
            ->first();
        $this->assertNotNull($export);
        $this->assertSame(7500, (int) $export->row_count);
        $this->assertSame('queued_chunked', $export->execution_mode);

        $schedule = DB::table('enterprise_scheduled_reports')->find($scheduleId);
        $this->assertNotNull($schedule->last_run_at);
        $this->assertNull($schedule->last_error);
        $this->assertTrue(now()->lt($schedule->next_run_at));

        Queue::assertPushed(
            ProcessReportExportChunk::class,
            fn (ProcessReportExportChunk $job): bool => $job->exportId === (int) $export->id,
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
}
