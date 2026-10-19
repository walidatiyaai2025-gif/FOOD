<?php

namespace App\Domain\Assistant\Tools;

final class AssistantToolDefinitionCatalog
{
    public static function forKey(string $key): AssistantToolDefinition
    {
        $definitions = self::definitions();

        return $definitions[$key] ?? self::fallback($key);
    }

    /** @return array<string,AssistantToolDefinition> */
    public static function definitions(): array
    {
        static $definitions = null;
        if (is_array($definitions)) {
            return $definitions;
        }

        $definitions = [];

        $add = static function (
            string $key,
            string $en,
            string $ar,
            array $permissions,
            string $permissionMode,
            array $channels,
            array $input,
            string $source,
            string $dataClass = 'INTERNAL',
            bool $sellOutConsent = false,
            int $rowLimit = 100,
            int $timeoutMs = 2500,
            array $freshness = [],
        ) use (&$definitions): void {
            $definitions[$key] = new AssistantToolDefinition(
                key: $key,
                schemaVersion: '1.0',
                labels: ['en' => $en, 'ar' => $ar],
                mode: 'READ',
                requiredPermissions: $permissions,
                permissionMode: $permissionMode,
                channels: $channels,
                inputSchema: $input,
                resultSchema: self::resultSchema(),
                timeoutMs: $timeoutMs,
                rowLimit: $rowLimit,
                dataClass: $dataClass,
                sellOutConsent: $sellOutConsent,
                source: $source,
                freshness: $freshness,
            );
        };

        $period = self::schema();
        $orders = self::schema([
            'order_id' => self::integer(),
            'order_number' => self::string(120),
            'status' => self::string(40),
        ]);
        $customers = self::schema([
            'customer_id' => self::integer(),
            'customer_name' => self::string(120),
        ]);
        $products = self::schema([
            'product_id' => self::integer(),
            'product_name' => self::string(160),
            'sort' => ['type' => 'string', 'enum' => ['top', 'slow']],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 25],
        ]);
        $wholesaleExecutive = self::schema([
            'target_revenue' => ['type' => 'number', 'minimum' => 0],
        ]);
        $drivers = self::schema([
            'driver_id' => self::integer(),
            'driver_name' => self::string(120),
            'status' => self::string(40),
        ]);

        $add('sales.summary', 'Summarize authorized sales using canonical FOODEX metrics.', 'تلخيص المبيعات المصرح بها باستخدام مقاييس FOODEX المعتمدة.', ['reports.view'], 'all', ['b2b', 'b2c'], $period, 'SemanticMetricService');
        $add('sales.compare', 'Compare authorized sales periods deterministically.', 'مقارنة فترات المبيعات المصرح بها بحسابات حتمية.', ['reports.view'], 'all', ['b2b', 'b2c'], $period, 'SemanticMetricService');
        $add('orders.summary', 'Summarize authorized orders and statuses.', 'تلخيص الطلبات والحالات المصرح بها.', ['orders.view'], 'all', ['b2b', 'b2c'], $orders, 'AssistantBusinessReadService', rowLimit: 25);
        $add('orders.lookup', 'Look up one authorized order by id or order number.', 'البحث عن طلب مصرح به بالرقم أو المعرّف.', ['orders.view'], 'all', ['b2b', 'b2c'], $orders, 'AssistantBusinessReadService', rowLimit: 1);
        $add('stores.summary', 'Summarize stores within the authorized scope.', 'تلخيص المتاجر داخل النطاق المصرح به.', ['stores.view'], 'all', ['b2b', 'b2c'], $period, 'AssistantBusinessReadService', rowLimit: 50);
        $add('stores.compare', 'Compare a bounded list of authorized stores.', 'مقارنة قائمة محدودة من المتاجر المصرح بها.', ['stores.view'], 'all', ['b2b', 'b2c'], self::schema([
            'store_ids' => ['type' => 'array', 'items' => self::integer(), 'maxItems' => 10, 'uniqueItems' => true],
        ]), 'AssistantBusinessReadService', rowLimit: 10);
        $add('customers.summary', 'Summarize authorized customer activity.', 'تلخيص نشاط العملاء المصرح به.', ['customers.view', 'b2b.accounts.view'], 'any', ['b2b', 'b2c'], $customers, 'AssistantBusinessReadService', rowLimit: 25);
        $add('customers.activity', 'Read bounded activity for one authorized customer.', 'قراءة نشاط محدود لعميل واحد مصرح به.', ['customers.view', 'b2b.accounts.view'], 'any', ['b2b', 'b2c'], $customers, 'AssistantBusinessReadService', rowLimit: 25);
        $add('products.performance', 'Return top or slow selling authorized SKUs for a bounded period.', 'عرض المنتجات الأعلى أو الأبطأ مبيعًا لفترة محدودة ومصرح بها.', ['catalog.view'], 'all', ['b2b', 'b2c'], $products, 'AssistantBusinessReadService', rowLimit: 25);
        $add('executive.wholesale', 'Analyze authorized wholesale sell-in performance and rank intervention priorities with evidence.', 'تحليل أداء البيع بالجملة المصرح به وترتيب أولويات التدخل مع الأدلة.', ['reports.view', 'catalog.view', 'b2b.accounts.view'], 'all', ['b2b'], $wholesaleExecutive, 'WholesaleExecutiveIntelligenceService', rowLimit: 100, timeoutMs: 5000);

