import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/api/b2b_api.dart';
import 'package:foodex_customer_app/core/api/b2c_account_api.dart';
import 'package:foodex_customer_app/core/api/b2c_catalog_api.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/theme/foodex_theme.dart';

const _b2b = CustomerSession.authenticated(
  CustomerChannel.b2b,
  accessToken: 'evidence-token',
);
const _b2c = CustomerSession.authenticated(
  CustomerChannel.b2c,
  accessToken: 'evidence-token',
);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setUpAll(_loadEvidenceFont);

  final cases = <_CaptureCase>[
    const _CaptureCase('01_Mobile/B2B_Customer/01_شاشة_الدخول__default__ar.png', '/b2b/login'),
    const _CaptureCase('01_Mobile/B2B_Customer/02_الصفحة_الرئيسية_Dashboard__populated__ar.png', '/b2b/dashboard', session: _b2b),
    const _CaptureCase('01_Mobile/B2B_Customer/03_تقارير_المشتريات_والرسوم_البيانية__populated__ar.png', '/b2b/reports/purchases', session: _b2b),
    const _CaptureCase('01_Mobile/B2B_Customer/04_أكثر_المنتجات_طلبا__populated__ar.png', '/b2b/products/top?from=2026-09-01&to=2026-09-30', session: _b2b),
    const _CaptureCase('01_Mobile/B2B_Customer/05_آخر_الفواتير__populated__ar.png', '/b2b/invoices', session: _b2b),
    const _CaptureCase('01_Mobile/B2B_Customer/06_كشف_الحساب_والمعاملات__populated__ar.png', '/b2b/account-statement', session: _b2b),
    const _CaptureCase('01_Mobile/B2B_Customer/07_طلباتي__populated__ar.png', '/b2b/orders', session: _b2b),
    const _CaptureCase('01_Mobile/B2B_Customer/08_تفاصيل_الطلب_وتتبع_الحالة__populated__ar.png', '/b2b/orders/77', session: _b2b),
    const _CaptureCase('01_Mobile/B2B_Customer/09_تفاصيل_الفاتورة__populated__ar.png', '/b2b/invoices/31', session: _b2b),
    const _CaptureCase('01_Mobile/B2B_Customer/10_تصفح_المنتجات__populated__ar.png', '/b2b/products?store=7', session: _b2b),
    const _CaptureCase('01_Mobile/B2B_Customer/11_تفاصيل_المنتج_وإضافة_للسلة__populated__ar.png', '/b2b/products/42?store_id=7', session: _b2b),
    const _CaptureCase('01_Mobile/B2B_Customer/12_سلة_المشتريات_وإتمام_الطلب__populated__ar.png', '/b2b/cart?store=7', session: _b2b),
    const _CaptureCase('01_Mobile/B2B_Customer/13_حسابي_والإعدادات__populated__ar.png', '/b2b/profile', session: _b2b),
    const _CaptureCase('01_Mobile/B2C_Customer/01_الشاشة_الافتتاحية__default__ar.png', '/splash'),
    const _CaptureCase('01_Mobile/B2C_Customer/02_تصفح_كضيف_او_تسجيل_الدخول__default__ar.png', '/entry'),
    const _CaptureCase('01_Mobile/B2C_Customer/03_اختيار_المتجر__populated__ar.png', '/stores'),
    const _CaptureCase('01_Mobile/B2C_Customer/04_الصفحة_الرئيسية_للمتجر__populated__ar.png', '/home?store=7'),
    const _CaptureCase('01_Mobile/B2C_Customer/05_عروض_وتخفيضات__populated__ar.png', '/offers?store=7'),
    const _CaptureCase('01_Mobile/B2C_Customer/06_قائمة_المنتجات_والفلاتر__populated__ar.png', '/products?store=7'),
    const _CaptureCase('01_Mobile/B2C_Customer/07_تفاصيل_المنتج__populated__ar.png', '/products/42?store=7'),
    const _CaptureCase('01_Mobile/B2C_Customer/08_سلة_التسوق__populated__ar.png', '/cart?store=7'),
    const _CaptureCase('01_Mobile/B2C_Customer/09_تسجيل_الدخول_لإتمام_الطلب__default__ar.png', '/auth/checkout'),
    const _CaptureCase('01_Mobile/B2C_Customer/10_العنوان_والدفع__default__ar.png', '/checkout/address-payment', session: _b2c),
    const _CaptureCase('01_Mobile/B2C_Customer/11_تتبع_الطلب__populated__ar.png', '/orders/101/track', session: _b2c),
    const _CaptureCase('01_Mobile/B2C_Customer/12_الملف_الشخصي_والمفضلة__populated__ar.png', '/profile', session: _b2c),
  ];

  for (final locale in const [Locale('ar'), Locale('en')]) {
    for (final item in cases) {
      final localeCode = locale.languageCode;
      final path = item.path.replaceAll('__ar.png', '__$localeCode.png');
      testWidgets('capture $path', (tester) async {
        tester.view.physicalSize = const Size(430, 932);
        tester.view.devicePixelRatio = 1;
        addTearDown(() {
          tester.view.resetPhysicalSize();
          tester.view.resetDevicePixelRatio();
        });

        final boundaryKey = GlobalKey();
        await tester.pumpWidget(
          RepaintBoundary(
            key: boundaryKey,
            child: FoodexCustomerApp(
              theme: FoodexTheme.light(fontFamily: _evidenceFontFamily),
              key: ValueKey('${item.route}-$localeCode'),
              session: item.session ?? const CustomerSession.guest(),
              initialRoute: item.route,
              b2bApi: const _EvidenceB2bApi(),
              b2cCatalogApi: const _EvidenceCatalogApi(),
              b2cAccountApi: const _EvidenceAccountApi(),
              actionApi: const _EvidenceActionApi(),
              locale: locale,
            ),
          ),
        );
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 150));

        await tester.runAsync(() async {
          final boundary =
              boundaryKey.currentContext!.findRenderObject()!
                  as RenderRepaintBoundary;
          final image = await boundary.toImage(pixelRatio: 1);
          final data = await image.toByteData(format: ui.ImageByteFormat.png);
          final file = File('../../ScreenShots/$path');
          file.parent.createSync(recursive: true);
          file.writeAsBytesSync(data!.buffer.asUint8List(), flush: true);
          expect(file.lengthSync(), greaterThan(1000));
        });
      });
    }
  }
}


