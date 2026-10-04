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
import 'package:foodex_customer_app/core/api/storefront_api.dart';
import 'package:foodex_customer_app/core/api/wholesale_commerce_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/theme/foodex_theme.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_order_models.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_orders_api.dart';

const _b2b = CustomerSession.authenticated(
  CustomerChannel.b2b,
  accessToken: 'evidence-token',
);
const _b2c = CustomerSession.authenticated(
  CustomerChannel.b2c,
  accessToken: 'evidence-token',
);
const _b2cWholesale = CustomerSession.authenticated(
  CustomerChannel.b2c,
  accessToken: 'evidence-token',
  b2bRetailStoreId: 7,
);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setUpAll(_loadEvidenceFont);

  final cases = <_CaptureCase>[
    const _CaptureCase('02_MultiStore/01_store_selection__ar.png', '/customer/store-selector', session: _b2c),
    const _CaptureCase('02_MultiStore/02_retail_grocery_home__ar.png', '/retail/7/home', session: _b2c),
    const _CaptureCase('02_MultiStore/03_retail_pharmacy_home__ar.png', '/retail/8/home', session: _b2c),
    const _CaptureCase('02_MultiStore/04_wholesale_home__ar.png', '/b2b/home?store_id=70', session: _b2cWholesale),
    const _CaptureCase('02_MultiStore/05_retail_product_details__ar.png', '/retail/7/products/42', session: _b2c),
    const _CaptureCase('02_MultiStore/06_wholesale_product_details__ar.png', '/b2b/products/42?store_id=70', session: _b2cWholesale),
    const _CaptureCase('02_MultiStore/07_wholesale_cart__ar.png', '/b2b/cart?store=70', session: _b2cWholesale),
    const _CaptureCase('02_MultiStore/08_wholesale_checkout__ar.png', '/b2b/checkout?store_id=70', session: _b2cWholesale),
    const _CaptureCase('02_MultiStore/09_wholesale_orders__ar.png', '/b2b/orders', session: _b2cWholesale),
    // C13 #873 final integrated exact-head matrix: capture Screens 1-13 in the existing AR/EN locale loop.
    const _CaptureCase('01_Mobile/B2B_Customer/01_شاشة_الدخول__default__ar.png', '/entry'),
    const _CaptureCase('01_Mobile/B2B_Customer/02_الصفحة_الرئيسية_Dashboard__populated__ar.png', '/b2b/dashboard', session: _b2b),
    const _CaptureCase('01_Mobile/B2B_Customer/03_تقارير_المشتريات_والرسوم_البيانية__populated__ar.png', '/b2b/reports/purchases', session: _b2b),
    const _CaptureCase('01_Mobile/B2B_Customer/04_أكثر_المنتجات_طلبا__populated__ar.png', '/b2b/products/top?from=2026-09-01&to=2026-09-30', session: _b2b),
    const _CaptureCase('01_Mobile/B2B_Customer/05_آخر_الفواتير__populated__ar.png', '/b2b/invoices', session: _b2b),
    const _CaptureCase('01_Mobile/B2B_Customer/06_كشف_الحساب_والمعاملات__populated__ar.png', '/b2b/account-statement', session: _b2b),
    const _CaptureCase('01_Mobile/B2B_Customer/07_طلباتي__populated__ar.png', '/b2b/orders', session: _b2b),
    const _CaptureCase('01_Mobile/B2B_Customer/08_تفاصيل_الطلب_وتتبع_الحالة__populated__ar.png', '/b2b/orders/77', session: _b2b),
    // C13 #868 exact-head evidence: Screen 9 is captured in both AR/RTL and EN/LTR by the locale loop.
    const _CaptureCase('01_Mobile/B2B_Customer/09_تفاصيل_الفاتورة__populated__ar.png', '/b2b/invoices/31', session: _b2b),
    const _CaptureCase('01_Mobile/B2B_Customer/10_تصفح_المنتجات__populated__ar.png', '/b2b/products?channel=wholesale&store_id=7', session: _b2b),
    // C13 Screen 11: exact-head product-detail hierarchy evidence.
    const _CaptureCase('01_Mobile/B2B_Customer/11_تفاصيل_المنتج_وإضافة_للسلة__populated__ar.png', '/b2b/products/42?store_id=7', session: _b2b),
    const _CaptureCase('01_Mobile/B2B_Customer/12_سلة_المشتريات_وإتمام_الطلب__populated__ar.png', '/b2b/cart?store=7', session: _b2b),
    const _CaptureCase('01_Mobile/B2B_Customer/13_حسابي_والإعدادات__populated__ar.png', '/b2b/profile', session: _b2b),
    const _CaptureCase('01_Mobile/B2C_Customer/01_الشاشة_الافتتاحية__default__ar.png', '/splash'),
    const _CaptureCase('01_Mobile/B2C_Customer/02_تصفح_كضيف_او_تسجيل_الدخول__default__ar.png', '/entry'),
    const _CaptureCase('01_Mobile/B2C_Customer/04_الصفحة_الرئيسية_للمتجر__populated__ar.png', '/home?channel=retail&store_id=7'),
    const _CaptureCase('01_Mobile/B2C_Customer/05_عروض_وتخفيضات__populated__ar.png', '/offers?channel=retail&store_id=7'),
    const _CaptureCase('01_Mobile/B2C_Customer/06_قائمة_المنتجات_والفلاتر__populated__ar.png', '/products?channel=retail&store_id=7'),
    const _CaptureCase('01_Mobile/B2C_Customer/07_تفاصيل_المنتج__populated__ar.png', '/products/42?channel=retail&store_id=7'),
    const _CaptureCase('01_Mobile/B2C_Customer/08_سلة_التسوق__populated__ar.png', '/cart?channel=retail&store_id=7'),
    const _CaptureCase('01_Mobile/B2C_Customer/09_تسجيل_الدخول_لإتمام_الطلب__default__ar.png', '/auth/checkout?channel=retail&store_id=7&next=%2Fcheckout%2Faddress-payment%3Fchannel%3Dretail%26store_id%3D7'),
    const _CaptureCase('01_Mobile/B2C_Customer/10_العنوان_والدفع__default__ar.png', '/checkout/address-payment?channel=retail&store_id=7', session: _b2c),
    const _CaptureCase('01_Mobile/B2C_Customer/11_تتبع_الطلب__populated__ar.png', '/orders/101/track?channel=retail&store_id=7', session: _b2c),
    const _CaptureCase('01_Mobile/B2C_Customer/12_الملف_الشخصي_والمفضلة__populated__ar.png', '/profile?channel=retail&store_id=7', session: _b2c),
    const _CaptureCase('01_Mobile/B2C_Customer/13_عناويني__populated__ar.png', '/profile/addresses?channel=retail&store_id=7', session: _b2c),
    const _CaptureCase('01_Mobile/B2C_Customer/15_التصنيفات__populated__ar.png', '/categories?channel=retail&store_id=7', session: _b2c),
    const _CaptureCase('01_Mobile/B2C_Customer/16_المفضلة__populated__ar.png', '/favorites?channel=retail&store_id=7', session: _b2c),
    const _CaptureCase('01_Mobile/B2C_Customer/17_طلباتي__populated__ar.png', '/orders?channel=retail&store_id=7', session: _b2c),
    const _CaptureCase('01_Mobile/B2C_Customer/18_الإشعارات__populated__ar.png', '/notifications?channel=retail&store_id=7', session: _b2c),
    const _CaptureCase('01_Mobile/B2C_Customer/19_الإعدادات_والمساعدة__populated__ar.png', '/profile/settings?channel=retail&store_id=7', session: _b2c),
  ];

  for (final locale in const [Locale('ar'), Locale('en')]) {
    final localeCode = locale.languageCode;
    for (final item in cases) {
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
              storefrontApi: const _EvidenceStorefrontApi(),
              wholesaleCommerceApi: const _EvidenceWholesaleCommerceApi(),
              customerOrdersApi: const _EvidenceOrdersApi(),
              locale: locale,
            ),
          ),
        );
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 150));

        if (item.route == '/b2b/account-statement') {
          expect(
            find.byKey(const ValueKey('b2b-statement-summary-closing')),
            findsOneWidget,
          );
          expect(
            find.byKey(const ValueKey('b2b-statement-direction-chip')),
            findsOneWidget,
          );
        }

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

    testWidgets('capture My Addresses edit with location $localeCode', (tester) async {
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
            session: _b2c,
            initialRoute: '/profile/addresses?channel=retail&store_id=7',
            b2bApi: const _EvidenceB2bApi(),
            b2cCatalogApi: const _EvidenceCatalogApi(),
            b2cAccountApi: const _EvidenceAccountApi(),
            actionApi: const _EvidenceActionApi(),
            storefrontApi: const _EvidenceStorefrontApi(),
            wholesaleCommerceApi: const _EvidenceWholesaleCommerceApi(),
            customerOrdersApi: const _EvidenceOrdersApi(),
            locale: locale,
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('customer-address-menu-8')));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(PopupMenuItem<String>).first);
      await tester.pumpAndSettle();
      expect(
        find.byKey(const ValueKey('customer-address-coordinates')),
        findsOneWidget,
      );

      await tester.runAsync(() async {
        final boundary = boundaryKey.currentContext!.findRenderObject()!
            as RenderRepaintBoundary;
        final image = await boundary.toImage(pixelRatio: 1);
        final data = await image.toByteData(format: ui.ImageByteFormat.png);
        final file = File(
          '../../ScreenShots/01_Mobile/B2C_Customer/14_تعديل_العنوان_والموقع__populated__$localeCode.png',
        );
        file.parent.createSync(recursive: true);
        file.writeAsBytesSync(data!.buffer.asUint8List(), flush: true);
        expect(file.lengthSync(), greaterThan(1000));
      });
    });
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
          {
            'rank': 1,
            'product_id': 42,
            'store_id': 7,
            'sku': 'TOP-1',
            'name': 'FOODEX Bulk Rice',
            'quantity': 24,
            'total': 174,
            'currency': 'KWD',
            'last_purchased_at': '2026-09-28',
            'pack_label': 'Case 12',
            'account_price': 7.25,
            'current_price_currency': 'KWD',
            'available_quantity': 240,
            'availability_state': 'AVAILABLE',
            'can_repurchase': true,
            'unavailable_reason': null,
          },
          {
            'rank': 2,
            'product_id': 43,
            'store_id': 7,
            'sku': 'TOP-2',
            'name': 'FOODEX Olive Oil',
            'quantity': 18,
            'total': 333,
            'currency': 'KWD',
            'last_purchased_at': '2026-09-23',
            'pack_label': 'Carton 6',
            'account_price': 18.5,
            'current_price_currency': 'KWD',
            'available_quantity': 0,
            'availability_state': 'OUT_OF_STOCK',
            'can_repurchase': false,
            'unavailable_reason': 'OUT_OF_STOCK',
          },
        ],
        'period': {'from': null, 'to': null},
        'sort': 'quantity',
        'meta': {
          'page': 1,
          'per_page': 20,
          'total': 2,
          'has_more': false,
        },
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
        'category_name': 'Fresh produce',
        'brand_name': 'FOODEX',
        'available_quantity': 240,
        'is_available': true,
        'currency': 'KWD',
      };
    }
    if (path.endsWith('/products') || path.contains('/products?')) {
      return {
        'data': [
          {
            'id': 42,
            'sku': 'B2B-P-42',
            'name': 'FOODEX Wholesale Tomato Box',
            'account_price': 7.25,
            'currency': 'KWD',
            'minimum_order_quantity': 5,
            'ordering_increment': 1,
            'pack_size': 12,
            'available_quantity': 240,
            'is_available': true,
            'category_id': 3,
            'category_name_ar': 'خضار وفواكه',
            'category_name_en': 'Fruit & vegetables',
          },
          {
            'id': 43,
            'sku': 'B2B-P-43',
            'name': 'FOODEX Premium Olive Oil',
            'account_price': 18.5,
            'currency': 'KWD',
            'minimum_order_quantity': 5,
            'ordering_increment': 1,
            'pack_size': 6,
            'available_quantity': 80,
            'is_available': true,
            'category_id': 4,
            'category_name_ar': 'معلبات',
            'category_name_en': 'Pantry',
          },
        ],
      };
    }
    if (path.endsWith('/dashboard')) {
      return {
        'customer': {
          'id': 9,
          'name': 'FOODEX Business Buyer',
          'email': 'buyer@foodex.test',
        },
        'account': {
          'id': 3,
          'company_name': 'FOODEX Business Demo',
          'status': 'active',
        },
        'finance': {
          'currency': 'KWD',
          'balance': 88.5,
          'balance_direction': 'customer_owes_company',
          'credit_limit': 500.0,
          'available_credit_line': 411.5,
          'open_amount': 146.25,
          'overdue_amount': 24.0,
        },
        'operations': {
          'purchases_this_month': 321.75,
          'payments_this_month': 233.25,
          'invoice_count': 4,
          'order_count': 6,
          'active_orders': 2,
        },
        'freshness': {
          'generated_at': DateTime.now().toUtc().toIso8601String(),
          'stale': false,
        },
        'currency': 'KWD',
      };
    }
    if (path.contains('/reports/purchases')) {
      return {
        'currency': 'KWD',
        'period': {'from': '2026-09-01', 'to': '2026-09-30'},
        'summary': {
          'total_purchases': 159.0,
          'order_count': 2,
          'invoice_count': 2,
          'average_order_value': 79.5,
        },
        'comparison': {
          'from': '2026-08-01',
          'to': '2026-08-31',
          'total_purchases': 120.0,
          'order_count': 2,
          'change_amount': 39.0,
          'change_percent': 32.5,
        },
        'data': [
          {
            'period': '2026-09-12',
            'orders_count': 1,
            'purchase_total': 48.0,
          },
          {
            'period': '2026-09-24',
            'orders_count': 1,
            'purchase_total': 111.0,
          },
        ],
        'categories': [
          {
            'category_id': 1,
            'category_name': 'Staples',
            'purchase_total': 48.0,
            'percentage': 30.19,
          },
          {
            'category_id': 2,
            'category_name': 'Pantry',
            'purchase_total': 111.0,
            'percentage': 69.81,
          },
        ],
        'orders': [
          {
            'id': 77,
            'order_number': 'B2B-77',
            'store_id': 7,
            'status': 'confirmed',
            'currency': 'KWD',
            'grand_total': 48.0,
            'created_at': '2026-09-12T10:00:00Z',
          },
          {
            'id': 78,
            'order_number': 'B2B-78',
            'store_id': 7,
            'status': 'delivered',
            'currency': 'KWD',
            'grand_total': 111.0,
            'created_at': '2026-09-24T11:30:00Z',
          },
        ],
        'meta': {
          'page': 1,
          'per_page': 5,
          'total': 2,
          'has_more': false,
        },
        'generated_at': '2026-09-30T12:00:00Z',
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
      return {
        'data': {
          'id': 31,
          'invoice_number': 'INV-31',
          'display_status': 'partially_paid',
          'status': 'partially_paid',
          'currency': 'KWD',
          'subtotal': 20.0,
          'discount_total': 1.0,
          'delivery_total': 2.0,
          'tax_total': 1.0,
          'total': 22.0,
          'paid_amount': 5.0,
          'outstanding_amount': 17.0,
          'credit_amount': 0.0,
          'store_id': 7,
          'order_id': 77,
          'issued_at': '2026-10-01T10:00:00Z',
          'due_at': '2026-10-20T10:00:00Z',
          'seller': {
            'store_id': 7,
            'name': 'FOODEX Wholesale',
          },
          'customer': {
            'name': 'FOODEX Business Demo',
            'email': 'buyer@foodex.test',
            'phone': '+96555500000',
          },
          'pdf_path':
              '/api/v1/invoices/31/download?channel=b2b&store_id=7',
          'items': [
            {
              'id': 501,
              'sku': 'WHO-501',
              'description': 'FOODEX Wholesale Tomato Box',
              'quantity': 2.0,
              'unit_price': 10.0,
              'discount_total': 1.0,
              'tax_total': 1.0,
              'line_total': 20.0,
              'currency': 'KWD',
            },
          ],
          'payments': [
            {
              'id': 9,
              'method': 'account',
              'reference': 'PAY-31',
              'amount': 5.0,
              'currency': 'KWD',
              'paid_at': '2026-10-02T10:00:00Z',
            },
          ],
          'ledger_entries': [
            {
              'id': 81,
              'type': 'credit_note',
              'reference': 'CN-31',
              'description': 'Credit adjustment',
              'debit': 0.0,
              'credit': 1.0,
              'currency': 'KWD',
              'occurred_at': '2026-10-03T10:00:00Z',
            },
          ],
        },
        'account': {
          'currency': 'KWD',
          'balance': 17.0,
          'balance_direction': 'customer_owes_company',
        },
        'generated_at': '2026-10-04T10:00:00Z',
      };
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
    if (path.endsWith('/profile?channel=retail&store_id=7')) {
      return {'company_name': 'FOODEX Business Demo', 'email': 'buyer@foodex.test', 'tax_number': 'TX-900'};
    }
    return {'status': 'ok'};
  }
}

