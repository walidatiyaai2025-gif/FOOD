<?php

namespace Tests\Feature;

use App\Models\CollectionAccount;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\B2cCustomerService;
use App\Services\CollectionCustodyService;
use App\Services\DriverCollectionReportService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DriverCollectionReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_split_collections_are_attributed_to_the_correct_driver_and_reconciled(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'COLL-REPORT',
            'name' => 'Collection Report Test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customer = app(B2cCustomerService::class)->create($storeId, [
            'name' => 'Collection Test Buyer',
        ]);
        $order = Order::query()->create([
            'store_id' => $storeId,
            'customer_id' => $customer->legacy_customer_id,
            'b2c_customer_id' => $customer->id,
            'order_number' => 'COLLECT-SPLIT-1',
            'channel' => 'b2c',
            'status' => 'out_for_delivery',
            'currency' => 'EGP',
            'subtotal' => 20,
            'discount_total' => 0,
            'delivery_total' => 0,
            'tax_total' => 0,
            'grand_total' => 20,
            'payment_method' => 'cash_on_delivery',
        ]);
        $invoice = Invoice::query()->create([
            'order_id' => $order->id,
            'store_id' => $storeId,
            'customer_id' => $customer->legacy_customer_id,
            'b2c_customer_id' => $customer->id,
            'invoice_number' => 'INV-COLLECT-SPLIT',
            'channel' => 'b2c',
            'status' => 'issued',
            'currency' => 'EGP',
            'total' => 20,
            'revision' => 1,
            'issued_at' => now(),
        ]);
        $actor = User::factory()->create();
        $driverA = CollectionAccount::query()->create([
            'actor_type' => 'driver',
            'actor_id' => 111,
            'store_id' => $storeId,
            'currency' => 'EGP',
            'status' => 'active',
        ]);
        $driverB = CollectionAccount::query()->create([
            'actor_type' => 'driver',
            'actor_id' => 222,
            'store_id' => $storeId,
            'currency' => 'EGP',
            'status' => 'active',
        ]);
        $custody = app(CollectionCustodyService::class);
        $custody->collect($driverA, $actor, 'split-driver-a', 5, 'EGP', 'driver_app', [
            ['invoice_id' => $invoice->id, 'amount' => 5],
        ]);
        $custody->collect($driverB, $actor, 'split-driver-b', 3, 'EGP', 'driver_app', [
            ['invoice_id' => $invoice->id, 'amount' => 3],
        ]);

        $reporter = app(DriverCollectionReportService::class);
        $a = $reporter->forAccount($driverA);
        $b = $reporter->forAccount($driverB);

        $this->assertSame(5.0, $a['total_collected']);
        $this->assertSame(3.0, $b['total_collected']);
        $this->assertCount(1, $a['orders']);
        $this->assertSame(5.0, $a['orders'][0]['collected_amount']);
        $this->assertSame(12.0, $a['orders'][0]['remaining_amount']);
        $this->assertSame('partially_settled', $a['orders'][0]['payment_state']);
        $this->assertSame(3.0, $b['orders'][0]['collected_amount']);
    }
}
