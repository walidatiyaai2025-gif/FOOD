import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/api/b2b_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/theme/foodex_theme.dart';

// Test-only response, deliberately different from the visual reference.
Map<String, Object?> dashboardResponse({bool second = false}) => {
  'customer': {
    'name': second ? 'عميل ثانٍ' : 'عميل الأعمال',
    'email': 'buyer@example.test',
  },
  'account': {'company_name': second ? 'شركة ثانية' : 'شركة الأغذية'},
  'finance': {
    'currency': second ? 'USD' : 'KWD',
    'balance': second ? -200 : 1234.5,
    'balance_direction': second
        ? 'company_owes_customer'
        : 'customer_owes_company',
    'credit_limit': second ? 7000 : 5000,
    'available_credit_line': second ? 7000 : 3765.5,
    'open_amount': second ? 0 : 1234.5,
    'overdue_amount': second ? 0 : 123,
  },
  'operations': {
    'purchases_this_month': second ? 400 : 9876.5,
    'payments_this_month': second ? 200 : 3456,
    'invoice_count': second ? 1 : 24,
    'order_count': second ? 2 : 35,
    'active_orders': second ? 1 : 6,
  },
  'freshness': {
    'generated_at': DateTime.now().toUtc().toIso8601String(),
    'stale': false,
  },
};

class DashboardApi implements B2bApi {
  Object? response = dashboardResponse();
  int calls = 0;
  String? lastPath;
  @override
  Future<Object?> get(String path) async {
    calls++;
    lastPath = path;
    if (response is Exception) throw response!;
    return response;
  }
}

Future<void> loadFonts() async {
  final arabic = [
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/noto/NotoSansArabic-Regular.ttf',
    '/System/Library/Fonts/Supplemental/Arial Unicode.ttf',
    'C:/Windows/Fonts/arial.ttf',
  ].map(File.new).firstWhere((file) => file.existsSync());
  final loader = FontLoader('DashboardEvidence')
    ..addFont(Future.value(ByteData.sublistView(await arabic.readAsBytes())));
  await loader.load();
  final icons = File(
    '${Platform.environment['FLUTTER_ROOT']}/bin/cache/artifacts/material_fonts/MaterialIcons-Regular.otf',
  );
  await (FontLoader(
        'MaterialIcons',
      )..addFont(Future.value(ByteData.sublistView(await icons.readAsBytes()))))
      .load();
}

Widget testApp(
  DashboardApi api, {
  Locale locale = const Locale('ar'),
  String scope = '?store_id=7',
}) => FoodexCustomerApp(
  session: const CustomerSession.authenticated(CustomerChannel.b2b),
  locale: locale,
  initialRoute: '/b2b/dashboard$scope',
  b2bApi: api,
  theme: FoodexTheme.light(fontFamily: 'DashboardEvidence'),
);

void main() {
  setUpAll(loadFonts);
  final before = Platform.environment['DASHBOARD_CAPTURE_PHASE'] == 'before';
  for (final size in const [
    Size(360, 800),
    Size(360, 780),
    Size(393, 873),
    Size(412, 915),
    Size(430, 932),
  ]) {
    for (final locale in const [Locale('ar'), Locale('en')]) {
      testWidgets(
        'dashboard ${size.width.toInt()}x${size.height.toInt()} ${locale.languageCode}',
        (tester) async {
          tester.view.physicalSize = size;
          tester.view.devicePixelRatio = 1;
          tester.view.padding = const FakeViewPadding(top: 24, bottom: 24);
          addTearDown(() {
            tester.view.resetPhysicalSize();
            tester.view.resetDevicePixelRatio();
            tester.view.resetPadding();
          });
          final key = GlobalKey();
          await tester.pumpWidget(
            RepaintBoundary(
              key: key,
              child: testApp(DashboardApi(), locale: locale),
            ),
          );
          await tester.pumpAndSettle();
          await tester.runAsync(() async {
            final boundary =
                key.currentContext!.findRenderObject()!
                    as RenderRepaintBoundary;
            final image = await boundary.toImage(pixelRatio: 2);
            final bytes = await image.toByteData(
              format: ui.ImageByteFormat.png,
            );
            final file = File(
              'build/dashboard-evidence/${before ? 'before' : 'after'}-${size.width.toInt()}x${size.height.toInt()}-${locale.languageCode}.png',
            );
            file.parent.createSync(recursive: true);
            file.writeAsBytesSync(bytes!.buffer.asUint8List());
            image.dispose();
          });
          expect(tester.takeException(), isNull);
          if (!before) {
            expect(find.byType(Scrollable), findsNothing);
            final promo = tester.getRect(
              find.byKey(const ValueKey('b2b-dashboard-offers')),
            );
            expect(promo.bottom, lessThan(size.height - 58));
            expect(
              find.byKey(const ValueKey('customer-logout')),
              findsOneWidget,
            );
            expect(
              find.byKey(const ValueKey('customer-footer-orders')),
              findsOneWidget,
            );
            for (final metric in [
              'credit-limit',
              'available-credit',
              'open-invoices',
              'overdue',
              'purchases-month',
              'payments-month',
              'invoice-count',
              'order-count',
              'active-orders',
            ]) {
              final rect = tester.getRect(
                find.byKey(ValueKey('b2b-dashboard-$metric')),
              );
              expect(rect.top, greaterThan(24));
              expect(rect.bottom, lessThan(size.height - 58));
            }
          }
        },
      );
    }
  }
  if (!before) {
    testWidgets(
      'refresh renders a second authoritative response and missing data stays unavailable',
      (tester) async {
        tester.view.physicalSize = const Size(393, 873);
        tester.view.devicePixelRatio = 1;
        addTearDown(() {
          tester.view.resetPhysicalSize();
          tester.view.resetDevicePixelRatio();
          tester.view.resetPadding();
        });
        final api = DashboardApi();
        await tester.pumpWidget(testApp(api));
        await tester.pumpAndSettle();
        expect(find.textContaining('1234.500 KWD'), findsWidgets);
        api.response = dashboardResponse(second: true);
        await tester.tap(find.byKey(const ValueKey('b2b-dashboard-refresh')));
        await tester.pumpAndSettle();
        expect(api.calls, 2);
        expect(api.lastPath, '/api/v1/b2b/dashboard?store_id=7');
        expect(find.text('شركة ثانية'), findsOneWidget);
        expect(find.textContaining('200.000 USD'), findsWidgets);
        expect(find.textContaining('1234.500 KWD'), findsNothing);
        api.response = <String, Object?>{};
        await tester.tap(find.byKey(const ValueKey('b2b-dashboard-refresh')));
        await tester.pumpAndSettle();
        expect(find.text('0'), findsNothing);
        expect(find.textContaining('0.000'), findsNothing);
        expect(tester.takeException(), isNull);
      },
    );
  }
}
