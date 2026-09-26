import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/app.dart';
import 'package:foodex_driver_app/core/api/http_driver_api.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/features/tasks/driver_journey.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

class FakeAuth implements DriverAuthRepository {
  FakeAuth(this.session);
  final DriverSession session;

  @override
  Future<DriverSession> login({
    required String email,
    required String password,
  }) async =>
      session;

  @override
  Future<void> logout(String token) async {}
}

class FakeAssignments implements DriverAssignmentRepository {
  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async => const [
        DriverAssignment(
          id: 3,
          orderId: 41,
          channel: DriverChannel.b2c,
          reference: '#41',
          status: 'assigned',
          availableStatuses: ['accepted'],
        ),
      ];

  @override
  Future<void> transition(int id, DriverChannel channel, String status) async {}
}

void main() {
  test('HTTP auth derives exact driver channel from backend roles', () async {
    final client = MockClient((request) async {
      expect(request.url.path, '/api/v1/auth/login');
      return http.Response(
        jsonEncode({
          'token': 'abc',
          'user': {
            'name': 'Driver',
            'email': 'driver@example.test',
            'locale': 'en',
            'roles': ['B2C_DRIVER'],
          },
        }),
        200,
        headers: {'content-type': 'application/json'},
      );
    });
    final repo = HttpDriverAuthRepository(
      'https://foodex.example/',
      client: client,
    );

    final result = await repo.login(
      email: 'driver@example.test',
      password: 'secret',
    );
    expect(result.channel, DriverChannel.b2c);
    expect(result.token, 'abc');
  });

  test('HTTP assignment repository uses canonical list and transition endpoints',
      () async {
    final requests = <String>[];
    final client = MockClient((request) async {
      requests.add(request.method + ' ' + request.url.path);
      if (request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 9,
                'order_id': 55,
                'assignment_type': 'b2b',
                'status': 'assigned',
                'available_statuses': ['accepted'],
              }
            ],
          }),
          200,
        );
      }
      expect(jsonDecode(request.body)['status'], 'accepted');
      return http.Response(
        jsonEncode({
          'data': {
            'id': 9,
            'order_id': 55,
            'assignment_type': 'b2b',
            'status': 'accepted',
            'available_statuses': ['picked_up'],
          }
        }),
        200,
      );
    });
    final repo = HttpDriverAssignmentRepository(
      'https://foodex.example/',
      'token',
      client: client,
    );

    final rows = await repo.list(DriverChannel.b2b);
    expect(rows.single.availableStatuses, ['accepted']);
    await repo.transition(9, DriverChannel.b2b, 'accepted');
    expect(requests, [
      'GET /api/v1/driver/assignments',
      'POST /api/v1/driver/assignments/9/status',
    ]);
  });

  testWidgets('launch login reaches backend-derived delivery journey',
      (tester) async {
    const session = DriverSession(
      token: 'token',
      name: 'Driver',
      email: 'driver@example.test',
      locale: 'ar',
      channel: DriverChannel.b2c,
    );
    await tester.pumpWidget(
      FoodexDriverApp(
        authRepository: FakeAuth(session),
        assignmentRepositoryFactory: (_) => FakeAssignments(),
      ),
    );

    await tester.enterText(
      find.byKey(const Key('driver-login-email')),
      'driver@example.test',
    );
    await tester.enterText(
      find.byKey(const Key('driver-login-password')),
      'password',
    );
    await tester.tap(find.byKey(const Key('driver-login-submit')));
    await tester.pumpAndSettle();
    expect(find.text('/driver/b2c/home'), findsOneWidget);

    await tester.tap(find.byKey(const Key('driver-open-deliveries')));
    await tester.pumpAndSettle();
    expect(find.text('#41'), findsOneWidget);
    expect(find.text('/driver/b2b/home'), findsNothing);
  });
}
