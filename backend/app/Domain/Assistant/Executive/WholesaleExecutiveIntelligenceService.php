<?php

namespace App\Domain\Assistant\Executive;

use App\Domain\Assistant\Business\AssistantBusinessReadService;
use App\Domain\Assistant\Data\AssistantAction;
use App\Domain\Assistant\Data\AssistantToolRequest;
use App\Domain\Assistant\Data\AssistantToolResult;
use App\Domain\Assistant\Operations\AssistantOperationsReadService;

final readonly class WholesaleExecutiveIntelligenceService
{
    public function __construct(
        private AssistantBusinessReadService $business,
        private AssistantOperationsReadService $operations,
        private WholesaleExecutiveAnalysisEngine $analysis,
    ) {}

    public function execute(AssistantToolRequest $request): AssistantToolResult
    {
        $current = $this->request($request, [
            ...$request->entities,
            'channel' => 'b2b',
        ]);

        $sales = $this->business->execute('sales.summary', $current);
        $comparison = $this->business->execute('sales.compare', $current);
        $customers = $this->business->execute('customers.summary', $current);
        $topProducts = $this->business->execute('products.performance', $this->request($current, [
            ...$current->entities,
            'sort' => 'top',
            'limit' => 5,
        ]));
        $slowProducts = $this->business->execute('products.performance', $this->request($current, [
            ...$current->entities,
            'sort' => 'slow',
            'limit' => 5,
        ]));
        $operations = $this->operations->execute('brief.daily', $current);

        $previousWindow = (array) ($comparison->data['previous'] ?? []);
        $previousCustomers = $this->business->execute('customers.summary', $this->request($current, [
            ...$current->entities,
            'from' => (string) ($previousWindow['from'] ?? ''),
            'to' => (string) ($previousWindow['to'] ?? ''),
        ]));

        $targetRevenue = $this->nullableFloat($request->entities['target_revenue'] ?? null);
        $data = $this->analysis->analyze([
            'sales' => $sales->data,
            'comparison' => $comparison->data,
            'customers_current' => $customers->data,
            'customers_previous' => $previousCustomers->data,
            'top_products' => $topProducts->data,
            'slow_products' => $slowProducts->data,
            'operations' => $operations->data,
        ], $request->locale, $targetRevenue);

        return new AssistantToolResult(
            data: $data,
            cards: $this->cards($data, $request->locale),
            actions: $this->actions($request->locale, $operations->data),
            references: $this->references($data),
        );
    }

    /** @param array<string,mixed> $entities */
    private function request(AssistantToolRequest $base, array $entities): AssistantToolRequest
    {
        return new AssistantToolRequest(
            actorUserId: $base->actorUserId,
            locale: $base->locale,
            channel: 'b2b',
            storeId: $base->storeId,
            entities: $entities,
            context: [
                ...$base->context,
                'channel' => 'b2b',
            ],
        );
    }

    /** @param array<string,mixed> $operations
     * @return list<AssistantAction>
     */
    private function actions(string $locale, array $operations): array
    {
        $actions = [
            new AssistantAction(
                label: $this->localized($locale, 'Open wholesale reports', 'فتح تقارير الجملة'),
                routeName: 'admin.reports.index',
            ),
            new AssistantAction(
                label: $this->localized($locale, 'Open catalog', 'فتح الكتالوج'),
                routeName: 'admin.catalog.index',
            ),
        ];

        $sections = is_array($operations['sections'] ?? null) ? $operations['sections'] : [];
        if (is_array($sections['late_orders'] ?? null) || is_array($sections['cancellations'] ?? null)) {
            $actions[] = new AssistantAction(
                label: $this->localized($locale, 'Open wholesale orders', 'فتح طلبات الجملة'),
                routeName: 'admin.b2b.module',
                routeParameters: ['module' => 'orders'],
            );
        }

        if (is_array($sections['inventory'] ?? null)) {
            $actions[] = new AssistantAction(
                label: $this->localized($locale, 'Open warehouse inventory', 'فتح مخزون المستودعات'),
                routeName: 'admin.erp.wms.index',
                routeParameters: ['tab' => 'reorder'],
            );
        }

        return $actions;
    }

    /** @return list<array<string,mixed>> */
    private function cards(array $data, string $locale): array
    {
        $arabic = strtolower($locale) === 'ar';
        $performanceGroup = is_array($data['performance'] ?? null) ? $data['performance'] : [];
        $performance = is_array($performanceGroup['current'] ?? null) ? $performanceGroup['current'] : [];
        $target = is_array($data['target_comparison'] ?? null) ? $data['target_comparison'] : [];

        return [
            [
                'type' => 'metric',
                'label' => $arabic ? 'إيراد الجملة' : 'Wholesale revenue',
                'value' => (float) ($performance['recognized_revenue'] ?? 0),
                'currency' => 'EGP',
                'flow' => 'sell_in',
            ],
            [
                'type' => 'metric',
                'label' => $arabic ? 'أولويات التدخل' : 'Intervention priorities',
                'value' => count((array) ($data['findings'] ?? [])),
            ],
            [
                'type' => 'status',
                'label' => $arabic ? 'مقارنة الهدف' : 'Target comparison',
                'value' => (string) ($target['state'] ?? 'unavailable'),
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function references(array $data): array
    {
        $references = [];
        foreach (array_slice((array) ($data['top_clients'] ?? []), 0, 5) as $client) {
            $id = (int) ($client['customer_id'] ?? 0);
            if ($id > 0) {
                $references[] = [
                    'type' => 'customer',
                    'id' => $id,
                    'channel' => 'b2b',
                ];
            }
        }

        foreach (array_slice((array) ($data['top_skus'] ?? []), 0, 5) as $product) {
            $id = (int) ($product['product_id'] ?? 0);
            if ($id > 0) {
                $references[] = [
                    'type' => 'product',
                    'id' => $id,
                    'sku' => (string) ($product['sku'] ?? ''),
                ];
            }
        }

        return $references;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? max(0.0, round((float) $value, 3)) : null;
    }

    private function localized(string $locale, string $english, string $arabic): string
    {
        return strtolower($locale) === 'ar' ? $arabic : $english;
    }
}