class _EvidenceCatalogApi implements B2cCatalogApi {
  const _EvidenceCatalogApi();

  @override
  Future<List<B2cStore>> stores() async => const [
        B2cStore(
          id: 7,
          name: 'FOODEX Premium Demo Store',
          code: 'FOODEX-DEMO-B2C',
        ),
      ];

  @override
  Future<List<B2cCategory>> categories(int storeId) async => const [
        B2cCategory(id: 3, name: 'خضروات'),
        B2cCategory(id: 4, name: 'بقالة'),
      ];

  @override
  Future<List<B2cOffer>> offers(int storeId) async => const [
        B2cOffer(
          id: 9,
          name: 'عرض نهاية الأسبوع',
          type: 'percentage',
          value: 10,
        ),
        B2cOffer(id: 10, name: 'عرض FOODEX', type: 'fixed', value: 2),
      ];

  @override
  Future<List<B2cBanner>> banners(int storeId) async => const [
        B2cBanner(
          id: 11,
          title: 'عرض فودكس اليوم',
          targetUrl: '/offers',
        ),
      ];

  @override
  Future<List<B2cProduct>> products(
    int storeId, {
    String? query,
    int? categoryId,
    String? sort,
    String? direction,
  }) async =>
      const [
        B2cProduct(
          id: 42,
          name: 'صندوق طماطم طازج',
          sku: 'TOM-42',
          price: 3.25,
        ),
        B2cProduct(
          id: 43,
          name: 'زيت زيتون عضوي',
          sku: 'OLV-43',
          price: 18.5,
        ),
      ];