        $add('orders.late', 'List bounded late orders in the authorized scope.', 'عرض الطلبات المتأخرة ضمن النطاق المصرح به.', ['orders.view'], 'all', ['b2b', 'b2c'], $orders, 'AssistantOperationsReadService', rowLimit: 50);
        $add('orders.cancelled', 'List bounded cancelled orders in the authorized scope.', 'عرض الطلبات الملغاة ضمن النطاق المصرح به.', ['orders.view'], 'all', ['b2b', 'b2c'], $orders, 'AssistantOperationsReadService', rowLimit: 50);
        $add('cancellations.summary', 'Summarize authorized cancellations.', 'تلخيص الإلغاءات المصرح بها.', ['orders.view'], 'all', ['b2b', 'b2c'], $period, 'AssistantOperationsReadService');
        $add('drivers.status', 'Read bounded driver availability and location freshness.', 'قراءة حالة السائقين وتوفرهم وحداثة مواقعهم.', ['drivers.b2b.view', 'drivers.b2c.view'], 'any', ['b2b', 'b2c'], $drivers, 'AssistantOperationsReadService', rowLimit: 50);
        $add('drivers.assignments', 'Read bounded active driver assignments.', 'قراءة تكليفات السائقين النشطة بشكل محدود.', ['drivers.b2b.view', 'drivers.b2c.view'], 'any', ['b2b', 'b2c'], $drivers, 'AssistantOperationsReadService', rowLimit: 50);
        $add('inventory.alerts', 'Read low-stock alerts in authorized inventory.', 'قراءة تنبيهات المخزون المنخفض المصرح بها.', ['inventory.view'], 'all', ['b2b', 'b2c'], $period, 'AssistantOperationsReadService', rowLimit: 50);
        $add('brief.daily', 'Build a permission-aware daily operational brief.', 'إنشاء ملخص تشغيلي يومي حسب الصلاحيات.', ['assistant.use'], 'all', ['b2b', 'b2c'], $period, 'AssistantOperationsReadService');

        $analyticsSchema = self::schema([
            'retailer_store_ids' => ['type' => 'array', 'items' => self::integer(), 'maxItems' => 25, 'uniqueItems' => true],
            'wholesale_store_id' => self::integer(),
        ]);

