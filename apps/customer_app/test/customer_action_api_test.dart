import 'dart:async';

import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('B2C guest cart token is captured for the exact store', () async {
    const guestToken =
        'guest-token-abcdefghijklmnopqrstuvwxyz-0123456789-abcdefghijklmnop';
    final session = CustomerGuestSession();
    final api = HttpCustomerActionApi(
      baseUrl: 'https://foodex.example',
      guestSession: session,
      client: MockClient((request) async {
        expect(request.headers['Authorization'], isNull);
        expect(request.url.path, '/api/v1/cart/items');
        expect(jsonDecode(request.body)['store_id'], 7);

        return http.Response(
          jsonEncode({
            'id': 1,
            'store_id': 7,
            'items': <Object>[],
          }),
          201,
          headers: const {'x-guest-token': guestToken},
        );
      }),
    );

    await api.addCartItem(
      storeId: 7,
      productId: 42,
      quantity: 1,
    );

    expect(session.tokenForStore(7), guestToken);
  });

  test('guest carts remain isolated when switching Retail stores', () async {
    const tokenA = 'guest-token-store-a-abcdefghijklmnopqrstuvwxyz-0123456789';
    const tokenB = 'guest-token-store-b-abcdefghijklmnopqrstuvwxyz-0123456789';
    final session = CustomerGuestSession();
    var call = 0;

    final api = HttpCustomerActionApi(
      baseUrl: 'https://foodex.example',
      guestSession: session,
      client: MockClient((request) async {
        call++;
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        final storeId = body['store_id'] as int;

        if (call == 1) {
          expect(storeId, 7);
          expect(request.headers['X-Guest-Token'], isNull);
          return http.Response('{}', 201, headers: const {'x-guest-token': tokenA});
        }

        if (call == 2) {
          expect(storeId, 8);
          expect(request.headers['X-Guest-Token'], isNull);
          return http.Response('{}', 201, headers: const {'x-guest-token': tokenB});
        }

        expect(storeId, 7);
        expect(request.headers['X-Guest-Token'], tokenA);
        return http.Response('{}', 201, headers: const {'x-guest-token': tokenA});
      }),
    );

    await api.addCartItem(storeId: 7, productId: 41, quantity: 1);
    await api.addCartItem(storeId: 8, productId: 42, quantity: 1);
    await api.addCartItem(storeId: 7, productId: 43, quantity: 1);

    expect(session.tokenForStore(7), tokenA);
    expect(session.tokenForStore(8), tokenB);
    expect(session.activeStoreId, 7);
  });

  test('credential login restores Platform Customer identity on one token', () async {
    final api = HttpCustomerActionApi(
      baseUrl: 'https://foodex.example',
      client: MockClient((request) async {
        expect(request.url.path, '/api/v1/auth/login');
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        expect(body['email'], 'customer@example.test');
        expect(body['password'], 'Password123!');

        return http.Response(
          jsonEncode({
            'token': 'platform-token',
            'token_type': 'Bearer',
            'user': {
              'id': 10,
              'email': 'customer@example.test',
              'platform_customer': true,
            },
          }),
          200,
        );
      }),
    );

    final result = await api.credentialLogin(
      email: 'CUSTOMER@example.test',
      password: 'Password123!',
    );

    expect(result.token, 'platform-token');
    expect(result.platformCustomer, isTrue);
  });

  test('duplicate logout triggers share one physical request', () async {
    var calls = 0;
    final gate = Completer<http.Response>();
    final api = HttpCustomerActionApi(
      baseUrl: 'https://foodex.example',
      token: 'logout-dedupe-token-1242',
      client: MockClient((request) {
        calls++;
        expect(request.url.path, '/api/v1/auth/logout');
        return gate.future;
      }),
    );

    final first = api.logout();
    final second = api.logout();

    await Future<void>.delayed(Duration.zero);
    expect(calls, 1);

    gate.complete(http.Response('', 204));
    await Future.wait([first, second]);

    await api.logout();
    expect(calls, 1);
  });

}
