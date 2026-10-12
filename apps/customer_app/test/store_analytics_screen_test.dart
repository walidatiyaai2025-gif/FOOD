import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/theme/foodex_theme.dart';
import 'package:foodex_customer_app/features/store_analytics/store_analytics_screen.dart';
import 'package:foodex_visualization/foodex_visualization.dart';

const _stores = <StoreAnalyticsStoreOption>[
  StoreAnalyticsStoreOption(id: 7, name: 'Downtown Grocery'),
  StoreAnalyticsStoreOption(id: 8, name: 'Second Retail Store'),
];

StoreAnalyticsCopy _copy(bool arabic) => arabic
    ? const StoreAnalyticsCopy(
        title: 'تحليل المتجر',
        revenue: 'المبيعات',
        orders: 'الطلبات',
        averageOrderValue: 'متوسط الطلب',
        comparison: 'مقارنة',
        vsPrevious: 'مقابل الفترة السابقة',
        noComparison: 'لا توجد مقارنة',
        salesTrend: 'اتجاه المبيعات',
        ordersTrend: 'الطلبات اليومية',
        trendSummary: 'المبيعات والطلبات حسب اليوم',
        insights: 'أهم الملاحظات',
        currencyEgp: 'ج.م',
        stale: 'تعرض الشاشة آخر بيانات مؤكدة وقد تحتاج إلى تحديث.',
        storeSelector: 'اختر متجر التجزئة',
        loadingLabel: 'جارٍ تحميل تحليل المتجر',
        emptyTitle: 'لا توجد بيانات تحليلية',
        emptyMessage: 'لا توجد طلبات أو بيانات كافية للفترة المحددة.',
        errorTitle: 'تعذر تحميل تحليل المتجر',
        errorMessage: 'حاول التحديث مرة أخرى.',
        noEntitlementTitle: 'تحليل المتجر غير متاح',
        noEntitlementMessage: 'لا تملك صلاحية تحليل متجر التجزئة المحدد.',
        retry: 'إعادة المحاولة',
        refresh: 'تحديث',
        storeLogo: 'شعار المتجر',
        ordersUnit: 'طلب',
      )
    : const StoreAnalyticsCopy(
        title: 'Store Analytics',
        revenue: 'Sales',
        orders: 'Orders',
        averageOrderValue: 'Average order value',
        comparison: 'Comparison',
        vsPrevious: 'vs previous period',
        noComparison: 'No comparison',
        salesTrend: 'Sales trend',
        ordersTrend: 'Daily orders',
        trendSummary: 'Sales and orders by day',
        insights: 'Key insights',
        currencyEgp: 'EGP',
        stale: 'Showing the last confirmed data. Refresh may be required.',
        storeSelector: 'Select Retail store',
        loadingLabel: 'Loading Store Analytics',
        emptyTitle: 'No analytics data',
        emptyMessage: 'No orders or useful analytics exist for this period.',
        errorTitle: 'Store Analytics could not load',
        errorMessage: 'Try refreshing again.',
        noEntitlementTitle: 'Store Analytics unavailable',
        noEntitlementMessage:
            'You are not entitled to analytics for the selected Retail store.',
        retry: 'Retry',
        refresh: 'Refresh',
        storeLogo: 'Store logo',
        ordersUnit: 'orders',
      );

