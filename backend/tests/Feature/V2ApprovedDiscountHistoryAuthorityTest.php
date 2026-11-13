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

final class V2ApprovedDiscountHistoryAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_approved_history_is_visible_on_exact_order_after_fulfillment_changes_in_arabic_and_english(): void
    {
        [$agent, $reviewer, $orderId, $requestId] = $this->approvedDiscount();

        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'id' => $requestId,
            'status' => 'approved',
            'reviewed_by_user_id' => $reviewer->getKey(),
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'order.customer_service_discount.approved',
            'user_id' => $reviewer->getKey(),
        ]);

        // Approval history remains visible after the pending-stage form closes.
        DB::table('orders')->where('id', $orderId)->update(['status' => 'confirmed']);

        // A requester never inherits review authority or approved-history
        // access from being assigned to this same order.
        $this->actingAs($agent)
            ->get(route('admin.operations.orders.index', ['order' => $orderId, 'channel' => 'b2b']))
            ->assertDontSee('data-order-approved-discount-history', false);

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $reviewer->forceFill(['locale' => $locale])->save();

            $this->actingAs($reviewer)
                ->get(route('admin.operations.orders.index', ['order' => $orderId, 'channel' => 'b2b']))
                ->assertOk()
                ->assertSee('data-order-approved-discount-history', false)
                ->assertSee('data-approved-discount-request-id="'.$requestId.'"', false)
                ->assertSee(__('customer_service_discount.approved_history'))
                ->assertSee(__('customer_service_discount.approved_by'));
        }

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 7.875]);
        $this->assertSame(1, DB::table('order_customer_service_discounts')->where('order_id', $orderId)->count());
    }

    public function test_approved_history_never_leaks_to_another_order_in_same_store(): void
    {
        [, $reviewer, $orderId, $requestId] = $this->approvedDiscount();
        $otherOrder = $this->makeOrder();

        $this->actingAs($reviewer)
            ->get(route('admin.operations.orders.index', ['order' => $otherOrder, 'channel' => 'b2b']))
            ->assertOk()
            ->assertDontSee('data-approved-discount-request-id="'.$requestId.'"', false)
            ->assertDontSee('data-order-approved-discount-history', false);

        $this->actingAs($reviewer)
            ->get(route('admin.operations.orders.index', ['order' => $orderId, 'channel' => 'b2b']))
            ->assertOk()
            ->assertSee('data-approved-discount-request-id="'.$requestId.'"', false);
    }

    public function test_authorized_reviewer_can_browse_approved_discounts_in_both_locales_without_mutating_totals(): void
    {
        [$agent, $reviewer, $orderId, $requestId] = $this->approvedDiscount();
        $url = route('admin.operations.orders.index', [
            'channel' => 'b2b',
            'discount_view' => 'approved',
            'action_required' => 0,
        ]);

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $reviewer->forceFill(['locale' => $locale])->save();
            $this->actingAs($reviewer)->get($url)
                ->assertOk()
                ->assertSee('data-v2-approved-discounts-workbench', false)
                ->assertSee('data-v2-approved-discount-id="'.$requestId.'"', false)
                ->assertSee('data-v2-approved-discount-order-link', false)
                ->assertSee(__('customer_service_discount.approved_history'));
        }

        $this->actingAs($agent)->get($url)->assertForbidden();
        $this->actingAs($reviewer)->get(route('admin.operations.orders.index', [
            'channel' => 'b2b',
            'discount_view' => 'approved',
            'store_id' => 999999,
        ]))->assertNotFound();

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 7.875]);
        $this->assertSame(1, DB::table('order_customer_service_discounts')->where('order_id', $orderId)->count());
    }

    public function test_approved_discounts_workbench_excludes_unapproved_and_foreign_channel_orders(): void
    {
        [, $reviewer, $orderId, $requestId] = $this->approvedDiscount();
        $unapprovedOrderId = $this->makeOrder();
        $url = route('admin.operations.orders.index', [
            'channel' => 'b2b',
            'discount_view' => 'approved',
            'action_required' => 0,
        ]);

        $this->actingAs($reviewer)->get($url)
            ->assertOk()
            ->assertSee('data-v2-approved-discount-id="'.$requestId.'"', false)
            ->assertDontSee('data-v2-approved-discount-id="'.$unapprovedOrderId.'"', false);

        $this->actingAs($reviewer)->get(route('admin.operations.orders.index', [
            'channel' => 'b2c',
            'discount_view' => 'approved',
        ]))->assertForbidden();

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 7.875]);
    }

    public function test_sales_control_exposes_authorized_approved_discount_navigation(): void
    {
        $superAdmin = $this->roleUser('SUPER_ADMIN');
        $storeId = app(WholesalePrincipal::class)->storeId();
        $this->actingAs($superAdmin)->get(route('admin.commercial.sales-control', [
            'store_id' => $storeId,
        ]))->assertOk()
            ->assertSee('data-v2-approved-discounts-sales-link', false)
            ->assertSee('discount_view=approved', false);

        $agent = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $this->actingAs($agent)->get(route('admin.operations.orders.index', [
            'channel' => 'b2b',
            'discount_view' => 'approved',
        ]))->assertForbidden();
    }

    private function approvedDiscount(): array
    {
        $agent = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $reviewer = $this->roleUser('CUSTOMER_SERVICE_MANAGER');
        $orderId = $this->makeOrder();

        DB::table('order_customer_service_assignments')->insert([
            'order_id' => $orderId,
            'assigned_user_id' => $agent->getKey(),
            'assigned_at' => now(),
            'unassigned_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($agent)->post(
            route('admin.operations.orders.customer-service-discount', ['order' => $orderId]),
            [
                'percentage' => 12.5,
                'reason' => 'P196 independently reviewed concession',
                'idempotency_key' => (string) Str::uuid(),
            ],
        )->assertRedirect();

        $requestId = (int) DB::table('order_customer_service_discount_requests')
            ->where('order_id', $orderId)->value('id');
        $this->assertGreaterThan(0, $requestId);
        $this->actingAs($reviewer)->post(
            route('admin.operations.orders.customer-service-discount.review', [
                'order' => $orderId,
                'discountRequest' => $requestId,
            ]),
            ['decision' => 'approve'],
        )->assertRedirect();

        return [$agent, $reviewer, $orderId, $requestId];
    }

    private function roleUser(string $code): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user->fresh();
    }

    private function makeOrder(): int
    {
        $customer = app(B2bCustomerService::class)->create(['name' => 'P196 Approved Discount Customer']);

        return (int) DB::table('orders')->insertGetId([
            'store_id' => app(WholesalePrincipal::class)->storeId(),
            'customer_id' => (int) $customer->legacy_customer_id,
            'b2b_customer_id' => (int) $customer->getKey(),
            'order_number' => 'P196-'.Str::upper(Str::random(8)),
            'channel' => 'b2b',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 1,
            'delivery_total' => 0,
            'tax_total' => 0,
            'grand_total' => 9,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
