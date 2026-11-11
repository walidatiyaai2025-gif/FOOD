<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DeliveryProof;
use App\Models\Driver;
use App\Models\DriverAssignment;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\VanRegistryService;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * V2 #2546 / P216: proof-of-delivery exists as an exact-order,
 * store/channel-authorized context action, never an unscoped Sidebar route.
 */
final class V2DeliveryProofSidebarHttpContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        Storage::fake('public');
    }

    public function test_retail_driver_proof_is_reachable_from_authorized_order_in_both_locales(): void
    {
        [$store, $order] = $this->retailOrder('P216-DRIVER');
        $actor = $this->retailAdmin($store, 'p216-driver-admin@example.test');
        [$assignment, $proof] = $this->driverProof($store, $order, 'p216-driver.jpg', true);
        $url = route('admin.driver-live-tracking.proofs.show', [
            'assignment' => $assignment->id,
            'proof' => $proof->id,
        ]);

        $this->assertTrue(Route::has('admin.operations.orders.index'));
        $this->assertTrue(Route::has('admin.driver-live-tracking.proofs.show'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $group = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->firstWhere('key', 'operations');
            $this->assertIsArray($group);
            $nodes = collect($group['children'])->where('key', 'order_operations')->values();
            $this->assertCount(1, $nodes);
            $this->assertSame('admin.operations.orders.index', $nodes->first()['route']);

            $this->actingAs($actor->fresh())
                ->get(route('admin.operations.orders.index', ['channel' => 'b2c', 'order' => $order->id]))
                ->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-order-detail-action-section="driver-proofs"', false)
                ->assertSee('data-delivery-proof-link', false)
                ->assertSee('href="'.$url.'"', false)
                ->assertDontSee('delivery-proofs/p216-driver.jpg');

            $response = $this->actingAs($actor->fresh())->get($url)->assertOk();
            $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        }
    }

    public function test_retail_foreign_store_and_forged_assignment_proof_pairs_fail_closed(): void
    {
        [$ownerStore, $ownerOrder] = $this->retailOrder('P216-OWNER');
        [$foreignStore, $foreignOrder] = $this->retailOrder('P216-FOREIGN');
        $actor = $this->retailAdmin($foreignStore, 'p216-foreign-admin@example.test');
        [$assignment, $proof] = $this->driverProof($ownerStore, $ownerOrder, 'p216-owner.jpg', true);
        [$otherAssignment, $otherProof] = $this->driverProof($foreignStore, $foreignOrder, 'p216-foreign.jpg', true);

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index', ['channel' => 'b2c', 'order' => $ownerOrder->id]))
            ->assertNotFound();

        $this->actingAs($actor)
            ->get(route('admin.driver-live-tracking.proofs.show', [
                'assignment' => $assignment->id,
                'proof' => $proof->id,
            ]))
            ->assertNotFound();

        // Even an otherwise authorized assignment cannot pair with another record's proof.
        $this->actingAs($actor)
            ->get(route('admin.driver-live-tracking.proofs.show', [
                'assignment' => $otherAssignment->id,
                'proof' => $proof->id,
            ]))
            ->assertNotFound();

        $this->actingAs($actor)
            ->get(route('admin.driver-live-tracking.proofs.show', [
                'assignment' => $otherAssignment->id,
                'proof' => $otherProof->id,
            ]))
            ->assertOk();
    }

    public function test_missing_driver_file_is_not_advertised_and_download_returns_not_found(): void
    {
        [$store, $order] = $this->retailOrder('P216-MISSING-DRIVER');
        $actor = $this->retailAdmin($store, 'p216-missing-driver@example.test');
        [$assignment, $proof] = $this->driverProof($store, $order, 'p216-driver-missing.jpg', false);

        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index', ['channel' => 'b2c', 'order' => $order->id]))
            ->assertOk()
            ->assertDontSee('data-order-detail-action-section="driver-proofs"', false)
            ->assertDontSee('data-delivery-proof-link', false);

        $this->actingAs($actor)
            ->get(route('admin.driver-live-tracking.proofs.show', [
                'assignment' => $assignment->id,
                'proof' => $proof->id,
            ]))
            ->assertNotFound();
    }

    public function test_wholesale_van_proof_is_reachable_and_missing_van_files_have_no_dead_links(): void
    {
        [$order, $assignment, $existing, $missing] = $this->vanProofFixture();
        $admin = $this->superAdmin();
        $existingUrl = route('admin.operations.orders.van-proofs.show', [
            'assignment' => $assignment,
            'proof' => $existing,
        ]);
        $missingUrl = route('admin.operations.orders.van-proofs.show', [
            'assignment' => $assignment,
            'proof' => $missing,
        ]);

        $this->assertTrue(Route::has('admin.operations.orders.van-proofs.show'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin->fresh())
                ->get(route('admin.operations.orders.index', [
                    'channel' => 'b2b',
                    'order' => $order,
                ]))
                ->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-order-detail-action-section="van-proofs"', false)
                ->assertSee('data-van-delivery-proof-link', false)
                ->assertSee('data-van-proof-link', false)
                ->assertSee('href="'.$existingUrl.'"', false)
                ->assertDontSee('href="'.$missingUrl.'"', false)
                ->assertDontSee('delivery-proofs/van/p216-van-existing.jpg');

            $response = $this->actingAs($admin->fresh())->get($existingUrl)->assertOk();
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

            $this->actingAs($admin->fresh())->get($missingUrl)->assertNotFound();
        }
    }

    public function test_van_proof_denies_cross_channel_and_mismatched_assignment(): void
    {
        [$order, $assignment, $proof] = $this->vanProofFixture();
        [$retailStore] = $this->retailOrder('P216-CROSS-CHANNEL');
        $retailActor = $this->retailAdmin($retailStore, 'p216-retail-against-van@example.test');
        $url = route('admin.operations.orders.van-proofs.show', [
            'assignment' => $assignment,
            'proof' => $proof,
        ]);

        $this->actingAs($retailActor)->get($url)->assertNotFound();
        $this->actingAs($retailActor)
            ->get(route('admin.operations.orders.index', ['channel' => 'b2b', 'order' => $order]))
            ->assertForbidden();

        $admin = $this->superAdmin();
        $this->actingAs($admin)
            ->get(route('admin.operations.orders.van-proofs.show', [
                'assignment' => $assignment + 900000,
                'proof' => $proof,
            ]))
            ->assertNotFound();
    }

    public function test_explicit_deny_roleless_and_inactive_actor_cannot_open_order_proofs(): void
    {
        [$store, $order] = $this->retailOrder('P216-DENIED');
        [$assignment, $proof] = $this->driverProof($store, $order, 'p216-denied.jpg', true);
        $admin = $this->retailAdmin($store, 'p216-denied-admin@example.test');
        $url = route('admin.driver-live-tracking.proofs.show', [
            'assignment' => $assignment->id,
            'proof' => $proof->id,
        ]);

        // Driver evidence read authority accepts either independent orders.view
        // or drivers.tracking.view. Denying BOTH makes this actor unauthorized.
        $this->deny($admin, 'orders.view');
        $this->deny($admin, 'drivers.tracking.view');

        $links = collect(app(AdminNavigation::class)->groupsFor($admin->fresh()))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')
            ->all();
        $this->assertNotContains('order_operations', $links);

        $this->actingAs($admin->fresh())
            ->get(route('admin.operations.orders.index', ['channel' => 'b2c', 'order' => $order->id]))
            ->assertForbidden();
        $this->actingAs($admin->fresh())->get($url)->assertNotFound();

        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)->get($url)->assertForbidden();

        $admin->forceFill(['is_active' => false])->save();
        $denied = $this->actingAs($admin->fresh())->get($url);
        $this->assertContains($denied->getStatusCode(), [403, 404]);
    }

    /** @return array{0:int,1:Order} */
    private function retailOrder(string $code): array
    {
        $store = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = Customer::query()->create([
            'name' => 'P216 Buyer '.$code,
            'type' => 'b2c',
        ]);

        $order = Order::query()->create([
            'store_id' => $store,
            'customer_id' => $customer->id,
            'order_number' => $code.'-ORDER',
            'channel' => 'b2c',
            'status' => 'delivered',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 10,
        ]);

        return [$store, $order];
    }

    private function retailAdmin(int $store, string $email): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en', 'email' => $email]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'store_id' => $store,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor->fresh();
    }

    /** @return array{0:DriverAssignment,1:DeliveryProof} */
    private function driverProof(int $store, Order $order, string $fileName, bool $fileExists): array
    {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'B2C_DRIVER')->firstOrFail());

        $driver = Driver::query()->create([
            'user_id' => $actor->id,
            'store_id' => $store,
            'driver_type' => 'b2c',
            'is_active' => true,
            'is_available' => true,
        ]);

        $assignment = DriverAssignment::query()->create([
            'driver_id' => $driver->id,
            'order_id' => $order->id,
            'store_id' => $store,
            'assignment_type' => 'b2c',
            'status' => 'delivered',
            'assigned_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);

        $path = 'delivery-proofs/'.$fileName;
        if ($fileExists) {
            Storage::disk('public')->put($path, 'test-proof-'.$order->id);
        }

        $proof = DeliveryProof::query()->create([
            'driver_assignment_id' => $assignment->id,
            'order_id' => $order->id,
            'user_id' => $actor->id,
            'proof_type' => 'delivery_image',
            'from_status' => 'out_for_delivery',
            'to_status' => 'delivered',
            'file_path' => $path,
            'captured_at' => now(),
        ]);

        return [$assignment, $proof];
    }

    /** @return array{0:int,1:int,2:int,3:int} */
    private function vanProofFixture(): array
    {
        $store = app(WholesalePrincipal::class)->storeId();
        $customer = app(B2bCustomerService::class)->create(['name' => 'P216 Van Buyer']);
        $order = (int) DB::table('orders')->insertGetId([
            'store_id' => $store,
            'customer_id' => (int) $customer->legacy_customer_id,
            'b2b_customer_id' => (int) $customer->id,
            'order_number' => 'P216-VAN-'.uniqid(),
            'channel' => 'b2b',
            'status' => 'delivered',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'tax_total' => 0,
            'grand_total' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $operator = User::factory()->create(['is_active' => true]);
        $operator->roles()->attach(Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail());
        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'P216-VAN-'.uniqid()]);
        $registry->assign($operator, $van, [
            'representative_user_id' => $operator->id,
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);
        $runtimeAssignment = DB::table('van_assignments')
            ->where('representative_user_id', $operator->id)
            ->where('van_id', $van->id)
            ->where('status', 'active')
            ->first(['id']);
        $this->assertNotNull($runtimeAssignment);

        $assignment = (int) DB::table('order_van_assignments')->insertGetId([
            'order_id' => $order,
            'van_id' => $van->id,
            'van_assignment_id' => $runtimeAssignment->id,
            'status' => 'active',
            'source' => 'smart_routing',
            'reason' => 'p216-proofs',
            'decision_key' => hash('sha256', 'p216-'.$order),
            'assigned_by' => $operator->id,
            'assigned_at' => now()->subMinutes(15),
            'created_at' => now()->subMinutes(15),
            'updated_at' => now(),
        ]);

        $state = (int) DB::table('order_van_execution_states')->insertGetId([
            'order_van_assignment_id' => $assignment,
            'order_id' => $order,
            'van_id' => $van->id,
            'status' => 'delivered',
            'version' => 3,
            'last_transition_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(15),
            'updated_at' => now(),
        ]);

        Storage::disk('public')->put('delivery-proofs/van/p216-van-existing.jpg', 'p216-van-proof');

        $makeEvent = function (string $name, string $path) use ($assignment, $state, $order, $van, $operator): int {
            return (int) DB::table('order_van_execution_events')->insertGetId([
                'order_van_assignment_id' => $assignment,
                'order_van_execution_state_id' => $state,
                'order_id' => $order,
                'van_id' => $van->id,
                'user_id' => $operator->id,
                'action' => 'transition',
                'idempotency_key' => $name,
                'request_fingerprint' => hash('sha256', $name),
                'from_status' => 'out_for_delivery',
                'to_status' => 'delivered',
                'proof_type' => 'delivery_image',
                'proof_path' => $path,
                'captured_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        $existing = $makeEvent('p216-existing', 'delivery-proofs/van/p216-van-existing.jpg');
        $missing = $makeEvent('p216-missing', 'delivery-proofs/van/p216-van-missing.jpg');

        return [$order, $assignment, $existing, $missing];
    }

    private function superAdmin(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor;
    }

    private function deny(User $actor, string $permission): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', $permission)->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
    }
}
