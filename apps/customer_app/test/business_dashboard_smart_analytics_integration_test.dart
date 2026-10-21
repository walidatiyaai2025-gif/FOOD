import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/b2b_api.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/core/localization/app_translations.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';
import 'package:foodex_customer_app/core/theme/foodex_theme.dart';
import 'package:foodex_customer_app/features/b2b/b2b_journey_screen.dart';
import 'package:foodex_customer_app/features/b2b/business_dashboard_smart_analytics_launcher.dart';

Map<String, Object?> _dashboardPayload({
  String? logoUrl = 'https://dashboard.foodex.test/downtown-store.png',
}) =>
    <String, Object?>{
      'customer': <String, Object?>{
        'name': 'Merchant Owner',
        'email': 'owner@example.test',
      },
      'account': <String, Object?>{'company_name': 'Merchant Company'},
      'retail_store': <String, Object?>{
        'id': 22,
        'name': 'Downtown Store',
        'logo_url': logoUrl,
      },
      'finance': <String, Object?>{
        'currency': 'KWD',
        'balance': 0,
        'credit_limit': 1000,
        'available_credit_line': 1000,
        'customer_credit_balance': 0,
        'open_amount': 0,
        'overdue_amount': 0,
      },
      'operations': <String, Object?>{
        'purchases_this_month': 120,
        'payments_this_month': 90,
        'invoice_count': 2,
        'order_count': 3,
        'active_orders': 1,
      },
      'freshness': <String, Object?>{
        'generated_at': DateTime.now().toUtc().toIso8601String(),
        'stale': false,
      },
      'purchase_total': 120,
    };

Map<String, Object?> _analyticsPayload({
  required bool hasNewInsights,
  String? logoUrl = 'https://cdn.foodex.test/downtown-store.png',
}) =>
    <String, Object?>{
      'data': <String, Object?>{
        'store': <String, Object?>{
          'id': 22,
          'name': 'Downtown Store',
          'logo_url': logoUrl,
          'currency': 'EGP',
        },
        'period': <String, Object?>{
          'from': '2026-10-06',
          'to': '2026-10-12',
          'days': 7,
        },
        'previous_period': <String, Object?>{
          'from': '2026-09-29',
          'to': '2026-10-05',
          'days': 7,
        },
        'metrics': <String, Object?>{
          'revenue': <String, Object?>{
            'value': 100,
            'previous_value': 80,
            'delta_percent': 25,
          },
          'orders': <String, Object?>{
            'value': 2,
            'previous_value': 1,
            'delta_percent': 100,
          },
          'average_order_value': <String, Object?>{
            'value': 50,
            'previous_value': 80,
            'delta_percent': -37.5,
          },
        },
        'order_facts': <String, Object?>{
          'completed': <String, Object?>{'value': 1, 'previous_value': 1},
          'cancelled': <String, Object?>{'value': 1, 'previous_value': 0},
          'late': <String, Object?>{
            'supported': false,
            'value': null,
            'previous_value': null,
          },
        },
        'daily_series': <Object?>[],
        'top_products': <Object?>[],
        'peak_activity': <String, Object?>{
          'hourly': <Object?>[],
          'peak_window': null,
        },
        'attention': <String, Object?>{
          'low_stock': <Object?>[],
          'merchant_summary': <String, Object?>{
            'reorder_now': 0,
            'reorder_soon': 0,
            'blocked': 0,
            'expected_reorder_spend': 0,
          },
          'merchant_attention': <Object?>[],
        },
        'insights': <Object?>[
          <String, Object?>{
            'id': 'revenue-up',
            'code': 'revenue_up',
            'severity': 'positive',
            'priority': 10,
            'facts': <String, Object?>{
              'delta_percent': 25,
              'current_revenue': 100,
              'previous_revenue': 80,
              'currency': 'EGP',
            },
          },
        ],
        'freshness': <String, Object?>{
          'computed_at': '2026-10-12T21:30:00+03:00',
          'source_updated_at': '2026-10-12T21:00:00+03:00',
          'stale_after_seconds': 86400,
          'is_stale': false,
          'state': 'fresh',
        },
        'insight_state': <String, Object?>{
          'version': List<String>.filled(64, 'b').join(),
          'has_new_insights': hasNewInsights,
          'unread_count': hasNewInsights ? 1 : 0,
          'lifecycle': <String, Object?>{
            'mode': 'client_version_echo',
            'request_parameter': 'seen_insight_version',
            'clear_when_seen_version_matches_current': true,
          },
        },
        'data_state': 'ready',
      },
    };

class _RetailDashboardApi extends HttpB2bApi {
  _RetailDashboardApi({
    required this.hasNewInsights,
    this.logoUrl = 'https://analytics.foodex.test/downtown-store.png',
    this.dashboardLogoUrl = 'https://dashboard.foodex.test/downtown-store.png',
  }) : super(
          baseUrl: 'https://api.foodex.test',
          token: 'test-token',
          retailStoreContextId: 22,
        );

  final bool hasNewInsights;
  final String? logoUrl;
  final String? dashboardLogoUrl;
  final List<String> paths = <String>[];