  @override
  Future<B2cProduct> product(
    int productId, {
    required int storeId,
  }) async =>
      const B2cProduct(
        id: 42,
        name: 'صندوق طماطم طازج',
        sku: 'TOM-42',
        price: 3.25,
        description: 'منتج FOODEX تجريبي آمن لصور المنتج.',
      );
}

class _EvidenceAccountApi implements B2cAccountApi, B2cRetailFavoritesApi {
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
  Future<Object?> updateCartItem(int itemId, double quantity) async =>
      {'id': itemId, 'quantity': quantity};

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
  Future<Object?> orders() async => {
        'data': [
          {
            'id': 101,
            'order_number': 'FOODEX-101',
            'status': 'out_for_delivery',
            'grand_total': 18.5,
            'currency': 'KWD',
          },
        ],
      };

  @override
  Future<Object?> profile() async => {
        'name': 'عميل FOODEX التجريبي',
        'email': 'customer@foodex.test',
        'phone': '+96555500000',
      };

  @override
  Future<Object?> updateProfile(Map<String, dynamic> values) async => values;

  @override
  Future<Object?> addresses() async => {
        'data': [
          {
            'id': 8,
            'label': 'المنزل',
            'line1': 'قطعة 1 شارع تجريبي',
            'area': 'بيان',
            'city': 'Kuwait City',
            'country_code': 'KW',
            'latitude': 29.3031,
            'longitude': 48.0489,
            'location_accuracy_meters': 6.0,
            'location_source': 'map_pin',
            'landmark': 'قرب الجمعية',
            'is_default': true,
          }
        ],
      };

