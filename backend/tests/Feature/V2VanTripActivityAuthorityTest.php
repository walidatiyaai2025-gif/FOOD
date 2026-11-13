<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\VanAssignment;
use App\Models\VanVisit;
use App\Services\VanRegistryService;
use App\Services\WholesalePrincipal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2VanTripActivityAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_bilingual_van_trip_activity_is_discoverable_from_the_canonical_visits_page(): void
    {
        $admin = $this->superAdmin();

        foreach (['en', 'ar'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin->fresh())
                ->get(route('admin.field-operations.visits', ['trip_view' => 'trips']))
                ->assertOk()
                ->assertSee('data-v2-van-activity-tabs', false)
                ->assertSee('data-v2-van-trip-activity', false)
                ->assertSee('data-v2-van-trip-filters', false)
                ->assertSee(__('field_operations.trip_activity.tab', [], $locale))
                ->assertSee(__('field_operations.trip_activity.evidence_notice', [], $locale));
        }
    }

    public function test_trip_activity_is_read_only_and_excludes_retail_visits_even_for_super_admin(): void
    {
        $admin = $this->superAdmin();

        $this->recordVisit($admin, 'b2b', 'V2-P276-WHOLESALE');
        $this->recordVisit($admin, 'b2c', 'V2-P276-RETAIL');

        $this->actingAs($admin)
            ->get(route('admin.field-operations.visits', ['trip_view' => 'trips']))
            ->assertOk()
            ->assertSee('data-trip-route="V2-P276-WHOLESALE"', false)
            ->assertDontSee('data-trip-route="V2-P276-RETAIL"', false)
            ->assertDontSee('name="trip_id"', false)
            ->assertDontSee('name="settlement_amount"', false);

        $this->assertDatabaseCount('van_visits', 2);
    }

    public function test_exact_route_and_status_filters_do_not_reveal_other_van_activity(): void
    {
        $admin = $this->superAdmin();
        $this->recordVisit($admin, 'b2b', 'V2-P276-SELECTED', 'started');
        $this->recordVisit($admin, 'b2b', 'V2-P276-OTHER', 'planned');

        $this->actingAs($admin)
            ->get(route('admin.field-operations.visits', [
                'trip_view' => 'trips',
                'trip_route' => 'V2-P276-SELECTED',
                'trip_status' => 'started',
            ]))
            ->assertOk()
            ->assertSee('data-trip-route="V2-P276-SELECTED"', false)
            ->assertDontSee('data-trip-route="V2-P276-OTHER"', false);
    }

    public function test_forged_metadata_van_id_without_authorized_warehouse_assignment_never_exposes_van_code(): void
    {
        $admin = $this->superAdmin();
        $unassignedVan = app(VanRegistryService::class)
            ->createVan(['code' => 'V2-P276-UNASSIGNED-VAN-SECRET']);

        VanVisit::query()->create([
            'actor_user_id' => $admin->id,
            'customer_type' => 'b2b',
            'customer_id' => 10001,
            'store_id' => null,
            'status' => 'planned',
            'planned_at' => now(),
            'metadata' => [
                'route_key' => 'V2-P276-VISIBLE-ROUTE',
                'van_id' => $unassignedVan->id,
            ],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.field-operations.visits', ['trip_view' => 'trips']))
            ->assertOk()
            ->assertSee('data-trip-route="V2-P276-VISIBLE-ROUTE"', false)
            ->assertDontSee('V2-P276-UNASSIGNED-VAN-SECRET');

        $this->assertDatabaseCount('van_visits', 1);
    }

    public function test_van_label_requires_current_active_assignment_van_and_wholesale_warehouse(): void
    {
        $admin = $this->superAdmin();
        $warehouseId = app(WholesalePrincipal::class)->warehouseId();
        $van = app(VanRegistryService::class)->createVan([
            'code' => 'V2-P276-AUTHORIZED-ONLY-VAN',
        ]);
        $assignment = VanAssignment::query()->create([
            'public_id' => (string) Str::uuid(),
            'van_id' => $van->id,
            'warehouse_id' => $warehouseId,
            'assignment_type' => 'primary',
            'status' => 'active',
            'effective_from' => now()->subHour(),
            'effective_until' => now()->addHour(),
            'created_by' => $admin->id,
        ]);

        VanVisit::query()->create([
            'actor_user_id' => $admin->id,
            'customer_type' => 'b2b',
            'customer_id' => 10001,
            'store_id' => null,
            'status' => 'planned',
            'planned_at' => now(),
            'metadata' => [
                'route_key' => 'V2-P276-AUTHORIZED-ROUTE',
                'van_id' => $van->id,
            ],
        ]);

        $url = route('admin.field-operations.visits', ['trip_view' => 'trips']);
        $this->actingAs($admin)->get($url)
            ->assertOk()
            ->assertSee('data-trip-route="V2-P276-AUTHORIZED-ROUTE"', false)
            ->assertSee('V2-P276-AUTHORIZED-ONLY-VAN');

        $assignment->forceFill(['effective_until' => now()->subMinute()])->save();
        $this->get($url)->assertOk()->assertDontSee('V2-P276-AUTHORIZED-ONLY-VAN');

        $assignment->forceFill(['effective_until' => now()->addHour(), 'status' => 'inactive'])->save();
        $this->get($url)->assertOk()->assertDontSee('V2-P276-AUTHORIZED-ONLY-VAN');

        $assignment->forceFill(['status' => 'active'])->save();
        $van->forceFill(['status' => 'inactive'])->save();
        $this->get($url)->assertOk()->assertDontSee('V2-P276-AUTHORIZED-ONLY-VAN');

        $van->forceFill(['status' => 'active'])->save();
        DB::table('warehouses')->where('id', $warehouseId)->update(['is_active' => false]);
        $this->get($url)->assertOk()->assertDontSee('V2-P276-AUTHORIZED-ONLY-VAN');

        $this->assertDatabaseCount('van_visits', 1);
    }

    public function test_user_without_b2b_fleet_authority_cannot_open_trip_activity(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail());

        $this->actingAs($user)
            ->get(route('admin.field-operations.visits', ['trip_view' => 'trips']))
            ->assertForbidden();
    }

    private function superAdmin(): User
    {
        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $admin;
    }

    private function recordVisit(User $actor, string $customerType, string $route, string $status = 'planned'): void
    {
        VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => $customerType,
            'customer_id' => 10001,
            'store_id' => null,
            'status' => $status,
            'planned_at' => now(),
            'metadata' => ['route_key' => $route],
        ]);
    }
}
