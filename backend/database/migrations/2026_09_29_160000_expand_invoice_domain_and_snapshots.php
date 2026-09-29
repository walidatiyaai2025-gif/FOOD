<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'commercial_locked_at')) {
                $table->timestamp('commercial_locked_at')->nullable()->index();
            }
        });

        Schema::table('invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoices', 'store_id')) {
                $table->foreignId('store_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('invoices', 'platform_customer_id')) {
                $table->foreignId('platform_customer_id')->nullable()->after('customer_id')->constrained('platform_customers')->nullOnDelete();
            }
            if (! Schema::hasColumn('invoices', 'channel')) {
                $table->string('channel', 10)->nullable()->index();
            }
            if (! Schema::hasColumn('invoices', 'order_number_snapshot')) {
                $table->string('order_number_snapshot')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'store_name_snapshot')) {
                $table->string('store_name_snapshot')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'customer_name_snapshot')) {
                $table->string('customer_name_snapshot')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'customer_email_snapshot')) {
                $table->string('customer_email_snapshot')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'customer_phone_snapshot')) {
                $table->string('customer_phone_snapshot')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'subtotal')) {
                $table->decimal('subtotal', 14, 3)->default(0);
            }
            if (! Schema::hasColumn('invoices', 'discount_total')) {
                $table->decimal('discount_total', 14, 3)->default(0);
            }
            if (! Schema::hasColumn('invoices', 'delivery_total')) {
                $table->decimal('delivery_total', 14, 3)->default(0);
            }
            if (! Schema::hasColumn('invoices', 'tax_total')) {
                $table->decimal('tax_total', 14, 3)->default(0);
            }
            if (! Schema::hasColumn('invoices', 'payment_method_snapshot')) {
                $table->string('payment_method_snapshot', 80)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'payment_status_snapshot')) {
                $table->string('payment_status_snapshot', 80)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'b2b_account_id_snapshot')) {
                $table->unsignedBigInteger('b2b_account_id_snapshot')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'price_tier_id_snapshot')) {
                $table->unsignedBigInteger('price_tier_id_snapshot')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'price_tier_code_snapshot')) {
                $table->string('price_tier_code_snapshot', 80)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'commercial_snapshot')) {
                $table->json('commercial_snapshot')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'revision')) {
                $table->unsignedInteger('revision')->default(1);
            }
            if (! Schema::hasColumn('invoices', 'revision_of_invoice_id')) {
                $table->unsignedBigInteger('revision_of_invoice_id')->nullable()->index();
            }
            if (! Schema::hasColumn('invoices', 'voided_at')) {
                $table->timestamp('voided_at')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'void_reason')) {
                $table->string('void_reason', 1000)->nullable();
            }
            if (! Schema::hasColumn('invoices', 'voided_by_user_id')) {
                $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('invoice_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoice_items', 'sku_snapshot')) {
                $table->string('sku_snapshot')->nullable();
            }
            if (! Schema::hasColumn('invoice_items', 'base_unit_price_snapshot')) {
                $table->decimal('base_unit_price_snapshot', 14, 3)->nullable();
            }
            if (! Schema::hasColumn('invoice_items', 'line_discount_total')) {
                $table->decimal('line_discount_total', 14, 3)->default(0);
            }
            if (! Schema::hasColumn('invoice_items', 'line_tax_total')) {
                $table->decimal('line_tax_total', 14, 3)->default(0);
            }
            if (! Schema::hasColumn('invoice_items', 'currency')) {
                $table->string('currency', 3)->nullable();
            }
            if (! Schema::hasColumn('invoice_items', 'quantity_conversion_factor')) {
                $table->decimal('quantity_conversion_factor', 14, 3)->default(1);
            }
            if (! Schema::hasColumn('invoice_items', 'b2b_account_id_snapshot')) {
                $table->unsignedBigInteger('b2b_account_id_snapshot')->nullable();
            }
            if (! Schema::hasColumn('invoice_items', 'price_tier_id_snapshot')) {
                $table->unsignedBigInteger('price_tier_id_snapshot')->nullable();
            }
            if (! Schema::hasColumn('invoice_items', 'price_tier_code_snapshot')) {
                $table->string('price_tier_code_snapshot', 80)->nullable();
            }
            if (! Schema::hasColumn('invoice_items', 'line_snapshot')) {
                $table->json('line_snapshot')->nullable();
            }
        });

        // Preserve every historical invoice. When an order is available, backfill provenance and totals
        // without changing the legacy invoice number, total or issue date.
        $storeAdminRoleId = DB::table('roles')->where('code', 'B2C_STORE_ADMIN')->value('id');
        if ($storeAdminRoleId !== null) {
            $financePermissionIds = DB::table('permissions')
                ->whereIn('code', ['finance.view', 'finance.manage'])
                ->pluck('id');

            foreach ($financePermissionIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore([
                    'permission_id' => (int) $permissionId,
                    'role_id' => (int) $storeAdminRoleId,
                ]);
            }
        }

        DB::table('invoices')
            ->whereNotNull('order_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $invoice) {
                    $order = DB::table('orders')->where('id', $invoice->order_id)->first();
                    if ($order === null) {
                        continue;
                    }

                    $store = DB::table('stores')->where('id', $order->store_id)->first(['name']);
                    $domain = $order->channel === 'b2b'
                        ? DB::table('b2b_customers')->where('id', $order->b2b_customer_id)->first()
                        : DB::table('b2c_customers')->where('id', $order->b2c_customer_id)->first();
                    $platformCustomerId = $domain?->user_id === null
                        ? null
                        : DB::table('platform_customers')->where('user_id', $domain->user_id)->value('id');

                    DB::table('invoices')->where('id', $invoice->id)->update([
                        'store_id' => $order->store_id,
                        'platform_customer_id' => $platformCustomerId,
                        'channel' => strtolower((string) $order->channel),
                        'order_number_snapshot' => $order->order_number,
                        'store_name_snapshot' => $store?->name,
                        'customer_name_snapshot' => $domain?->name,
                        'customer_email_snapshot' => $domain?->email,
                        'customer_phone_snapshot' => $domain?->phone,
                        'subtotal' => $order->subtotal ?? $invoice->total,
                        'discount_total' => $order->discount_total ?? 0,
                        'delivery_total' => $order->delivery_total ?? 0,
                        'tax_total' => $order->tax_total ?? 0,
                        'payment_method_snapshot' => $order->payment_method ?? null,
                        'b2b_account_id_snapshot' => $order->b2b_account_id_snapshot ?? null,
                        'price_tier_id_snapshot' => $order->price_tier_id_snapshot ?? null,
                        'price_tier_code_snapshot' => $order->price_tier_code_snapshot ?? null,
                        'revision' => 1,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally non-destructive for invoice history. Only the order lock is reversible.
        if (Schema::hasColumn('orders', 'commercial_locked_at')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropColumn('commercial_locked_at');
            });
        }
    }
};
