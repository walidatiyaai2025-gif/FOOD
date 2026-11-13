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

final class V2CustomerServiceDiscountApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_assigned_agent_request_does_not_mutate_prices_and_independent_manager_review_applies_once(): void
    {
        $agent = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $manager = $this->roleUser('CUSTOMER_SERVICE_MANAGER');
        $orderId = $this->order();
        $this->assignAgent($orderId, $agent);
        $key = (string) Str::uuid();
        $submitUrl = route('admin.operations.orders.customer-service-discount', ['order' => $orderId]);

        $this->actingAs($agent)->post($submitUrl, [
            'percentage' => 12.5,
            'reason' => 'Service recovery adjustment',
            'idempotency_key' => $key,
        ])->assertRedirect();

        $request = DB::table('order_customer_service_discount_requests')
            ->where('order_id', $orderId)->first();
        $this->assertNotNull($request);
        $this->assertSame('pending', $request->status);
        $this->assertSame($agent->getKey(), (int) $request->requested_by_user_id);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'discount_total' => 1, 'grand_total' => 9]);
        $this->assertSame(0, DB::table('order_customer_service_discounts')->where('order_id', $orderId)->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'order.customer_service_discount.requested', 'user_id' => $agent->getKey(),
        ]);

        $this->actingAs($agent)->post($submitUrl, [
            'percentage' => 12.5,
            'reason' => 'Service recovery adjustment',
            'idempotency_key' => $key,
        ])->assertRedirect();
        $this->assertSame(1, DB::table('order_customer_service_discount_requests')->where('order_id', $orderId)->count());

        $this->actingAs($manager)
            ->get(route('admin.operations.orders.index', ['order' => $orderId, 'channel' => 'b2b']))
            ->assertOk()
            ->assertSee('data-order-customer-service-review', false);

        $reviewUrl = $this->reviewUrl($orderId, (int) $request->id);
        $this->actingAs($manager)->post($reviewUrl, ['decision' => 'approve'])->assertRedirect();
        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'id' => $request->id,
            'status' => 'approved',
            'reviewed_by_user_id' => $manager->getKey(),
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 7.875]);
        $this->assertDatabaseHas('order_customer_service_discounts', [
            'order_id' => $orderId, 'idempotency_key' => $key,
            'applied_by_user_id' => $manager->getKey(),
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'order.customer_service_discount.approved', 'user_id' => $manager->getKey(),
        ]);
        $this->actingAs($manager)->post($reviewUrl, ['decision' => 'approve'])->assertStatus(409);
        $this->assertSame(1, DB::table('order_customer_service_discounts')->where('order_id', $orderId)->count());
    }

    public function test_unprivileged_reviewer_cannot_approve_and_self_approval_is_forbidden(): void
    {
        $agent = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $manager = $this->roleUser('CUSTOMER_SERVICE_MANAGER');
        $otherAgent = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $orderId = $this->order();
        $this->assignAgent($orderId, $agent);

        $this->actingAs($agent)->post(route('admin.operations.orders.customer-service-discount', ['order' => $orderId]), [
            'percentage' => 15,
            'reason' => 'Requires independent authorization',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $requestId = (int) DB::table('order_customer_service_discount_requests')->where('order_id', $orderId)->value('id');
        $url = $this->reviewUrl($orderId, $requestId);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $otherAgent->forceFill(['locale' => $locale])->save();
            $this->actingAs($otherAgent)->post($url, ['decision' => 'approve'])->assertForbidden();
        }

        // This protects the service from privilege-combination self-review:
        // a manager must not approve a request recorded under their own ID.
        DB::table('order_customer_service_discount_requests')->where('id', $requestId)
            ->update(['requested_by_user_id' => $manager->getKey()]);
        $this->actingAs($manager)->post($url, ['decision' => 'approve'])->assertForbidden();
        $this->assertDatabaseHas('order_customer_service_discount_requests', ['id' => $requestId, 'status' => 'pending']);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 9]);
        $this->assertSame(0, DB::table('order_customer_service_discounts')->where('order_id', $orderId)->count());
    }

    public function test_rejection_is_audited_without_price_change_and_replay_is_denied(): void
    {
        $agent = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $manager = $this->roleUser('CUSTOMER_SERVICE_MANAGER');
        $orderId = $this->order();
        $this->assignAgent($orderId, $agent);

        $this->actingAs($agent)->post(route('admin.operations.orders.customer-service-discount', ['order' => $orderId]), [
            'percentage' => 25,
            'reason' => 'Unverified customer concession',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $requestId = (int) DB::table('order_customer_service_discount_requests')->where('order_id', $orderId)->value('id');
        $url = $this->reviewUrl($orderId, $requestId);
        $this->actingAs($manager)->post($url, [
            'decision' => 'reject', 'review_reason' => '',
        ])->assertSessionHasErrors('review_reason');
        $this->actingAs($manager)->post($url, [
            'decision' => 'reject', 'review_reason' => 'Pricing not justified',
        ])->assertRedirect();
        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'id' => $requestId, 'status' => 'rejected',
            'reviewed_by_user_id' => $manager->getKey(),
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'order.customer_service_discount.rejected', 'user_id' => $manager->getKey(),
        ]);
        $this->actingAs($manager)->post($url, ['decision' => 'approve'])->assertStatus(409);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 9]);
        $this->assertSame(0, DB::table('order_customer_service_discounts')->where('order_id', $orderId)->count());
    }

    public function test_stale_dispatched_or_completed_request_cannot_change_order_when_reviewed(): void
    {
        $agent = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $manager = $this->roleUser('CUSTOMER_SERVICE_MANAGER');
        $orderId = $this->order();
        $this->assignAgent($orderId, $agent);

        $this->actingAs($agent)->post(route('admin.operations.orders.customer-service-discount', ['order' => $orderId]), [
            'percentage' => 10,
            'reason' => 'Discount before fulfillment change',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $requestId = (int) DB::table('order_customer_service_discount_requests')->where('order_id', $orderId)->value('id');
        DB::table('orders')->where('id', $orderId)->update(['status' => 'confirmed']);

        $this->actingAs($manager)->post($this->reviewUrl($orderId, $requestId), [
            'decision' => 'approve',
        ])->assertSessionHasErrors('percentage');
        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'id' => $requestId, 'status' => 'pending',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 9]);
        $this->assertSame(0, DB::table('order_customer_service_discounts')->where('order_id', $orderId)->count());
    }

    private function roleUser(string $code): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user->fresh();
    }

    private function order(): int
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $customer = app(B2bCustomerService::class)->create(['name' => 'T087R04 Discount Review Customer']);

        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => (int) $customer->legacy_customer_id,
            'b2b_customer_id' => (int) $customer->getKey(),
            'order_number' => 'T087R04-'.Str::upper(Str::random(8)),
            'channel' => 'b2b', 'status' => 'pending',
            'currency' => 'KWD', 'subtotal' => 10,
            'discount_total' => 1, 'delivery_total' => 0,
            'tax_total' => 0, 'grand_total' => 9,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assignAgent(int $orderId, User $agent): void
    {
        DB::table('order_customer_service_assignments')->insert([
            'order_id' => $orderId,
            'assigned_user_id' => $agent->getKey(),
            'assigned_at' => now(),
            'unassigned_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function reviewUrl(int $orderId, int $requestId): string
    {
        return route('admin.operations.orders.customer-service-discount.review', [
            'order' => $orderId, 'discountRequest' => $requestId,
        ]);
    }
}
