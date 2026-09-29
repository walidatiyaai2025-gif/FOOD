import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/api/b2b_api.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/core/api/storefront_api.dart';
import 'package:foodex_customer_app/core/api/wholesale_commerce_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';

void main() {
  const b2b = CustomerSession.authenticated(CustomerChannel.b2b);

  testWidgets('B2B unauthenticated protected route uses the unified Customer login', (tester) async {
    await tester.pumpWidget(const FoodexCustomerApp(initialRoute: '/b2b/dashboard'));
    await tester.pumpAndSettle();
    expect(find.text('تسجيل الدخول'), findsWidgets);
    expect(find.textContaining('/auth/checkout'), findsOneWidget);
    expect(find.textContaining('next='), findsOneWidget);
  });

  testWidgets('B2B approved customer dashboard is RTL and exposes finance areas', (tester) async {
    await tester.pumpWidget(const FoodexCustomerApp(session: b2b, initialRoute: '/b2b/dashboard'));
    await tester.pumpAndSettle();
    expect(Directionality.of(tester.element(find.text('لوحة الأعمال'))), TextDirection.rtl);
    expect(find.text('المشتريات'), findsOneWidget);
    expect(find.text('الفواتير'), findsOneWidget);
    expect(find.text('كشف الحساب'), findsOneWidget);
  });

  testWidgets('B2B storefront applies server branding and theme on mobile', (tester) async {
    final storefront = _FakeWholesaleStorefrontApi();
    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/home?store_id=70',
        b2bApi: _FakeB2bApi({
          'data': [
            {
              'id': 42,
              'name': 'Bulk Water',
              'sku': 'WATER-42',
              'account_price': 75,
              'minimum_order_quantity': 5,
              'category_id': 3,
            },
          ],
        }),
        storefrontApi: storefront,
        actionApi: _FakeCustomerActionApi(),
      ),
    );
    await tester.pumpAndSettle();

    expect(storefront.lastStoreId, 70);
    expect(find.text('فودكس جملة المخصص'), findsOneWidget);
    expect(find.text('أسعار مخصصة من لوحة التحكم'), findsOneWidget);
    expect(find.text('ابدأ طلب الجملة'), findsOneWidget);
    expect(find.text('تصنيفات الجملة'), findsOneWidget);
    await tester.scrollUntilVisible(
      find.text('عروض الحساب'),
      280,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.text('عروض الحساب'), findsOneWidget);

    final scaffold = tester.widget<Scaffold>(find.byType(Scaffold).first);
    expect(scaffold.backgroundColor, const Color(0xFFF7F1FC));
  });

  testWidgets('B2B product details render authoritative account pricing and inventory', (tester) async {
    final api = _FakeB2bApi({
      'id': 42,
      'sku': 'B2B-P-1',
      'name': 'Wholesale Product',
      'store_id': 7,
      'account_price': 7.25,
      'minimum_order_quantity': 5,
      'price_tier': 'GOLD',
      'available_quantity': 24,
      'is_available': true,
      'currency': 'KWD',
      'image_url': 'https://example.invalid/b2b-primary.jpg',
      'images': [
        'https://example.invalid/b2b-primary.jpg',
        'https://example.invalid/b2b-second.jpg',
      ],
    });
    final actionApi = _FakeCustomerActionApi();
    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/products/42?store_id=7',
        b2bApi: api,
        actionApi: actionApi,
      ),
    );
    await tester.pumpAndSettle();

    expect(api.lastPath, '/api/v1/b2b/products/42?store_id=7');
    expect(find.byKey(const ValueKey('b2b-product-detail-data')), findsOneWidget);
    expect(find.byKey(const ValueKey('b2b-product-gallery')), findsOneWidget);
    expect(find.byType(PageView), findsOneWidget);
    expect(find.text('Wholesale Product'), findsOneWidget);
    expect(find.textContaining('7.25 KWD'), findsOneWidget);
    expect(find.textContaining('5'), findsWidgets);
    expect(find.textContaining('24'), findsWidgets);
    expect(find.text('إضافة إلى السلة'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('customer-add-cart')));
    await tester.pumpAndSettle();

    expect(actionApi.lastStoreId, 7);
    expect(actionApi.lastProductId, 42);
    expect(find.text('سلة الجملة'), findsOneWidget);
    expect(api.lastPath, '/api/v1/cart?store=7');
  });

  testWidgets('B2B cart exposes authoritative checkout action', (tester) async {
    await tester.pumpWidget(const FoodexCustomerApp(session: b2b, initialRoute: '/b2b/cart', b2bApi: _StaticB2bApi()));
    await tester.pumpAndSettle();
    expect(find.text('سلة الجملة'), findsOneWidget);
    expect(find.text('إتمام الطلب'), findsOneWidget);
  });

  testWidgets('B2B checkout with order id navigates to B2B details and uses B2B API', (tester) async {
    final api = _FakeB2bApi({
      'id': 4,
      'order_number': 'FDX-B2B-4',
      'status': 'pending',
      'store_name': 'Wholesale Store',
      'grand_total': 25.5,
      'currency': 'KWD',
      'payment_method': 'cash_on_delivery',
      'items': [
        {
          'name': 'Bulk Water',
          'quantity': 2,
          'unit_price': 12.75,
          'line_total': 25.5,
        },
      ],
    });

    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/checkout?store=7',
        b2bApi: api,
        storefrontApi: const _CheckoutStorefrontApi(),
        wholesaleCommerceApi: const _FakeWholesaleCommerceApi({'id': 4}),
      ),
    );
    await tester.pumpAndSettle();

    await tester.scrollUntilVisible(
      find.text('تأكيد الطلب'),
      300,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.tap(find.text('تأكيد الطلب'));
    await tester.pumpAndSettle();

    expect(api.lastPath, '/api/v1/b2b/orders/4');
    expect(find.byKey(const ValueKey('b2b-order-detail')), findsOneWidget);
    expect(find.text('FDX-B2B-4'), findsOneWidget);
    expect(find.text('Wholesale Store'), findsOneWidget);
    expect(find.text('الدفع عند الاستلام'), findsOneWidget);
    expect(find.text('/orders/4/track'), findsNothing);
  });

  testWidgets('B2B checkout without usable order id falls back to B2B orders', (tester) async {
    final api = _FakeB2bApi(const {'data': <Object>[]});

    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/checkout?store=7',
        b2bApi: api,
        storefrontApi: const _CheckoutStorefrontApi(),
        wholesaleCommerceApi: const _FakeWholesaleCommerceApi(<String, Object?>{}),
      ),
    );
    await tester.pumpAndSettle();

    await tester.scrollUntilVisible(
      find.text('تأكيد الطلب'),
      300,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.tap(find.text('تأكيد الطلب'));
    await tester.pumpAndSettle();

    expect(api.lastPath, '/api/v1/b2b/orders');
    expect(find.byKey(const ValueKey('b2b-empty')), findsOneWidget);
  });

  testWidgets('B2B order details refresh route stays on authoritative B2B endpoint', (tester) async {
    final api = _FakeB2bApi({
      'id': 77,
      'order_number': 'B2B-77',
      'status': 'out_for_delivery',
      'grand_total': 101.0,
      'currency': 'KWD',
      'payment_method': 'account_credit',
      'items': const <Object>[],
    });

    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/orders/77',
        b2bApi: api,
      ),
    );
    await tester.pumpAndSettle();

    expect(api.lastPath, '/api/v1/b2b/orders/77');
    expect(find.byKey(const ValueKey('b2b-order-detail')), findsOneWidget);
    expect(find.text('B2B-77'), findsOneWidget);
  });

  testWidgets('B2C session cannot enter B2B protected journey', (tester) async {
    await tester.pumpWidget(const FoodexCustomerApp(session: CustomerSession.authenticated(CustomerChannel.b2c), initialRoute: '/b2b/invoices'));
    await tester.pumpAndSettle();
    expect(find.text('دخول عميل الأعمال'), findsOneWidget);
  });

  testWidgets('B2B top products use ranked endpoint and render authoritative data', (tester) async {
    final api = _FakeB2bApi({
      'data': [
        {
          'rank': 1,
          'product_id': 42,
          'sku': 'TOP-1',
          'name': 'Top Product',
          'quantity': 12,
          'total': 144.5,
          'currency': 'KWD',
        },
      ],
      'period': {'from': '2026-09-01', 'to': '2026-09-30'},
    });
    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/products/top?from=2026-09-01&to=2026-09-30',
        b2bApi: api,
      ),
    );
    await tester.pumpAndSettle();

    expect(api.lastPath, '/api/v1/b2b/products/top?from=2026-09-01&to=2026-09-30');
    expect(find.byKey(const ValueKey('b2b-top-products-data')), findsOneWidget);
    expect(find.textContaining('Top Product'), findsOneWidget);
    expect(find.textContaining('144.5 KWD'), findsOneWidget);
  });

  testWidgets('B2B remote routes visibly render authoritative payload fields', (tester) async {
    final cases = <({String route, Object? payload, String expected})>[
      (
        route: '/b2b/dashboard',
        payload: {
          'purchases_total': 321.75,
          'open_invoices': 4,
          'balance': 88.5,
          'currency': 'KWD',
        },
        expected: '321.75',
      ),
      (
        route: '/b2b/reports/purchases',
        payload: {
          'period': {'from': '2026-09-01', 'to': '2026-09-30'},
          'data': [
            {'product_name': 'Bulk Rice', 'quantity': 12, 'total': 48.0},
          ],
        },
        expected: 'Bulk Rice',
      ),
      (
        route: '/b2b/invoices',
        payload: {
          'data': [
            {
              'id': 31,
              'invoice_number': 'INV-31',
              'payment_status': 'paid',
              'total': 55.25,
              'currency': 'KWD',
            },
          ],
        },
        expected: 'INV-31',
      ),
      (
        route: '/b2b/account-statement',
        payload: {
          'balance': 19.75,
          'currency': 'KWD',
          'data': <Object?>[],
        },
        expected: '19.75',
      ),
      (
        route: '/b2b/orders',
        payload: {
          'data': [
            {
              'id': 77,
              'order_number': 'B2B-77',
              'status': 'processing',
              'grand_total': 101.0,
            },
          ],
        },
        expected: 'B2B-77',
      ),
      (
        route: '/b2b/cart?store=7',
        payload: {
          'store_id': 7,
          'subtotal': 36.25,
          'items': [
            {
              'product_id': 42,
              'name': 'Wholesale Product',
              'quantity': 5,
              'unit_price': 7.25,
            },
          ],
        },
        expected: 'Wholesale Product',
      ),
      (
        route: '/b2b/profile',
        payload: {
          'company_name': 'Acme Foods',
          'email': 'buyer@example.test',
          'tax_number': 'TX-900',
        },
        expected: 'Acme Foods',
      ),
    ];

    for (final item in cases) {
      final api = _FakeB2bApi(item.payload);
      await tester.pumpWidget(
        FoodexCustomerApp(
          key: ValueKey('b2b-route-${item.route}'),
          session: b2b,
          initialRoute: item.route,
          b2bApi: api,
        ),
      );
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('b2b-loaded')),
        findsNothing,
        reason: item.route,
      );
      expect(find.textContaining(item.expected), findsWidgets, reason: item.route);
      expect(
        Directionality.of(tester.element(find.textContaining(item.expected).first)),
        TextDirection.rtl,
        reason: item.route,
      );
    }
  });

  testWidgets('B2B collections expose approved detail navigation', (tester) async {
    final api = _FakeB2bApi({
      'data': [
        {
          'id': 31,
          'invoice_number': 'INV-31',
          'payment_status': 'paid',
        },
      ],
    });
    await tester.pumpWidget(
      FoodexCustomerApp(
        session: b2b,
        initialRoute: '/b2b/invoices',
        b2bApi: api,
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('INV-31'));
    await tester.pumpAndSettle();

    expect(find.text('/b2b/invoices/31'), findsOneWidget);
    expect(api.lastPath, '/api/v1/b2b/invoices/31');
  });

  testWidgets('B2B remote journey renders loading and empty states', (tester) async {
    final api = _FakeB2bApi(const {'data': []});
    await tester.pumpWidget(FoodexCustomerApp(session: b2b, initialRoute: '/b2b/invoices', b2bApi: api));
    expect(find.byKey(const ValueKey('b2b-loading')), findsOneWidget);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('b2b-empty')), findsOneWidget);
    expect(api.lastPath, '/api/v1/b2b/invoices');
  });

  testWidgets('B2B remote journey renders safe error state', (tester) async {
    await tester.pumpWidget(FoodexCustomerApp(session: b2b, initialRoute: '/b2b/account-statement', b2bApi: _FailingB2bApi()));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('b2b-error')), findsOneWidget);
  });
}

