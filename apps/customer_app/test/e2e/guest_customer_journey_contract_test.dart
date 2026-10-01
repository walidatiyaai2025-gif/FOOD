import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/b2c_account_api.dart';
import 'package:foodex_customer_app/core/api/b2c_catalog_api.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/auth/customer_session_store.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context_store.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_order_models.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_orders_api.dart';
import 'package:foodex_customer_app/features/retail/commerce/retail_commerce_api.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  group('CJ-V2 Guest journey boundary contracts', () {
    test('catalog requests keep exact Retail store context at every boundary', () async {
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
      const tokenA = 'guest-store-a-token-abcdefghijklmnopqrstuvwxyz-0123456789';
      const tokenB = 'guest-store-b-token-abcdefghijklmnopqrstuvwxyz-0123456789';
      final session = CustomerGuestSession();
      var call = 0;
      final api = HttpCustomerActionApi(
        baseUrl: 'https://foodex.example',
        guestSession: session,
        client: MockClient((request) async {
          call++;
          final body = jsonDecode(request.body) as Map<String, dynamic>;
          final storeId = body['store_id'] as int;
          expect(request.headers['X-FOODEX-Store-ID'], '$storeId');

          if (storeId == 7) {
            expect(request.headers['X-Guest-Token'], call == 3 ? tokenA : isNull);
            return http.Response('{}', 201, headers: const {'x-guest-token': tokenA});
          }

          expect(storeId, 8);
          expect(request.headers['X-Guest-Token'], isNull);
          return http.Response('{}', 201, headers: const {'x-guest-token': tokenB});
        }),
      );

      await api.addCartItem(storeId: 7, productId: 41, quantity: 1);
      await api.addCartItem(storeId: 8, productId: 42, quantity: 1);
      await api.addCartItem(storeId: 7, productId: 43, quantity: 1);

      expect(session.tokenForStore(7), tokenA);
      expect(session.tokenForStore(8), tokenB);
      expect(session.activeStoreId, 7);
    });

    test('cart reload sends the token for the exact selected store only', () async {
      final session = CustomerGuestSession()
        ..captureStoreToken(7, 'guest-store-7-token')
        ..captureStoreToken(8, 'guest-store-8-token');
      late http.Request captured;
      final api = HttpB2cAccountApi(
        baseUrl: 'https://foodex.example',
        guestSession: session,
        client: MockClient((request) async {
          captured = request;
          return http.Response(
            jsonEncode({'id': 11, 'store_id': 7, 'items': <Object>[]}),
            200,
            headers: const {'content-type': 'application/json'},
          );
        }),
      );

      await api.cart(storeId: 7);

      expect(captured.url.path, '/api/v1/cart');
      expect(captured.url.queryParameters['store'], '7');
      expect(captured.headers['X-FOODEX-Store-ID'], '7');
      expect(captured.headers['X-Guest-Token'], 'guest-store-7-token');
      expect(captured.headers['X-Guest-Token'], isNot('guest-store-8-token'));
    });

    test('checkout preserves store context and one stable idempotency key', () async {
      final requests = <http.Request>[];
      final api = HttpCustomerActionApi(
        baseUrl: 'https://foodex.example',
        token: 'customer-token',
        guestSession: CustomerGuestSession()
          ..captureStoreToken(7, 'guest-store-7-token'),
        client: MockClient((request) async {
          requests.add(request);
          return http.Response(
            jsonEncode({'order': {'id': 9001, 'store_id': 7}}),
            201,
            headers: const {'content-type': 'application/json'},
          );
        }),
      );

      for (var attempt = 0; attempt < 2; attempt++) {
        await api.checkout(
          addressId: 55,
          storeId: 7,
          paymentMethod: 'cash',
          idempotencyKey: 'journey-order-9001',
        );
      }

      expect(requests, hasLength(2));
      for (final request in requests) {
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        expect(request.headers['Authorization'], 'Bearer customer-token');
        expect(request.headers['X-FOODEX-Store-ID'], '7');
        expect(request.headers['X-Guest-Token'], 'guest-store-7-token');
        expect(request.headers['Idempotency-Key'], 'journey-order-9001');
        expect(body['store_id'], 7);
        expect(body['address_id'], 55);
        expect(body['payment_method'], 'cash');
      }
    });
  });

  group('CJ-V2 integrated Guest acceptance', () {
    test('guest session restores the same store cart after app restart', () async {
      final storage = _MemorySecureStore();
      await SecureCustomerGuestCartTokenStore(storage: storage)
          .writeToken(7, 'persisted-store-7-token');

      final restartedSession = CustomerGuestSession();
      late http.Request captured;
      final accountApi = HttpB2cAccountApi(
        baseUrl: 'https://foodex.example',
        guestSession: restartedSession,
        client: MockClient((request) async {
          captured = request;
          return http.Response(
            jsonEncode({
              'store_id': 7,
              'currency': 'KWD',
              'items': <Object>[],
              'subtotal': 0,
              'has_unavailable_items': false,
              'quote': {'grand_total': 0},
            }),
            200,
            headers: const {'content-type': 'application/json'},
          );
        }),
      );
      final commerce = DefaultRetailCommerceApi(
        accountApi: accountApi,
        actionApi: _NoopCustomerActionApi(),
        checkoutOptionsApi: _NoopCheckoutOptionsApi(),
        guestSession: restartedSession,
        guestCartTokenStore:
            SecureCustomerGuestCartTokenStore(storage: storage),
      );

      final cart = await commerce.loadCart(storeId: 7);

      expect(cart.storeId, 7);
      expect(captured.url.queryParameters['store'], '7');
      expect(captured.headers['X-FOODEX-Store-ID'], '7');
      expect(captured.headers['X-Guest-Token'], 'persisted-store-7-token');
      expect(restartedSession.tokenForStore(7), 'persisted-store-7-token');
    });

    test('guest Login or Register returns to checkout and merges cart exactly once', () async {
      final storage = _MemorySecureStore();
      await SecureCustomerGuestCartTokenStore(storage: storage)
          .writeToken(7, 'guest-before-auth-token');

      final session = CustomerGuestSession();
      final seenGuestTokens = <String?>[];
      final accountApi = HttpB2cAccountApi(
        baseUrl: 'https://foodex.example',
        token: 'authenticated-platform-token',
        guestSession: session,
        client: MockClient((request) async {
          seenGuestTokens.add(request.headers['X-Guest-Token']);
          return http.Response(
            jsonEncode({
              'store_id': 7,
              'currency': 'KWD',
              'items': <Object>[],
              'subtotal': 0,
              'has_unavailable_items': false,
              'quote': {'grand_total': 0},
            }),
            200,
            headers: const {'content-type': 'application/json'},
          );
        }),
      );
      final tokenStore = SecureCustomerGuestCartTokenStore(storage: storage);
      final commerce = DefaultRetailCommerceApi(
        accountApi: accountApi,
        actionApi: _NoopCustomerActionApi(),
        checkoutOptionsApi: _NoopCheckoutOptionsApi(),
        guestSession: session,
        guestCartTokenStore: tokenStore,
      );

      await commerce.mergeGuestCartAfterAuthentication(storeId: 7);
      expect(seenGuestTokens, ['guest-before-auth-token']);
      expect(session.tokenForStore(7), isNull);
      expect(await tokenStore.readToken(7), isNull);

      await commerce.loadCart(storeId: 7);
      expect(seenGuestTokens, ['guest-before-auth-token', null]);
    });

    test('checkout uses backend-supported address and payment options', () async {
      late http.Request captured;
      final optionsApi = HttpRetailCheckoutOptionsApi(
        baseUrl: 'https://foodex.example',
        token: 'platform-token',
        client: MockClient((request) async {
          captured = request;
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
                },
              ],
              'payment_methods': ['cash', 'card'],
            }),
            200,
            headers: const {'content-type': 'application/json'},
          );
        }),
      );
      final commerce = DefaultRetailCommerceApi(
        accountApi: _NoopB2cAccountApi(),
        actionApi: _NoopCustomerActionApi(),
        checkoutOptionsApi: optionsApi,
        guestSession: CustomerGuestSession(),
        guestCartTokenStore: _MemoryGuestCartTokenStore(),
      );

      final options = await commerce.checkoutOptions(storeId: 7);

      expect(captured.url.path, '/api/v1/checkout/options');
      expect(captured.url.queryParameters['store_id'], '7');
      expect(captured.headers['Authorization'], 'Bearer platform-token');
      expect(captured.headers['X-FOODEX-Store-ID'], '7');
      expect(captured.headers['X-FOODEX-Customer-Domain'], 'b2c');
      expect(options.storeId, 7);
      expect(options.addresses.single.id, 55);
      expect(options.addresses.single.isDefault, isTrue);
      expect(options.paymentMethods, ['cash', 'card']);
    });

    test('checkout retry keeps one order identity and stable idempotency key', () async {
      var checkoutCalls = 0;
      final seenKeys = <String?>[];
      final actionApi = HttpCustomerActionApi(
        baseUrl: 'https://foodex.example',
        token: 'platform-token',
        guestSession: CustomerGuestSession()
          ..captureStoreToken(7, 'guest-merge-token'),
        client: MockClient((request) async {
          checkoutCalls++;
          seenKeys.add(request.headers['Idempotency-Key']);
          expect(request.url.path, '/api/v1/checkout');
          expect(request.headers['X-FOODEX-Store-ID'], '7');
          if (checkoutCalls == 1) {
            return http.Response(
              jsonEncode({'message': 'temporary_failure'}),
              503,
              headers: const {'content-type': 'application/json'},
            );
          }
          return http.Response(
            jsonEncode({'id': 9001, 'store_id': 7}),
            201,
            headers: const {'content-type': 'application/json'},
          );
        }),
      );
      final api = DefaultRetailCommerceApi(
        accountApi: _NoopB2cAccountApi(),
        actionApi: actionApi,
        checkoutOptionsApi: _NoopCheckoutOptionsApi(),
        guestSession: actionApi.guestSession,
        guestCartTokenStore: _MemoryGuestCartTokenStore(),
      );
      final guard = RetailCheckoutSubmissionGuard(
        api,
        idempotencyKeyFactory: (_) => 'journey-7-key-000001',
      );

      await expectLater(
        guard.submit(
          storeId: 7,
          addressId: 55,
          paymentMethod: 'cash',
        ),
        throwsA(isA<RetailCommerceException>()),
      );

      final order = await guard.submit(
        storeId: 7,
        addressId: 55,
        paymentMethod: 'cash',
      );

      expect(order.id, 9001);
      expect(order.storeId, 7);
      expect(checkoutCalls, 2);
      expect(seenKeys, ['journey-7-key-000001', 'journey-7-key-000001']);
    });

    test('order lifecycle push refetches authoritative order state', () async {
      final intent = CustomerOrderNotificationIntent.fromData({
        'order_id': 91,
        'store_id': 7,
        'channel': 'b2c',
        'status': 'delivered',
      });
      expect(intent, isNotNull);

      late http.Request captured;
      final orders = HttpCustomerOrdersApi(
        baseUrl: 'https://foodex.example',
        token: 'platform-token',
        client: MockClient((request) async {
          captured = request;
          return http.Response(
            jsonEncode({
              'id': 91,
              'order_number': 'FO-91',
              'store_id': 7,
              'store': {'id': 7, 'name': 'Retail A', 'logo_url': null},
              'channel': 'b2c',
              'status': 'preparing',
              'currency': 'KWD',
              'subtotal': 10,
              'discount_total': 0,
              'delivery_total': 2.5,
              'grand_total': 12.5,
              'items': <Object>[],
              'status_history': <Object>[],
            }),
            200,
            headers: const {'content-type': 'application/json'},
          );
        }),
      );

      final authoritative = await orders.order(
        orderId: intent!.orderId,
        context: intent.context,
      );

      expect(captured.url.path, '/api/v1/orders/91');
      expect(captured.url.queryParameters['store_id'], '7');
      expect(captured.headers['X-FOODEX-Customer-Domain'], 'b2c');
      expect(authoritative.summary.status, 'preparing');
      expect(authoritative.summary.status, isNot('delivered'));
    });

    test('authenticated multi-store and Wholesale smoke regressions stay isolated', () async {
      const session = CustomerSession.platformCustomer(
        accessToken: 'platform-token',
      );
      expect(session.allowsChannel(CustomerChannel.b2c), isTrue);
      expect(session.allowsChannel(CustomerChannel.b2b), isTrue);

      final requests = <http.Request>[];
      final api = HttpCustomerOrdersApi(
        baseUrl: 'https://foodex.example',
        token: 'platform-token',
        client: MockClient((request) async {
          requests.add(request);
          final storeId = int.parse(request.url.queryParameters['store_id']!);
          final channel = request.url.queryParameters['channel']!;
          return http.Response(
            jsonEncode({
              'data': [
                {
                  'id': channel == 'b2c' ? 91 : 92,
                  'order_number': channel == 'b2c' ? 'R-91' : 'W-92',
                  'store_id': storeId,
                  'store': {'id': storeId, 'name': 'Store $storeId'},
                  'channel': channel,
                  'status': 'confirmed',
                  'currency': 'KWD',
                  'grand_total': 10,
                },
              ],
              'meta': {
                'current_page': 1,
                'per_page': 20,
                'total': 1,
                'scope': 'platform_customer',
              },
            }),
            200,
            headers: const {'content-type': 'application/json'},
          );
        }),
      );

      final retail = await api.orders(
        context: const CustomerOrderContext(storeId: 7, channel: 'b2c'),
      );
      final wholesale = await api.orders(
        context: const CustomerOrderContext(storeId: 70, channel: 'b2b'),
      );

      expect(retail.orders.single.storeId, 7);
      expect(retail.orders.single.channel, 'b2c');
      expect(wholesale.orders.single.storeId, 70);
      expect(wholesale.orders.single.channel, 'b2b');

      expect(requests[0].headers['X-FOODEX-Store-ID'], '7');
      expect(requests[0].headers['X-FOODEX-Customer-Domain'], 'b2c');
      expect(requests[1].headers['X-FOODEX-Store-ID'], '70');
      expect(requests[1].headers['X-FOODEX-Customer-Domain'], 'b2b');
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

class _MemoryGuestCartTokenStore implements CustomerGuestCartTokenStore {
  final Map<int, String> _tokens = <int, String>{};

  @override
  Future<void> clear() async => _tokens.clear();

  @override
  Future<Map<int, String>> readAll() async => Map<int, String>.from(_tokens);

  @override
  Future<String?> readToken(int storeId) async => _tokens[storeId];

  @override
  Future<void> removeToken(int storeId) async => _tokens.remove(storeId);

  @override
  Future<void> writeToken(int storeId, String token) async {
    _tokens[storeId] = token;
  }
}

class _NoopCustomerActionApi implements CustomerActionApi {
  @override
  dynamic noSuchMethod(Invocation invocation) =>
      throw UnimplementedError(invocation.memberName.toString());
}

class _NoopB2cAccountApi implements B2cAccountApi {
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