  @override
  Future<Object?> createAddress(Map<String, dynamic> values) async =>
      {'id': 9, ...values};

  @override
  Future<Object?> updateAddress(
    int addressId,
    Map<String, dynamic> values,
  ) async =>
      {'id': addressId, ...values};

  @override
  Future<Object?> setDefaultAddress(int addressId) async => {'id': addressId, 'is_default': true};

  @override
  Future<void> removeAddress(int addressId) async {}

  @override
  Future<Object?> favorites() async => {
        'data': [
          {'id': 42, 'name': 'صندوق طماطم طازج', 'sku': 'TOM-42'}
        ],
      };

  @override
  Future<void> addFavorite(int productId) async {}

  @override
  Future<void> removeFavorite(int productId) async {}

  @override
  Future<Object?> favoritesForStore(int storeId) async => {
        'data': [
          {
            'id': 42,
            'name': 'صندوق طماطم طازج',
            'sku': 'TOM-42',
            'store_id': storeId,
            'price': 3.25,
            'currency': 'KWD',
          }
        ],
      };

  @override
  Future<void> addFavoriteForStore(int storeId, int productId) async {}

  @override
  Future<void> removeFavoriteForStore(int storeId, int productId) async {}

  @override
  Future<Object?> notifications({String locale = 'ar'}) async => {
        'data': [
          {
            'id': 4,
            'title': locale == 'en' ? 'Order update' : 'تحديث الطلب',
            'body': locale == 'en' ? 'On the way' : 'في الطريق',
            'read_at': null,
          },
        ],
      };

