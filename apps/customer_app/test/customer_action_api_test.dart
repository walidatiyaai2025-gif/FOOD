import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('B2C guest can add to cart and captures the shared guest token', () async {
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

    expect(session.token, guestToken);
  });
}