        $add('analytics.summary', 'Summarize materialized FOODEX commerce facts with explicit freshness.', 'تلخيص حقائق التجارة المادية في FOODEX مع إظهار حداثة البيانات.', ['reports.view'], 'all', ['b2b', 'b2c'], $analyticsSchema, 'AnalyticsReadModelService', rowLimit: 1000, freshness: ['stale_after_minutes' => 20]);
        $add('analytics.diagnose', 'Compute deterministic period comparisons, trends, mix shifts and diagnostic evidence for authorized commerce data.', 'حساب مقارنات الفترات والاتجاهات وتحولات المزيج والأدلة التشخيصية بشكل حتمي لبيانات التجارة المصرح بها.', ['reports.view'], 'all', ['b2b', 'b2c'], $analyticsSchema, 'DeterministicAnalyticsEngine', rowLimit: 1000, timeoutMs: 3500, freshness: ['stale_after_minutes' => 20]);
        $add('inventory.snapshot', 'Read the latest authorized inventory snapshots from the analytics read model.', 'قراءة أحدث لقطات المخزون المصرح بها من نموذج التحليلات.', ['reports.view'], 'all', ['b2b', 'b2c'], $analyticsSchema, 'AnalyticsReadModelService', rowLimit: 100, freshness: ['stale_after_minutes' => 20]);
        $add('refunds.summary', 'Summarize refunds, cancellations and refunded payments from materialized facts.', 'تلخيص المرتجعات والإلغاءات والمدفوعات المرتجعة من الحقائق المادية.', ['reports.view'], 'all', ['b2b', 'b2c'], $analyticsSchema, 'AnalyticsReadModelService', rowLimit: 1000, freshness: ['stale_after_minutes' => 20]);
        $add('retailers.performance', 'Compare consented retailer sell-out performance without direct SQL.', 'مقارنة أداء البيع للمستهلك لدى تجار التجزئة الموافقين بدون SQL مباشر.', ['reports.view'], 'all', ['b2b', 'b2c'], $analyticsSchema, 'AnalyticsReadModelService', 'SELL_OUT', true, 1000, 3000, ['stale_after_minutes' => 20]);
        $add('retailers.360', 'Build a governed 360 view of consented retailer sell-in, actual sell-out, account health and drilldowns.', 'إنشاء رؤية 360 محكومة لمشتريات تاجر التجزئة والبيع الفعلي للمستهلك وصحة الحساب والتفاصيل.', ['reports.view'], 'all', ['b2b'], $analyticsSchema, 'Retailer360IntelligenceService', 'SELL_OUT', true, 1000, 4000, ['stale_after_minutes' => 20]);
        $add('wholesale.purchases', 'Summarize consented retailer wholesale purchases and received units.', 'تلخيص مشتريات الجملة والوحدات المستلمة لتجار التجزئة الموافقين.', ['reports.view'], 'all', ['b2b', 'b2c'], $analyticsSchema, 'AnalyticsReadModelService', 'SELL_OUT', true, 1000, 3000, ['stale_after_minutes' => 20]);
        $add('margin.summary', 'Compute authorized gross margin only where cost coverage exists.', 'حساب هامش الربح المصرح به فقط عند توفر تغطية التكلفة.', ['reports.view', 'catalog.view'], 'all', ['b2b', 'b2c'], $period, 'AssistantAnalyticsReadService', rowLimit: 1000);
        $add('support.summary', 'Summarize authorized customer-service workload and SLA risk.', 'تلخيص عبء خدمة العملاء ومخاطر اتفاقية مستوى الخدمة المصرح بها.', ['customers.view', 'b2b.accounts.view', 'whatsapp.inbox.view'], 'any', ['b2b', 'b2c'], $period, 'CustomerServiceCaseManager', rowLimit: 100);

        $add(
            'knowledge.search',
            'Search approved FOODEX manuals, policies, procedures, FAQs and product knowledge. Use for document knowledge, not live transactional facts.',
            'البحث في أدلة وسياسات وإجراءات وأسئلة FOODEX الشائعة ومعرفة المنتجات المعتمدة. يستخدم لمعرفة المستندات وليس للبيانات التشغيلية الحية.',
            ['assistant.use'],
            'all',
            ['b2b', 'b2c'],
            [
                'type' => 'object',
                'required' => ['query'],
                'properties' => [
                    'channel' => ['type' => 'string', 'enum' => ['b2b', 'b2c', 'B2B', 'B2C']],
                    'store_id' => self::integer(),
                    'query' => self::string(500),
                    'max_results' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8],
                ],
                'additionalProperties' => false,
            ],
            'AssistantKnowledgeIndex',
            'KNOWLEDGE',
            rowLimit: 8,
            timeoutMs: 2500,
            freshness: ['source_version_required' => true],
        );

        return $definitions;
    }

    /** @param array<string,array<string,mixed>> $extra
     * @return array<string,mixed>
     */
    private static function schema(array $extra = []): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'format' => 'date'],
                'to' => ['type' => 'string', 'format' => 'date'],
                'channel' => ['type' => 'string', 'enum' => ['b2b', 'b2c', 'B2B', 'B2C']],
                'store_id' => self::integer(),
                'store_name' => self::string(160),
                'query' => self::string(160),
                ...$extra,
            ],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string,mixed> */
    private static function resultSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                '_tool' => ['type' => 'object'],
            ],
            'additionalProperties' => true,
        ];
    }

    /** @return array<string,mixed> */
    private static function integer(): array
    {
        return ['type' => 'integer', 'minimum' => 1];
    }

    /** @return array<string,mixed> */
    private static function string(int $maxLength): array
    {
        return ['type' => 'string', 'maxLength' => $maxLength];
    }

    private static function fallback(string $key): AssistantToolDefinition
    {
        return new AssistantToolDefinition(
            key: $key,
            schemaVersion: 'compat-1',
            labels: ['en' => $key, 'ar' => $key],
            mode: 'READ',
            requiredPermissions: [],
            permissionMode: 'all',
            channels: [],
            inputSchema: [
                'type' => 'object',
                'properties' => [],
                'additionalProperties' => true,
            ],
            resultSchema: self::resultSchema(),
            timeoutMs: 2500,
            rowLimit: 100,
            dataClass: 'INTERNAL',
            sellOutConsent: false,
            source: 'registered_compatibility_tool',
        );
    }
}