  @override
  Future<void> markNotificationRead(int notificationId) async {}
}


class _EvidenceOrdersApi implements CustomerOrdersApi {
  const _EvidenceOrdersApi();

  CustomerOrderSummary _summary(
    int orderId, {
    String channel = 'b2c',
  }) =>
      CustomerOrderSummary(
        id: orderId,
        orderNumber: 'FOODEX-$orderId',
        storeId: channel == 'b2b' ? 70 : 7,
        storeName:
            channel == 'b2b' ? 'FOODEX Wholesale' : 'FOODEX Fresh Market',
        storeLogoUrl: null,
        channel: channel,
        status: 'out_for_delivery',
        currency: 'KWD',
        grandTotal: 18.5,
        createdAt: DateTime.utc(2026, 10, 1, 10, 30),
      );

  @override
  Future<CustomerOrderPage> orders({
    int page = 1,
    int perPage = 20,
    String? status,
    String? channel,
    CustomerOrderContext? context,
  }) async =>
      CustomerOrderPage(
        orders: [
          _summary(
            101,
            channel: channel ?? context?.normalizedChannel ?? 'b2c',
          ),
        ],
        currentPage: page,
        perPage: perPage,
        total: 1,
        scope: channel ??
            (context == null
                ? 'all'
                : '${context.normalizedChannel}:${context.storeId}'),
      );

