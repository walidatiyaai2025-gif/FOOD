import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/api/b2c_account_api.dart';
import 'package:foodex_customer_app/core/api/b2c_catalog_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';

void main() {
  testWidgets('B2C guest product detail renders authoritative API data in RTL', (tester) async {
    await tester.pumpWidget(
      FoodexCustomerApp(
        initialRoute: '/products/42?store=7',
        b2cCatalogApi: _FakeCatalogApi(),
        b2cAccountApi: _FakeAccountApi(),
      ),
    );
    await tester.pumpAndSettle();

    expect(Directionality.of(tester.element(find.text('تفاصيل المنتج'))), TextDirection.rtl);
    expect(find.text('Tomato Box'), findsOneWidget);
    expect(find.textContaining('3.250 KWD'), findsOneWidget);
    expect(find.text('إضافة إلى السلة'), findsOneWidget);
  });

  testWidgets('B2C store selection propagates store context to home', (tester) async {
    final api = _FakeCatalogApi();
    await tester.pumpWidget(
      FoodexCustomerApp(
        initialRoute: '/stores',
        b2cCatalogApi: api,
        b2cAccountApi: _FakeAccountApi(),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Salmiya Store'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('b2c-store-7')));
    await tester.pumpAndSettle();

    expect(api.lastStoreId, 7);
    expect(find.text('Vegetables'), findsOneWidget);
    expect(find.text('Weekend Offer'), findsOneWidget);
    expect(find.text('Tomato Box'), findsOneWidget);
  });

  testWidgets('B2C products render remote data and preserve store in detail navigation', (tester) async {
    await tester.pumpWidget(
      FoodexCustomerApp(
        initialRoute: '/products?store=7',
        b2cCatalogApi: _FakeCatalogApi(),
        b2cAccountApi: _FakeAccountApi(),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Tomato Box'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('b2c-product-42')));
    await tester.pumpAndSettle();

    expect(find.text('/products/42?store=7'), findsOneWidget);
    expect(find.byKey(const ValueKey('b2c-product-detail')), findsOneWidget);
  });

  testWidgets('B2C cart renders authoritative values and reloads after quantity mutation', (tester) async {
    final accountApi = _FakeAccountApi();
    await tester.pumpWidget(
      FoodexCustomerApp(
        initialRoute: '/cart?store=7',
        b2cCatalogApi: _FakeCatalogApi(),
        b2cAccountApi: accountApi,
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('b2c-cart-data')), findsOneWidget);
    expect(find.text('Tomato Box'), findsOneWidget);
    expect(find.textContaining('6.5'), findsWidgets);

    await tester.tap(find.byKey(const ValueKey('b2c-cart-inc-5')));
    await tester.pumpAndSettle();

    expect(accountApi.quantity, 3);
    expect(accountApi.cartReads, greaterThan(1));
    expect(find.textContaining('9.75'), findsWidgets);
  });

  testWidgets('B2C cart removal persists and reloads the empty state', (tester) async {
    final accountApi = _FakeAccountApi();
    await tester.pumpWidget(
      FoodexCustomerApp(
        initialRoute: '/cart?store=7',
        b2cCatalogApi: _FakeCatalogApi(),
        b2cAccountApi: accountApi,
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('b2c-cart-remove-5')));
    await tester.pumpAndSettle();

    expect(accountApi.removed, isTrue);
    expect(find.text('السلة فارغة'), findsOneWidget);
  });

  testWidgets('B2C order tracking renders server order status', (tester) async {
    await tester.pumpWidget(
      FoodexCustomerApp(
        session: const CustomerSession.authenticated(
          CustomerChannel.b2c,
          accessToken: 'token',
        ),
        initialRoute: '/orders/101/track',
        b2cCatalogApi: _FakeCatalogApi(),
        b2cAccountApi: _FakeAccountApi(),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('b2c-order-data')), findsOneWidget);
    expect(find.text('out_for_delivery'), findsOneWidget);
    expect(find.textContaining('18.500'), findsWidgets);
  });

  testWidgets('B2C profile renders authoritative profile addresses and favorites', (tester) async {
    await tester.pumpWidget(
      FoodexCustomerApp(
        session: const CustomerSession.authenticated(
          CustomerChannel.b2c,
          accessToken: 'token',
        ),
        initialRoute: '/profile',
        b2cCatalogApi: _FakeCatalogApi(),
        b2cAccountApi: _FakeAccountApi(),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('b2c-profile-data')), findsOneWidget);
    expect(find.text('Walid Customer'), findsOneWidget);
    expect(find.text('Bayan'), findsOneWidget);
    expect(find.text('Tomato Box'), findsOneWidget);
  });

  testWidgets('B2C authenticated checkout exposes address payment and submit states', (tester) async {
    await tester.pumpWidget(
      FoodexCustomerApp(
        session: const CustomerSession.authenticated(CustomerChannel.b2c),
        initialRoute: '/checkout/address-payment',
        b2cCatalogApi: _FakeCatalogApi(),
        b2cAccountApi: _FakeAccountApi(),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('العنوان والدفع'), findsOneWidget);
    expect(find.text('عنوان التوصيل'), findsOneWidget);
    expect(find.text('طريقة الدفع'), findsOneWidget);
    expect(find.text('تأكيد الطلب'), findsOneWidget);
  });

  testWidgets('B2C cart has explicit empty state', (tester) async {
    final accountApi = _FakeAccountApi()..removed = true;
    await tester.pumpWidget(
      FoodexCustomerApp(
        initialRoute: '/cart?store=7',
        b2cCatalogApi: _FakeCatalogApi(),
        b2cAccountApi: accountApi,
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('السلة فارغة'), findsOneWidget);
  });
  testWidgets('B2C account offline state is explicit and retryable', (tester) async {
    await tester.pumpWidget(
      FoodexCustomerApp(
        initialRoute: '/cart?store=7',
        b2cCatalogApi: _FakeCatalogApi(),
        b2cAccountApi: const _ErrorAccountApi(
          B2cAccountException('network_unavailable'),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('b2c-offline')), findsOneWidget);
    expect(find.textContaining('تعذر الاتصال'), findsOneWidget);
    expect(find.byKey(const ValueKey('b2c-retry')), findsOneWidget);
  });

  testWidgets('B2C expired session clears auth and exposes sign-in recovery', (tester) async {
    await tester.pumpWidget(
      FoodexCustomerApp(
        session: const CustomerSession.authenticated(
          CustomerChannel.b2c,
          accessToken: 'expired-token',
        ),
        initialRoute: '/profile',
        b2cCatalogApi: _FakeCatalogApi(),
        b2cAccountApi: const _ErrorAccountApi(
          B2cAccountException('session_expired'),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('b2c-session-expired')), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('b2c-sign-in-recovery')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('customer-login-email')), findsOneWidget);
    expect(find.byKey(const ValueKey('customer-login-submit')), findsOneWidget);
  });
}

class _FakeCatalogApi implements B2cCatalogApi {
  int? lastStoreId;

  void _remember(int storeId) => lastStoreId = storeId;

  @override
  Future<List<B2cStore>> stores() async =>
      const [B2cStore(id: 7, name: 'Salmiya Store', code: 'SLM')];

  @override
  Future<List<B2cCategory>> categories(int storeId) async {
    _remember(storeId);
    return const [B2cCategory(id: 3, name: 'Vegetables')];
  }

  @override
  Future<List<B2cOffer>> offers(int storeId) async {
    _remember(storeId);
    return const [
      B2cOffer(id: 9, name: 'Weekend Offer', type: 'percentage', value: 10),
    ];
  }

  @override
  Future<List<B2cBanner>> banners(int storeId) async {
    _remember(storeId);
    return const [
      B2cBanner(id: 11, title: 'Fresh every day', targetUrl: '/offers'),
    ];
  }

  @override
  Future<List<B2cProduct>> products(
    int storeId, {
    String? query,
    int? categoryId,
    String? sort,
    String? direction,
  }) async {
    _remember(storeId);
    return const [
      B2cProduct(
        id: 42,
        name: 'Tomato Box',
        sku: 'TOM-42',
        price: 3.25,
      ),
    ];
  }

  @override
  Future<B2cProduct> product(int productId, {required int storeId}) async {
    _remember(storeId);
    return const B2cProduct(
      id: 42,
      name: 'Tomato Box',
      sku: 'TOM-42',
      price: 3.25,
      description: 'Fresh product',
    );
  }
}

class _FakeAccountApi implements B2cAccountApi {
  double quantity = 2;
  bool removed = false;
  int cartReads = 0;
  bool notificationRead = false;

  @override
  Future<Object?> cart({int? storeId}) async {
    cartReads++;
    if (removed) {
      return {
        'id': 1,
        'store_id': storeId ?? 7,
        'currency': 'KWD',
        'items': <Object>[],
        'subtotal': 0,
      };
    }
    return {
      'id': 1,
      'store_id': storeId ?? 7,
      'currency': 'KWD',
      'items': [
        {
          'id': 5,
          'product': {'id': 42, 'name': 'Tomato Box'},
          'quantity': quantity,
          'line_total': quantity * 3.25,
        },
      ],
      'subtotal': quantity * 3.25,
    };
  }

  @override
  Future<Object?> updateCartItem(int itemId, double nextQuantity) async {
    quantity = nextQuantity;
    return cart(storeId: 7);
  }

  @override
  Future<void> removeCartItem(int itemId) async {
    removed = true;
  }

  @override
  Future<Object?> order(int orderId) async => {
        'id': orderId,
        'order_number': 'FOODEX-$orderId',
        'status': 'out_for_delivery',
        'total': '18.500',
        'currency': 'KWD',
        'items': [
          {'product_name': 'Tomato Box', 'quantity': 2, 'line_total': '6.500'},
        ],
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
        'name': 'Walid Customer',
        'email': 'customer@example.test',
      };

  @override
  Future<Object?> updateProfile(Map<String, dynamic> values) async => values;

  @override
  Future<Object?> addresses() async => {
        'data': [
          {
            'id': 8,
            'label': 'Home',
            'line1': 'Block 1',
            'city': 'Kuwait City',
            'area': 'Bayan',
            'country_code': 'KW',
            'is_default': true,
          },
        ],
      };

  @override
  Future<Object?> createAddress(Map<String, dynamic> values) async => {
        'id': 9,
        ...values,
      };

  @override
  Future<Object?> updateAddress(
    int addressId,
    Map<String, dynamic> values,
  ) async =>
      {'id': addressId, ...values};

  @override
  Future<void> removeAddress(int addressId) async {}

  @override
  Future<Object?> favorites() async => {
        'data': [
          {'id': 42, 'name': 'Tomato Box', 'sku': 'TOM-42'},
        ],
      };

  @override
  Future<void> addFavorite(int productId) async {}

  @override
  Future<void> removeFavorite(int productId) async {}

  @override
  Future<Object?> notifications({String locale = 'ar'}) async => {
        'data': [
          {
            'id': 4,
            'title': locale == 'en' ? 'Order update' : 'تحديث الطلب',
            'body': locale == 'en' ? 'On the way' : 'في الطريق',
            'read_at': notificationRead ? '2026-09-26T12:00:00Z' : null,
          },
        ],
      };

  @override
  Future<void> markNotificationRead(int notificationId) async {
    notificationRead = true;
  }
}

class _ErrorAccountApi implements B2cAccountApi {
  const _ErrorAccountApi(this.error);

  final B2cAccountException error;

  Never _fail() => throw error;

  @override
  Future<Object?> cart({int? storeId}) async => _fail();

  @override
  Future<Object?> updateCartItem(int itemId, double quantity) async => _fail();

  @override
  Future<void> removeCartItem(int itemId) async => _fail();

  @override
  Future<Object?> order(int orderId) async => _fail();

  @override
  Future<Object?> orders() async => _fail();

  @override
  Future<Object?> profile() async => _fail();

  @override
  Future<Object?> updateProfile(Map<String, dynamic> values) async => _fail();

  @override
  Future<Object?> addresses() async => _fail();

  @override
  Future<Object?> createAddress(Map<String, dynamic> values) async => _fail();

  @override
  Future<Object?> updateAddress(
    int addressId,
    Map<String, dynamic> values,
  ) async =>
      _fail();

  @override
  Future<void> removeAddress(int addressId) async => _fail();

  @override
  Future<Object?> favorites() async => _fail();

  @override
  Future<void> addFavorite(int productId) async => _fail();

  @override
  Future<void> removeFavorite(int productId) async => _fail();

  @override
  Future<Object?> notifications({String locale = 'ar'}) async => _fail();

  @override
  Future<void> markNotificationRead(int notificationId) async => _fail();
}
