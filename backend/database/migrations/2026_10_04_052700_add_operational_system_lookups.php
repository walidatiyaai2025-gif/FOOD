<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('operational_lookups')) {
            Schema::create('operational_lookups', function (Blueprint $table): void {
                $table->id();
                $table->string('type', 50);
                $table->string('code', 80);
                $table->string('label_ar');
                $table->string('label_en');
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['type', 'code'], 'operational_lookups_type_code_unique');
                $table->index(['type', 'is_active', 'sort_order'], 'operational_lookups_active_sort_idx');
            });
        }

        if (Schema::hasTable('b2b_price_tiers')) {
            if (! Schema::hasColumn('b2b_price_tiers', 'name_ar')) {
                Schema::table('b2b_price_tiers', function (Blueprint $table): void {
                    $table->string('name_ar')->nullable();
                });
            }
            if (! Schema::hasColumn('b2b_price_tiers', 'name_en')) {
                Schema::table('b2b_price_tiers', function (Blueprint $table): void {
                    $table->string('name_en')->nullable();
                });
            }
            if (! Schema::hasColumn('b2b_price_tiers', 'is_active')) {
                Schema::table('b2b_price_tiers', function (Blueprint $table): void {
                    $table->boolean('is_active')->default(true);
                });
            }

            DB::table('b2b_price_tiers')->orderBy('id')->get(['id', 'code', 'name'])->each(function (object $tier): void {
                $labels = match (strtoupper((string) $tier->code)) {
                    'STANDARD' => ['قياسي', 'Standard'],
                    'WHOLESALE' => ['جملة', 'Wholesale'],
                    'VIP' => ['كبار العملاء', 'VIP'],
                    default => [(string) $tier->name, (string) $tier->name],
                };

                DB::table('b2b_price_tiers')->where('id', $tier->id)->update([
                    'name_ar' => DB::raw("COALESCE(name_ar, '".str_replace("'", "''", $labels[0])."')"),
                    'name_en' => DB::raw("COALESCE(name_en, '".str_replace("'", "''", $labels[1])."')"),
                    'is_active' => DB::raw('COALESCE(is_active, 1)'),
                ]);
            });
        }

        $now = now();
        $rows = [
            ...$this->rows('payment_operation_type', [
                ['charge', 'تحصيل', 'Charge'],
                ['refund', 'استرداد', 'Refund'],
                ['void', 'إلغاء عملية', 'Void'],
                ['adjustment', 'تسوية', 'Adjustment'],
            ]),
            ...$this->paymentMethodRows(),
            ...$this->rows('order_status', [
                ['pending', 'قيد الانتظار', 'Pending'],
                ['confirmed', 'مؤكد', 'Confirmed'],
                ['preparing', 'قيد التجهيز', 'Preparing'],
                ['ready', 'جاهز', 'Ready'],
                ['out_for_delivery', 'في الطريق للتوصيل', 'Out for delivery'],
                ['failed', 'تعذر التوصيل', 'Failed delivery'],
                ['delivered', 'تم التسليم', 'Delivered'],
                ['cancelled', 'ملغي', 'Cancelled'],
            ]),
            ...$this->rows('failed_delivery_reason', [
                ['customer_no_answer', 'العميل لا يرد', 'Customer did not answer'],
                ['wrong_address', 'العنوان غير صحيح', 'Wrong address'],
                ['customer_refused', 'العميل رفض الاستلام', 'Customer refused delivery'],
                ['customer_absent', 'العميل غير موجود', 'Customer not available'],
                ['payment_issue', 'مشكلة في الدفع', 'Payment issue'],
                ['order_issue', 'مشكلة في الطلب', 'Order issue'],
                ['other', 'سبب آخر', 'Other reason'],
            ]),
        ];

        DB::table('operational_lookups')->upsert(
            array_map(static fn (array $row): array => [
                ...$row,
                'created_at' => $now,
                'updated_at' => $now,
            ], $rows),
            ['type', 'code'],
            ['label_ar', 'label_en', 'sort_order', 'updated_at'],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_lookups');

        if (Schema::hasTable('b2b_price_tiers')) {
            $columns = array_values(array_filter(
                ['name_ar', 'name_en', 'is_active'],
                static fn (string $column): bool => Schema::hasColumn('b2b_price_tiers', $column),
            ));

            if ($columns !== []) {
                Schema::table('b2b_price_tiers', function (Blueprint $table) use ($columns): void {
                    $table->dropColumn($columns);
                });
            }
        }
    }

    /** @param list<array{0:string,1:string,2:string}> $values
     * @return list<array{type:string,code:string,label_ar:string,label_en:string,sort_order:int,is_active:bool}>
     */
    private function rows(string $type, array $values): array
    {
        return array_map(static fn (array $row, int $index): array => [
            'type' => $type,
            'code' => $row[0],
            'label_ar' => $row[1],
            'label_en' => $row[2],
            'sort_order' => ($index + 1) * 10,
            'is_active' => true,
        ], $values, array_keys($values));
    }

    /** @return list<array{type:string,code:string,label_ar:string,label_en:string,sort_order:int,is_active:bool}> */
    private function paymentMethodRows(): array
    {
        $configured = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            (array) config('checkout.payment_methods', ['cash_on_delivery']),
        ))));

        foreach (['cash_on_delivery', 'account_credit'] as $required) {
            if (! in_array($required, $configured, true)) {
                $configured[] = $required;
            }
        }

        return array_map(static function (string $code, int $index): array {
            $labels = match ($code) {
                'cash_on_delivery' => ['الدفع عند الاستلام', 'Cash on delivery'],
                'knet' => ['كي نت', 'KNET'],
                'card' => ['بطاقة', 'Card'],
                'account_credit' => ['رصيد الحساب', 'Account credit'],
                default => [Str::headline($code), Str::headline($code)],
            };

            return [
                'type' => 'payment_method',
                'code' => $code,
                'label_ar' => $labels[0],
                'label_en' => $labels[1],
                'sort_order' => ($index + 1) * 10,
                'is_active' => true,
            ];
        }, $configured, array_keys($configured));
    }
};