  @override
  Future<CustomerOrderDetails> order({
    required int orderId,
    CustomerOrderContext? context,
  }) async =>
      CustomerOrderDetails(
        summary: _summary(orderId),
        subtotal: 16.5,
        discountTotal: 0,
        deliveryTotal: 2,
        paymentMethod: 'cash_on_delivery',
        deliveryAddress: const {
          'label': 'المنزل',
          'line1': 'قطعة 1 شارع تجريبي',
          'area': 'بيان',
          'city': 'Kuwait City',
          'country_code': 'KW',
        },
        items: const [
          CustomerOrderItem(
            id: 1,
            productId: 42,
            sku: 'TOM-42',
            name: 'صندوق طماطم طازج',
            quantity: 2,
            unitPrice: 8.25,
            lineTotal: 16.5,
          ),
        ],
        history: [
          CustomerOrderHistoryEntry(
            id: 1,
            fromStatus: 'confirmed',
            toStatus: 'preparing',
            note: null,
            createdAt: DateTime.utc(2026, 10, 1, 10, 35),
          ),
          CustomerOrderHistoryEntry(
            id: 2,
            fromStatus: 'preparing',
            toStatus: 'out_for_delivery',
            note: 'FOODEX Driver assigned',
            createdAt: DateTime.utc(2026, 10, 1, 11),
          ),
        ],
        payment: const CustomerOrderPayment(
          id: 1,
          provider: 'cash',
          status: 'pending',
          amount: 18.5,
          currency: 'KWD',
        ),
        requestedDeliveryDate: '2026-10-01',
      );
}

