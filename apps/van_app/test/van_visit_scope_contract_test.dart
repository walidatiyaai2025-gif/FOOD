import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/core/api/http_van_api.dart';
import 'package:foodex_van_app/core/auth/van_session.dart';
import 'package:foodex_van_app/features/visits/http_van_visit_repository.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

Map<String, Object?> visit(String customerType) => {
      'id': 601,
      'customer_type': customerType,
      'customer_id': 42,
      'store_id': 7,
      'route_key': 'WHOLESALE-7',
      'status': 'planned',
      'allowed_transitions': ['started'],
    };

void main() {
  test('Van visit feed accepts assigned B2B route records', () async {
    late Uri requested;
    final repository = HttpVanVisitRepository(
      VanApiClient(
        'https://foodex.example/',
        'van-token',
        client: MockClient((request) async {
          requested = request.url;
          expect(request.headers['Authorization'], 'Bearer van-token');
          return http.Response(
            jsonEncode({
              'data': [visit('b2b')]
            }),
            200,
          );
        }),
      ),
    );

    final results = await repository.visits(status: 'planned');

    expect(requested.path, '/api/v1/van/visits');
    expect(requested.queryParameters['status'], 'planned');
    expect(results, hasLength(1));
    expect(results.single.customerType, 'b2b');
    expect(results.single.routeKey, 'WHOLESALE-7');
  });

  test('Van feed rejects entire mixed B2B/B2C payload without dropping rows',
      () async {
    final repository = HttpVanVisitRepository(
      VanApiClient(
        'https://foodex.example/',
        'van-token',
        client: MockClient((request) async => http.Response(
              jsonEncode({
                'data': [visit('b2b'), visit('b2c')],
              }),
              200,
            )),
      ),
    );

    await expectLater(repository.visits(), throwsA(isA<VanApiException>()));
  });

  test('Van visit transition rejects mismatched customer channel response',
      () async {
    final repository = HttpVanVisitRepository(
      VanApiClient(
        'https://foodex.example/',
        'van-token',
        client: MockClient((request) async {
          expect(request.url.path, '/api/v1/van/visits/601/transition');
          expect(request.method, 'POST');
          return http.Response(jsonEncode(visit('b2c')), 200);
        }),
      ),
    );

    await expectLater(
      repository.transition(visitId: 601, status: 'started'),
      throwsA(isA<VanApiException>()),
    );
  });
}
