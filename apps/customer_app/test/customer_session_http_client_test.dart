import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'package:foodex_customer_app/core/auth/customer_session_http_client.dart';

void main() {
  group('CustomerSessionHttpClient', () {
    test('signals authoritative expiry on authenticated 401', () async {
      var expired = 0;
      final client = CustomerSessionHttpClient(
        MockClient((request) async => http.Response('', 401)),
        onUnauthorized: () => expired++,
      );

      final response = await client.get(
        Uri.parse('https://example.test/profile'),
        headers: const {'Authorization': 'Bearer expired-token'},
      );

      expect(response.statusCode, 401);
      expect(expired, 1);
      client.close();
    });

    test('does not treat unauthenticated login 401 as session expiry', () async {
      var expired = 0;
      final client = CustomerSessionHttpClient(
        MockClient((request) async => http.Response('', 401)),
        onUnauthorized: () => expired++,
      );

      final response = await client.post(
        Uri.parse('https://example.test/auth/login'),
      );

      expect(response.statusCode, 401);
      expect(expired, 0);
      client.close();
    });

    test('does not clear session for authenticated non-401 response', () async {
      var expired = 0;
      final client = CustomerSessionHttpClient(
        MockClient((request) async => http.Response('', 403)),
        onUnauthorized: () => expired++,
      );

      final response = await client.get(
        Uri.parse('https://example.test/profile'),
        headers: const {'Authorization': 'Bearer valid-token'},
      );

      expect(response.statusCode, 403);
      expect(expired, 0);
      client.close();
    });
  });
}
