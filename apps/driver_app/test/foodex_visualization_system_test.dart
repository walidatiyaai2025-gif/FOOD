import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_visualization/foodex_visualization.dart';

void main() {
  test('shared visualization palette keeps canonical FOODEX semantics', () {
    expect(FoodexChartPalette.primary, const Color(0xFF158A3A));
    expect(FoodexChartPalette.warning, const Color(0xFFEE731C));
    expect(FoodexChartPalette.danger, const Color(0xFFEF5350));
    expect(FoodexChartPalette.series.length, greaterThanOrEqualTo(4));
  });

  for (final direction in TextDirection.values) {
    testWidgets('shared visualization primitives render in $direction', (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Directionality(
            textDirection: direction,
            child: SingleChildScrollView(
              child: Column(
                children: const [
                  SizedBox(
                    width: 320,
                    child: FoodexTrendChart(
                      values: [4, 8, 6, 11],
                      semanticLabel: 'Sales trend: up overall',
                    ),
                  ),
                  SizedBox(
                    width: 180,
                    child: FoodexSparkline(
                      values: [1, 3, 2, 5],
                      semanticLabel: 'Compact sales trend',
                    ),
                  ),
                  SizedBox(
                    width: 320,
                    child: FoodexBarChart(
                      groups: [
                        [3, 2],
                        [5, 1],
                        [4, 4],
                      ],
                      semanticLabel: 'Orders by channel',
                    ),
                  ),
                  FoodexDonutChart(
                    values: [50, 30, 20],
                    semanticLabel: 'Inventory risk composition',
                  ),
                  SizedBox(
                    width: 320,
                    child: FoodexProgressRange(
                      value: .62,
                      reorderAt: .45,
                      criticalAt: .22,
                      semanticLabel: 'Days of cover is 62 percent',
                    ),
                  ),
                  FoodexHeatmap(
                    values: [
                      [1, 2, 3],
                      [2, 4, 1],
                    ],
                    semanticLabel: 'Demand heatmap',
                  ),
                  FoodexChartLegend(labels: ['Retail', 'Wholesale', 'Risk']),
                ],
              ),
            ),
          ),
        ),
      );

      await tester.pump();
      expect(find.byType(FoodexTrendChart), findsOneWidget);
      expect(find.byType(FoodexSparkline), findsOneWidget);
      expect(find.byType(FoodexBarChart), findsOneWidget);
      expect(find.byType(FoodexDonutChart), findsOneWidget);
      expect(find.byType(FoodexProgressRange), findsOneWidget);
      expect(find.byType(FoodexHeatmap), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
}
