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

class _TopProductsVisualApi implements B2bApi {
  String? lastPath;

  @override
  Future<Object?> get(String path) async {
    lastPath = path;
    // Test-only fixtures. Production widgets must stay bound to B2bApi data.
    return {
      'data': [
        {
          'rank': 1,
          'product_id': 501,
          'store_id': 7,
          'sku': 'QA-A1',
          'name': 'منتج اختبار ألف',
          'quantity': 17,
          'total': 81.25,
          'currency': 'KWD',
          'last_purchased_at': '2026-09-27T09:14:33Z',
          'account_price': 4.75,
          'current_price_currency': 'KWD',
          'can_repurchase': true,
          'unavailable_reason': null,
        },
        {
          'rank': 2,
          'product_id': 502,
          'store_id': 7,
          'sku': 'QA-B2',
          'name': 'منتج اختبار باء',
          'quantity': 11,
          'total': 63.5,
          'currency': 'KWD',
          'last_purchased_at': '2026-09-22T15:05:09Z',
          'account_price': 5.5,
          'current_price_currency': 'KWD',
          'can_repurchase': true,
          'unavailable_reason': null,
        },
        {
          'rank': 3,
          'product_id': 503,
          'store_id': 7,
          'sku': 'QA-C3',
          'name': 'منتج اختبار جيم',
          'quantity': 8,
          'total': 49.0,
          'currency': 'KWD',
          'last_purchased_at': '2026-09-16T07:45:00Z',
          'account_price': 6.125,
          'current_price_currency': 'KWD',
          'can_repurchase': true,
          'unavailable_reason': null,
        },
        {
          'rank': 4,
          'product_id': 504,
          'store_id': 7,
          'sku': 'QA-D4',
          'name': 'منتج اختبار دال',
          'quantity': 5,
          'total': 31.0,
          'currency': 'KWD',
          'last_purchased_at': '2026-09-03T18:30:00Z',
          'account_price': 6.2,
          'current_price_currency': 'KWD',
          'can_repurchase': false,
          'unavailable_reason': 'OUT_OF_STOCK',
        },
      ],
      'sort': 'quantity',
      'meta': {
        'page': 1,
        'per_page': 20,
        'total': 4,
        'has_more': false,
      },
    };
  }
}

Future<void> _loadFonts() async {
  final arabic = [
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/noto/NotoSansArabic-Regular.ttf',
    '/System/Library/Fonts/Supplemental/Arial Unicode.ttf',
    'C:/Windows/Fonts/arial.ttf',
  ].map(File.new).firstWhere((file) => file.existsSync());
  final loader = FontLoader('TopProductsEvidence')
    ..addFont(Future.value(ByteData.sublistView(await arabic.readAsBytes())));
  await loader.load();

  final flutterRoot = Platform.environment['FLUTTER_ROOT'] ?? '';
  final icons = File(
    '$flutterRoot/bin/cache/artifacts/material_fonts/MaterialIcons-Regular.otf',
  );
  await (FontLoader(
        'MaterialIcons',
      )..addFont(Future.value(ByteData.sublistView(await icons.readAsBytes()))))
      .load();
}

Widget _app(_TopProductsVisualApi api) => FoodexCustomerApp(
      session: const CustomerSession.authenticated(CustomerChannel.b2b),
      locale: const Locale('ar'),
      initialRoute: '/b2b/products/top?store_id=7',
      b2bApi: api,
      theme: FoodexTheme.light(fontFamily: 'TopProductsEvidence'),
    );

void main() {
  setUpAll(_loadFonts);

  for (final size in const [
    Size(360, 800),
    Size(375, 812),
    Size(390, 844),
    Size(412, 915),
  ]) {
    final testName =
        'top products visual ${size.width.toInt()}x${size.height.toInt()} ar';
    testWidgets(
      testName,
      (tester) async {
        tester.view.physicalSize = size;
        tester.view.devicePixelRatio = 1;
        tester.view.padding = const FakeViewPadding(top: 24, bottom: 0);
        tester.view.viewPadding = const FakeViewPadding(top: 24, bottom: 0);
        addTearDown(() {
          tester.view.resetPhysicalSize();
          tester.view.resetDevicePixelRatio();
          tester.view.resetPadding();
          tester.view.resetViewPadding();
        });

        final boundaryKey = GlobalKey();
        final api = _TopProductsVisualApi();
        await tester.pumpWidget(
          RepaintBoundary(
            key: boundaryKey,
            child: _app(api),
          ),
        );
        await tester.pumpAndSettle();

        expect(tester.takeException(), isNull);
        expect(
          find.byKey(const ValueKey('b2b-top-products-screen')),
          findsOneWidget,
        );
        expect(find.text('فترة الترتيب'), findsNothing);
        expect(find.text('كل القنوات'), findsOneWidget);
        expect(
          find.text('ابحث في منتجاتك ومشترياتك'),
          findsOneWidget,
        );
        expect(find.byType(GridView), findsNothing);

        final current = tester.getRect(
          find.byKey(const ValueKey('b2b-top-products-current-period')),
        );
        final all = tester.getRect(
          find.byKey(const ValueKey('b2b-top-products-all-time')),
        );
        final previous = tester.getRect(
          find.byKey(const ValueKey('b2b-top-products-previous-period')),
        );
        expect((current.center.dy - all.center.dy).abs(), lessThan(1));
        expect((all.center.dy - previous.center.dy).abs(), lessThan(1));

        final first = tester.getRect(
          find.byKey(const ValueKey('b2b-top-product-1')),
        );
        final second = tester.getRect(
          find.byKey(const ValueKey('b2b-top-product-2')),
        );
        final productImage = tester.getRect(
          find.byKey(const ValueKey('b2b-top-product-image-1')),
        );
        expect(first.width, greaterThan(size.width - 40));
        expect((first.left - second.left).abs(), lessThan(1));
        expect((first.width - second.width).abs(), lessThan(1));
        expect(second.top, greaterThan(first.bottom));
        expect(productImage.width, lessThanOrEqualTo(86));
        expect(productImage.height, lessThanOrEqualTo(86));

        await tester.runAsync(() async {
          final boundary =
              boundaryKey.currentContext!.findRenderObject()!
                  as RenderRepaintBoundary;
          final image = await boundary.toImage(pixelRatio: 2);
          final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
          final file = File(
            'build/top-products-evidence/top-products-'
            '${size.width.toInt()}x${size.height.toInt()}-ar.png',
          );
          file.parent.createSync(recursive: true);
          file.writeAsBytesSync(bytes!.buffer.asUint8List(), flush: true);
          image.dispose();
          expect(file.lengthSync(), greaterThan(1000));
        });
      },
    );
  }
}