  @override
  Future<Object?> get(String path) async {
    paths.add(path);
    if (path.startsWith('/api/v1/mobile/store-analytics')) {
      final seenVersion =
          Uri.parse(path).queryParameters['seen_insight_version'];
      return _analyticsPayload(
        hasNewInsights: hasNewInsights && seenVersion == null,
        logoUrl: logoUrl,
      );
    }
    return _dashboardPayload(logoUrl: dashboardLogoUrl);
  }
}

class _Actions implements CustomerActionApi {
  const _Actions();

  @override
  Future<CustomerLoginResult> login({required String username}) async =>
      const CustomerLoginResult(token: 'test-token');

  @override
  Future<void> logout() async {}

  @override
  Future<Object?> addCartItem({
    required int storeId,
    required int productId,
    required double quantity,
  }) async =>
      null;

  @override
  Future<Object?> checkout({
    required int addressId,
    int? storeId,
    String? paymentMethod,
    String? couponCode,
    required String idempotencyKey,
  }) async =>
      null;
}

Widget _app(
  _RetailDashboardApi api, {
  required Locale locale,
  ValueChanged<String?>? onOpenRoute,
}) {
  final definition = customerRouteDefinitions.firstWhere(
    (route) => route.pattern == CustomerRoutePaths.b2bDashboard,
  );
  return MaterialApp(
    theme: FoodexTheme.light(fontFamily: 'DashboardAnalyticsTest'),
    home: AppTranslations(
      locale: locale,
      overrides: const <String, String>{},
      child: Directionality(
        textDirection:
            locale.languageCode == 'ar' ? TextDirection.rtl : TextDirection.ltr,
        child: B2bJourneyScreen(
          definition: definition,
          location: CustomerRoutePaths.b2bDashboard,
          api: api,
          actionApi: const _Actions(),
        ),
      ),
    ),
    onGenerateRoute: (settings) {
      onOpenRoute?.call(settings.name);
      return MaterialPageRoute<void>(
        settings: settings,
        builder: (_) => const Scaffold(body: Text('Destination')),
      );
    },
  );
}

Future<void> _pumpRemote(WidgetTester tester) async {
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 30));
  await tester.pump();
}

Future<void> _loadTestFonts() async {
  final font = <String>[
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/noto/NotoSansArabic-Regular.ttf',
    '/System/Library/Fonts/Supplemental/Arial Unicode.ttf',
    'C:/Windows/Fonts/arial.ttf',
  ].map(File.new).firstWhere((file) => file.existsSync());
  final loader = FontLoader('DashboardAnalyticsTest')
    ..addFont(Future.value(ByteData.sublistView(await font.readAsBytes())));
  await loader.load();
}

void main() {
  setUpAll(_loadTestFonts);

  testWidgets(
    'retail dashboard consumes authoritative logo/unread state and opens canonical route',
    (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      String? opened;
      final api = _RetailDashboardApi(hasNewInsights: true);
      await tester.pumpWidget(
        _app(
          api,
          locale: const Locale('en'),
          onOpenRoute: (route) => opened = route,
        ),
      );
      await _pumpRemote(tester);

      expect(
        api.paths,
        contains('/api/v1/mobile/store-analytics?retail_store_id=22'),
      );
      expect(
        find.byKey(const ValueKey('business-smart-analytics-launcher')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('business-smart-analytics-launcher-badge')),
        findsNothing,
      );
      expect(find.byTooltip('Open Smart Store Analytics'), findsOneWidget);

      final storeLogo = tester.widget<BusinessDashboardStoreLogo>(
        find.byType(BusinessDashboardStoreLogo),
      );
      expect(storeLogo.storeName, 'Downtown Store');
      expect(
        storeLogo.logoUrl,
        'https://dashboard.foodex.test/downtown-store.png',
      );

      await tester.tap(
        find.byKey(const ValueKey('business-smart-analytics-launcher')),
        warnIfMissed: false,
      );
      await tester.pump(const Duration(milliseconds: 350));
      expect(opened, isNull);
      expect(tester.takeException(), isNull);
    },
  );

  for (final width in const <double>[320, 360, 390, 412, 430]) {
    for (final locale in const <Locale>[Locale('ar'), Locale('en')]) {
      testWidgets(
          'retail dashboard launcher is overflow-safe at width $width '
          '${locale.languageCode}', (tester) async {
        tester.view.physicalSize = Size(width, 844);
        tester.view.devicePixelRatio = 1;
        addTearDown(() {
          tester.view.resetPhysicalSize();
          tester.view.resetDevicePixelRatio();
        });

        final api = _RetailDashboardApi(
          hasNewInsights: false,
          logoUrl: null,
          dashboardLogoUrl: null,
        );
        await tester.pumpWidget(_app(api, locale: locale));
        await _pumpRemote(tester);

        final target = tester.getSize(
          find.byKey(
            const ValueKey('business-smart-analytics-launcher-target'),
          ),
        );
        expect(target.width, greaterThanOrEqualTo(44));
        expect(target.height, greaterThanOrEqualTo(44));
        expect(
          find.byKey(const ValueKey('business-smart-analytics-launcher-badge')),
          findsNothing,
        );
        expect(
          find.byKey(const ValueKey('b2b-dashboard-store-logo-fallback')),
          findsOneWidget,
        );
        expect(find.text('D'), findsOneWidget);
        expect(tester.takeException(), isNull);
      });
    }
  }
}
