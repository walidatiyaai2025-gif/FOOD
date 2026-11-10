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
 * P129: an ordinary Customer Service concession cannot silently write off
 * the full value of a paid-value order without independent approval.
 */
final class V2CommercialDiscountZeroValueGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_full_writeoff_and_rounding_to_zero_are_denied_without_financial_or_notification_mutation_in_both_locales(): void
    {
        $actor = $this->manager();
        $customerUser = User::factory()->create(['is_active' => true]);
        $order = $this->order($customerUser);
        $route = route('admin.operations.orders.customer-service-discount', ['order' => $order]);

        DB::table('payments')->insert([
            'order_id' => $order,
            'provider' => 'cash',
            'status' => 'pending',
            'amount' => 9,
            'currency' => 'KWD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $beforeAudit = DB::table('audit_logs')->count();
        $beforeNotifications = DB::table('notifications')->count();

        foreach (['ar' => '100', 'en' => '99.999'] as $locale => $percentage) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor)->post($route, [
                'percentage' => $percentage,
                'reason' => 'Unapproved complete order writeoff',
                'idempotency_key' => (string) Str::uuid(),
            ])->assertSessionHasErrors('percentage');

            $this->assertDatabaseHas('orders', [
                'id' => $order,
                'subtotal' => 10,
                'discount_total' => 1,
                'grand_total' => 9,
            ]);
            $this->assertDatabaseHas('payments', [
                'order_id' => $order,
                'status' => 'pending',
                'amount' => 9,
            ]);
            $this->assertDatabaseMissing('order_customer_service_discounts', ['order_id' => $order]);
            $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
            $this->assertSame($beforeNotifications, DB::table('notifications')->count());
        }
    }

    public function test_legitimate_positive_value_discount_still_posts_once_with_same_role_and_scope(): void
    {
        $actor = $this->manager();
        $order = $this->order();
        $route = route('admin.operations.orders.customer-service-discount', ['order' => $order]);
        $key = (string) Str::uuid();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor)->post($route, [
                'percentage' => '10',
                'reason' => 'Authorized regular customer concession',
                'idempotency_key' => $key,
            ])->assertRedirect();
        }

        $this->assertDatabaseHas('orders', [
            'id' => $order,
            'discount_total' => 1.9,
            'grand_total' => 8.1,
        ]);
        $this->assertSame(1, DB::table('order_customer_service_discounts')
            ->where('order_id', $order)->count());
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'order.customer_service_discount.applied')
            ->where('auditable_id', $order)->count());
    }

    public function test_unprivileged_actor_cannot_use_discount_endpoint_as_full_writeoff_bypass(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $order = $this->order();

        $this->actingAs($actor)->post(
            route('admin.operations.orders.customer-service-discount', ['order' => $order]),
            [
                'percentage' => '100',
                'reason' => 'Unauthorized waiver attempt',
                'idempotency_key' => (string) Str::uuid(),
            ],
        )->assertForbidden();

        $this->assertDatabaseMissing('order_customer_service_discounts', ['order_id' => $order]);
        $this->assertDatabaseHas('orders', ['id' => $order, 'grand_total' => 9]);
    }

    private function manager(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'CUSTOMER_SERVICE_MANAGER')->firstOrFail());

        return $actor->fresh();
    }

    private function order(?User $customerUser = null): int
    {
        $store = app(WholesalePrincipal::class)->storeId();
        $buyer = app(B2bCustomerService::class)->create(['name' => 'V2 P129 Buyer']);
        if ($customerUser !== null) {
            DB::table('b2b_customers')->where('id', $buyer->getKey())
                ->update(['user_id' => $customerUser->getKey()]);
        }

        return (int) DB::table('orders')->insertGetId([
            'store_id' => $store,
            'customer_id' => (int) $buyer->legacy_customer_id,
            'b2b_customer_id' => (int) $buyer->getKey(),
            'order_number' => 'V2-P129-'.Str::upper(Str::random(9)),
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