class _CheckoutStorefrontApi implements StorefrontApi {
  const _CheckoutStorefrontApi();

  @override
  Future<Map<String, dynamic>> selection({
    String? countryCode,
    String? city,
    String? area,
    bool support = false,
  }) async =>
      const {};

  @override
  Future<Map<String, dynamic>> retailHome(int storeId) async => const {};

  @override
  Future<Map<String, dynamic>> wholesaleHome(int storeId) async => const {};

  @override
  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId) async => const {
        'addresses': [
          {'id': 1, 'label': 'المخزن', 'line1': 'شارع 1'},
        ],
        'delivery_dates': ['2026-09-30'],
        'payment_methods': ['cash_on_delivery'],
      };
}

class _FakeWholesaleCommerceApi implements WholesaleCommerceApi {
  const _FakeWholesaleCommerceApi(this.checkoutResult);

  final Object? checkoutResult;

  @override
  Future<Object?> cart(int storeId) async => const {
        'currency': 'KWD',
        'subtotal': 25.5,
        'grand_total': 25.5,
        'items': [
          {'name': 'Bulk Water', 'quantity': 2},
        ],
      };

  @override
  Future<Object?> addItem(int storeId, int productId, double quantity) async =>
      null;

  @override
  Future<Object?> updateItem(int itemId, double quantity) async => null;

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
  }) async =>
      checkoutResult;
}

