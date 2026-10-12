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
  testWidgets(
    'launcher keeps an accessible target and badge follows unread state',
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
    },
  );

  testWidgets(
    'pulse changes softly in normal motion and stays static when reduced',
    (tester) async {
      double scale() {
        final widget = tester.widget<Transform>(
          find.byKey(
            const ValueKey('business-smart-analytics-launcher-pulse'),
          ),
        );
        return widget.transform.getMaxScaleOnAxis();
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
      final animatedBefore = scale();
      await tester.pump(const Duration(milliseconds: 420));
      final animatedAfter = scale();
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
      final reducedBefore = scale();
      await tester.pump(const Duration(milliseconds: 700));
      final reducedAfter = scale();
      expect(reducedAfter, equals(reducedBefore));
    },
  );

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

  testWidgets(
    'store logo prefers authoritative branding and falls back to business initial',
    (tester) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: BusinessDashboardStoreLogo(
              storeName: 'Downtown Store',
              logoUrl: 'https://cdn.foodex.test/store.png',
            ),
          ),
        ),
      );
      await tester.pump();

      expect(
        find.byKey(const ValueKey('b2b-dashboard-store-logo')),
        findsOneWidget,
      );
      final image = tester.widget<Image>(find.byType(Image));
      expect(image.image, isA<NetworkImage>());
      expect(
        (image.image as NetworkImage).url,
        'https://cdn.foodex.test/store.png',
      );

      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: BusinessDashboardStoreLogo(storeName: 'Downtown Store'),
          ),
        ),
      );
      await tester.pump();

      expect(
        find.byKey(const ValueKey('b2b-dashboard-store-logo-fallback')),
        findsOneWidget,
      );
      expect(find.text('D'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );
}
