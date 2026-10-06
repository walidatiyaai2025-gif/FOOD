<?php

namespace Tests\Feature;

use App\Services\CommercialPolicyService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommercialPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $storeId;

    private int $catalogId;

    private int $unitId;

    private int $productId;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();
        $storeTypeId = (int) DB::table('store_types')->insertGetId([
            'code' => 'B2C',
            'name' => 'Retail',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'STORE-984',
            'name' => 'Commercial Policy Store',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $this->storeId,
            'channel' => 'b2c',
            'code' => 'default',
            'name' => 'Default',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->unitId = (int) DB::table('units')->insertGetId([
            'store_id' => $this->storeId,
            'scope' => 'store',
            'scope_key' => 'store:'.$this->storeId,
            'code' => 'piece',
            'name' => 'Piece',
            'name_ar' => 'قطعة',
            'name_en' => 'Piece',
            'decimal_places' => 0,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $this->catalogId,
            'unit_id' => $this->unitId,
            'sku' => 'SKU-984',
            'name' => 'Policy Product',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->customerId = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => 'Policy Customer',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->flag('commercial_rules_enabled', true);
    }

    public function test_selling_units_convert_to_base_quantity_without_bypassing_inventory_unit(): void
    {
        DB::table('product_selling_units')->insert([
            'product_id' => $this->productId,
            'unit_id' => null,
            'code' => 'carton',
            'name' => 'Carton',
            'conversion_factor' => 10,
            'price' => 25,
            'sku' => 'SKU-984-C10',
            'barcode' => '98400010',
            'is_base' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unit = $this->service()->sellingUnit($this->productId, 'carton', 2);

        $this->assertSame(20.0, $unit['base_quantity']);
        $this->assertSame(10.0, $unit['conversion_factor']);
        $this->assertSame('SKU-984-C10', $unit['sku']);
    }

    public function test_customer_rule_overrides_group_and_channel_defaults(): void
    {
        $this->policy([
            'status' => CommercialPolicyService::STATUS_RESTRICTED,
            'channels' => json_encode(['customer' => true], JSON_THROW_ON_ERROR),
            'default_max_per_day' => 20,
        ]);

        $groupId = (int) DB::table('commercial_customer_groups')->insertGetId([
            'store_id' => $this->storeId,
            'name' => 'Preferred',
            'priority' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('commercial_customer_group_members')->insert([
            'customer_group_id' => $groupId,
            'customer_id' => $this->customerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('product_commercial_rules')->insert([
            [
                'product_id' => $this->productId,
                'customer_group_id' => $groupId,
                'channel' => 'customer',
                'is_allowed' => true,
                'max_per_day' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_id' => $this->productId,
                'customer_id' => $this->customerId,
                'channel' => 'customer',
                'is_allowed' => true,
                'max_per_day' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $allowed = $this->service()->evaluate(
            $this->productId,
            $this->customerId,
            'customer',
            2,
        );
        $blocked = $this->service()->evaluate(
            $this->productId,
            $this->customerId,
            'customer',
            4,
        );

        $this->assertTrue($allowed['allowed']);
        $this->assertSame(3.0, $allowed['limits']['max_per_day']);
        $this->assertFalse($blocked['allowed']);
        $this->assertContains('MAX_PER_DAY_EXCEEDED', $blocked['reason_codes']);
    }

    public function test_yearly_availability_window_uses_server_supplied_business_time(): void
    {
        $this->policy(['business_timezone' => 'Asia/Kuwait']);

        DB::table('product_availability_windows')->insert([
            'product_id' => $this->productId,
            'recurrence' => 'yearly',
            'start_month' => 11,
            'start_day' => 1,
            'end_month' => 12,
            'end_day' => 31,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $outside = $this->service()->evaluate(
            $this->productId,
            $this->customerId,
            'customer',
            1,
            CarbonImmutable::parse('2026-10-06 08:00:00', 'Asia/Kuwait'),
        );
        $inside = $this->service()->evaluate(
            $this->productId,
            $this->customerId,
            'customer',
            1,
            CarbonImmutable::parse('2026-11-06 08:00:00', 'Asia/Kuwait'),
        );

        $this->assertFalse($outside['allowed']);
        $this->assertContains('OUTSIDE_AVAILABILITY', $outside['reason_codes']);
        $this->assertTrue($inside['allowed']);
    }

    public function test_quota_reservation_is_atomic_idempotent_and_release_restores_capacity(): void
    {
        $this->policy(['default_max_per_day' => 5]);

        $orderOne = $this->order('ORD-984-A');
        $orderTwo = $this->order('ORD-984-B');

        $first = $this->service()->reserveForOrder(
            $orderOne,
            $this->customerId,
            $this->productId,
            'piece',
            3,
            'customer',
        );
        $retry = $this->service()->reserveForOrder(
            $orderOne,
            $this->customerId,
            $this->productId,
            'piece',
            3,
            'customer',
        );

        $this->assertSame($first['reservation_token'], $retry['reservation_token']);
        $this->assertSame(
            1,
            DB::table('commercial_quota_reservations')
                ->where('order_id', $orderOne)
                ->where('product_id', $this->productId)
                ->count(),
        );

        try {
            $this->service()->reserveForOrder(
                $orderTwo,
                $this->customerId,
                $this->productId,
                'piece',
                3,
                'customer',
            );
            $this->fail('Expected daily quota rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('MAX_PER_DAY_EXCEEDED', $exception->getMessage());
        }

        $this->assertSame(1, $this->service()->releaseOrderReservations($orderOne));

        $second = $this->service()->reserveForOrder(
            $orderTwo,
            $this->customerId,
            $this->productId,
            'piece',
            3,
            'customer',
        );

        $this->assertNotSame($first['reservation_token'], $second['reservation_token']);
    }

    public function test_break_pack_modes_are_enforced_and_base_quantity_cannot_bypass_unit_policy(): void
    {
        DB::table('product_selling_units')->insert([
            'product_id' => $this->productId,
            'unit_id' => null,
            'code' => 'carton',
            'name' => 'Carton',
            'conversion_factor' => 10,
            'price' => 25,
            'sku' => 'SKU-984-C10',
            'barcode' => '98400010',
            'is_base' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->policy(['break_pack_policy' => 'full-pack-only']);

        $this->assertSame(10.0, $this->service()->sellingUnit($this->productId, 'carton', 1)['base_quantity']);
        $this->expectDomainException(fn () => $this->service()->sellingUnit($this->productId, 'piece', 1), 'UNIT_NOT_ALLOWED');
        $this->expectDomainException(
            fn () => $this->service()->reserveBaseQuantityForOrder(
                $this->order('ORD-984-BYPASS'),
                $this->customerId,
                $this->productId,
                1,
                'customer',
            ),
            'UNIT_CONTEXT_REQUIRED',
        );

        DB::table('product_commercial_policies')->where('product_id', $this->productId)->update([
            'break_pack_policy' => 'loose-only',
            'break_pack_unit_code' => null,
        ]);
        $this->assertSame(1.0, $this->service()->sellingUnit($this->productId, 'piece', 1)['base_quantity']);
        $this->expectDomainException(fn () => $this->service()->sellingUnit($this->productId, 'carton', 1), 'UNIT_NOT_ALLOWED');

        DB::table('product_commercial_policies')->where('product_id', $this->productId)->update([
            'break_pack_policy' => 'one-unit-type',
            'break_pack_unit_code' => 'carton',
        ]);
        $this->assertSame(20.0, $this->service()->sellingUnit($this->productId, 'carton', 2)['base_quantity']);
        $this->expectDomainException(fn () => $this->service()->sellingUnit($this->productId, 'piece', 1), 'UNIT_NOT_ALLOWED');

        DB::table('product_commercial_policies')->where('product_id', $this->productId)->update([
            'break_pack_policy' => 'mixed',
            'break_pack_unit_code' => null,
        ]);
        $this->assertSame(1.0, $this->service()->sellingUnit($this->productId, 'piece', 1)['base_quantity']);
        $this->assertSame(10.0, $this->service()->sellingUnit($this->productId, 'carton', 1)['base_quantity']);
    }

    public function test_commercial_rules_flag_defaults_off_for_backward_compatibility(): void
    {
        DB::table('settings')->whereNull('store_id')->where('key', 'commercial_rules_enabled')->delete();
        $this->policy([
            'status' => CommercialPolicyService::STATUS_CLOSED,
            'default_max_per_day' => 1,
        ]);

        $legacy = $this->service()->evaluate($this->productId, $this->customerId, 'customer', 5);
        $this->assertTrue($legacy['allowed']);
        $this->assertSame(CommercialPolicyService::STATUS_OPEN, $legacy['status']);
        $this->assertFalse($legacy['feature_enabled']);

        $this->flag('commercial_rules_enabled', true);
        $enabled = $this->service()->evaluate($this->productId, $this->customerId, 'customer', 5);
        $this->assertFalse($enabled['allowed']);
        $this->assertTrue($enabled['feature_enabled']);
        $this->assertContains('PRODUCT_CLOSED', $enabled['reason_codes']);
    }

    /** @param array<string,mixed> $overrides */
    private function policy(array $overrides = []): void
    {
        DB::table('product_commercial_policies')->insert(array_merge([
            'product_id' => $this->productId,
            'status' => CommercialPolicyService::STATUS_OPEN,
            'hide_when_closed' => false,
            'override_allowed' => false,
            'channels' => null,
            'break_pack_policy' => 'mixed',
            'break_pack_unit_code' => null,
            'business_timezone' => 'UTC',
            'week_starts_on' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function order(string $number): int
    {
        return (int) DB::table('orders')->insertGetId([
            'store_id' => $this->storeId,
            'customer_id' => $this->customerId,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 0,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function flag(string $key, bool $enabled): void
    {
        DB::table('settings')->updateOrInsert(
            ['store_id' => null, 'key' => $key],
            [
                'value' => json_encode($enabled, JSON_THROW_ON_ERROR),
                'is_secret' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function expectDomainException(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected DomainException: '.$message);
        } catch (DomainException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function service(): CommercialPolicyService
    {
        return app(CommercialPolicyService::class);
    }
}