class _FakeWholesaleStorefrontApi implements StorefrontApi {
  int? lastStoreId;

  @override
  Future<Map<String, dynamic>> selection({
    String? countryCode,
    String? city,
    String? area,
    bool support = false,
  }) async =>
      const {};

  @override
  Future<Map<String, dynamic>> retailHome(int storeId) async => const {};

  @override
  Future<Map<String, dynamic>> wholesaleHome(int storeId) async {
    lastStoreId = storeId;
    return {
      'store': {'id': storeId, 'name': 'Wholesale'},
      'theme': {
        'code': 'wholesale_b2b',
        'primary': '#712FA5',
        'primary_dark': '#35195E',
        'accent': '#D6A7FA',
        'background': '#F7F1FC',
      },
      'branding': {
        'address': 'القاهرة والإسكندرية',
        'custom': {
          'brand_title_ar': 'فودكس جملة المخصص',
          'brand_subtitle_ar': 'أسعار مخصصة من لوحة التحكم',
          'hero_cta_ar': 'ابدأ طلب الجملة',
        },
      },
      'sections': [
        {'key': 'hero', 'type': 'hero', 'sort_order': 10},
        {
          'key': 'categories',
          'type': 'categories',
          'title_ar': 'تصنيفات الجملة',
          'sort_order': 20,
        },
        {
          'key': 'offers',
          'type': 'offers',
          'title_ar': 'عروض الحساب',
          'sort_order': 30,
        },
      ],
    };
  }