class _EvidenceActionApi implements CustomerActionApi {
  const _EvidenceActionApi();

  @override
  Future<CustomerLoginResult> login({required String username}) async =>
      const CustomerLoginResult(token: 'evidence-token');

  @override
  Future<void> logout() async {}

  @override
  Future<Object?> addCartItem({required int storeId, required int productId, required double quantity}) async =>
      {'store_id': storeId, 'product_id': productId, 'quantity': quantity};

  @override
  Future<Object?> checkout({required int addressId, int? storeId, String? paymentMethod, String? couponCode, required String idempotencyKey}) async =>
      {'id': 101, 'status': 'confirmed'};
}


class _EvidenceStorefrontApi implements StorefrontApi {
  const _EvidenceStorefrontApi();

  @override
  Future<Map<String, dynamic>> selection({
    String? countryCode,
    String? city,
    String? area,
    bool support = false,
  }) async => {
        'retail_stores': [
          {
            'id': 7,
            'code': 'GROCERY-7',
            'name': 'FOODEX Fresh Market',
            'theme_code': 'retail_grocery',
            'address': 'سموحة · توصيل سريع',
          },
          {
            'id': 8,
            'code': 'PHARMACY-8',
            'name': 'FOODEX Pharmacy',
            'theme_code': 'retail_pharmacy',
            'address': 'صيدلية · خدمة 24 ساعة',
          },
        ],
        'wholesale_stores': [
          {
            'id': 70,
            'code': 'WHOLESALE-70',
            'name': 'FOODEX Wholesale',
            'theme_code': 'wholesale_b2b',
            'retail_context_ids': [7],
          },
        ],
        'entitlements': {
          'retail_context_ids': [7],
          'direct_b2b': false,
        },
      };

