<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Services\B2bCustomerService;
use App\Services\VanRegistryService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2VanMovementLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_van_detail_exposes_only_bounded_immutable_b2b_movements_with_translated_statuses(): void
    {
        $fixture = $this->fixture();
        for ($i = 0; $i < 53; $i++) {
            $this->recordTransition($fixture['van_id'], $fixture['order_a'], 'P265-'.$i, $i);
        }

        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $before = DB::table('order_van_execution_events')->count();

        foreach (['en', 'ar'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();
            $page = $this->actingAs($admin)->get(route('admin.field-operations.vans.show', $fixture['van_id']));
            $page->assertOk()
                ->assertSee('data-v2-van-movement-log', false)
                ->assertSee(__('field_operations.movement_log.title'))
                ->assertSee(__('field_operations.movement_log.description'))
                ->assertSee(__('order_operations.business_labels.out_for_delivery'))
                ->assertSee(__('order_operations.business_labels.delivered'))
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                ->assertDontSee('P265-0<')
                ->assertDontSee('data-v2-van-movement-row><td>FAKE', false);
            $this->assertSame(50, substr_count((string) $page->getContent(), 'data-v2-van-movement-row'));
        }

        $this->assertSame($before, DB::table('order_van_execution_events')->count());
    }

    public function test_scoped_b2b_viewer_cannot_read_foreign_store_movements_or_order_list(): void
    {
        $fixture = $this->fixture();
        $this->recordTransition($fixture['van_id'], $fixture['order_a'], 'AUTHORIZED-MOVE', 5);
        $this->recordTransition($fixture['van_id'], $fixture['order_b'], 'FOREIGN-MOVE', 4);

        $viewer = $this->scopedViewer($fixture['store_a']);
        $response = $this->actingAs($viewer)->get(route('admin.field-operations.vans.show', $fixture['van_id']));
        $response->assertOk()
            ->assertSee('data-v2-van-movement-log', false)
            ->assertSee('P265-ORDER-OWN')
            ->assertDontSee('P265-ORDER-FOREIGN')
            ->assertDontSee('FOREIGN-MOVE');
        $this->assertSame(1, substr_count((string) $response->getContent(), 'data-v2-van-movement-row'));

        $viewer->forceFill(['locale' => 'ar'])->save();
        $this->actingAs($viewer)->get(route('admin.field-operations.vans.show', $fixture['van_id']))
            ->assertOk()
            ->assertSee(__('field_operations.movement_log.title'))
            ->assertDontSee('P265-ORDER-FOREIGN');
    }

    public function test_missing_b2b_permission_is_denied_and_empty_log_has_safe_localized_state(): void
    {
        $fixture = $this->fixture();
        $unprivileged = User::factory()->create(['is_active' => true]);
        $this->actingAs($unprivileged)->get(route('admin.field-operations.vans.show', $fixture['van_id']))
            ->assertForbidden();

        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $this->actingAs($admin)->get(route('admin.field-operations.vans.show', $fixture['van_id']))
            ->assertOk()
            ->assertSee(__('field_operations.movement_log.empty'))
            ->assertDontSee('data-v2-van-movement-row');
    }

    /** @return array{store_a:int,store_b:int,van_id:int,order_a:int,order_b:int} */
    private function fixture(): array
    {
        $storeA = app(WholesalePrincipal::class)->storeId();
        $storeType = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $storeB = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeType,
            'code' => 'P265-OTHER-STORE',
            'name' => 'Other Wholesale Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customer = app(B2bCustomerService::class)->create(['name' => 'P265 Buyer']);
        $van = app(VanRegistryService::class)->createVan(['code' => 'P265-VAN']);
        $createOrder = function (int $storeId, string $reference) use ($customer): int {
            return (int) DB::table('orders')->insertGetId([
                'store_id' => $storeId,
                'customer_id' => (int) $customer->legacy_customer_id,
                'b2b_customer_id' => (int) $customer->getKey(),
                'order_number' => $reference,
                'channel' => 'b2b',
                'status' => 'out_for_delivery',
                'currency' => 'KWD',
                'subtotal' => 12,
                'discount_total' => 0,
                'delivery_total' => 0,
                'tax_total' => 0,
                'grand_total' => 12,
                'payment_method' => 'cash_on_delivery',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        return [
            'store_a' => (int) $storeA,
            'store_b' => $storeB,
            'van_id' => (int) $van->id,
            'order_a' => $createOrder((int) $storeA, 'P265-ORDER-OWN'),
            'order_b' => $createOrder($storeB, 'P265-ORDER-FOREIGN'),
        ];
    }

    private function recordTransition(int $vanId, int $orderId, string $key, int $minute): void
    {
        $assignment = (int) DB::table('order_van_assignments')->insertGetId([
            'order_id' => $orderId,
            'van_id' => $vanId,
            'status' => 'active',
            'source' => 'manual',
            'decision_key' => hash('sha256', 'P265-'.$key),
            'assigned_at' => now()->subMinutes($minute + 1),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $state = (int) DB::table('order_van_execution_states')->insertGetId([
            'order_van_assignment_id' => $assignment,
            'order_id' => $orderId,
            'van_id' => $vanId,
            'status' => 'delivered',
            'version' => 2,
            'last_transition_at' => now()->subMinutes($minute),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_van_execution_events')->insert([
            'order_van_assignment_id' => $assignment,
            'order_van_execution_state_id' => $state,
            'order_id' => $orderId,
            'van_id' => $vanId,
            'action' => 'transition',
            'idempotency_key' => $key,
            'from_status' => 'out_for_delivery',
            'to_status' => 'delivered',
            'captured_at' => now()->subMinutes($minute),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function scopedViewer(int $storeId): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $global = Role::query()->create([
            'code' => 'V2_P265_B2B_GLOBAL_VIEW',
            'name' => 'V2 P265 B2B Operational Viewer',
            'scope' => 'global',
            'is_active' => true,
        ]);
        $global->permissions()->attach(
            Permission::query()->where('code', 'drivers.b2b.view')->firstOrFail()->id,
        );
        $user->roles()->attach($global);
        $storeRole = Role::query()->create([
            'code' => 'V2_P265_B2B_STORE_VIEW',
            'name' => 'V2 P265 Assigned Wholesale Store',
            'scope' => 'store',
            'is_active' => true,
        ]);
        UserStoreRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $storeRole->id,
            'store_id' => $storeId,
        ]);

        return $user;
    }
}
