<?php

namespace App\Domain\Analytics\Semantic;

final class MetricDictionary
{
    public const VERSION = '1.0.0';

    public const TIMEZONE = 'Asia/Kuwait';

    public const FLOW_SELL_IN = 'sell_in';

    public const FLOW_SELL_OUT = 'sell_out';

    /** @var list<string> */
    public const FLOWS = [self::FLOW_SELL_IN, self::FLOW_SELL_OUT];

    /**
     * @return array<string,MetricDefinition>
     */
    public function all(): array
    {
        $commerceDimensions = [
            'wholesale_account',
            'retailer',
            'store',
            'restaurant',
            'product',
            'sku',
            'category',
            'geography',
            'channel',
            'date',
            'currency',
        ];

        return [
            'orders.total' => $this->definition(
                'orders.total', 'count',
                'COUNT(all scoped orders)',
                'orders',
                $commerceDimensions,
                'Orders', 'الطلبات',
                'All orders created in the selected scope and Kuwait-time window, including cancelled and refunded orders.',
                'كل الطلبات المنشأة داخل النطاق والفترة المحددة بتوقيت الكويت، بما فيها الطلبات الملغاة والمستردة.',
            ),
            'orders.recognized' => $this->definition(
                'orders.recognized', 'count',
                'COUNT(orders WHERE status NOT IN [cancelled, refunded])',
                'orders',
                $commerceDimensions,
                'Recognized orders', 'الطلبات المحتسبة',
                'Orders eligible for revenue and unit metrics after excluding cancelled and fully refunded orders.',
                'الطلبات المؤهلة لمقاييس الإيراد والوحدات بعد استبعاد الطلبات الملغاة والمستردة بالكامل.',
            ),
            'revenue.gross' => $this->definition(
                'revenue.gross', 'currency',
                'SUM(subtotal + delivery_total) over recognized orders',
                'orders.subtotal + orders.delivery_total',
                $commerceDimensions,
                'Gross revenue', 'الإيراد الإجمالي',
                'Recognized order value before order-level discounts and before refund-state exclusion has any further adjustment.',
                'قيمة الطلبات المحتسبة قبل خصومات مستوى الطلب، مع استبعاد حالات الإلغاء والاسترداد الكامل.',
            ),
            'discounts.total' => $this->definition(
                'discounts.total', 'currency',
                'SUM(discount_total) over recognized orders',
                'orders.discount_total',
                $commerceDimensions,
                'Discounts', 'الخصومات',
                'Order-level discounts recorded on recognized orders in the selected scope and window.',
                'خصومات مستوى الطلب المسجلة على الطلبات المحتسبة داخل النطاق والفترة المحددة.',
            ),
            'revenue.net' => $this->definition(
                'revenue.net', 'currency',
                'SUM(grand_total) over recognized orders',
                'orders.grand_total',
                $commerceDimensions,
                'Net revenue', 'صافي الإيراد',
                'Authoritative recognized revenue after recorded discounts and totals, excluding cancelled and fully refunded orders.',
                'الإيراد المحتسب المعتمد بعد الخصومات والإجماليات المسجلة، مع استبعاد الطلبات الملغاة والمستردة بالكامل.',
            ),
            'refunds.total' => $this->definition(
                'refunds.total', 'currency',
                'SUM(grand_total) WHERE status = refunded',
                'orders.grand_total',
                $commerceDimensions,
                'Refunded value', 'قيمة الاستردادات',
                'Value of orders whose authoritative order status is refunded. Partial payment refunds require payment-level facts.',
                'قيمة الطلبات التي حالتها المعتمدة مستردة. الاستردادات الجزئية تحتاج إلى بيانات على مستوى الدفع.',
            ),
            'refunds.orders' => $this->definition(
                'refunds.orders', 'count',
                'COUNT(orders WHERE status = refunded)',
                'orders.status',
                $commerceDimensions,
                'Refunded orders', 'الطلبات المستردة',
                'Number of orders in refunded status.',
                'عدد الطلبات في حالة مستردة.',
            ),
            'cancellations.total' => $this->definition(
                'cancellations.total', 'currency',
                'SUM(grand_total) WHERE status = cancelled',
                'orders.grand_total',
                $commerceDimensions,
                'Cancelled value', 'قيمة الإلغاءات',
                'Value of cancelled orders; it is not recognized revenue.',
                'قيمة الطلبات الملغاة ولا تدخل ضمن الإيراد المحتسب.',
            ),
            'cancellations.orders' => $this->definition(
                'cancellations.orders', 'count',
                'COUNT(orders WHERE status = cancelled)',
                'orders.status',
                $commerceDimensions,
                'Cancelled orders', 'الطلبات الملغاة',
                'Number of orders in cancelled status.',
                'عدد الطلبات في حالة ملغاة.',
            ),
            'units.sold' => $this->definition(
                'units.sold', 'units',
                'SUM(order_items.quantity) over recognized orders',
                'order_items.quantity',
                $commerceDimensions,
                'Units', 'الوحدات',
                'Product units on recognized orders only.',
                'وحدات المنتجات الموجودة في الطلبات المحتسبة فقط.',
            ),
            'orders.aov' => $this->definition(
                'orders.aov', 'currency',
                'revenue.net / orders.recognized; 0 when denominator is 0',
                'derived from canonical revenue.net and orders.recognized',
                $commerceDimensions,
                'Average order value', 'متوسط قيمة الطلب',
                'Net recognized revenue divided by recognized orders.',
                'صافي الإيراد المحتسب مقسوماً على عدد الطلبات المحتسبة.',
            ),
            'margin.gross' => $this->definition(
                'margin.gross', 'currency',
                'revenue.net - recognized cost_of_goods_sold',
                'canonical revenue plus authoritative product/cost facts',
                $commerceDimensions,
                'Gross margin', 'هامش الربح الإجمالي',
                'Available only when authoritative cost facts exist; the model must never estimate missing cost.',
                'يتاح فقط عند وجود بيانات تكلفة معتمدة، ولا يجوز للنموذج تقدير تكلفة مفقودة.',
            ),
            'inventory.stock' => $this->definition(
                'inventory.stock', 'units',
                'SUM(inventory.quantity - inventory.reserved_quantity)',
                'inventories',
                ['store', 'warehouse', 'product', 'sku', 'category', 'date'],
                'Available stock', 'المخزون المتاح',
                'Physical quantity minus reserved quantity for the selected inventory scope.',
                'الكمية الفعلية ناقص الكمية المحجوزة داخل نطاق المخزون المحدد.',
            ),
            'inventory.velocity' => $this->definition(
                'inventory.velocity', 'units_per_day',
                'recognized units / complete observed days',
                'canonical units plus observation window',
                $commerceDimensions,
                'Sales velocity', 'سرعة المبيعات',
                'Recognized units normalized by observed days; partial periods must expose their actual coverage.',
                'الوحدات المحتسبة مقسومة على أيام الرصد الفعلية، ويجب إظهار تغطية الفترات الجزئية.',
            ),
            'inventory.stock_cover' => $this->definition(
                'inventory.stock_cover', 'days',
                'available stock / velocity_units_per_day',
                'canonical inventory.stock and inventory.velocity',
                $commerceDimensions,
                'Stock cover', 'تغطية المخزون',
                'Estimated days of cover from deterministic stock and velocity; undefined when velocity is zero.',
                'أيام التغطية التقديرية من المخزون والسرعة المحسوبين حتمياً، ولا تُعرّف عندما تكون السرعة صفراً.',
            ),
            'inventory.reorder' => $this->definition(
                'inventory.reorder', 'units',
                'MAX(0, target_stock - available_stock) under approved replenishment policy',
                'canonical stock, velocity, lead time and replenishment policy',
                $commerceDimensions,
                'Reorder quantity', 'كمية إعادة الطلب',
                'Deterministic replenishment quantity under the active approved policy; never authored by the LLM.',
                'كمية إعادة التوريد محسوبة حتمياً وفق السياسة المعتمدة ولا ينشئها نموذج اللغة.',
            ),
            'performance.index' => $this->definition(
                'performance.index', 'score',
                'versioned deterministic weighted metric set',
                'canonical metric observations',
                $commerceDimensions,
                'Performance', 'الأداء',
                'A versioned deterministic score whose component metrics and weights must be exposed with the result.',
                'درجة حتمية بإصدار محدد ويجب إظهار المقاييس والأوزان المكوّنة لها مع النتيجة.',
            ),
        ];
    }

    public function get(string $id): MetricDefinition
    {
        $definition = $this->all()[$id] ?? null;

        if (! $definition instanceof MetricDefinition) {
            throw new \InvalidArgumentException("Unknown canonical metric [{$id}].");
        }

        return $definition;
    }

    /** @return array<string,array<string,mixed>> */
    public function catalog(string $locale = 'en'): array
    {
        return array_map(
            static fn (MetricDefinition $definition): array => $definition->toArray($locale),
            $this->all(),
        );
    }

    /**
     * @param  list<string>  $dimensions
     */
    private function definition(
        string $id,
        string $unit,
        string $formula,
        string $source,
        array $dimensions,
        string $englishLabel,
        string $arabicLabel,
        string $englishExplanation,
        string $arabicExplanation,
    ): MetricDefinition {
        return new MetricDefinition(
            id: $id,
            version: self::VERSION,
            flows: self::FLOWS,
            unit: $unit,
            formula: $formula,
            source: $source,
            dimensions: $dimensions,
            labels: ['en' => $englishLabel, 'ar' => $arabicLabel],
            explanations: ['en' => $englishExplanation, 'ar' => $arabicExplanation],
        );
    }
}