  @override
  Future<Map<String, dynamic>> retailHome(int storeId) async {
    final pharmacy = storeId == 8;
    return {
      'store': {
        'id': storeId,
        'name': pharmacy ? 'FOODEX Pharmacy' : 'FOODEX Fresh Market',
      },
      'theme': {
        'code': pharmacy ? 'retail_pharmacy' : 'retail_grocery',
        'primary': pharmacy ? '#0A8DDA' : '#078A43',
        'primary_dark': pharmacy ? '#0668A9' : '#006736',
      },
      'branding': {
        'address': pharmacy ? 'صيدلية · خدمة 24 ساعة' : 'سموحة · توصيل سريع',
      },
      'hero': {
        'title': pharmacy ? 'صحتك أولويتنا' : 'طازج كل يوم',
      },
      'sections': [
        {'key': 'hero', 'type': 'hero', 'sort_order': 10},
        {'key': 'categories', 'type': 'categories', 'title_ar': 'التصنيفات', 'sort_order': 20},
        {'key': 'products', 'type': 'products', 'title_ar': pharmacy ? 'العناية والصحة' : 'وصل حديثًا', 'sort_order': 30},
      ],
    };
  }

  @override
  Future<Map<String, dynamic>> wholesaleHome(int storeId) async => {
        'store': {
          'id': storeId,
          'code': 'WHOLESALE-$storeId',
          'name': 'FOODEX Wholesale',
          'theme_code': 'wholesale_b2b',
        },
        'theme': {
          'code': 'wholesale_b2b',
          'primary': '#5D2A91',
          'primary_dark': '#35195E',
          'accent': '#B983F0',
          'background': '#FBFAFD',
        },
        'branding': {
          'address': 'تغطية توريد الجملة',
          'custom': {
            'brand_title_ar': 'FOODEX جملة',
            'brand_subtitle_ar': 'أفضل الأسعار لمتاجر التجزئة',
            'hero_cta_ar': 'تصفح الكتالوج',
          },
        },
        'hero': {
          'title': 'عرض الجملة',
          'image_url': null,
        },
        'sections': [
          {'key': 'hero', 'type': 'hero', 'sort_order': 10},
          {
            'key': 'categories',
            'type': 'categories',
            'title_ar': 'التصنيفات',
            'sort_order': 20,
          },
          {
            'key': 'offers',
            'type': 'offers',
            'title_ar': 'عروض الجملة',
            'sort_order': 30,
          },
        ],
      };

  @override
  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId) async => {
        'store_id': storeId,
        'addresses': [
          {
            'id': 81,
            'label': 'المتجر',
            'line1': 'سموحة - شارع FOODEX',
            'area': 'سموحة',
            'city': 'الإسكندرية',
            'country_code': 'EG',
            'is_default': true,
          },
        ],
        'delivery_dates': ['2026-09-29', '2026-09-30', '2026-10-01'],
        'payment_methods': ['cash_on_delivery', 'account_credit'],
        'credit_limit': 5000,
        'currency': 'EGP',
      };
}

class _EvidenceWholesaleCommerceApi implements WholesaleCommerceApi {
  const _EvidenceWholesaleCommerceApi();

  @override
  Future<Object?> cart(int storeId) async => {
        'store_id': storeId,
        'subtotal': 725.0,
        'currency': 'EGP',
        'items': [
          {
            'id': 501,
            'product_id': 42,
            'name': 'كرتونة طماطم FOODEX',
            'quantity': 10,
            'unit_price': 72.5,
            'line_total': 725.0,
            'minimum_order_quantity': 5,
            'ordering_increment': 5,
            'pack_size': 12,
            'pack_label': 'كرتونة 12',
          },
        ],
      };

  @override
  Future<Object?> addItem(int storeId, int productId, double quantity) async =>
      cart(storeId);

  @override
  Future<Object?> updateItem(int itemId, double quantity) async => {
        'id': itemId,
        'quantity': quantity,
      };

  @override
  Future<void> removeItem(int itemId) async {}

  @override
  Future<Object?> checkout({
    required int storeId,
    required int addressId,
    required String paymentMethod,
    String? requestedDeliveryDate,
    String? note,
    String? couponCode,
    required String idempotencyKey,
  }) async => {
        'id': 990,
        'order_number': 'B2B-990',
        'store_id': storeId,
        'status': 'pending',
        'currency': 'EGP',
        'grand_total': 725.0,
      };
}
