<?php

namespace App\Domain\Analytics\Semantic;

use InvalidArgumentException;

final class SemanticMetricDictionary
{
    public const VERSION = '1.0';

    /** @return list<string> */
    public function orderMetricKeys(): array
    {
        return [
            'gross_revenue',
            'net_revenue',
            'discounts',
            'refunds',
            'margin',
            'units',
            'average_order_value',
            'cancellations',
        ];
    }

    public function definition(string $metric, string $datasetClass): SemanticMetricDefinition
    {
        $datasetClass = strtoupper(trim($datasetClass));
        $templates = $this->orderTemplates();

        if (! isset($templates[$metric])) {
            throw new InvalidArgumentException("Unknown order semantic metric [{$metric}].");
        }

        if (! in_array($datasetClass, [MetricDatasetClass::SELL_IN, MetricDatasetClass::SELL_OUT], true)) {
            throw new InvalidArgumentException('Order semantic metric definitions require SELL_IN or SELL_OUT.');
        }

        $template = $templates[$metric];
        $prefix = MetricDatasetClass::prefix($datasetClass);
        $source = $datasetClass === MetricDatasetClass::SELL_IN
            ? 'orders(channel=b2b) + order_items + authoritative payment/refund state'
            : 'orders(channel=b2c) + order_items + authoritative payment/refund state';

        return new SemanticMetricDefinition(
            key: $prefix.'.'.$metric,
            version: self::VERSION,
            datasetClass: $datasetClass,
            unit: $template['unit'],
            labelEn: $template['label_en'],
            labelAr: $template['label_ar'],
            descriptionEn: $template['description_en'],
            descriptionAr: $template['description_ar'],
            formula: $template['formula'],
            source: $source,
        );
    }

    public function inventoryDefinition(string $metric): SemanticMetricDefinition
    {
        $templates = $this->inventoryTemplates();
        if (! isset($templates[$metric])) {
            throw new InvalidArgumentException("Unknown inventory semantic metric [{$metric}].");
        }

        $template = $templates[$metric];

        return new SemanticMetricDefinition(
            key: 'inventory.'.$metric,
            version: self::VERSION,
            datasetClass: MetricDatasetClass::INVENTORY,
            unit: $template['unit'],
            labelEn: $template['label_en'],
            labelAr: $template['label_ar'],
            descriptionEn: $template['description_en'],
            descriptionAr: $template['description_ar'],
            formula: $template['formula'],
            source: 'inventories + stock_movements + deterministic inventory/reorder intelligence services',
        );
    }

    /** @return list<array<string, string>> */
    public function catalog(string $locale = 'en'): array
    {
        $definitions = [];

        foreach ([MetricDatasetClass::SELL_IN, MetricDatasetClass::SELL_OUT] as $datasetClass) {
            foreach ($this->orderMetricKeys() as $metric) {
                $definitions[] = $this->definition($metric, $datasetClass)->toArray($locale);
            }
        }

        foreach (array_keys($this->inventoryTemplates()) as $metric) {
            $definitions[] = $this->inventoryDefinition($metric)->toArray($locale);
        }

        return $definitions;
    }

