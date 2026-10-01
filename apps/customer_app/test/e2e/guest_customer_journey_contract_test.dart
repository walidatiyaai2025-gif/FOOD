import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/b2c_account_api.dart';
import 'package:foodex_customer_app/core/api/b2c_catalog_api.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/auth/customer_session_store.dart';
import 'package:foodex_customer_app/core/localization/app_translations.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context_store.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_order_models.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_order_screens.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_orders_api.dart';
import 'package:foodex_customer_app/features/retail/commerce/retail_commerce_api.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  group('CJ-V2 Guest full-journey acceptance', () {
    test('catalog requests keep exact Retail store context at every boundary',
        () async {
      final seen = <Uri>[];
      final api = HttpB2cCatalogApi(
        baseUrl: 'https://foodex.example',
        client: MockClient((request) async {
          seen.add(request.url);
          if (request.url.path.endsWith('/products/42')) {
            return http.Response(
              jsonEncode({
                'id': 42,
                'name': 'Tomato Box',
                'sku': 'SKU-42',
                'price': 3.25,
              }),
              200,
              headers: const {'content-type': 'application/json'},
            );
          }
          return http.Response(
            jsonEncode({'data': <Object>[]}),
            200,
            headers: const {'content-type': 'application/json'},
          );
        }),
      );

      await api.products(7, query: 'tomato', categoryId: 3);
      await api.product(42, storeId: 7);

      expect(seen[0].path, '/api/v1/stores/7/products');
      expect(seen[0].queryParameters['q'], 'tomato');
      expect(seen[0].queryParameters['category'], '3');
      expect(seen[1].path, '/api/v1/products/42');
      expect(seen[1].queryParameters['store'], '7');
    });

    test('guest cart tokens never cross Retail stores', () async {
      const tokenA =
          'guest-store-a-token-abcdefghijklmnopqrstuvwxyz-0123456789';
      const tokenB =
          'guest-store-b-token-abcdefghijklmnopqrstuvwxyz-0123456789';
      final session = CustomerGuestSession();
      var call = 0;
      final api = HttpCustomerActionApi(
        baseUrl: 'https://foodex.example',
        guestSession: session,
        client: MockClient((request) async {
          call++;
          final body = jsonDecode(request.body) as Map<String, dynamic>;
          final storeId = body['store_id'] as int;
          expect(request.headers['X-FOODEX-Store-ID'], storeId.toString());

          if (storeId == 7) {
            expect(
              request.headers['X-Guest-Token'],
              call == 3 ? tokenA : isNull,
            );
            return http.Response(
              '{}',
              201,
              headers: const {'x-guest-token': tokenA},
            );
          }

          expect(storeId, 8);
          expect(request.headers['X-Guest-Token'], isNull);
          return http.Response(
            '{}',
            201,
            headers: const {'x-guest-token': tokenB},
          );
        }),
      );

      await api.addCartItem(storeId: 7, productId: 41, quantity: 1);
      await api.addCartItem(storeId: 8, productId: 42, quantity: 1);
      await api.addCartItem(storeId: 7, productId: 43, quantity: 1);

      expect(session.tokenForStore(7), tokenA);
      expect(session.tokenForStore(8), tokenB);
      expect(session.activeStoreId, 7);
    });

    test('guest session restores the same store cart after app restart',
        () async {
      final secureStorage = _MemorySecureStore();
      final firstTokenStore =
          SecureCustomerGuestCartTokenStore(storage: secureStorage);
      await firstTokenStore.writeToken(7, 'persisted-guest-store-7');
      await firstTokenStore.writeToken(8, 'persisted-guest-store-8');

      final restartedSession = CustomerGuestSession();
      final accountApi = _RecordingB2cAccountApi(restartedSession);
      final restartedTokenStore =
          SecureCustomerGuestCartTokenStore(storage: secureStorage);
      final commerce = DefaultRetailCommerceApi(
        accountApi: accountApi,
        actionApi: _NoopCustomerActionApi(),
        checkoutOptionsApi: _NoopCheckoutOptionsApi(),
        guestSession: restartedSession,
        guestCartTokenStore: restartedTokenStore,
      );

      final cart = await commerce.loadCart(storeId: 7);

      expect(cart.storeId, 7);
      expect(accountApi.storeIdsSeen, [7]);
      expect(accountApi.guestTokensSeen, ['persisted-guest-store-7']);
      expect(restartedSession.activeStoreId, 7);
      expect(
        restartedSession.tokenForStore(7),
        'persisted-guest-store-7',
      );
      expect(
        await restartedTokenStore.readToken(8),
        'persisted-guest-store-8',
      );
    });

    test(
        'guest Login or Register returns to checkout and merges cart exactly once',
        () async {
      const context = CustomerCommerceContext(
        channel: CustomerCommerceChannel.retail,
        storeId: 7,
        retailReceiverId: 19,
      );
      final checkout = CustomerRouteLocations.retailCheckout(context);
      final login = Uri.parse(
        CustomerRouteLocations.authHandoff(
          context: context,
          next: checkout,
        ),
      );
      final register = Uri.parse(
        CustomerRouteLocations.authHandoff(
          context: context,
          next: checkout,
          entry: CustomerAuthEntry.register,
        ),
      );

      expect(login.queryParameters['entry'], 'login');
      expect(register.queryParameters['entry'], 'register');
      expect(login.queryParameters['next'], checkout);
      expect(register.queryParameters['next'], checkout);
      expect(login.queryParameters['store_id'], '7');
      expect(register.queryParameters['store_id'], '7');

      final secureStorage = _MemorySecureStore();
      final tokenStore =
          SecureCustomerGuestCartTokenStore(storage: secureStorage);
      await tokenStore.writeToken(7, 'merge-me-once-store-7');
      await tokenStore.writeToken(8, 'keep-store-8');

      final session = CustomerGuestSession();
      final accountApi = _RecordingB2cAccountApi(session);
      final commerce = DefaultRetailCommerceApi(
        accountApi: accountApi,
        actionApi: _NoopCustomerActionApi(),
        checkoutOptionsApi: _NoopCheckoutOptionsApi(),
        guestSession: session,
        guestCartTokenStore: tokenStore,
      );

      await commerce.mergeGuestCartAfterAuthentication(storeId: 7);
      expect(accountApi.guestTokensSeen, ['merge-me-once-store-7']);
      expect(await tokenStore.readToken(7), isNull);
      expect(await tokenStore.readToken(8), 'keep-store-8');

      await commerce.loadCart(storeId: 7);
      expect(accountApi.guestTokensSeen, ['merge-me-once-store-7', isNull]);
      expect(await tokenStore.readToken(7), isNull);
    });

    test('checkout uses backend-supported address and payment options',
        () async {
      final api = HttpRetailCheckoutOptionsApi(
        baseUrl: 'https://foodex.example',
        token: 'customer-token',
        client: MockClient((request) async {
          expect(request.url.path, '/api/v1/checkout/options');
          expect(request.url.queryParameters['store_id'], '7');
          expect(request.headers['Authorization'], 'Bearer customer-token');
          expect(request.headers['X-FOODEX-Store-ID'], '7');

          return http.Response(
            jsonEncode({
              'store_id': 7,
              'addresses': [
                {
                  'id': 55,
                  'label': 'Home',
                  'line1': 'Street 1',
                  'city': 'Kuwait City',
                  'is_default': true,
                }
              ],
              'payment_methods': ['cash_on_delivery', 'knet'],
            }),
            200,
            headers: const {'content-type': 'application/json'},
          );
        }),
      );

      final options = await api.load(storeId: 7);

      expect(options.storeId, 7);
      expect(options.addresses.single.id, 55);
      expect(options.paymentMethods, ['cash_on_delivery', 'knet']);
    });

    test('checkout retry keeps one order identity and stable idempotency key',
        () async {
      final api = _RetryingRetailCommerceApi()..failuresRemaining = 1;
      var generated = 0;
      final guard = RetailCheckoutSubmissionGuard(
        api,
        idempotencyKeyFactory: (storeId) {
          generated++;
          return 'journey-store-$storeId-key-$generated';
        },
      );

      await expectLater(
        guard.submit(
          storeId: 7,
          addressId: 55,
          paymentMethod: 'knet',
        ),
        throwsA(isA<RetailCommerceException>()),
      );

      final order = await guard.submit(
        storeId: 7,
        addressId: 55,
        paymentMethod: 'knet',
      );

      expect(order.id, 9001);
      expect(order.storeId, 7);
      expect(api.submitCalls, 2);
      expect(api.storeIds, [7, 7]);
      expect(api.idempotencyKeys, hasLength(2));
      expect(api.idempotencyKeys[0], api.idempotencyKeys[1]);
      expect(generated, 1);
    });

    testWidgets(
        'order lifecycle push deep-link forces authoritative order refresh',
        (tester) async {
      final notifications = StreamController<Map<String, dynamic>>();
      final api = _FakeOrdersApi([
        _details('pending'),
        _details('preparing'),
      ]);

      await tester.pumpWidget(
        AppTranslations(
          locale: const Locale('en'),
          overrides: const {},
          child: MaterialApp(
            home: CustomerOrderTrackingScreen(
              api: api,
              orderId: 91,
              orderContext: const CustomerOrderContext(
                storeId: 7,
                channel: 'b2c',
              ),
              lifecycleNotifications: notifications.stream,
            ),
          ),
        ),
      );
      await tester.pump();
      await tester.pump();

      expect(api.detailCalls, 1);
      expect(find.text('Order received'), findsWidgets);

      notifications.add({
        'order_id': 91,
        'store_id': 7,
        'channel': 'b2c',
        'status': 'delivered',
      });
      await tester.pump();
      await tester.pump();

      expect(api.detailCalls, 2);
      expect(find.text('Preparing'), findsWidgets);
      expect(find.text('Delivered'), findsNothing);

      unawaited(notifications.close());
      await tester.pump();
      await tester.pumpWidget(const SizedBox.shrink());
    });

    test(
        'authenticated multi-store and Wholesale smoke regressions stay isolated',
        () {
      const retailA = CustomerCommerceContext(
        channel: CustomerCommerceChannel.retail,
        storeId: 7,
      );
      const retailB = CustomerCommerceContext(
        channel: CustomerCommerceChannel.retail,
        storeId: 8,
      );
      const wholesale = CustomerCommerceContext(
        channel: CustomerCommerceChannel.wholesale,
        storeId: 12,
        retailReceiverId: 7,
      );

      final retailAOrders = CustomerRouteLocations.retailOrders(retailA);
      final retailBOrders = CustomerRouteLocations.retailOrders(retailB);
      final wholesaleHome = CustomerRouteLocations.wholesaleHome(wholesale);

      expect(CustomerCommerceContext.tryParseLocation(retailAOrders), retailA);
      expect(CustomerCommerceContext.tryParseLocation(retailBOrders), retailB);
      expect(
        CustomerCommerceContext.tryParseLocation(wholesaleHome),
        wholesale,
      );
      expect(
        safeCustomerContextReturnLocation(
          retailBOrders,
          context: retailA,
        ),
        isNull,
      );

      const platformSession = CustomerSession.platformCustomer(
        accessToken: 'platform-token',
      );
      expect(platformSession.allowsChannel(CustomerChannel.b2c), isTrue);
      expect(platformSession.allowsChannel(CustomerChannel.b2b), isTrue);
      expect(retailA.sameScope(retailB), isFalse);
      expect(retailA.sameScope(wholesale), isFalse);
    });
  });
}

