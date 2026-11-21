<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleRegistry;
use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/**
 * P258: ordinary WMS warehouse transfers must never silently accept a
 * van-loading target and misrepresent warehouse stock as loaded van stock.
 */
final class V2VanLoadingAuthorizationContractTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Signed synthetic workflow proof is scoped to test fixtures, not ERP runtime certification.
        SyntheticErpWorkflowEvidence::install();
        // The adversarial matrix exercises many repeated HTTP denials; keep
        // authentication and RBAC active, but isolate unrelated rate limiting.
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(CoreReferenceSeeder::class);

        // Test-only readiness grant for the actual module version.
        config()->set(
            'enterprise_module_readiness.certified_versions.inventory_wms',
            app(ModuleRegistry::class)->manifest('inventory_wms')->version,
        );
    }

    public function test_warehouse_transfer_actions_reject_a_van_target_without_creating_stock_or_audit_events(): void
    {
        $storeId = (int) app(WholesalePrincipal::class)->storeId();
        $this->enableEnterpriseWmsForStore($storeId);

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $payload = [
            'store_id' => $storeId,
            'source_warehouse_id' => 1,
            'target_warehouse_id' => 2,
            'product_id' => 1,
            'quantity' => 2,
            'request_key' => 'P258-NOT-A-VAN-LOAD',
        ];

        // These endpoints authorize warehouse transfers, not Van loading.
        // Reject explicit IDs, alternate target/source identifiers, Van trip
        // metadata and nested aliases before any warehouse mutation or audit.
        $forgedVanTargets = [
            ['van_id' => 987654],
            ['van_id' => null],
            ['van_id' => 0],
            ['van_id' => 'forged-van'],
            ['source_van_id' => 987654],
            ['target_van_id' => 987654],
            ['destination_van_id' => 987654],
            ['van_assignment_id' => 987654],
            ['van_trip_id' => 987654],
            ['van_load_id' => 987654],
            ['source_type' => 'van'],
            ['target_type' => 'van'],
            ['destination_type' => 'van'],
            ['metadata' => ['van_id' => 987654]],
            ['metadata' => ['target_van_id' => null]],
            // Alias spellings must not escape Laravel's exact-key "missing"
            // validator and be reported as an accepted warehouse transfer.
            ['vanId' => 987654],
            ['vanID' => 987654],
            ['Van-ID' => 987654],
            ['VAN_ID' => 987654],
            ['sourceVanId' => 987654],
            ['targetVanId' => 987654],
            ['destinationVanId' => 987654],
            ['vanAssignmentId' => 987654],
            ['vanTripId' => 987654],
            ['vanLoadId' => 987654],
            ['sourceType' => 'van'],
            ['targetType' => 'van'],
            ['destinationType' => 'van'],
            ['metaData' => ['vanId' => 987654]],
            ['meta-data' => ['targetVanId' => 987654]],
            // Unknown wrappers must also fail closed: ignoring these would
            // report an ordinary warehouse transfer as an accepted Van load.
            ['payload' => ['van_id' => 987654]],
            ['cargo' => ['van_id' => 987654]],
            ['source' => ['type' => 'van']],
            ['destination' => ['type' => 'van']],
            ['van_stock' => 2],
            ['warehouse_to_van' => 1],
            ['target' => 'van'],
            ['unrecognized_field' => 'reject, never silently ignore'],
        ];
        foreach (['en', 'ar'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            foreach ($forgedVanTargets as $vanPayload) {
                $rejectedField = array_key_first($vanPayload);
                $this->actingAs($actor->fresh())
                    ->post(route('admin.erp.wms.transfers.store'), [
                        ...$payload,
                        ...$vanPayload,
                    ])
                    ->assertRedirect()
                    ->assertSessionHasErrors([$rejectedField]);

                foreach (['dispatch', 'receive'] as $transition) {
                    $this->actingAs($actor->fresh())
                        ->post(route('admin.erp.wms.transfers.'.$transition, 999999), [
                            'store_id' => $storeId,
                            ...$vanPayload,
                        ])
                        ->assertRedirect()
                        ->assertSessionHasErrors([$rejectedField]);
                }
            }
        }

        $this->assertSame(0, DB::table('enterprise_warehouse_transfers')->count());
        $this->assertSame(0, DB::table('enterprise_warehouse_transfer_lines')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'like', 'enterprise.warehouse_transfer.%')->count());
    }
}