    /**
     * @return array<string, array{
     *   unit:string,label_en:string,label_ar:string,description_en:string,description_ar:string,formula:string
     * }>
     */
    private function orderTemplates(): array
    {
        return [
            'gross_revenue' => [
                'unit' => 'currency',
                'label_en' => 'Gross revenue',
                'label_ar' => 'إجمالي الإيراد',
                'description_en' => 'Booked order value in the selected dataset before cancelled/refunded orders are excluded.',
                'description_ar' => 'قيمة الطلبات المسجلة داخل مجموعة البيانات قبل استبعاد الطلبات الملغاة أو المستردة.',
                'formula' => 'SUM(orders.grand_total) for the exact dataset/scope/window.',
            ],
            'net_revenue' => [
                'unit' => 'currency',
                'label_en' => 'Net recognized revenue',
                'label_ar' => 'صافي الإيراد المعترف به',
                'description_en' => 'Order value recognized after excluding cancelled and fully refunded orders.',
                'description_ar' => 'قيمة الطلبات المعترف بها بعد استبعاد الطلبات الملغاة والمستردة بالكامل.',
                'formula' => 'SUM(orders.grand_total) WHERE status NOT IN (cancelled, refunded).',
            ],
            'discounts' => [
                'unit' => 'currency',
                'label_en' => 'Recognized discounts',
                'label_ar' => 'الخصومات المعترف بها',
                'description_en' => 'Discount value attached to recognized orders in the selected dataset.',
                'description_ar' => 'قيمة الخصومات المرتبطة بالطلبات المعترف بها داخل مجموعة البيانات المحددة.',
                'formula' => 'SUM(orders.discount_total) WHERE status NOT IN (cancelled, refunded).',
            ],
            'refunds' => [
                'unit' => 'currency',
                'label_en' => 'Refunded order value',
                'label_ar' => 'قيمة الطلبات المستردة',
                'description_en' => 'Value of orders whose authoritative order state is fully refunded.',
                'description_ar' => 'قيمة الطلبات التي حالتها المعتمدة مستردة بالكامل.',
                'formula' => 'SUM(orders.grand_total) WHERE status = refunded; partial payment refunds require payment/refund evidence.',
            ],
            'margin' => [
                'unit' => 'currency',
                'label_en' => 'Gross margin',
                'label_ar' => 'هامش الربح الإجمالي',
                'description_en' => 'Recognized revenue less attributable authoritative product cost where cost coverage is available.',
                'description_ar' => 'الإيراد المعترف به ناقص التكلفة المعتمدة القابلة للإسناد للمنتجات عندما تكون بيانات التكلفة متاحة.',
                'formula' => 'recognized line revenue - authoritative attributable cost; never inferred when cost coverage is missing.',
            ],
            'units' => [
                'unit' => 'units',
                'label_en' => 'Units sold',
                'label_ar' => 'الوحدات المباعة',
                'description_en' => 'Order-item quantity belonging to recognized orders in the selected dataset.',
                'description_ar' => 'كمية بنود الطلب التابعة للطلبات المعترف بها داخل مجموعة البيانات المحددة.',
                'formula' => 'SUM(order_items.quantity) for recognized order ids.',
            ],
            'average_order_value' => [
                'unit' => 'currency',
                'label_en' => 'Average order value',
                'label_ar' => 'متوسط قيمة الطلب',
                'description_en' => 'Net recognized revenue divided by recognized order count.',
                'description_ar' => 'صافي الإيراد المعترف به مقسومًا على عدد الطلبات المعترف بها.',
                'formula' => 'net_revenue / recognized_order_count; zero when no recognized orders exist.',
            ],
            'cancellations' => [
                'unit' => 'orders',
                'label_en' => 'Cancelled orders',
                'label_ar' => 'الطلبات الملغاة',
                'description_en' => 'Count of orders whose authoritative status is cancelled.',
                'description_ar' => 'عدد الطلبات التي حالتها المعتمدة ملغاة.',
                'formula' => 'COUNT(orders.id) WHERE status = cancelled.',
            ],
        ];
    }

    /**
     * @return array<string, array{
     *   unit:string,label_en:string,label_ar:string,description_en:string,description_ar:string,formula:string
     * }>
     */
    private function inventoryTemplates(): array
    {
        return [
            'stock_on_hand' => [
                'unit' => 'units',
                'label_en' => 'Stock on hand',
                'label_ar' => 'المخزون المتاح',
                'description_en' => 'Authoritative inventory balance for the selected product/location scope.',
                'description_ar' => 'رصيد المخزون المعتمد ضمن نطاق المنتج والموقع المحدد.',
                'formula' => 'authoritative inventory quantity after valid stock movements.',
            ],
            'stock_cover_days' => [
                'unit' => 'days',
                'label_en' => 'Stock cover',
                'label_ar' => 'تغطية المخزون',
                'description_en' => 'Estimated days current available stock can cover deterministic sales velocity.',
                'description_ar' => 'عدد الأيام التقديري التي يغطيها المخزون المتاح وفق سرعة المبيعات المحسوبة حتميًا.',
                'formula' => 'available_stock / deterministic_velocity_units_per_day when velocity > 0.',
            ],
            'velocity_units_per_day' => [
                'unit' => 'units_per_day',
                'label_en' => 'Sales velocity',
                'label_ar' => 'سرعة المبيعات',
                'description_en' => 'Deterministic recognized unit sales normalized by observed days.',
                'description_ar' => 'مبيعات الوحدات المعترف بها محسوبة حتميًا ومقسمة على عدد أيام الرصد.',
                'formula' => 'recognized_units / observed_days.',
            ],
            'reorder_quantity' => [
                'unit' => 'units',
                'label_en' => 'Recommended reorder quantity',
                'label_ar' => 'كمية إعادة الطلب المقترحة',
                'description_en' => 'Deterministic reorder quantity produced by the authoritative reorder policy.',
                'description_ar' => 'كمية إعادة الطلب المحسوبة حتميًا بواسطة سياسة إعادة الطلب المعتمدة.',
                'formula' => 'authoritative reorder policy output using stock, velocity, lead time and safety rules.',
            ],
            'performance_score' => [
                'unit' => 'score',
                'label_en' => 'Performance score',
                'label_ar' => 'درجة الأداء',
                'description_en' => 'Versioned deterministic performance score whose component metrics and weights are explicit.',
                'description_ar' => 'درجة أداء محسوبة حتميًا بإصدار محدد مع إظهار المقاييس المكونة والأوزان بوضوح.',
                'formula' => 'versioned weighted composition of explicitly named canonical component metrics.',
            ],
        ];
    }
}
