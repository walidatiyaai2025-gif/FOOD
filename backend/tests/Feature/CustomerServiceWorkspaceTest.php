<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\PlatformCustomer;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerServiceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_customer_service_workspace_is_permission_aware_and_uses_canonical_destinations(): void
    {
        $storeId = $this->store('B2C', 'CS-WORKSPACE');
        $actor = $this->storeUser($storeId, [
            'customers.view',
            'customers.manage',
            'orders.view',
            'finance.view',
            'field_ops.manage',
            'whatsapp.inbox.view',
        ]);
        $this->customer($storeId, 'b2c', 'Workspace Customer');

        $this->actingAs($actor)
            ->get(route('admin.erp.customer-service'))
            ->assertOk()
            ->assertViewIs('admin.customer-service-workspace')
            ->assertSee(route('admin.customer-360.index'), false)
            ->assertSee(route('admin.operations.orders.index'), false)
            ->assertSee(route('admin.field-operations.overview'), false)
            ->assertSee(route('admin.whatsapp-inbox.index'), false)
            ->assertSee(__('customer_service_workspace.truth_title'))
            ->assertSee(__('customer_service_workspace.dashboard.title'))
            ->assertSee(__('customer_service_workspace.cases.title'))
            ->assertSee('data-pagination-required', false);
    }

    public function test_customer_only_role_does_not_leak_unpermitted_operational_destinations(): void
    {
        $storeId = $this->store('B2C', 'CS-CUSTOMER');
        $actor = $this->storeUser($storeId, ['customers.view']);

        $response = $this->actingAs($actor)
            ->get(route('admin.erp.customer-service'))
            ->assertOk()
            ->assertSee(route('admin.customer-360.index'), false)
            ->assertViewHas('capabilities', function ($capabilities): bool {
                $keys = $capabilities->pluck('key');

                return $keys->contains('customer')
                    && $keys->contains('activity')
                    && ! $keys->contains('delivery')
                    && ! $keys->contains('conversations');
            });

        $response->assertDontSee(route('admin.whatsapp-inbox.index'), false);
    }

    public function test_user_without_customer_service_permissions_is_denied(): void
    {
        $this->store('B2C', 'CS-DENIED');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->actingAs($actor)
            ->get(route('admin.erp.customer-service'))
            ->assertForbidden();
    }

    public function test_case_lifecycle_is_store_scoped_audited_and_supports_feedback(): void
    {
        $storeA = $this->store('B2C', 'CS-A');
        $storeB = $this->store('B2C', 'CS-B');
        $actor = $this->storeUser($storeA, ['customers.view', 'customers.manage']);
        $customerA = $this->customer($storeA, 'b2c', 'Scoped Customer A');
        $customerB = $this->customer($storeB, 'b2c', 'Scoped Customer B');

        $this->actingAs($actor)
            ->post(route('admin.erp.customer-service.cases.store'), [
                'store_id' => $storeA,
                'platform_customer_id' => $customerA->id,
                'type' => 'complaint',
                'priority' => 'high',
                'queue' => 'general',
                'subject' => 'Damaged item complaint',
                'description' => 'Customer reported damaged packaging.',
            ])
            ->assertRedirect();

        $case = DB::table('customer_service_cases')->where('platform_customer_id', $customerA->id)->first();
        $this->assertNotNull($case);
        $this->assertSame($storeA, (int) $case->store_id);
        $this->assertSame('open', $case->status);
        $this->assertSame('high', $case->priority);
        $this->assertNotNull($case->sla_target_at);

        $this->actingAs($actor)
            ->post(route('admin.erp.customer-service.cases.store'), [
                'store_id' => $storeA,
                'platform_customer_id' => $customerB->id,
                'type' => 'other',
                'priority' => 'normal',
                'subject' => 'Foreign customer',
            ])
            ->assertNotFound();

        $this->actingAs($actor)
            ->patch(route('admin.erp.customer-service.cases.update', ['case' => $case->id]), [
                'status' => 'resolved',
                'service_recovery_code' => 'GOODWILL',
                'service_recovery_note' => 'Recovery approved under customer-service policy.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('customer_service_cases', [
            'id' => $case->id,
            'status' => 'resolved',
            'service_recovery_code' => 'GOODWILL',
        ]);

        $this->actingAs($actor)
            ->post(route('admin.erp.customer-service.cases.notes.store', ['case' => $case->id]), [
                'body' => 'Customer accepted the recovery action.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('customer_service_case_notes', [
            'case_id' => $case->id,
            'body' => 'Customer accepted the recovery action.',
            'is_internal' => true,
        ]);

        $this->actingAs($actor)
            ->post(route('admin.erp.customer-service.cases.feedback.store', ['case' => $case->id]), [
                'csat_score' => 5,
                'nps_score' => 9,
                'feedback_context' => 'post_resolution_call',
                'consent' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('customer_service_cases', [
            'id' => $case->id,
            'csat_score' => 5,
            'nps_score' => 9,
            'feedback_context' => 'post_resolution_call',
        ]);

        $this->assertDatabaseHas('audit_logs', ['event' => 'customer_service.case.created']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer_service.case.updated']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer_service.case.note_added']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer_service.case.feedback_recorded']);

        $this->actingAs($actor)
            ->get(route('admin.erp.customer-service.cases.show', ['case' => $case->id]))
            ->assertOk()
            ->assertSee('Damaged item complaint')
            ->assertSee('Customer accepted the recovery action.');

        $this->actingAs($actor)
            ->get(route('admin.erp.customer-service.cases.show', ['case' => 999999]))
            ->assertNotFound();
    }

    public function test_chatbot_case_authority_is_promoted_to_customer_service_cases(): void
    {
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_10_17_160000_promote_customer_service_cases',
        ]);
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('customer_service_cases'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('customer_service_case_notes'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('whats_app_chatbot_cases'));
    }

    public function test_customer_service_reports_are_registered_for_shared_export_engine(): void
    {
        $reports = config('enterprise_reporting.reports');

        foreach ([
            'customer_service.case_sla',
            'customer_service.feedback',
            'customer_service.customer_growth',
            'customer_service.recovery',
            'customer_service.workload',
        ] as $code) {
            $this->assertSame('customer_service_operations', $reports[$code]['read_model']);
            $this->assertSame('reports.view', $reports[$code]['view_permission']);
            $this->assertSame('reports.export', $reports[$code]['export_permission']);
        }
    }

    public function test_workspace_has_recursive_arabic_and_english_translation_parity(): void
    {
        $en = require lang_path('en/customer_service_workspace.php');
        $ar = require lang_path('ar/customer_service_workspace.php');

        $this->assertSame($this->keysRecursive($en), $this->keysRecursive($ar));

        $customerEn = require lang_path('en/customer_360.php');
        $customerAr = require lang_path('ar/customer_360.php');
        $this->assertSame(array_keys($customerEn['service']), array_keys($customerAr['service']));
        $this->assertSame($customerEn['tabs']['service'] !== '', $customerAr['tabs']['service'] !== '');
    }

    /** @return array<string,mixed> */
    private function keysRecursive(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $result[$key] = is_array($value) ? $this->keysRecursive($value) : true;
        }

        return $result;
    }

    private function store(string $channel, string $code): int
    {
        $type = (int) DB::table('store_types')->where('code', $channel)->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function customer(int $storeId, string $channel, string $name): PlatformCustomer
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => Str::slug($name).'@example.test',
            'locale' => 'en',
            'is_active' => true,
            'is_platform_customer' => true,
        ]);

        $legacyId = (int) DB::table('customers')->insertGetId([
            'user_id' => $user->id,
            'type' => $channel,
            'name' => $name,
            'phone' => '+96550000000',
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PlatformCustomer::query()->create([
            'user_id' => $user->id,
            'legacy_customer_id' => $legacyId,
            'name' => $name,
            'phone' => '+96550000000',
            'email' => $user->email,
            'origin_channel' => $channel,
            'origin_store_id' => $storeId,
            'registration_source' => 'dashboard',
            'registered_at' => now(),
            'is_active' => true,
        ]);
    }

    private function storeUser(int $storeId, array $codes): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->create([
            'code' => 'CUSTOMER_SERVICE_TEST_'.strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Customer Service Test',
            'scope' => 'store',
            'is_system' => false,
            'is_active' => true,
        ]);

        $permissionIds = collect($codes)->map(function (string $code): int {
            return (int) Permission::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $code],
            )->id;
        })->all();

        $role->permissions()->attach($permissionIds);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