const _evidenceFontFamily = 'FoodexEvidence';

Future<void> _loadEvidenceFont() async {
  const candidates = <String>[
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/noto/NotoSansArabic-Regular.ttf',
    '/System/Library/Fonts/Supplemental/Arial Unicode.ttf',
    'C:/Windows/Fonts/arial.ttf',
  ];

  File? fontFile;
  for (final path in candidates) {
    final candidate = File(path);
    if (candidate.existsSync()) {
      fontFile = candidate;
      break;
    }
  }

  if (fontFile == null) {
    throw StateError(
      'No Arabic-capable evidence font found. Install DejaVu Sans, Noto Sans Arabic, or Arial before screenshot capture.',
    );
  }

  final bytes = await fontFile.readAsBytes();
  final loader = FontLoader(_evidenceFontFamily)
    ..addFont(Future<ByteData>.value(ByteData.sublistView(bytes)));
  await loader.load();

  final flutterRoot = Platform.environment['FLUTTER_ROOT'];
  if (flutterRoot == null || flutterRoot.isEmpty) {
    throw StateError('FLUTTER_ROOT is required for readable Material Icons evidence.');
  }

  final materialIcons = File(
    '$flutterRoot/bin/cache/artifacts/material_fonts/MaterialIcons-Regular.otf',
  );
  if (!materialIcons.existsSync()) {
    throw StateError('Material Icons font not found at ${materialIcons.path}.');
  }

  final iconBytes = await materialIcons.readAsBytes();
  final iconLoader = FontLoader('MaterialIcons')
    ..addFont(Future<ByteData>.value(ByteData.sublistView(iconBytes)));
  await iconLoader.load();
}

