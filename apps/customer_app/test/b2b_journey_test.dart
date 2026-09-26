import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/api/b2b_api.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';

void main() {
  const b2b = CustomerSession.authenticated(CustomerChannel.b2b);

  testWidgets('B2B unauthenticated protected route redirects to login without registration', (tester) async {
    await tester.pumpWidget(const FoodexCustomerApp(initialRoute: '/b2b/dashboard'));
    await tester.pumpAndSettle();
    expect(find.text('دخول عميل الأعمال'), findsOneWidget);
    expect(find.textContaining('تُنشأ وتُعتمد'), findsOneWidget);
  });

  testWidgets('B2B approved customer dashboard is RTL and exposes finance areas', (tester) async {
    await tester.pumpWidget(const FoodexCustomerApp(session: b2b, initialRoute: '/b2b/dashboard'));
    await tester.pumpAndSettle();
    expect(Directionality.of(tester.element(find.text('لوحة الأعمال'))), TextDirection.rtl);
    expect(find.text('المشتريات'), findsOneWidget);
    expect(find.text('الفواتير'), findsOneWidget);
    expect(find.text('كشف الحساب'), findsOneWidget);
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
    expect(find.text('Wholesale Product'), findsOneWidget);
    expect(find.textContaining('7.25 KWD'), findsOneWidget);
    expect(find.textContaining('5'), findsWidgets);
    expect(find.textContaining('24'), findsWidgets);
    expect(find.text('إضافة إلى السلة'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('customer-add-cart')));
    await tester.pumpAndSettle();

    expect(actionApi.lastStoreId, 7);
    expect(actionApi.lastProductId, 42);
    expect(
      find.text('/b2b/cart?store=7'),
      findsOneWidget,
    );
    expect(api.lastPath, '/api/v1/cart?store=7');
  });

  testWidgets('B2B cart exposes authoritative checkout action', (tester) async {
    await tester.pumpWidget(const FoodexCustomerApp(session: b2b, initialRoute: '/b2b/cart', b2bApi: _StaticB2bApi()));
    await tester.pumpAndSettle();
    expect(find.text('سلة الجملة'), findsOneWidget);
    await tester.drag(find.byType(ListView), const Offset(0, -300));
    await tester.pumpAndSettle();
    expect(find.text('إتمام الطلب'), findsOneWidget);
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
    required String email,
    required String password,
  }) async =>
      const CustomerLoginResult(token: 'test-token');

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
    String? paymentMethod,
    required String idempotencyKey,
  }) async =>
      {'id': 1};
}
