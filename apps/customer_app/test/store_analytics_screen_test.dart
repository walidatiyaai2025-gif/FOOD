import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/store_analytics_api.dart'
    as analytics_api;
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
        lastUpdated: 'آخر تحديث',
        cancelledOrders: 'طلبات ملغاة',
        stockAttention: 'تنبيه مخزون',
        topProduct: 'المنتج الأقوى',
        viewOrders: 'عرض الطلبات',
        viewProducts: 'عرض المنتجات',
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
        lastUpdated: 'Last updated',
        cancelledOrders: 'Cancelled orders',
        stockAttention: 'Stock attention',
        topProduct: 'Top product',
        viewOrders: 'View orders',
        viewProducts: 'View products',
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
        StoreAnalyticsDailyPoint(
            dateLabel: '12 Oct', sales: 3000.75, orders: 8),
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

Map<String, dynamic> _authoritativeReadModelJson() => <String, dynamic>{
      'store': <String, dynamic>{
        'id': 7,
        'name': 'Downtown Grocery',
        'logo_url': 'https://cdn.foodex.test/store.png',
        'currency': 'EGP',
      },
      'period': <String, dynamic>{
        'from': '2026-10-06',
        'to': '2026-10-12',
        'days': 7,
      },
      'previous_period': <String, dynamic>{
        'from': '2026-09-29',
        'to': '2026-10-05',
        'days': 7,
      },
      'metrics': <String, dynamic>{
        'revenue': <String, dynamic>{
          'value': 18425.75,
          'previous_value': 20115.45,
          'delta_percent': -8.4,
        },
        'orders': <String, dynamic>{
          'value': 53,
          'previous_value': 57,
          'delta_percent': -7.0,
        },
        'average_order_value': <String, dynamic>{
          'value': 347.66,
          'previous_value': 352.9,
          'delta_percent': -1.5,
        },
      },
      'order_facts': <String, dynamic>{
        'completed': <String, dynamic>{'value': 50, 'previous_value': 54},
        'cancelled': <String, dynamic>{'value': 3, 'previous_value': 3},
        'late': <String, dynamic>{
          'supported': false,
          'value': null,
          'previous_value': null,
        },
      },
      'daily_series': <Map<String, dynamic>>[
        <String, dynamic>{'date': '2026-10-12', 'sales': 3000.75, 'orders': 8},
      ],
      'top_products': const <Map<String, dynamic>>[],
      'peak_activity': <String, dynamic>{
        'hourly': const <Map<String, dynamic>>[],
        'peak_window': null,
      },
      'attention': <String, dynamic>{
        'low_stock': const <Map<String, dynamic>>[],
        'merchant_summary': const <String, dynamic>{},
        'merchant_attention': const <Map<String, dynamic>>[],
      },
      'insights': <Map<String, dynamic>>[
        <String, dynamic>{
          'id': 'cancelled_orders',
          'code': 'cancelled_orders_attention',
          'severity': 'warning',
          'priority': 90,
          'facts': <String, dynamic>{
            'cancelled_orders': 3,
            'order_count': 53,
            'cancelled_percent': 5.7,
          },
        },
        <String, dynamic>{
          'id': 'stock_attention',
          'code': 'stock_attention_required',
          'severity': 'warning',
          'priority': 80,
          'facts': <String, dynamic>{
            'low_stock_count': 2,
            'reorder_now_count': 1,
            'first_product_id': 42,
          },
        },
        <String, dynamic>{
          'id': 'revenue_change',
          'code': 'revenue_decreased',
          'severity': 'warning',
          'priority': 70,
          'facts': <String, dynamic>{'delta_percent': -8.4},
        },
        <String, dynamic>{
          'id': 'top_product',
          'code': 'top_product_contribution',
          'severity': 'positive',
          'priority': 40,
          'facts': <String, dynamic>{
            'product_id': 42,
            'name': 'Fresh Milk',
            'contribution_percent': 28.5,
            'currency': 'EGP',
          },
        },
      ],
      'freshness': <String, dynamic>{
        'computed_at': '2026-10-12T21:30:00+03:00',
        'source_updated_at': '2026-10-10T20:00:00+03:00',
        'stale_after_seconds': 86400,
        'is_stale': true,
        'state': 'stale',
      },
      'insight_state': <String, dynamic>{
        'version': List<String>.filled(64, 'b').join(),
        'has_new_insights': true,
        'unread_count': 4,
        'lifecycle': <String, dynamic>{
          'mode': 'client_version_echo',
          'request_parameter': 'seen_insight_version',
          'clear_when_seen_version_matches_current': true,
        },
      },
      'data_state': 'stale',
    };

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

  test('adapts authoritative W1/W2 analytics facts without recalculation', () {
    final model = analytics_api.StoreAnalyticsReadModel.fromJson(
      _authoritativeReadModelJson(),
    );
    final data = storeAnalyticsDataFromReadModel(model, copy: _copy(false));

    expect(data.storeId, 7);
    expect(data.storeName, 'Downtown Grocery');
    expect(data.logoUrl, 'https://cdn.foodex.test/store.png');
    expect(data.currency, 'EGP');
    expect(data.periodLabel, '2026-10-06 – 2026-10-12');
    expect(data.freshnessLabel, contains('2026-10-10T20:00:00+03:00'));
    expect(data.revenue, 18425.75);
    expect(data.orders, 53);
    expect(data.averageOrderValue, 347.66);
    expect(data.revenueDeltaPercent, -8.4);
    expect(data.dailySeries.single.sales, 3000.75);
    expect(data.dailySeries.single.orders, 8);
    expect(data.isStale, isTrue);
    expect(data.insights.map((insight) => insight.id), <String>[
      'cancelled_orders',
      'stock_attention',
      'revenue_change',
      'top_product',
    ]);
    expect(
      data.insights.first.targetRoute,
      '/orders?channel=retail&store_id=7',
    );
    expect(
      data.insights.last.targetRoute,
      '/products/42?channel=retail&store_id=7',
    );
  });

  test('maps typed consumer states to presentation states', () {
    final staleModel = analytics_api.StoreAnalyticsReadModel.fromJson(
      _authoritativeReadModelJson(),
    );

    expect(
      storeAnalyticsStatusFromConsumerState(
        const analytics_api.StoreAnalyticsConsumerState.loading(),
      ),
      StoreAnalyticsStatus.loading,
    );
    expect(
      storeAnalyticsStatusFromConsumerState(
        analytics_api.StoreAnalyticsConsumerState.fromData(staleModel),
      ),
      StoreAnalyticsStatus.loaded,
    );
    expect(
      storeAnalyticsStatusFromConsumerState(
        const analytics_api.StoreAnalyticsConsumerState.noEntitlement(),
      ),
      StoreAnalyticsStatus.noEntitlement,
    );
    expect(
      storeAnalyticsStatusFromConsumerState(
        const analytics_api.StoreAnalyticsConsumerState.error('network'),
      ),
      StoreAnalyticsStatus.error,
    );
  });

  for (final width in const [320.0, 360.0, 390.0, 412.0, 430.0]) {
    for (final arabic in const [true, false]) {
      testWidgets(
        'Store Analytics fits width ${width.toInt()} in ${arabic ? 'AR RTL' : 'EN LTR'}',
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
          expect(find.textContaining(arabic ? 'ج.م' : 'EGP'), findsWidgets);
          expect(tester.takeException(), isNull);
        },
      );
    }
  }

  testWidgets('stale data is explicit and missing logo uses store fallback', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    await tester.pumpWidget(
      _app(size: const Size(390, 844), arabic: true, data: _data(stale: true)),
    );
    await tester.pump();

    expect(find.byKey(const ValueKey('store-analytics-stale')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('store-analytics-logo-fallback')),
      findsOneWidget,
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets('insights are prioritized to four and exact drill-down is used', (
    tester,
  ) async {
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
    final content = find.byKey(const ValueKey('store-analytics-content'));
    final scrollable = find.descendant(
      of: content,
      matching: find.byType(Scrollable),
    );
    await tester.scrollUntilVisible(action, 220, scrollable: scrollable);
    await tester.tap(action);
    await tester.pump();

    expect(opened, '/orders?channel=retail&store_id=7');
  });

  testWidgets('multi-store selection returns the explicit Retail store id', (
    tester,
  ) async {
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
    testWidgets('explicit Store Analytics state ${status.name} renders', (
      tester,
    ) async {
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

  testWidgets('trend chart exposes raw daily facts through semantics', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 932);
    tester.view.devicePixelRatio = 1;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    await tester.pumpWidget(
      _app(size: const Size(390, 932), arabic: false, data: _data()),
    );
    await tester.pump();

    final semantics = tester.getSemantics(find.byType(FoodexTrendChart));
    expect(semantics.label, contains('06 Oct: 1900.00 EGP, 6 orders'));
    expect(semantics.label, contains('12 Oct: 3000.75 EGP, 8 orders'));
  });
}
