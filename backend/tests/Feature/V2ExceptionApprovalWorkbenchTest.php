<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V2 #2560 / P230: commercial exception approval uses the canonical
 * independently authorized Order Customer Service discount request workflow.
 */
final class V2ExceptionApprovalWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_scoped_pending_inbox_opens_real_independent_review_in_arabic_and_english(): void
    {
        $agent = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $reviewer = $this->roleUser('CUSTOMER_SERVICE_MANAGER');
        $superAdmin = $this->roleUser('SUPER_ADMIN');
        $orderId = $this->makeOrder();
        $unrelatedOrderId = $this->makeOrder();
        $requestId = $this->submitRequest($agent, $orderId);
        $storeId = app(WholesalePrincipal::class)->storeId();

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 9]);
        $this->assertSame(0, DB::table('order_customer_service_discounts')->where('order_id', $orderId)->count());

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $superAdmin->forceFill(['locale' => $locale])->save();
            $reviewer->forceFill(['locale' => $locale])->save();

            $this->actingAs($superAdmin->fresh())
                ->get(route('admin.commercial.sales-control', ['store_id' => $storeId]))
                ->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-v2-exception-approvals-link', false)
                ->assertSee('discount_view=pending', false)
                ->assertSee(__('customer_service_discount.pending_review'));

            $this->actingAs($reviewer->fresh())->get($this->inboxUrl())
                ->assertOk()
                ->assertSee('data-v2-pending-discounts-workbench', false)
                ->assertSee('data-v2-pending-discounts-tab', false)
                ->assertSee('data-order-row="'.$orderId.'"', false)
                ->assertDontSee('data-order-row="'.$unrelatedOrderId.'"', false)
                ->assertSee(__('customer_service_discount.pending_description'));

            $this->actingAs($reviewer->fresh())
                ->get(route('admin.operations.orders.index', [
                    'channel' => 'b2b', 'store_id' => $storeId, 'order' => $orderId,
                    'discount_view' => 'pending', 'action_required' => 0,
                ]))
                ->assertOk()
                ->assertSee('data-order-customer-service-review', false)
                ->assertSee('data-discount-request-id="'.$requestId.'"', false);
        }

        $this->actingAs($reviewer->fresh())
            ->post(route('admin.operations.orders.customer-service-discount.review', [
                'order' => $orderId, 'discountRequest' => $requestId,
            ]), ['decision' => 'approve'])
            ->assertRedirect();

        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'id' => $requestId, 'status' => 'approved',
            'reviewed_by_user_id' => $reviewer->getKey(),
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 7.875]);
        $this->assertSame(1, DB::table('order_customer_service_discounts')->where('order_id', $orderId)->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'order.customer_service_discount.approved',
            'user_id' => $reviewer->getKey(),
        ]);

        $this->actingAs($reviewer->fresh())->get($this->inboxUrl())
            ->assertOk()
            ->assertDontSee('data-order-row="'.$orderId.'"', false);
        $this->actingAs($reviewer->fresh())->get(route('admin.operations.orders.index', [
            'channel' => 'b2b', 'discount_view' => 'approved', 'action_required' => 0,
        ]))->assertOk()->assertSee('data-v2-approved-discount-id="'.$requestId.'"', false);
    }

    public function test_catalog_visibility_cannot_grant_exception_review_or_foreign_store_and_channel_access(): void
    {
        $agent = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $reviewer = $this->roleUser('CUSTOMER_SERVICE_MANAGER');
        $orderId = $this->makeOrder();
        $requestId = $this->submitRequest($agent, $orderId);
        $storeId = app(WholesalePrincipal::class)->storeId();

        $this->actingAs($agent)->get($this->inboxUrl())->assertForbidden();
        $this->actingAs($reviewer)->get(route('admin.operations.orders.index', [
            'channel' => 'b2c', 'discount_view' => 'pending',
        ]))->assertForbidden();
        $this->actingAs($reviewer)->get(route('admin.operations.orders.index', [
            'channel' => 'b2b', 'store_id' => 999999, 'discount_view' => 'pending',
        ]))->assertNotFound();

        $catalogActor = $this->roleUser('B2B_ADMIN');
        DB::table('user_permission_overrides')->insert([
            'user_id' => $catalogActor->getKey(),
            'permission_id' => DB::table('permissions')->where('code', 'orders.approve')->value('id'),
            'scope_key' => 'global', 'store_id' => null, 'effect' => 'deny',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $catalogActor->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($catalogActor->fresh())
            ->get(route('admin.commercial.sales-control', ['store_id' => $storeId]))
            ->assertOk()
            ->assertDontSee('data-v2-exception-approvals-link', false);
        $this->actingAs($catalogActor->fresh())->get($this->inboxUrl())->assertForbidden();

        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'id' => $requestId, 'status' => 'pending',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 9]);
    }

    public function test_rejected_exception_exits_inbox_without_price_mutation_or_replay(): void
    {
        $agent = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $reviewer = $this->roleUser('CUSTOMER_SERVICE_MANAGER');
        $orderId = $this->makeOrder();
        $requestId = $this->submitRequest($agent, $orderId);
        $url = route('admin.operations.orders.customer-service-discount.review', [
            'order' => $orderId, 'discountRequest' => $requestId,
        ]);

        $this->actingAs($reviewer)->get($this->inboxUrl())->assertOk()
            ->assertSee('data-order-row="'.$orderId.'"', false);
        $this->actingAs($reviewer)->post($url, [
            'decision' => 'reject', 'review_reason' => 'Pricing exception is unsupported',
        ])->assertRedirect();
        $this->actingAs($reviewer)->get($this->inboxUrl())->assertOk()
            ->assertDontSee('data-order-row="'.$orderId.'"', false);
        $this->actingAs($reviewer)->post($url, ['decision' => 'approve'])->assertStatus(409);

        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'id' => $requestId, 'status' => 'rejected',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 9]);
        $this->assertSame(0, DB::table('order_customer_service_discounts')->where('order_id', $orderId)->count());
    }

    private function inboxUrl(): string
    {
        return route('admin.operations.orders.index', [
            'channel' => 'b2b', 'store_id' => app(WholesalePrincipal::class)->storeId(),
            'discount_view' => 'pending', 'action_required' => 0,
        ]);
    }

    private function roleUser(string $code): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $actor->fresh();
    }

    private function makeOrder(): int
    {
        $customer = app(B2bCustomerService::class)->create(['name' => 'P230 Review Customer']);

        return (int) DB::table('orders')->insertGetId([
            'store_id' => app(WholesalePrincipal::class)->storeId(),
            'customer_id' => (int) $customer->legacy_customer_id,
            'b2b_customer_id' => (int) $customer->getKey(),
            'order_number' => 'P230-'.Str::upper(Str::random(8)),
            'channel' => 'b2b', 'status' => 'pending',
            'currency' => 'KWD', 'subtotal' => 10,
            'discount_total' => 1, 'delivery_total' => 0,
            'tax_total' => 0, 'grand_total' => 9,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function submitRequest(User $agent, int $orderId): int
    {
        DB::table('order_customer_service_assignments')->insert([
            'order_id' => $orderId, 'assigned_user_id' => $agent->getKey(),
            'assigned_at' => now(), 'unassigned_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($agent)->post(
            route('admin.operations.orders.customer-service-discount', ['order' => $orderId]),
            [
                'percentage' => 12.5,
                'reason' => 'P230 independently reviewed pricing concession',
                'idempotency_key' => (string) Str::uuid(),
            ],
        )->assertRedirect();

        return (int) DB::table('order_customer_service_discount_requests')
            ->where('order_id', $orderId)->value('id');
    }
}
