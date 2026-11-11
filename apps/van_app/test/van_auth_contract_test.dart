import 'dart:convert';

import 'package:foodex_van_app/core/api/http_van_api.dart';
import 'package:foodex_van_app/core/auth/van_session.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('Van login accepts backend permission authority', () async {
    final repo = HttpVanAuthRepository(
      'https://foodex.example/',
      client: MockClient((request) async {
        expect(request.url.path, '/api/v1/auth/login');
        return http.Response(
          jsonEncode({
            'token': 'token-1',
            'user': {
              'name': 'Van Operator',
              'email': 'van@example.test',
              'locale': 'en',
              'permissions': ['van.login', 'van.support.view'],
              'van_scope': {
                'van_id': 12,
                'van_code': 'VAN-12',
                'assignment_id': 34,
              },
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    final session = await repo.login(
      email: 'van@example.test',
      password: 'password',
    );

    expect(session.canUseVan, isTrue);
    expect(session.permissions, contains('van.support.view'));
  });

  test('Van login fails closed when van.login is absent', () async {
    final repo = HttpVanAuthRepository(
      'https://foodex.example/',
      client: MockClient(
        (_) async => http.Response(
          jsonEncode({
            'token': 'token-2',
            'user': {
              'name': 'Not Van',
              'email': 'user@example.test',
              'locale': 'en',
              'permissions': ['orders.view'],
            },
          }),
          200,
        ),
      ),
    );

    expect(
      repo.login(email: 'user@example.test', password: 'password'),
      throwsA(isA<VanAccessDeniedException>()),
    );
  });

  test(
      'Van login maps a server 403 to access denied rather than a generic API error',
      () async {
    final repo = HttpVanAuthRepository(
      'https://foodex.example/',
      client: MockClient((request) async {
        expect(request.url.path, '/api/v1/auth/login');
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        expect(body['app'], 'van');
        return http.Response(
          jsonEncode({
            'message':
                'No effective Van assignment is available for this account.',
          }),
          403,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    await expectLater(
      repo.login(email: 'denied@example.test', password: 'irrelevant'),
      throwsA(isA<VanAccessDeniedException>()),
    );
  });

  test(
      'Van login denies 200 payload with van.login but no effective assignment',
      () async {
    final repo = HttpVanAuthRepository(
      'https://foodex.example/',
      client: MockClient((_) async => http.Response(
            jsonEncode({
              'token': 'must-not-be-accepted',
              'user': {
                'name': 'Unassigned Operator',
                'email': 'unassigned@example.test',
                'locale': 'ar',
                'permissions': ['van.login'],
                'van_scope': {
                  'van_id': 8,
                  'van_code': 'VAN-8',
                  'assignment_id': 0,
                },
              },
            }),
            200,
            headers: {'content-type': 'application/json'},
          )),
    );

    await expectLater(
      repo.login(email: 'unassigned@example.test', password: 'irrelevant'),
      throwsA(isA<VanAccessDeniedException>()),
    );
  });

  test('authenticated client maps 401 to session expiry', () async {
    final api = VanApiClient(
      'https://foodex.example/',
      'expired',
      client: MockClient((_) async => http.Response('{}', 401)),
    );

    expect(
      api.getJson('van/visits'),
      throwsA(isA<VanSessionExpiredException>()),
    );
  });
}
