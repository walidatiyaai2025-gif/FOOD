import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/core/api/http_van_api.dart';
import 'package:foodex_van_app/features/wallet/http_van_wallet_repository.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('Van customer API requires exact true for direct-sale authority', () async {
    final client = MockClient((request) async {
      expect(request.method, 'GET');
      expect(request.url.path, '/api/v1/van/customers');

      return http.Response(
        jsonEncode({
          'data': [
            {
              'type': 'b2b',
              'id': 10,
              'name': 'Active visit',
              'store_id': 4,
              'can_direct_sell': true,
            },
            {
              'type': 'b2b',
              'id': 11,
              'name': 'Closed visit',
              'store_id': 4,
              'can_direct_sell': false,
            },
            {
              'type': 'b2b',
              'id': 12,
              'name': 'Legacy missing signal',
              'store_id': 4,
            },
            {
              'type': 'b2b',
              'id': 13,
              'name': 'Forged string signal',
              'store_id': 4,
              'can_direct_sell': 'true',
            },
            {
              'type': 'b2c',
              'id': 14,
              'name': 'Retail collection history',
              'store_id': 5,
              'can_direct_sell': false,
            },
          ],
        }),
        200,
        headers: {'content-type': 'application/json'},
      );
    });
    final repository = HttpVanWalletRepository(
      VanApiClient('https://foodex.test/', 'test-token', client: client),
    );

    final customers = await repository.customers();
    expect(customers.length, 5);
    expect(customers[0].canDirectSell, isTrue);
    for (final customer in customers.skip(1)) {
      expect(customer.canDirectSell, isFalse);
    }
    expect(customers[4].type, 'b2c');
    client.close();
  });
}