class _MemorySecureStore implements CustomerSecureKeyValueStore {
  final Map<String, String> _values = <String, String>{};

  @override
  Future<String?> read(String key) async => _values[key];

  @override
  Future<void> write(String key, String value) async {
    _values[key] = value;
  }

  @override
  Future<void> delete(String key) async {
    _values.remove(key);
  }
}

class _RecordingB2cAccountApi implements B2cAccountApi {
  _RecordingB2cAccountApi(this.guestSession);

  final CustomerGuestSession guestSession;
  final List<int?> storeIdsSeen = <int?>[];
  final List<String?> guestTokensSeen = <String?>[];

  @override
  Future<Object?> cart({int? storeId}) async {
    storeIdsSeen.add(storeId);
    guestTokensSeen.add(
      storeId == null
          ? guestSession.token
          : guestSession.tokenForStore(storeId),
    );
    return {
      'store_id': storeId,
      'currency': 'KWD',
      'items': <Object>[],
      'subtotal': 0,
      'has_unavailable_items': false,
      'quote': {'grand_total': 0},
    };
  }

  @override
  dynamic noSuchMethod(Invocation invocation) =>
      throw UnimplementedError(invocation.memberName.toString());
}

class _NoopCustomerActionApi implements CustomerActionApi {
  @override
  dynamic noSuchMethod(Invocation invocation) =>
      throw UnimplementedError(invocation.memberName.toString());
}

