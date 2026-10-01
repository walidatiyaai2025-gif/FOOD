import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/api/b2b_api.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';

void main() {
  test('reference route patterns are represented in one route registry', () {
    final patterns = customerRouteDefinitions
        .map((definition) => definition.pattern)
        .toSet();

    expect(
      patterns,
      containsAll(<String>{
        '/splash',
        '/entry',
        '/marketplace',
        '/stores',
        '/home',
        '/offers',
        '/products',
        '/products/:id',
        '/categories',
        '/favorites',
        '/orders',
        '/notifications',
        '/profile/addresses',
        '/profile/settings',
        '/cart',
        '/auth/checkout',
        '/checkout/address-payment',
        '/orders/:id/track',
        '/profile',
        '/b2b/login',
        '/b2b/dashboard',
        '/b2b/reports/purchases',
        '/b2b/products/top',
        '/b2b/invoices',
        '/b2b/invoices/:id',
        '/b2b/account-statement',
        '/b2b/orders',
        '/b2b/orders/:id',
        '/b2b/products',
        '/b2b/products/:id',
        '/b2b/cart',
        '/b2b/profile',
      }),
    );
  });

  test('customer app defaults to Marketplace Home for guest and authenticated launches', () {
    expect(const FoodexCustomerApp().initialRoute, CustomerRoutePaths.marketplace);
    expect(
      const FoodexCustomerApp(
        session: CustomerSession.authenticated(CustomerChannel.b2c),
      ).initialRoute,
      CustomerRoutePaths.marketplace,
    );
  });

  test('auth return validation preserves exact channel-correct internal targets', () {
    const b2bTarget =
        '/b2b/products/42?store_id=7&campaign=october%20launch';

    expect(
      safeCustomerReturnLocation(
        b2bTarget,
        channel: CustomerChannel.b2b,
      ),
      b2bTarget,
    );
    expect(
      safeCustomerReturnLocation(
        '/checkout/address-payment?store=3',
        channel: CustomerChannel.b2c,
      ),
      '/checkout/address-payment?store=3',
    );
  });

  test('auth return validation rejects external, cross-channel and login loops', () {
    expect(
      safeCustomerReturnLocation(
        'https://evil.example/b2b/products/42?store_id=7',
        channel: CustomerChannel.b2b,
      ),
      isNull,
    );
    expect(
      safeCustomerReturnLocation(
        '//evil.example/b2b/products/42?store_id=7',
        channel: CustomerChannel.b2b,
      ),
      isNull,
    );
    expect(
      safeCustomerReturnLocation(
        '/profile',
        channel: CustomerChannel.b2b,
      ),
      isNull,
    );
    expect(
      safeCustomerReturnLocation(
        '/b2b/login',
        channel: CustomerChannel.b2b,
      ),
      isNull,
    );
  });

  testWidgets('guest can resolve B2C catalog routes without authentication',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(initialRoute: '/products/42'),
    );
    await tester.pumpAndSettle();

    expect(find.text('تفاصيل المنتج'), findsOneWidget);
    expect(find.text('/products/42'), findsOneWidget);
  });

  testWidgets('guest B2C protected route redirects to checkout login',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(initialRoute: '/checkout/address-payment'),
    );
    await tester.pumpAndSettle();

    expect(find.text('تسجيل الدخول'), findsWidgets);
    expect(find.textContaining('/auth/checkout'), findsOneWidget);
    expect(find.textContaining('next='), findsOneWidget);
  });

  testWidgets('guest B2B protected route redirects to B2B-aware login',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(initialRoute: '/b2b/dashboard'),
    );
    await tester.pumpAndSettle();

    expect(find.text('دخول عميل الأعمال'), findsOneWidget);
    expect(find.textContaining('/b2b/login'), findsOneWidget);
    expect(find.textContaining('next='), findsOneWidget);
    expect(find.textContaining('/auth/checkout'), findsNothing);
  });

  testWidgets(
      'B2B product login returns to the same product/store and loads authenticated API',
      (tester) async {
    final b2bApi = _RecordingB2bApi();
    const target =
        '/b2b/products/42?store_id=7&campaign=october%20launch';

    await tester.pumpWidget(
      FoodexCustomerApp(
        initialRoute: target,
        b2bApi: b2bApi,
        actionApi: const _SuccessfulCustomerActionApi(),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('دخول عميل الأعمال'), findsOneWidget);
    expect(find.textContaining('/b2b/login'), findsOneWidget);
    expect(find.textContaining('next='), findsOneWidget);

    await tester.enterText(
      find.byKey(const ValueKey('customer-login-username')),
      'wholesale-buyer',
    );
    await tester.tap(find.byKey(const ValueKey('customer-login-submit')));
    await tester.pumpAndSettle();

    expect(
      b2bApi.requestedPaths,
      contains('/api/v1/b2b/products/42?store_id=7'),
    );
    expect(
      find.byKey(const ValueKey('b2b-product-detail-data')),
      findsOneWidget,
    );
  });

  testWidgets('authenticated B2C session reaches B2C protected routes',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(
        session: CustomerSession.authenticated(CustomerChannel.b2c),
        initialRoute: '/profile',
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('حسابي'), findsWidgets);
    expect(find.text('/profile'), findsOneWidget);
  });

  testWidgets('authenticated B2B session reaches B2B protected routes',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(
        session: CustomerSession.authenticated(CustomerChannel.b2b),
        initialRoute: '/b2b/orders/101',
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('تفاصيل طلب الجملة'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('b2b-order-detail-empty')),
      findsOneWidget,
    );
  });

  testWidgets('legacy B2C session cannot enter protected B2B partition',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(
        session: CustomerSession.authenticated(CustomerChannel.b2c),
        initialRoute: '/b2b/dashboard',
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('دخول عميل الأعمال'), findsOneWidget);
    expect(find.text('/b2b/login'), findsOneWidget);
  });

  testWidgets('platform-wide customer can enter Retail protected routes from one Wholesale session',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(
        session: CustomerSession.authenticated(
          CustomerChannel.b2b,
          accessToken: 'token',
          platformWide: true,
        ),
        initialRoute: '/profile',
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('حسابي'), findsWidgets);
  });

  testWidgets('platform-wide customer can enter Wholesale protected routes from one Retail session',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(
        session: CustomerSession.authenticated(
          CustomerChannel.b2c,
          accessToken: 'token',
          platformWide: true,
        ),
        initialRoute: '/b2b/dashboard',
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('لوحة الأعمال'), findsOneWidget);
    expect(find.text('/b2b/dashboard'), findsOneWidget);
  });
}

class _SuccessfulCustomerActionApi implements CustomerActionApi {
  const _SuccessfulCustomerActionApi();

  @override
  Future<CustomerLoginResult> login({required String username}) async =>
      const CustomerLoginResult(token: 'b2b-token');

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

class _RecordingB2bApi implements B2bApi {
  final requestedPaths = <String>[];

  @override
  Future<Object?> get(String path) async {
    requestedPaths.add(path);
    return <String, Object?>{
      'id': 42,
      'name': 'Wholesale test product',
      'sku': 'SKU-42',
      'account_price': 1.25,
      'currency': 'KWD',
      'minimum_order_quantity': 1,
      'ordering_increment': 1,
    };
  }
}
