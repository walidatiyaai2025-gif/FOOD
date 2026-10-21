import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/api/b2b_api.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/core/api/storefront_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/routing/customer_pending_action.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';
import 'package:foodex_customer_app/features/retail/retail_customer_journey_screen.dart';
import 'package:foodex_customer_app/features/storefront/platform_marketplace_screen.dart';
import 'package:foodex_customer_app/features/storefront/wholesale_multistore_screens.dart';

Future<void> _pumpCustomerFrames(WidgetTester tester) async {
  for (var frame = 0; frame < 30; frame++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

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

  test('legacy business-login and store-selector routes stay purged', () {
    final patterns = customerRouteDefinitions
        .map((definition) => definition.pattern)
        .toSet();

    expect(patterns, isNot(contains('/b2b/login')));
    expect(patterns, isNot(contains('/stores')));
    expect(patterns, isNot(contains('/customer/store-selector')));
  });

  test('customer app defaults to the canonical Login entry', () {
    expect(const FoodexCustomerApp().initialRoute, CustomerRoutePaths.entry);
    expect(
      const FoodexCustomerApp(
        session: CustomerSession.authenticated(CustomerChannel.b2c),
      ).initialRoute,
      CustomerRoutePaths.entry,
    );
  });

  test(
      'auth return validation preserves exact channel-correct internal targets',
      () {
    const b2bTarget = '/b2b/products/42?store_id=7&campaign=october%20launch';

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

  test('auth return validation rejects external, cross-channel and login loops',
      () {
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

  testWidgets('entry route renders unified login without Marketplace bypass',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(initialRoute: '/entry'),
    );
    await _pumpCustomerFrames(tester);

    expect(
      find.byKey(const ValueKey('unified-customer-auth-screen')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('c13-business-login-title')),
      findsOneWidget,
    );
    expect(find.text('تسجيل دخول العميل'), findsWidgets);
    expect(find.text('دخول عميل الأعمال'), findsNothing);
    expect(find.byKey(const ValueKey('unified-auth-submit')), findsOneWidget);
    expect(find.byKey(const ValueKey('customer-auth-guest')), findsNothing);
    expect(
      find.byKey(const ValueKey('customer-auth-language-toggle')),
      findsOneWidget,
    );

    await tester.tap(
      find.byKey(const ValueKey('customer-auth-language-toggle')),
    );
    await _pumpCustomerFrames(tester);

    expect(find.text('Customer sign in'), findsWidgets);
    expect(find.byKey(const ValueKey('customer-auth-guest')), findsNothing);
  });

  testWidgets('successful non-merchant login lands on canonical Wholesale Home',
      (tester) async {
    await tester.pumpWidget(
      FoodexCustomerApp(
        initialRoute: '/entry',
        actionApi: const _SuccessfulCustomerActionApi(),
        b2bApi: _RecordingB2bApi(),
        storefrontApi: const _CanonicalWholesaleHomeStorefrontApi(),
      ),
    );
    await _pumpCustomerFrames(tester);

    await tester.enterText(
      find.byKey(const ValueKey('unified-auth-email')),
      'buyer@example.test',
    );
    await tester.enterText(
      find.byKey(const ValueKey('unified-auth-password')),
      'password-123',
    );
    final submit = find.byKey(const ValueKey('unified-auth-submit'));
    await tester.ensureVisible(submit);
    await _pumpCustomerFrames(tester);
    await tester.tap(submit);
    await _pumpCustomerFrames(tester);

    expect(find.byType(WholesaleHomeDesignScreen), findsOneWidget);
    expect(find.byType(PlatformMarketplaceScreen), findsNothing);
    expect(
        find.byKey(const ValueKey('c13-business-login-title')), findsNothing);
  });

  testWidgets(
      'successful Retail merchant login lands on canonical Wholesale Home',
      (tester) async {
    await tester.pumpWidget(
      FoodexCustomerApp(
        initialRoute: '/entry',
        actionApi: const _RetailMerchantCustomerActionApi(),
        b2bApi: _RecordingB2bApi(),
        storefrontApi: const _CanonicalWholesaleHomeStorefrontApi(),
      ),
    );
    await _pumpCustomerFrames(tester);

    await tester.enterText(
      find.byKey(const ValueKey('unified-auth-email')),
      'merchant@example.test',
    );
    await tester.enterText(
      find.byKey(const ValueKey('unified-auth-password')),
      'password-123',
    );
    final submit = find.byKey(const ValueKey('unified-auth-submit'));
    await tester.ensureVisible(submit);
    await _pumpCustomerFrames(tester);
    await tester.tap(submit);
    await _pumpCustomerFrames(tester);

    expect(find.byType(WholesaleHomeDesignScreen), findsOneWidget);
    expect(find.byType(RetailCustomerJourneyScreen), findsNothing);
    expect(find.byType(PlatformMarketplaceScreen), findsNothing);
  });

  testWidgets('entry register mode exposes the existing registration controls',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(initialRoute: '/entry?entry=register'),
    );
    await _pumpCustomerFrames(tester);

    expect(find.byKey(const ValueKey('unified-auth-name')), findsOneWidget);
    expect(find.byKey(const ValueKey('unified-auth-phone')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('unified-auth-password-confirmation')),
      findsOneWidget,
    );
  });

  testWidgets('entry validation is visible at the owning fields',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(initialRoute: '/entry'),
    );
    await _pumpCustomerFrames(tester);

    final submit = find.byKey(const ValueKey('unified-auth-submit'));
    await tester.ensureVisible(submit);
    await _pumpCustomerFrames(tester);
    await tester.tap(submit);
    await tester.pump();

    expect(find.text('أدخل البريد الإلكتروني'), findsOneWidget);
    expect(find.text('أدخل كلمة المرور'), findsOneWidget);
  });

  testWidgets('entry maps locked-account failures to a safe visible state',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(
        initialRoute: '/entry',
        actionApi: _LockedCustomerActionApi(),
      ),
    );
    await _pumpCustomerFrames(tester);

    await tester.enterText(
      find.byKey(const ValueKey('unified-auth-email')),
      'locked@example.test',
    );
    await tester.enterText(
      find.byKey(const ValueKey('unified-auth-password')),
      'password-123',
    );
    final submit = find.byKey(const ValueKey('unified-auth-submit'));
    await tester.ensureVisible(submit);
    await _pumpCustomerFrames(tester);
    await tester.tap(submit);
    await _pumpCustomerFrames(tester);

    expect(
      find.text('الحساب مقفل حاليًا. تواصل مع الدعم أو حاول لاحقًا.'),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('unified-auth-submit')), findsOneWidget);
  });

  testWidgets('guest resolves store-scoped Retail catalog on the NEW surface',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(
        initialRoute: '/products/42?channel=retail&store_id=7',
      ),
    );
    await tester.pump();

    expect(
      find.byKey(const ValueKey('retail-catalog-product')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('customer-route-location')),
      findsNothing,
    );
  });

  testWidgets('guest B2C protected route redirects to checkout login',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(
        initialRoute: '/checkout/address-payment?channel=retail&store_id=7',
      ),
    );
    await _pumpCustomerFrames(tester);

    expect(find.text('تسجيل دخول العميل'), findsWidgets);
    expect(
      find.byKey(const ValueKey('unified-auth-submit')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('customer-auth-remember-me')),
        findsOneWidget);
    expect(find.textContaining('/auth/checkout'), findsNothing);
    expect(find.textContaining('next='), findsNothing);
  });

  testWidgets('guest B2B protected route redirects to unified customer login',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(initialRoute: '/b2b/dashboard'),
    );
    await _pumpCustomerFrames(tester);

    expect(find.text('تسجيل دخول العميل'), findsWidgets);
    expect(find.text('دخول عميل الأعمال'), findsNothing);
    expect(
      find.byKey(const ValueKey('unified-auth-submit')),
      findsOneWidget,
    );
    expect(find.textContaining('/b2b/login'), findsNothing);
    expect(find.textContaining('next='), findsNothing);
    expect(find.textContaining('/auth/checkout'), findsNothing);
  });

  testWidgets(
      'guest Wholesale product stays browseable and add auth resumes exact store',
      (tester) async {
    tester.view.physicalSize = const Size(800, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final b2bApi = _RecordingB2bApi();
    final pending = _MemoryPendingActionStore();
    const target =
        '/b2b/products/42?channel=wholesale&store_id=7&campaign=october%20launch';

    await tester.pumpWidget(
      FoodexCustomerApp(
        initialRoute: target,
        b2bApi: b2bApi,
        actionApi: const _SuccessfulCustomerActionApi(),
        pendingActionStore: pending,
      ),
    );
    await _pumpCustomerFrames(tester);

    expect(
      find.byKey(const ValueKey('b2b-product-detail-data')),
      findsOneWidget,
    );
    expect(
      b2bApi.requestedPaths,
      contains('/api/v1/b2b/products/42?store_id=7'),
    );
    expect(
      find.byKey(const ValueKey('unified-customer-auth-screen')),
      findsNothing,
    );

    await tester.tap(find.byKey(const ValueKey('customer-add-cart')));
    await _pumpCustomerFrames(tester);

    expect(
      find.byKey(const ValueKey('unified-customer-auth-screen')),
      findsOneWidget,
    );
    expect(find.text('تسجيل دخول العميل'), findsWidgets);
    expect(find.text('دخول عميل الأعمال'), findsNothing);

    await tester.enterText(
      find.byKey(const ValueKey('unified-auth-email')),
      'wholesale-buyer@example.test',
    );
    await tester.enterText(
      find.byKey(const ValueKey('unified-auth-password')),
      'test-password',
    );
    await tester.tap(find.byKey(const ValueKey('unified-auth-submit')));
    await _pumpCustomerFrames(tester);

    expect(find.text('سلة الجملة'), findsOneWidget);
  });

  testWidgets('authenticated B2C session reaches B2C protected routes',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(
        session: CustomerSession.authenticated(CustomerChannel.b2c),
        initialRoute: '/profile?channel=retail&store_id=7',
      ),
    );
    await _pumpCustomerFrames(tester);

    expect(find.text('حسابي'), findsWidgets);
    expect(find.text('/profile'), findsNothing);
    expect(
      find.byKey(const ValueKey('customer-route-location')),
      findsNothing,
    );
  });

  testWidgets('authenticated B2B session reaches B2B protected routes',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(
        session: CustomerSession.authenticated(CustomerChannel.b2b),
        initialRoute: '/b2b/orders/101',
      ),
    );
    await _pumpCustomerFrames(tester);

    expect(find.text('تفاصيل الطلب'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('b2b-order-detail-empty')),
      findsOneWidget,
    );
  });

  testWidgets(
      'legacy channel-scoped session falls back without a Business login',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(
        session: CustomerSession.authenticated(CustomerChannel.b2c),
        initialRoute: '/b2b/dashboard',
      ),
    );
    await _pumpCustomerFrames(tester);

    expect(
      find.byKey(const ValueKey('unified-customer-auth-screen')),
      findsNothing,
    );
    expect(find.text('دخول عميل الأعمال'), findsNothing);
    expect(find.textContaining('/b2b/login'), findsNothing);
    expect(
      find.byKey(const ValueKey('customer-route-location')),
      findsNothing,
    );
  });

  testWidgets(
      'platform-wide customer can enter Retail protected routes from one Wholesale session',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(
        session: CustomerSession.authenticated(
          CustomerChannel.b2b,
          accessToken: 'token',
          platformWide: true,
        ),
        initialRoute: '/profile?channel=retail&store_id=7',
      ),
    );
    await _pumpCustomerFrames(tester);

    expect(find.text('حسابي'), findsWidgets);
  });

  testWidgets(
      'platform-wide customer can enter Wholesale protected routes from one Retail session',
      (tester) async {
    await tester.pumpWidget(
      FoodexCustomerApp(
        session: CustomerSession.authenticated(
          CustomerChannel.b2c,
          accessToken: 'token',
          platformWide: true,
        ),
        initialRoute: '/b2b/dashboard',
        b2bApi: _RecordingB2bApi(),
      ),
    );
    await _pumpCustomerFrames(tester);

    expect(
      find.byKey(const ValueKey('foodex-wholesale-shared-header')),
      findsOneWidget,
    );
    expect(find.text('/b2b/dashboard'), findsNothing);
    expect(
      find.byKey(const ValueKey('customer-route-location')),
      findsNothing,
    );
  });
}

