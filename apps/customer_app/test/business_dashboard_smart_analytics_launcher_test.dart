import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/features/b2b/business_dashboard_smart_analytics_launcher.dart';

Widget _app({
  required bool hasNewInsights,
  required bool reducedMotion,
  required TextDirection direction,
  required String label,
  required VoidCallback onTap,
  double width = 390,
}) =>
    MaterialApp(
      home: MediaQuery(
        data: MediaQueryData(
          size: Size(width, 800),
          disableAnimations: reducedMotion,
        ),
        child: Directionality(
          textDirection: direction,
          child: Scaffold(
            body: Align(
              alignment: Alignment.topCenter,
              child: BusinessDashboardSmartAnalyticsLauncher(
                semanticLabel: label,
                hasNewInsights: hasNewInsights,
                onTap: onTap,
              ),
            ),
          ),
        ),
      ),
    );

void main() {
  testWidgets('launcher keeps an accessible target and badge follows unread state',
      (tester) async {
    var taps = 0;
    await tester.pumpWidget(
      _app(
        hasNewInsights: true,
        reducedMotion: false,
        direction: TextDirection.ltr,
        label: 'Smart Store Analytics',
        onTap: () => taps++,
      ),
    );

    final target = tester.getSize(
      find.byKey(
        const ValueKey('business-smart-analytics-launcher-target'),
      ),
    );
    expect(target.width, greaterThanOrEqualTo(44));
    expect(target.height, greaterThanOrEqualTo(44));
    expect(
      find.byKey(
        const ValueKey('business-smart-analytics-launcher-badge'),
      ),
      findsOneWidget,
    );
    expect(
      find.bySemanticsLabel('Smart Store Analytics'),
      findsOneWidget,
    );

    await tester.tap(
      find.byKey(
        const ValueKey('business-smart-analytics-launcher'),
      ),
    );
    expect(taps, 1);

    await tester.pumpWidget(
      _app(
        hasNewInsights: false,
        reducedMotion: false,
        direction: TextDirection.rtl,
        label: 'التحليل الذكي للمتجر',
        onTap: () {},
      ),
    );
    expect(
      find.byKey(
        const ValueKey('business-smart-analytics-launcher-badge'),
      ),
      findsNothing,
    );
    expect(
      find.bySemanticsLabel('التحليل الذكي للمتجر'),
      findsOneWidget,
    );
  });

  testWidgets('pulse changes softly in normal motion and stays static when reduced',
      (tester) async {
    Future<Matrix4> transform() async {
      final widget = tester.widget<Transform>(
        find.byKey(
          const ValueKey('business-smart-analytics-launcher-pulse'),
        ),
      );
      return widget.transform;
    }

    await tester.pumpWidget(
      _app(
        hasNewInsights: true,
        reducedMotion: false,
        direction: TextDirection.ltr,
        label: 'Smart Store Analytics',
        onTap: () {},
      ),
    );
    final animatedBefore = await transform();
    await tester.pump(const Duration(milliseconds: 420));
    final animatedAfter = await transform();
    expect(animatedAfter, isNot(equals(animatedBefore)));

    await tester.pumpWidget(
      _app(
        hasNewInsights: true,
        reducedMotion: true,
        direction: TextDirection.ltr,
        label: 'Smart Store Analytics',
        onTap: () {},
      ),
    );
    await tester.pump();
    final reducedBefore = await transform();
    await tester.pump(const Duration(milliseconds: 700));
    final reducedAfter = await transform();
    expect(reducedAfter, equals(reducedBefore));
  });

  for (final width in const [320.0, 360.0, 390.0, 412.0, 430.0]) {
    testWidgets('launcher is overflow-safe at width $width', (tester) async {
      await tester.pumpWidget(
        _app(
          width: width,
          hasNewInsights: true,
          reducedMotion: false,
          direction: TextDirection.rtl,
          label: 'التحليل الذكي للمتجر',
          onTap: () {},
        ),
      );
      await tester.pump(const Duration(milliseconds: 50));
      expect(tester.takeException(), isNull);
    });
  }
}
