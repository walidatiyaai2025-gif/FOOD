import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/b2c_account_api.dart';
import 'package:foodex_customer_app/core/api/b2c_catalog_api.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  group('CJ-V2 Guest journey contract scaffold', () {
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

  group('CJ-V2 integrated acceptance pending lanes A-F', () {
    test(
      'guest session restores the same store cart after app restart',
      () {},
      skip: 'Activate after #676 persistent guest-session contract lands.',
    );
    test(
      'guest Login or Register returns to checkout and merges cart exactly once',
      () {},
      skip: 'Activate after #676 and #678 converge.',
    );
    test(
      'checkout creates one order and one Dashboard order-created notification',
      () {},
      skip: 'Activate after #678 and #681 converge.',
    );
    test(
      'new registration creates one Dashboard registration notification only',
      () {},
      skip: 'Activate after #681 registration integration lands.',
    );
    test(
      'order lifecycle push deep-link forces authoritative order refresh',
      () {},
      skip: 'Activate after #680 order refresh contract lands.',
    );
    test(
      'authenticated multi-store and Wholesale smoke regressions stay isolated',
      () {},
      skip: 'Activate against integrated main before #682 is merge-ready.',
    );
  });
}