StoreAnalyticsData _data({
  bool stale = false,
  String? logoUrl,
  String storeName =
      'Downtown Grocery and Fresh Food Market with a deliberately long name',
}) =>
    StoreAnalyticsData(
      storeId: 7,
      storeName: storeName,
      logoUrl: logoUrl,
      currency: 'EGP',
      periodLabel: '06 Oct - 12 Oct 2026',
      freshnessLabel: stale ? 'Updated 26 hours ago' : 'Updated 5 minutes ago',
      revenue: 18425.75,
      orders: 53,
      averageOrderValue: 347.66,
      revenueDeltaPercent: -8.4,
      isStale: stale,
      dailySeries: const [
        StoreAnalyticsDailyPoint(dateLabel: '06 Oct', sales: 1900, orders: 6),
        StoreAnalyticsDailyPoint(dateLabel: '07 Oct', sales: 2700, orders: 8),
        StoreAnalyticsDailyPoint(dateLabel: '08 Oct', sales: 2310, orders: 7),
        StoreAnalyticsDailyPoint(dateLabel: '09 Oct', sales: 3125, orders: 9),
        StoreAnalyticsDailyPoint(dateLabel: '10 Oct', sales: 2880, orders: 8),
        StoreAnalyticsDailyPoint(dateLabel: '11 Oct', sales: 2510, orders: 7),
        StoreAnalyticsDailyPoint(dateLabel: '12 Oct', sales: 3000.75, orders: 8),
      ],
      insights: const [
        StoreAnalyticsInsight(
          id: 'cancelled_orders',
          title: 'Cancellation attention',
          body: '3 cancelled orders in the selected period.',
          tone: StoreAnalyticsInsightTone.warning,
          actionLabel: 'Open orders',
          targetRoute: '/orders?channel=retail&store_id=7',
        ),
        StoreAnalyticsInsight(
          id: 'stock_attention',
          title: 'Stock attention',
          body: 'Two products need stock attention.',
          tone: StoreAnalyticsInsightTone.warning,
        ),
        StoreAnalyticsInsight(
          id: 'revenue_change',
          title: 'Revenue changed',
          body: 'Revenue changed compared with the previous period.',
        ),
        StoreAnalyticsInsight(
          id: 'top_product',
          title: 'Top contribution',
          body: 'Fresh Milk contributed the most revenue.',
          tone: StoreAnalyticsInsightTone.positive,
        ),
        StoreAnalyticsInsight(
          id: 'ignored_fifth',
          title: 'Fifth item',
          body: 'The compact surface must cap visible insights at four.',
        ),
      ],
    );

