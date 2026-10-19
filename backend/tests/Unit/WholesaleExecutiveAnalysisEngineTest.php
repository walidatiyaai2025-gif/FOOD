<?php

namespace Tests\Unit;

use App\Domain\Assistant\Executive\WholesaleExecutiveAnalysisEngine;
use PHPUnit\Framework\TestCase;

final class WholesaleExecutiveAnalysisEngineTest extends TestCase
{
    public function test_it_ranks_fact_findings_with_evidence_and_keeps_recommendations_separate(): void
    {
        $result = (new WholesaleExecutiveAnalysisEngine)->analyze($this->facts(), 'en', 1000.0);

        $this->assertSame('b2b', $result['channel']);
        $this->assertSame('sell_in', $result['flow']);
        $this->assertSame('configured', $result['target_comparison']['state']);
        $this->assertSame(80.0, $result['target_comparison']['attainment_percent']);
        $this->assertSame('complete', $result['client_ranking_coverage']['current']);
        $this->assertSame('Client B', $result['bottom_clients'][0]['customer']);
        $this->assertSame('not_combined', $result['sell_out']['state']);
        $this->assertTrue($result['sell_out']['requires_explicit_retailer_consent']);
        $this->assertFalse($result['quality']['model_arithmetic_allowed']);

        $codes = array_column($result['findings'], 'code');
        $this->assertContains('sell_in_revenue_decline', $codes);
        $this->assertContains('revenue_below_target', $codes);
        $this->assertContains('late_wholesale_orders', $codes);
        $this->assertContains('stock_exposure', $codes);
        $this->assertContains('client_lost_momentum:101', $codes);
        $this->assertContains('slow_sku:202', $codes);

        foreach ($result['findings'] as $index => $finding) {
            $this->assertSame($index + 1, $finding['rank']);
            $this->assertSame('fact', $finding['kind']);
            $this->assertNotEmpty($finding['evidence']);
            $this->assertArrayHasKey('drillthrough', $finding);

            foreach ($finding['evidence'] as $evidence) {
                $this->assertArrayHasKey('metric', $evidence);
                $this->assertArrayHasKey('value', $evidence);
                $this->assertArrayHasKey('window', $evidence);
                $this->assertArrayHasKey('entity', $evidence);
                $this->assertArrayHasKey('source', $evidence);
            }
        }

        $this->assertCount(count($result['findings']), $result['recommendations']);
        foreach ($result['recommendations'] as $recommendation) {
            $this->assertSame('recommendation', $recommendation['kind']);
            $this->assertNotSame('', $recommendation['based_on']);
        }
    }

    public function test_it_localizes_arabic_output_and_never_fabricates_a_target(): void
    {
        $facts = $this->facts();
        $facts['comparison']['recognized_revenue_change_percent'] = null;

        $result = (new WholesaleExecutiveAnalysisEngine)->analyze($facts, 'ar');

        $this->assertSame('unavailable', $result['target_comparison']['state']);
        $this->assertFalse($result['target_comparison']['fabricated_target_allowed']);
        $this->assertStringContainsString('تم رصد', $result['executive_summary']);
        $this->assertStringContainsString('حلل', $result['query_patterns'][0]['prompt']);
        $this->assertStringContainsString('لا تُدمج', $result['sell_out']['message']);
    }

    public function test_it_marks_bounded_client_sources_partial_instead_of_inventing_bottom_or_momentum_rankings(): void
    {
        $facts = $this->facts();
        $facts['customers_current']['active_customers'] = 30;
        $facts['customers_previous']['active_customers'] = 40;
        $facts['operations']['sections'] = [];

        $result = (new WholesaleExecutiveAnalysisEngine)->analyze($facts);

        $this->assertSame('bounded', $result['client_ranking_coverage']['current']);
        $this->assertSame('bounded', $result['client_ranking_coverage']['previous']);
        $this->assertSame([], $result['bottom_clients']);
        $this->assertSame([], $result['lost_momentum_clients']);
        $this->assertSame('partial', $result['quality']['state']);
        $this->assertSame('unavailable_for_role', $result['stock_exposure']['state']);
    }

    public function test_empty_data_is_explicit_and_safe(): void
    {
        $result = (new WholesaleExecutiveAnalysisEngine)->analyze([]);

        $this->assertSame('sell_in', $result['flow']);
        $this->assertSame([], $result['top_clients']);
        $this->assertSame([], $result['top_skus']);
        $this->assertSame('unavailable', $result['target_comparison']['state']);
        $this->assertContains('no_wholesale_activity', array_column($result['findings'], 'code'));
        $this->assertSame(0, $result['pending_wholesale_issues']['late_orders']);
        $this->assertSame('not_combined', $result['sell_out']['state']);
    }

    /** @return array<string,mixed> */
    private function facts(): array
    {
        return [
            'sales' => [
                'from' => '2026-10-18',
                'to' => '2026-10-18',
                'recognized_revenue' => 800.0,
                'recognized_orders' => 8,
                'cancelled_orders' => 2,
                'refunded_orders' => 1,
                'currency' => 'EGP',
                'store_ids' => [1],
            ],
            'comparison' => [
                'current' => [
                    'from' => '2026-10-18',
                    'to' => '2026-10-18',
                    'recognized_revenue' => 800.0,
                ],
                'previous' => [
                    'from' => '2026-10-17',
                    'to' => '2026-10-17',
                    'recognized_revenue' => 1200.0,
                ],
                'recognized_revenue_change_percent' => -33.3,
            ],
            'customers_current' => [
                'active_customers' => 2,
                'top_customers' => [
                    ['customer_id' => 101, 'customer' => 'Client A', 'recognized_revenue' => 500.0],
                    ['customer_id' => 102, 'customer' => 'Client B', 'recognized_revenue' => 300.0],
                ],
            ],
            'customers_previous' => [
                'active_customers' => 2,
                'top_customers' => [
                    ['customer_id' => 101, 'customer' => 'Client A', 'recognized_revenue' => 900.0],
                    ['customer_id' => 102, 'customer' => 'Client B', 'recognized_revenue' => 300.0],
                ],
            ],
            'top_products' => [
                'currency' => 'EGP',
                'products' => [
                    ['product_id' => 201, 'sku' => 'TOP-1', 'name' => 'Top SKU', 'revenue' => 1000.0],
                ],
            ],
            'slow_products' => [
                'currency' => 'EGP',
                'products' => [
                    ['product_id' => 202, 'sku' => 'SLOW-1', 'name' => 'Slow SKU', 'revenue' => 50.0],
                ],
            ],
            'operations' => [
                'generated_at' => '2026-10-18T23:50:00+03:00',
                'sections' => [
                    'late_orders' => [
                        'as_of' => '2026-10-18T23:50:00+03:00',
                        'late_orders' => 3,
                    ],
                    'inventory' => [
                        'threshold' => 12,
                        'alert_count' => 2,
                        'truncated' => false,
                        'alerts' => [
                            ['product_id' => 201, 'available_quantity' => 0],
                            ['product_id' => 202, 'available_quantity' => 4],
                        ],
                    ],
                ],
            ],
        ];
    }
}
