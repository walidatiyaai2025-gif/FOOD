<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderCustomerServiceDiscountMigrationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_08_070000_create_order_customer_service_discounts.php');
    }

    public function test_replaying_migration_preserves_existing_table_and_rows(): void
    {
        $this->assertTrue(Schema::hasTable('order_customer_service_discounts'));
        $before = DB::table('order_customer_service_discounts')->count();

        $this->migration()->up();
        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('order_customer_service_discounts'));
        $this->assertSame($before, DB::table('order_customer_service_discounts')->count());
    }

    public function test_rollback_does_not_delete_existing_discount_audit_table(): void
    {
        $this->migration()->down();

        $this->assertTrue(Schema::hasTable('order_customer_service_discounts'));
    }
}
