<?php

namespace Tests\Feature;

use App\Models\B2bCustomer;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Models\VanVisit;
use App\Services\VanVisitLifecycleService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class V2VanVisitOrderChannelGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_channel_order_with_matching_customer_and_store_is_rejected_in_both_locales(): void
    {
        [$actor, $visit, $order] = $this->fixture('b2c');
        $this->assertSame('b2b', $visit->customer_type);
        $this->assertSame((int) $visit->customer_id, (int) $order->b2b_customer_id);
        $this->assertSame((int) $visit->store_id, (int) $order->store_id);

        $originalLocale = app()->getLocale();
        $messages = [];

        try {
            foreach (['en', 'ar'] as $locale) {
                app()->setLocale($locale);

                try {
                    app(VanVisitLifecycleService::class)->transition(
                        $visit,
                        'completed_with_order',
                        $actor,
                        orderId: $order->id,
                    );
                    $this->fail('A Retail order must not complete a Wholesale Van visit.');
                } catch (ValidationException $exception) {
                    $messages[$locale] = $exception->errors()['order_id'][0] ?? null;
                    $this->assertSame(
                        __('van_visit_guard.order_customer_store_channel_mismatch'),
                        $messages[$locale],
                    );
                }

                $fresh = $visit->fresh();
                $this->assertSame('started', $fresh->status);
                $this->assertNull($fresh->order_id);
                $this->assertNull($fresh->completed_at);
                $this->assertNull($fresh->no_order_reason_id);
                $this->assertNull($fresh->closed_at);
                $this->assertNotNull($fresh->started_at);
            }
        } finally {
            app()->setLocale($originalLocale);
        }

        $this->assertNotSame($messages['en'], $messages['ar']);
        $this->assertSame('b2c', $order->fresh()->channel);
    }

    public function test_matching_wholesale_order_customer_and_store_completes_visit(): void
    {
        [$actor, $visit, $order] = $this->fixture('b2b');

        $updated = app(VanVisitLifecycleService::class)->transition(
            $visit,
            'completed_with_order',
            $actor,
            orderId: $order->id,
        );

        $this->assertSame('completed_with_order', $updated->status);
        $this->assertSame((int) $order->id, (int) $updated->order_id);
        $this->assertNotNull($updated->completed_at);
        $this->assertNull($updated->no_order_reason_id);
    }

    public function test_wholesale_order_from_another_store_is_still_rejected(): void
    {
        [$actor, $visit, $order] = $this->fixture('b2b');
        $otherStore = $this->createStore('V2-VISIT-OTHER-STORE');
        $order->forceFill(['store_id' => $otherStore])->save();

        $this->assertOrderCannotComplete($actor, $visit, $order);
    }

    public function test_wholesale_order_from_another_customer_is_still_rejected(): void
    {
        [$actor, $visit, $order] = $this->fixture('b2b');
        $otherCustomer = B2bCustomer::query()->create(['name' => 'V2 Other Wholesale Buyer']);
        $order->forceFill(['b2b_customer_id' => $otherCustomer->id])->save();

        $this->assertOrderCannotComplete($actor, $visit, $order);
    }

    /** @return array{User,VanVisit,Order} */
    private function fixture(string $orderChannel): array
    {
        $this->seed(CoreReferenceSeeder::class);

        $actor = User::factory()->create();
        $storeId = $this->createStore('V2-VISIT-AUTH-STORE');
        $legacyCustomer = Customer::query()->create([
            'type' => 'b2b',
            'name' => 'V2 Wholesale Buyer',
        ]);
        $b2bCustomer = B2bCustomer::query()->create([
            'legacy_customer_id' => $legacyCustomer->id,
            'name' => 'V2 Wholesale Buyer',
        ]);
        $visit = VanVisit::query()->create([
            'actor_user_id' => $actor->id,
            'customer_type' => 'b2b',
            'customer_id' => $b2bCustomer->id,
            'store_id' => $storeId,
            'status' => 'started',
            'started_at' => now()->subMinute(),
        ]);
        // Historical imported records may have both a B2B customer FK and
        // a contradictory Retail channel. That inconsistency must never
        // authorize completing a Wholesale Van visit.
        $order = Order::query()->create([
            'store_id' => $storeId,
            'customer_id' => $legacyCustomer->id,
            'b2b_customer_id' => $b2bCustomer->id,
            'order_number' => 'V2-VISIT-ORDER-'.strtoupper($orderChannel),
            'channel' => $orderChannel,
            'status' => 'pending',
            'currency' => 'KWD',
        ]);

        return [$actor, $visit, $order];
    }

    private function createStore(string $code): int
    {
        $typeId = DB::table('store_types')->where('code', 'B2B')->value('id');
        $this->assertNotNull($typeId);

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertOrderCannotComplete(User $actor, VanVisit $visit, Order $order): void
    {
        try {
            app(VanVisitLifecycleService::class)->transition(
                $visit,
                'completed_with_order',
                $actor,
                orderId: $order->id,
            );
            $this->fail('A different order scope must not complete this Van visit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order_id', $exception->errors());
        }

        $this->assertSame('started', $visit->fresh()->status);
        $this->assertNull($visit->fresh()->order_id);
        $this->assertNull($visit->fresh()->completed_at);
    }
}