Widget _app({
  required Size size,
  required bool arabic,
  StoreAnalyticsStatus status = StoreAnalyticsStatus.loaded,
  StoreAnalyticsData? data,
  List<StoreAnalyticsStoreOption> stores = _stores,
  ValueChanged<int>? onStoreChanged,
  VoidCallback? onRetry,
  Future<void> Function()? onRefresh,
  ValueChanged<String>? onDrillDown,
}) {
  return MaterialApp(
    theme: FoodexTheme.light(),
    home: MediaQuery(
      data: MediaQueryData(
        size: size,
        textScaler: const TextScaler.linear(1.15),
      ),
      child: Directionality(
        textDirection: arabic ? TextDirection.rtl : TextDirection.ltr,
        child: StoreAnalyticsScreen(
          copy: _copy(arabic),
          status: status,
          data: data,
          storeOptions: stores,
          selectedStoreId: data?.storeId,
          onStoreChanged: onStoreChanged,
          onRetry: onRetry,
          onRefresh: onRefresh,
          onDrillDown: onDrillDown,
        ),
      ),
    ),
  );
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  for (final width in const [320.0, 360.0, 390.0, 412.0, 430.0]) {
    for (final arabic in const [true, false]) {
      testWidgets(
        'Store Analytics fits width ' +
            width.toInt().toString() +
            ' in ' +
            (arabic ? 'AR RTL' : 'EN LTR'),
        (tester) async {
          final size = Size(width, width <= 360 ? 800 : 932);
          tester.view.physicalSize = size;
          tester.view.devicePixelRatio = 1;
          addTearDown(() {
            tester.view.resetPhysicalSize();
            tester.view.resetDevicePixelRatio();
          });

          await tester.pumpWidget(
            _app(
              size: size,
              arabic: arabic,
              data: _data(),
              onRefresh: () async {},
            ),
          );
          await tester.pump();

          expect(
            find.byKey(const ValueKey('store-analytics-screen')),
            findsOneWidget,
          );
          expect(
            find.byKey(const ValueKey('store-analytics-kpis')),
            findsOneWidget,
          );
          expect(find.byType(FoodexTrendChart), findsOneWidget);
          expect(find.byType(FoodexBarChart), findsOneWidget);
          expect(
            find.byKey(const ValueKey('store-analytics-store-selector')),
            findsOneWidget,
          );
          expect(
            find.byKey(const ValueKey('store-analytics-logo-fallback')),
            findsOneWidget,
          );
          expect(
            find.textContaining(arabic ? 'ج.م' : 'EGP'),
            findsWidgets,
          );
          expect(tester.takeException(), isNull);
        },
      );
    }
  }

  testWidgets('stale data is explicit and missing logo uses store fallback',
      (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    await tester.pumpWidget(
      _app(
        size: const Size(390, 844),
        arabic: true,
        data: _data(stale: true),
      ),
    );
    await tester.pump();

    expect(
      find.byKey(const ValueKey('store-analytics-stale')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('store-analytics-logo-fallback')),
      findsOneWidget,
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets('insights are prioritized to four and exact drill-down is used',
      (tester) async {
    tester.view.physicalSize = const Size(390, 932);
    tester.view.devicePixelRatio = 1;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    String? opened;
    await tester.pumpWidget(
      _app(
        size: const Size(390, 932),
        arabic: false,
        data: _data(),
        onDrillDown: (route) => opened = route,
      ),
    );
    await tester.pump();

    expect(
      find.byKey(const ValueKey('store-analytics-insight-ignored_fifth')),
      findsNothing,
    );

    final action = find.byKey(
      const ValueKey('store-analytics-insight-action-cancelled_orders'),
    );
    await tester.scrollUntilVisible(
      action,
      220,
      scrollable: find.byKey(const ValueKey('store-analytics-content')),
    );
    await tester.tap(action);
    await tester.pump();

    expect(opened, '/orders?channel=retail&store_id=7');
  });

  testWidgets('multi-store selection returns the explicit Retail store id',
      (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    int? selected;
    await tester.pumpWidget(
      _app(
        size: const Size(390, 844),
        arabic: false,
        data: _data(),
        onStoreChanged: (id) => selected = id,
      ),
    );
    await tester.pump();

    await tester.tap(
      find.byKey(const ValueKey('store-analytics-store-selector')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Second Retail Store').last);
    await tester.pumpAndSettle();

    expect(selected, 8);
  });

  for (final status in const [
    StoreAnalyticsStatus.loading,
    StoreAnalyticsStatus.empty,
    StoreAnalyticsStatus.error,
    StoreAnalyticsStatus.noEntitlement,
  ]) {
    testWidgets('explicit Store Analytics state ' + status.name + ' renders',
        (tester) async {
      tester.view.physicalSize = const Size(320, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      var retries = 0;
      await tester.pumpWidget(
        _app(
          size: const Size(320, 800),
          arabic: true,
          status: status,
          data: null,
          stores: const [],
          onRetry: () => retries++,
        ),
      );
      await tester.pump();

      if (status == StoreAnalyticsStatus.loading) {
        expect(
          find.byKey(const ValueKey('store-analytics-loading')),
          findsOneWidget,
        );
      } else {
        expect(
          find.byKey(const ValueKey('store-analytics-state-list')),
          findsOneWidget,
        );
      }

      if (status == StoreAnalyticsStatus.error) {
        await tester.tap(find.text('إعادة المحاولة'));
        await tester.pump();
        expect(retries, 1);
      }

      expect(tester.takeException(), isNull);
    });
  }

  testWidgets('trend chart exposes raw daily facts through semantics',
      (tester) async {
    tester.view.physicalSize = const Size(390, 932);
    tester.view.devicePixelRatio = 1;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    await tester.pumpWidget(
      _app(
        size: const Size(390, 932),
        arabic: false,
        data: _data(),
      ),
    );
    await tester.pump();

    final semantics = tester.getSemantics(find.byType(FoodexTrendChart));
    expect(semantics.label, contains('06 Oct: 1900.00 EGP, 6 orders'));
    expect(semantics.label, contains('12 Oct: 3000.75 EGP, 8 orders'));
  });
}