class _CaptureCase {
  const _CaptureCase(this.path, this.route, {this.session});
  final String path;
  final String route;
  final CustomerSession? session;
}

class _EvidenceB2bApi implements B2bApi {
  const _EvidenceB2bApi();

  @override
  Future<Object?> get(String path) async {
    if (path.contains('/products/top')) {
      return {
        'data': [
          {'rank': 1, 'product_id': 42, 'sku': 'TOP-1', 'name': 'FOODEX Bulk Rice', 'quantity': 24, 'total': 174, 'currency': 'KWD'},
          {'rank': 2, 'product_id': 43, 'sku': 'TOP-2', 'name': 'FOODEX Olive Oil', 'quantity': 18, 'total': 333, 'currency': 'KWD'},
        ],
      };
    }
    if (path.contains('/products/42')) {
      return {
        'id': 42,
        'sku': 'B2B-P-42',
        'name': 'FOODEX Wholesale Tomato Box',
        'store_id': 7,
        'account_price': 7.25,
        'minimum_order_quantity': 5,
        'price_tier': 'GOLD',
        'available_quantity': 240,
        'is_available': true,
        'currency': 'KWD',
      };
    }
    if (path.endsWith('/products') || path.contains('/products?')) {
      return {
        'data': [
          {'id': 42, 'sku': 'B2B-P-42', 'name': 'FOODEX Wholesale Tomato Box', 'price': 7.25, 'currency': 'KWD'},
          {'id': 43, 'sku': 'B2B-P-43', 'name': 'FOODEX Premium Olive Oil', 'price': 18.5, 'currency': 'KWD'},
        ],
      };
    }
    if (path.endsWith('/dashboard')) {
      return {'purchases_total': 321.75, 'open_invoices': 4, 'balance': 88.5, 'currency': 'KWD'};
    }
    if (path.contains('/reports/purchases')) {
      return {
        'period': {'from': '2026-09-01', 'to': '2026-09-30'},
        'data': [
          {'product_name': 'FOODEX Bulk Rice', 'quantity': 12, 'total': 48.0},
          {'product_name': 'FOODEX Olive Oil', 'quantity': 6, 'total': 111.0},
        ],
      };
    }
    if (path.endsWith('/invoices')) {
      return {
        'data': [
          {'id': 31, 'invoice_number': 'INV-31', 'payment_status': 'paid', 'total': 55.25, 'currency': 'KWD'},
          {'id': 32, 'invoice_number': 'INV-32', 'payment_status': 'pending', 'total': 91.0, 'currency': 'KWD'},
        ],
      };
    }
    if (path.contains('/invoices/31')) {
      return {'invoice_number': 'INV-31', 'payment_status': 'paid', 'total': 55.25, 'currency': 'KWD', 'items_count': 4};
    }
    if (path.endsWith('/account-statement')) {
      return {
        'balance': 19.75,
        'currency': 'KWD',
        'data': [
          {'reference': 'INV-31', 'type': 'invoice', 'amount': 55.25},
          {'reference': 'PAY-31', 'type': 'payment', 'amount': -35.5},
        ],
      };
    }
    if (path.endsWith('/orders')) {
      return {
        'data': [
          {'id': 77, 'order_number': 'B2B-77', 'status': 'processing', 'grand_total': 101.0, 'currency': 'KWD'},
          {'id': 78, 'order_number': 'B2B-78', 'status': 'delivered', 'grand_total': 74.5, 'currency': 'KWD'},
        ],
      };
    }
    if (path.contains('/orders/77')) {
      return {'id': 77, 'order_number': 'B2B-77', 'status': 'out_for_delivery', 'driver': 'FOODEX Driver 1', 'grand_total': 101.0};
    }
    if (path.startsWith('/api/v1/cart')) {
      return {
        'store_id': 7,
        'subtotal': 36.25,
        'items': [
          {'product_id': 42, 'name': 'FOODEX Wholesale Tomato Box', 'quantity': 5, 'unit_price': 7.25},
        ],
      };
    }
    if (path.endsWith('/profile')) {
      return {'company_name': 'FOODEX Business Demo', 'email': 'buyer@foodex.test', 'tax_number': 'TX-900'};
    }
    return {'status': 'ok'};
  }
}

