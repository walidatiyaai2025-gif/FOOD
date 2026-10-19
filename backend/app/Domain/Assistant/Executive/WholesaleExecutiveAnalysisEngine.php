<?php

namespace App\Domain\Assistant\Executive;

final class WholesaleExecutiveAnalysisEngine
{
    private const MAX_CLIENT_FINDINGS = 3;

    /** @param array<string,mixed> $facts
     * @return array<string,mixed>
     */
    public function analyze(array $facts, string $locale = 'en', ?float $targetRevenue = null): array
    {
        $arabic = strtolower($locale) === 'ar';
        $sales = (array) ($facts['sales'] ?? []);
        $comparison = (array) ($facts['comparison'] ?? []);
        $currentCustomers = (array) ($facts['customers_current'] ?? []);
        $previousCustomers = (array) ($facts['customers_previous'] ?? []);
        $topProducts = (array) ($facts['top_products'] ?? []);
        $slowProducts = (array) ($facts['slow_products'] ?? []);
        $operations = (array) ($facts['operations'] ?? []);

        $current = (array) ($comparison['current'] ?? []);
        $previous = (array) ($comparison['previous'] ?? []);
        $window = [
            'from' => (string) ($current['from'] ?? $sales['from'] ?? ''),
            'to' => (string) ($current['to'] ?? $sales['to'] ?? ''),
        ];
        $previousWindow = [
            'from' => (string) ($previous['from'] ?? ''),
            'to' => (string) ($previous['to'] ?? ''),
        ];

        $currentRevenue = $this->number($current['recognized_revenue'] ?? $sales['recognized_revenue'] ?? 0);
        $previousRevenue = $this->number($previous['recognized_revenue'] ?? 0);
        $changePercent = $this->nullableNumber($comparison['recognized_revenue_change_percent'] ?? null);
        $findings = [];

        if ($currentRevenue <= 0.0) {
            $findings[] = $this->finding(
                'no_wholesale_activity',
                $previousRevenue > 0.0 ? 100 : 70,
                $previousRevenue > 0.0 ? 'critical' : 'warning',
                $arabic ? 'لا توجد مبيعات جملة معتمدة في الفترة الحالية' : 'No recognized wholesale sales in the current period',
                [[
                    'metric' => 'recognized_revenue',
                    'value' => $currentRevenue,
                    'unit' => (string) ($sales['currency'] ?? 'EGP'),
                    'window' => $window,
                    'entity' => $this->scopeEntity($sales),
                    'source' => 'sales.summary',
                ]],
                ['route_name' => 'admin.reports.index'],
            );
        } elseif ($changePercent !== null && $changePercent < 0.0) {
            $score = $changePercent <= -20.0 ? 95 : ($changePercent <= -5.0 ? 82 : 65);
            $findings[] = $this->finding(
                'sell_in_revenue_decline',
                $score,
                $score >= 90 ? 'critical' : ($score >= 80 ? 'warning' : 'watch'),
                $arabic ? 'إيراد البيع بالجملة أقل من الفترة السابقة' : 'Wholesale sell-in revenue is below the prior period',
                [
                    [
                        'metric' => 'recognized_revenue',
                        'value' => $currentRevenue,
                        'unit' => (string) ($sales['currency'] ?? 'EGP'),
                        'window' => $window,
                        'entity' => $this->scopeEntity($sales),
                        'source' => 'sales.compare',
                    ],
                    [
                        'metric' => 'prior_recognized_revenue',
                        'value' => $previousRevenue,
                        'unit' => (string) ($sales['currency'] ?? 'EGP'),
                        'window' => $previousWindow,
                        'entity' => $this->scopeEntity($sales),
                        'source' => 'sales.compare',
                    ],
                    [
                        'metric' => 'recognized_revenue_change_percent',
                        'value' => $changePercent,
                        'unit' => 'percent',
                        'window' => $window,
                        'entity' => $this->scopeEntity($sales),
                        'source' => 'sales.compare',
                    ],
                ],
                ['route_name' => 'admin.reports.index'],
            );
        }

        $targetComparison = $this->targetComparison($currentRevenue, $targetRevenue, $sales, $window);
        if (($targetComparison['state'] ?? null) === 'configured' && ($targetComparison['attainment_percent'] ?? 100) < 100) {
            $attainment = (float) $targetComparison['attainment_percent'];
            $findings[] = $this->finding(
                'revenue_below_target',
                $attainment < 70 ? 92 : 78,
                $attainment < 70 ? 'critical' : 'warning',
                $arabic ? 'إيراد الجملة أقل من الهدف المحدد' : 'Wholesale revenue is below the configured target',
                [[
                    'metric' => 'target_attainment_percent',
                    'value' => $attainment,
                    'unit' => 'percent',
                    'window' => $window,
                    'entity' => $this->scopeEntity($sales),
                    'source' => 'executive.wholesale',
                ]],
                ['route_name' => 'admin.reports.index'],
            );
        }

        $cancelledOrders = (int) ($sales['cancelled_orders'] ?? 0);
        $totalForRate = max(0, (int) ($sales['orders'] ?? 0));
        $cancellationRate = $totalForRate === 0 ? 0.0 : round(($cancelledOrders / $totalForRate) * 100, 1);
        if ($cancelledOrders > 0) {
            $score = $cancellationRate >= 10.0 ? 88 : ($cancellationRate >= 5.0 ? 76 : 58);
            $findings[] = $this->finding(
                'wholesale_cancellations',
                $score,
                $score >= 85 ? 'critical' : ($score >= 70 ? 'warning' : 'watch'),
                $arabic ? 'هناك طلبات جملة ملغاة تحتاج مراجعة' : 'Wholesale cancellations need review',
                [
                    [
                        'metric' => 'cancelled_orders',
                        'value' => $cancelledOrders,
                        'unit' => 'orders',
                        'window' => $window,
                        'entity' => $this->scopeEntity($sales),
                        'source' => 'sales.summary',
                    ],
                    [
                        'metric' => 'cancellation_rate_percent',
                        'value' => $cancellationRate,
                        'unit' => 'percent',
                        'window' => $window,
                        'entity' => $this->scopeEntity($sales),
                        'source' => 'executive.wholesale',
                    ],
                ],
                ['route_name' => 'admin.operations.orders.index'],
            );
        }

        $refundedOrders = (int) ($sales['refunded_orders'] ?? 0);
        if ($refundedOrders > 0) {
            $findings[] = $this->finding(
                'wholesale_refunds',
                64,
                'watch',
                $arabic ? 'توجد مرتجعات في مبيعات الجملة' : 'Wholesale refunds are present',
                [[
                    'metric' => 'refunded_orders',
                    'value' => $refundedOrders,
                    'unit' => 'orders',
                    'window' => $window,
                    'entity' => $this->scopeEntity($sales),
                    'source' => 'sales.summary',
                ]],
                ['route_name' => 'admin.reports.index'],
            );
        }

        $clientCoverage = $this->clientCoverage($currentCustomers, $previousCustomers);
        $lostMomentum = $this->lostMomentum($currentCustomers, $previousCustomers, $clientCoverage, $window, $previousWindow);
        foreach (array_slice($lostMomentum, 0, self::MAX_CLIENT_FINDINGS) as $row) {
            $findings[] = $this->finding(
                'client_lost_momentum:'.(string) $row['customer_id'],
                (int) $row['priority_score'],
                (string) $row['severity'],
                $arabic
                    ? 'عميل جملة فقد زخمه: '.(string) $row['customer']
                    : 'Wholesale client lost momentum: '.(string) $row['customer'],
                $row['evidence'],
                ['route_name' => 'admin.b2b.module', 'route_parameters' => ['module' => 'customers']],
            );
        }

        $sections = (array) ($operations['sections'] ?? []);
        $late = is_array($sections['late_orders'] ?? null) ? $sections['late_orders'] : null;
        if ($late !== null && (int) ($late['late_orders'] ?? 0) > 0) {
            $count = (int) $late['late_orders'];
            $findings[] = $this->finding(
                'late_wholesale_orders',
                $count >= 5 ? 90 : 74,
                $count >= 5 ? 'critical' : 'warning',
                $arabic ? 'طلبات جملة متأخرة تحتاج تدخلاً' : 'Late wholesale orders need intervention',
                [[
                    'metric' => 'late_orders',
                    'value' => $count,
                    'unit' => 'orders',
                    'window' => ['as_of' => (string) ($late['as_of'] ?? '')],
                    'entity' => $this->scopeEntity($sales),
                    'source' => 'brief.daily',
                ]],
                ['route_name' => 'admin.operations.orders.index'],
            );
        }

        $inventory = is_array($sections['inventory'] ?? null) ? $sections['inventory'] : null;
        $stockExposure = $this->stockExposure($inventory);
        if (($stockExposure['alert_count'] ?? 0) > 0) {
            $findings[] = $this->finding(
                'stock_exposure',
                ($stockExposure['out_of_stock_count'] ?? 0) > 0 ? 91 : 72,
                ($stockExposure['out_of_stock_count'] ?? 0) > 0 ? 'critical' : 'warning',
                $arabic ? 'تعرض مخزون قد يؤثر على مبيعات الجملة' : 'Inventory exposure may affect wholesale sales',
                [[
                    'metric' => 'low_stock_alerts',
                    'value' => (int) $stockExposure['alert_count'],
                    'unit' => 'skus',
                    'window' => ['as_of' => (string) ($operations['generated_at'] ?? '')],
                    'entity' => $this->scopeEntity($sales),
                    'source' => 'brief.daily',
                ]],
                ['route_name' => 'admin.erp.wms.index', 'route_parameters' => ['tab' => 'reorder']],
            );
        }

        $slowRows = array_values((array) ($slowProducts['products'] ?? []));
        $topRows = array_values((array) ($topProducts['products'] ?? []));
        if ($slowRows !== [] && $topRows !== []) {
            $slowest = $slowRows[0];
            $top = $topRows[0];
            $topRevenue = $this->number($top['revenue'] ?? 0);
            $slowRevenue = $this->number($slowest['revenue'] ?? 0);
            if ($topRevenue > 0 && $slowRevenue <= ($topRevenue * 0.10)) {
                $findings[] = $this->finding(
                    'slow_sku:'.(string) ($slowest['product_id'] ?? 0),
                    52,
                    'watch',
                    $arabic
                        ? 'صنف جملة بطيء مقارنة بأفضل الأصناف: '.(string) ($slowest['name'] ?? '')
                        : 'Slow wholesale SKU versus top sellers: '.(string) ($slowest['name'] ?? ''),
                    [[
                        'metric' => 'product_revenue',
                        'value' => $slowRevenue,
                        'unit' => (string) ($slowProducts['currency'] ?? 'EGP'),
                        'window' => $window,
                        'entity' => [
                            'type' => 'product',
                            'id' => (int) ($slowest['product_id'] ?? 0),
                            'sku' => (string) ($slowest['sku'] ?? ''),
                            'name' => (string) ($slowest['name'] ?? ''),
                        ],
                        'source' => 'products.performance',
                    ]],
                    ['route_name' => 'admin.catalog.index'],
                );
            }
        }

        usort($findings, static function (array $left, array $right): int {
            $score = ($right['_priority'] ?? 0) <=> ($left['_priority'] ?? 0);

            return $score !== 0 ? $score : strcmp((string) $left['code'], (string) $right['code']);
        });

        $ranked = [];
        foreach ($findings as $index => $finding) {
            unset($finding['_priority']);
            $finding['rank'] = $index + 1;
            $ranked[] = $finding;
        }

        $recommendations = array_map(
            fn (array $finding): array => $this->recommendation($finding, $arabic),
            $ranked,
        );

        $bottomClients = [];
        if ($clientCoverage['current'] === 'complete') {
            $bottomClients = array_values((array) ($currentCustomers['top_customers'] ?? []));
            usort(
                $bottomClients,
                static fn (array $a, array $b): int => ((float) ($a['recognized_revenue'] ?? 0))
                    <=> ((float) ($b['recognized_revenue'] ?? 0)),
            );
            $bottomClients = array_slice($bottomClients, 0, 5);
        }

        $pending = [
            'late_orders' => (int) ($late['late_orders'] ?? 0),
            'cancelled_orders' => $cancelledOrders,
            'refunds' => $refundedOrders,
            'low_stock_alerts' => (int) ($stockExposure['alert_count'] ?? 0),
            'out_of_stock_alerts' => (int) ($stockExposure['out_of_stock_count'] ?? 0),
        ];

        return [
            'analysis_type' => 'wholesale_executive_intelligence',
            'channel' => 'b2b',
            'flow' => 'sell_in',
            'window' => $window,
            'previous_window' => $previousWindow,
            'executive_summary' => $this->summary(count($ranked), $currentRevenue, $changePercent, $arabic),
            'performance' => [
                'current' => $current,
                'previous' => $previous,
                'recognized_revenue_change_percent' => $changePercent,
            ],
            'target_comparison' => $targetComparison,
            'top_clients' => array_slice(array_values((array) ($currentCustomers['top_customers'] ?? [])), 0, 5),
            'bottom_clients' => $bottomClients,
            'client_ranking_coverage' => $clientCoverage,
            'lost_momentum_clients' => $lostMomentum,
            'top_skus' => array_slice($topRows, 0, 5),
            'slow_skus' => array_slice($slowRows, 0, 5),
            'stock_exposure' => $stockExposure,
            'pending_wholesale_issues' => $pending,
            'findings' => $ranked,
            'recommendations' => $recommendations,
            'sell_out' => [
                'state' => 'not_combined',
                'flow' => 'sell_out',
                'requires_explicit_retailer_consent' => true,
                'approved_tool' => 'retailers.performance',
                'message' => $arabic
                    ? 'بيانات بيع التجزئة للمستهلك لا تُدمج مع البيع بالجملة، وتُقرأ فقط بموافقة صريحة وسارية.'
                    : 'Retailer sell-out is never pooled with wholesale sell-in and requires explicit active retailer consent.',
            ],
            'query_patterns' => $this->queryPatterns($arabic),
            'quality' => [
                'state' => in_array('bounded', $clientCoverage, true) ? 'partial' : 'complete',
                'model_arithmetic_allowed' => false,
                'facts_are_server_computed' => true,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function targetComparison(
        float $currentRevenue,
        ?float $targetRevenue,
        array $sales,
        array $window,
    ): array {
        if ($targetRevenue === null) {
            return [
                'state' => 'unavailable',
                'reason' => 'no_target_configured_or_supplied',
                'fabricated_target_allowed' => false,
            ];
        }

        $target = max(0.0, round($targetRevenue, 3));
        $gap = round($currentRevenue - $target, 3);

        return [
            'state' => 'configured',
            'metric' => 'recognized_revenue',
            'actual' => $currentRevenue,
            'target' => $target,
            'gap' => $gap,
            'attainment_percent' => $target <= 0.0 ? 100.0 : round(($currentRevenue / $target) * 100, 1),
            'currency' => (string) ($sales['currency'] ?? 'EGP'),
            'window' => $window,
        ];
    }

    /** @return array{current:string,previous:string} */
    private function clientCoverage(array $current, array $previous): array
    {
        return [
            'current' => (int) ($current['active_customers'] ?? 0) <= count((array) ($current['top_customers'] ?? []))
                ? 'complete'
                : 'bounded',
            'previous' => (int) ($previous['active_customers'] ?? 0) <= count((array) ($previous['top_customers'] ?? []))
                ? 'complete'
                : 'bounded',
        ];
    }

    /** @return list<array<string,mixed>> */
    private function lostMomentum(
        array $current,
        array $previous,
        array $coverage,
        array $window,
        array $previousWindow,
    ): array {
        if (($coverage['current'] ?? null) !== 'complete' || ($coverage['previous'] ?? null) !== 'complete') {
            return [];
        }

        $currentById = [];
        foreach ((array) ($current['top_customers'] ?? []) as $row) {
            $currentById[(int) ($row['customer_id'] ?? 0)] = $row;
        }

        $lost = [];
        foreach ((array) ($previous['top_customers'] ?? []) as $row) {
            $id = (int) ($row['customer_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $previousRevenue = $this->number($row['recognized_revenue'] ?? 0);
            $currentRevenue = $this->number($currentById[$id]['recognized_revenue'] ?? 0);
            if ($previousRevenue <= 0.0 || $currentRevenue >= $previousRevenue) {
                continue;
            }

            $drop = round($previousRevenue - $currentRevenue, 3);
            $dropPercent = round(($drop / $previousRevenue) * 100, 1);
            $score = $dropPercent >= 80.0 ? 86 : ($dropPercent >= 40.0 ? 73 : 56);
            $lost[] = [
                'customer_id' => $id,
                'customer' => (string) ($row['customer'] ?? ''),
                'previous_revenue' => $previousRevenue,
                'current_revenue' => $currentRevenue,
                'drop' => $drop,
                'drop_percent' => $dropPercent,
                'priority_score' => $score,
                'severity' => $score >= 85 ? 'critical' : ($score >= 70 ? 'warning' : 'watch'),
                'evidence' => [
                    [
                        'metric' => 'customer_recognized_revenue',
                        'value' => $currentRevenue,
                        'unit' => 'EGP',
                        'window' => $window,
                        'entity' => ['type' => 'customer', 'id' => $id, 'name' => (string) ($row['customer'] ?? '')],
                        'source' => 'customers.summary',
                    ],
                    [
                        'metric' => 'prior_customer_recognized_revenue',
                        'value' => $previousRevenue,
                        'unit' => 'EGP',
                        'window' => $previousWindow,
                        'entity' => ['type' => 'customer', 'id' => $id, 'name' => (string) ($row['customer'] ?? '')],
                        'source' => 'customers.summary',
                    ],
                ],
            ];
        }

        usort(
            $lost,
            static fn (array $a, array $b): int => ($b['drop'] <=> $a['drop']) ?: ($a['customer_id'] <=> $b['customer_id']),
        );

        return $lost;
    }

    /** @return array<string,mixed> */
    private function stockExposure(?array $inventory): array
    {
        if ($inventory === null) {
            return [
                'state' => 'unavailable_for_role',
                'alert_count' => 0,
                'out_of_stock_count' => 0,
                'alerts' => [],
            ];
        }

        $alerts = array_values((array) ($inventory['alerts'] ?? []));
        $out = count(array_filter(
            $alerts,
            static fn (array $row): bool => (float) ($row['available_quantity'] ?? 0) <= 0.0,
        ));

        return [
            'state' => 'available',
            'threshold' => $inventory['threshold'] ?? null,
            'alert_count' => (int) ($inventory['alert_count'] ?? count($alerts)),
            'out_of_stock_count' => $out,
            'alerts' => array_slice($alerts, 0, 10),
            'truncated' => (bool) ($inventory['truncated'] ?? false),
        ];
    }

    /** @param list<array<string,mixed>> $evidence
     * @param  array<string,mixed>  $drillthrough
     * @return array<string,mixed>
     */
    private function finding(
        string $code,
        int $priority,
        string $severity,
        string $title,
        array $evidence,
        array $drillthrough,
    ): array {
        return [
            'code' => $code,
            'kind' => 'fact',
            'severity' => $severity,
            'title' => $title,
            'evidence' => $evidence,
            'drillthrough' => $drillthrough,
            '_priority' => $priority,
        ];
    }

    /** @return array<string,mixed> */
    private function recommendation(array $finding, bool $arabic): array
    {
        $code = explode(':', (string) ($finding['code'] ?? ''), 2)[0];
        $text = match ($code) {
            'sell_in_revenue_decline', 'no_wholesale_activity' => $arabic
                ? 'راجع العملاء والأصناف التي فقدت الزخم قبل تعديل الأسعار أو العروض.'
                : 'Review clients and SKUs that lost momentum before changing prices or promotions.',
            'revenue_below_target' => $arabic
                ? 'ركز التدخل على أكبر فجوات العملاء والأصناف حتى يتحسن تحقيق الهدف.'
                : 'Focus intervention on the largest client and SKU gaps until target attainment improves.',
            'wholesale_cancellations', 'wholesale_refunds' => $arabic
                ? 'راجع أسباب الإلغاء أو المرتجعات والطلبات المتأثرة في شاشة الطلبات.'
                : 'Review cancellation/refund causes and affected orders in the authoritative orders view.',
            'late_wholesale_orders' => $arabic
                ? 'ابدأ بأقدم طلبات الجملة المتأخرة وحدد عائق التنفيذ.'
                : 'Start with the oldest late wholesale orders and identify the fulfilment blocker.',
            'stock_exposure' => $arabic
                ? 'أمّن الأصناف النافدة أو منخفضة المخزون المرتبطة بالمبيعات قبل الترويج لها.'
                : 'Secure out-of-stock or low-stock SKUs tied to sales before promoting them.',
            'client_lost_momentum' => $arabic
                ? 'راجع نشاط العميل والطلبات السابقة وحدد سبب تراجع الشراء قبل التواصل.'
                : 'Review the client activity and prior orders to diagnose the purchase decline before outreach.',
            'slow_sku' => $arabic
                ? 'راجع التسعير والتوفر والطلب على الصنف البطيء قبل زيادة المخزون.'
                : 'Review price, availability and demand for the slow SKU before increasing inventory.',
            default => $arabic ? 'راجع الدليل قبل اتخاذ أي إجراء.' : 'Review the evidence before taking action.',
        };

        return [
            'kind' => 'recommendation',
            'based_on' => (string) ($finding['code'] ?? ''),
            'rank' => (int) ($finding['rank'] ?? 0),
            'text' => $text,
            'drillthrough' => $finding['drillthrough'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function scopeEntity(array $sales): array
    {
        return [
            'type' => 'wholesale_scope',
            'store_ids' => array_values((array) ($sales['store_ids'] ?? [])),
            'channel' => 'b2b',
            'flow' => 'sell_in',
        ];
    }

    /** @return list<array{label:string,prompt:string}> */
    private function queryPatterns(bool $arabic): array
    {
        if ($arabic) {
            return [
                ['label' => 'اليوم', 'prompt' => 'حلل مبيعات الجملة اليوم ورتب أين يجب أن أتدخل.'],
                ['label' => 'الأسبوع', 'prompt' => 'قارن أداء الجملة هذا الأسبوع بالفترة السابقة وحدد العملاء والأصناف التي فقدت الزخم.'],
                ['label' => 'الشهر', 'prompt' => 'حلل أداء مبيعات الجملة هذا الشهر وقارنه بالفترة السابقة ورتب أولويات التدخل.'],
                ['label' => 'المخزون', 'prompt' => 'ما مخاطر المخزون والطلبات المعلقة التي قد تؤثر على مبيعات الجملة؟'],
            ];
        }

        return [
            ['label' => 'Today', 'prompt' => 'Analyze wholesale today and rank where I should intervene.'],
            ['label' => 'Week', 'prompt' => 'Compare wholesale this week with the prior period and identify clients and SKUs losing momentum.'],
            ['label' => 'Month', 'prompt' => 'Analyze wholesale this month versus the prior period and rank intervention priorities.'],
            ['label' => 'Stock', 'prompt' => 'What stock exposure and pending issues could affect wholesale sales?'],
        ];
    }

    private function summary(int $findingCount, float $revenue, ?float $changePercent, bool $arabic): string
    {
        if ($findingCount === 0) {
            return $arabic
                ? 'لم تُكتشف أولويات تدخل جوهرية من البيانات المتاحة لهذه الفترة.'
                : 'No material intervention priorities were detected from the available data for this period.';
        }

        $change = $changePercent === null ? '' : sprintf(' (%+.1f%%)', $changePercent);

        return $arabic
            ? sprintf('تم رصد %d أولوية تدخل. إيراد البيع بالجملة المعتمد %.3f%s.', $findingCount, $revenue, $change)
            : sprintf('%d intervention priorities detected. Recognized wholesale sell-in revenue is %.3f%s.', $findingCount, $revenue, $change);
    }

    private function number(mixed $value): float
    {
        return is_numeric($value) ? round((float) $value, 3) : 0.0;
    }

    private function nullableNumber(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 3) : null;
    }
}
