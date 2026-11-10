import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'package:foodex_customer_app/core/auth/customer_session_http_client.dart';

void main() {
  group('V2 Customer session revocation', () {
    test('stale 401 cannot expire a newer session', () async {
      final pending = Completer<http.Response>();
      String? currentToken = 'old-token';
      var expiryCalls = 0;
      final client = CustomerSessionHttpClient(
        MockClient((request) => pending.future),
        onUnauthorized: () {
          expiryCalls++;
          currentToken = null;
        },
        isCurrentToken: (token) => token == currentToken,
      );

      final oldRequest = client.get(
        Uri.parse('https://example.test/api/v1/auth/profile'),
        headers: const {'Authorization': 'Bearer old-token'},
      );

      // A new login completed before the old request returned. Its token is
      // authoritative; a late response must not sign that new session out.
      currentToken = 'new-token';
      pending.complete(http.Response('{"message":"Unauthenticated."}', 401));

      final response = await oldRequest;
      expect(response.statusCode, 401);
      expect(currentToken, 'new-token');
      expect(expiryCalls, 0);
      client.close();
    });

    test('current revoked token clears session exactly once', () async {
      String? currentToken = 'revoked-token';
      var expiryCalls = 0;
      final client = CustomerSessionHttpClient(
        MockClient((request) async => http.Response('', 401)),
        onUnauthorized: () {
          expiryCalls++;
          currentToken = null;
        },
        isCurrentToken: (token) => token == currentToken,
      );

      for (var attempt = 0; attempt < 2; attempt++) {
        final response = await client.get(
          Uri.parse('https://example.test/api/v1/auth/profile'),
          headers: const {'Authorization': 'Bearer revoked-token'},
        );
        expect(response.statusCode, 401);
      }

      expect(currentToken, isNull);
      expect(expiryCalls, 1);
      client.close();
    });

    test('permission 403 preserves the valid session', () async {
      const currentToken = 'still-authorized-for-login';
      var expiryCalls = 0;
      final client = CustomerSessionHttpClient(
        MockClient((request) async => http.Response('', 403)),
        onUnauthorized: () => expiryCalls++,
        isCurrentToken: (token) => token == currentToken,
      );

      final response = await client.get(
        Uri.parse('https://example.test/api/v1/retail/orders'),
        headers: const {'Authorization': 'Bearer still-authorized-for-login'},
      );

      expect(response.statusCode, 403);
      expect(expiryCalls, 0);
      expect(currentToken, 'still-authorized-for-login');
      client.close();
    });

    test('login 401 without bearer does not expire a session', () async {
      var expiryCalls = 0;
      final client = CustomerSessionHttpClient(
        MockClient((request) async => http.Response('', 401)),
        onUnauthorized: () => expiryCalls++,
        isCurrentToken: (token) => true,
      );

      final response = await client.post(
        Uri.parse('https://example.test/api/v1/auth/login'),
      );

      expect(response.statusCode, 401);
      expect(expiryCalls, 0);
      client.close();
    });

    test('bearer scheme casing is ignored', () async {
      String? currentToken = 'case-token';
      var expiryCalls = 0;
      final client = CustomerSessionHttpClient(
        MockClient((request) async => http.Response('', 401)),
        onUnauthorized: () {
          expiryCalls++;
          currentToken = null;
        },
        isCurrentToken: (token) => token == currentToken,
      );

      final response = await client.get(
        Uri.parse('https://example.test/api/v1/auth/profile'),
        headers: const {'authorization': 'bearer case-token'},
      );

      expect(response.statusCode, 401);
      expect(expiryCalls, 1);
      expect(currentToken, isNull);
      client.close();
    });
  });
}