class _LockedCustomerActionApi implements CustomerActionApi {
  const _LockedCustomerActionApi();

  @override
  Future<CustomerLoginResult> login({required String username}) async {
    throw const CustomerActionException('account_locked');
  }

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

class _RetailMerchantCustomerActionApi implements CustomerActionApi {
  const _RetailMerchantCustomerActionApi();

  @override
  Future<CustomerLoginResult> login({required String username}) async =>
      const CustomerLoginResult(
        token: 'retail-token',
        retailStoreIds: <int>[7],
      );

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

class _MemoryPendingActionStore implements CustomerPendingActionStore {
  CustomerPendingAction? value;

  @override
  Future<void> clear() async {
    value = null;
  }

  @override
  Future<CustomerPendingAction?> read() async => value;

  @override
  Future<CustomerPendingAction?> take() async {
    final current = value;
    value = null;
    return current;
  }

  @override
  Future<void> write(CustomerPendingAction action) async {
    value = action;
  }
}

class _CanonicalWholesaleHomeStorefrontApi implements StorefrontApi {
  const _CanonicalWholesaleHomeStorefrontApi();

  @override
  Future<Map<String, dynamic>> selection({
    String? countryCode,
    String? city,
    String? area,
    bool support = false,
  }) async =>
      const <String, dynamic>{
        'wholesale_stores': <Object>[
          <String, Object?>{
            'id': 70,
            'name': 'FOODEX Main Wholesale',
            'is_platform_principal': true,
          },
        ],
        'entitlements': <String, Object?>{
          'principal_wholesale_store_id': 70,
        },
      };

  @override
  Future<Map<String, dynamic>> wholesaleHome(int storeId) async =>
      <String, dynamic>{
        'store': <String, Object?>{
          'id': storeId,
          'name': 'FOODEX Main Wholesale',
        },
        'sections': const <Object>[
          <String, Object?>{
            'type': 'hero',
            'sort_order': 10,
          },
        ],
        'products': const <String, Object?>{
          'data': <Object>[],
        },
      };

  @override
  Future<Map<String, dynamic>> retailHome(int storeId) async =>
      const <String, dynamic>{};

  @override
  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId) async =>
      const <String, dynamic>{};
}