class _NoopCheckoutOptionsApi implements RetailCheckoutOptionsApi {
  @override
  Future<RetailCheckoutOptions> load({required int storeId}) {
    throw UnimplementedError();
  }
}

class _RetryingRetailCommerceApi implements RetailCommerceApi {
  int failuresRemaining = 0;
  int submitCalls = 0;
  final List<int> storeIds = <int>[];
  final List<String> idempotencyKeys = <String>[];

  @override
  Future<RetailCreatedOrder> submitCheckout({
    required int storeId,
    required int addressId,
    required String paymentMethod,
    String? couponCode,
    required String idempotencyKey,
  }) async {
    submitCalls++;
    storeIds.add(storeId);
    idempotencyKeys.add(idempotencyKey);
    if (failuresRemaining > 0) {
      failuresRemaining--;
      throw const RetailCommerceException('temporary_failure');
    }
    return RetailCreatedOrder(id: 9001, storeId: storeId);
  }

  @override
  dynamic noSuchMethod(Invocation invocation) =>
      throw UnimplementedError(invocation.memberName.toString());
}

CustomerOrderDetails _details(String status) => CustomerOrderDetails(
      summary: CustomerOrderSummary(
        id: 91,
        orderNumber: 'FO-91',
        storeId: 7,
        storeName: 'Retail A',
        storeLogoUrl: null,
        channel: 'b2c',
        status: status,
        currency: 'KWD',
        grandTotal: 12.5,
        createdAt: DateTime.utc(2026, 10, 1, 10),
      ),
      subtotal: 10,
      discountTotal: 0,
      deliveryTotal: 2.5,
      paymentMethod: 'cash',
      deliveryAddress: const {'area': 'Bayan'},
      items: const [],
      history: const [],
      payment: null,
      requestedDeliveryDate: null,
    );

class _FakeOrdersApi implements CustomerOrdersApi {
  _FakeOrdersApi(this.details);

  final List<CustomerOrderDetails> details;
  int detailCalls = 0;

  @override
  Future<CustomerOrderDetails> order({
    required int orderId,
    CustomerOrderContext? context,
  }) async {
    final index = detailCalls < details.length
        ? detailCalls
        : details.length - 1;
    detailCalls += 1;
    return details[index];
  }

  @override
  Future<CustomerOrderPage> orders({
    int page = 1,
    int perPage = 20,
    String? status,
    CustomerOrderContext? context,
  }) {
    throw UnimplementedError();
  }
}