class _EvidenceCatalogApi implements B2cCatalogApi {
  const _EvidenceCatalogApi();

  @override
  Future<List<B2cStore>> stores() async => const [
        B2cStore(id: 7, name: 'FOODEX Premium Demo Store', code: 'FOODEX-DEMO-B2C'),
      ];

  @override
  Future<List<B2cCategory>> categories(int storeId) async => const [
        B2cCategory(id: 3, name: 'خضروات'),
        B2cCategory(id: 4, name: 'بقالة'),
      ];

  @override
  Future<List<B2cOffer>> offers(int storeId) async => const [
        B2cOffer(id: 9, name: 'عرض نهاية الأسبوع', type: 'percentage', value: 10),
        B2cOffer(id: 10, name: 'عرض FOODEX', type: 'fixed', value: 2),
      ];

  @override
  Future<List<B2cProduct>> products(int storeId, {String? query, int? categoryId}) async => const [
        B2cProduct(id: 42, name: 'صندوق طماطم طازج', sku: 'TOM-42', price: 3.25),
        B2cProduct(id: 43, name: 'زيت زيتون عضوي', sku: 'OLV-43', price: 18.5),
      ];

  @override
  Future<B2cProduct> product(int productId, {required int storeId}) async => const B2cProduct(
        id: 42,
        name: 'صندوق طماطم طازج',
        sku: 'TOM-42',
        price: 3.25,
        description: 'منتج FOODEX تجريبي آمن لصور المنتج.',
      );
}

class _EvidenceAccountApi implements B2cAccountApi {
  const _EvidenceAccountApi();

  @override
  Future<Object?> cart({int? storeId}) async => {
        'id': 1,
        'store_id': storeId ?? 7,
        'currency': 'KWD',
        'items': [
          {
            'id': 5,
            'product': {'id': 42, 'name': 'صندوق طماطم طازج'},
            'quantity': 2,
            'unit_price': 3.25,
            'line_total': 6.5,
          }
        ],
        'subtotal': 6.5,
      };

  @override
  Future<Object?> updateCartItem(int itemId, double quantity) async => {'id': itemId, 'quantity': quantity};

  @override
  Future<void> removeCartItem(int itemId) async {}

  @override
  Future<Object?> order(int orderId) async => {
        'id': orderId,
        'order_number': 'FOODEX-$orderId',
        'status': 'out_for_delivery',
        'currency': 'KWD',
        'grand_total': 18.5,
        'driver_name': 'ناصر · سائق FOODEX',
      };

  @override
  Future<Object?> profile() async => {
        'name': 'عميل FOODEX التجريبي',
        'email': 'customer@foodex.test',
        'phone': '+96555500000',
      };

  @override
  Future<Object?> addresses() async => {
        'data': [
          {'label': 'المنزل', 'area': 'بيان', 'block': '1', 'street': 'شارع تجريبي'}
        ],
      };

  @override
  Future<Object?> favorites() async => {
        'data': [
          {'id': 42, 'name': 'صندوق طماطم طازج', 'sku': 'TOM-42'}
        ],
      };
}

class _EvidenceActionApi implements CustomerActionApi {
  const _EvidenceActionApi();

  @override
  Future<CustomerLoginResult> login({required String email, required String password}) async =>
      const CustomerLoginResult(token: 'evidence-token');

  @override
  Future<Object?> addCartItem({required int storeId, required int productId, required double quantity}) async =>
      {'store_id': storeId, 'product_id': productId, 'quantity': quantity};

  @override
  Future<Object?> checkout({required int addressId, String? paymentMethod, required String idempotencyKey}) async =>
      {'id': 101, 'status': 'confirmed'};
}