  @override
  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId) async =>
      const {};
}

class _FakeB2bApi implements B2bApi {
  _FakeB2bApi(this.value);
  final Object? value;
  String? lastPath;
  @override
  Future<Object?> get(String path) async { lastPath = path; return value; }
}

class _FailingB2bApi implements B2bApi {
  @override
  Future<Object?> get(String path) async => throw const B2bApiException('test');
}

class _StaticB2bApi implements B2bApi {
  const _StaticB2bApi();
  @override
  Future<Object?> get(String path) async => null;
}


class _FakeCustomerActionApi implements CustomerActionApi {
  int? lastStoreId;
  int? lastProductId;

  @override
  Future<CustomerLoginResult> login({
    required String username,
  }) async =>
      const CustomerLoginResult(token: 'test-token');

  @override
  Future<void> logout() async {}

  @override
  Future<Object?> addCartItem({
    required int storeId,
    required int productId,
    required double quantity,
  }) async {
    lastStoreId = storeId;
    lastProductId = productId;
    return {'store_id': storeId, 'product_id': productId, 'quantity': quantity};
  }

  @override
  Future<Object?> checkout({
    required int addressId,
    int? storeId,
    String? paymentMethod,
    String? couponCode,
    required String idempotencyKey,
  }) async =>
      {'id': 1};
}
