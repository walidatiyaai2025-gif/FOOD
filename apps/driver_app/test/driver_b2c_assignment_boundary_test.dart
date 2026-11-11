import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/core/api/http_driver_api.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

Map<String, dynamic> _assignment(String channel, int id) => {
      'id': id,
      'order_id': 90 + id,
      'assignment_type': channel,
      'status': 'assigned',
      'available_statuses': ['accepted', 'failed'],
      'order': {
        'number': 'RET-$id',
        'status': 'processing',
        'customer': {'name': 'Customer'},
        'items': [],
      },
    };

void main() {
  test('authorized B2C assignment and workflow statuses remain available',
      () async {
    final requests = <String>[];
    final repo = HttpDriverAssignmentRepository(
      'https://foodex.example',
      'driver-b2c-token',
      client: MockClient((request) async {
        requests.add('${request.method} ${request.url.path}');
        expect(request.headers['Authorization'], 'Bearer driver-b2c-token');
        if (request.method == 'GET') {
          return http.Response(
            jsonEncode({
              'data': [_assignment('b2c', 11)],
            }),
            200,
          );
        }
        expect(jsonDecode(request.body)['status'], 'accepted');
        return http.Response('{}', 200);
      }),
    );

    final assignments = await repo.list(DriverChannel.b2c);
    expect(assignments.single.id, 11);
    expect(assignments.single.channel, DriverChannel.b2c);
    expect(assignments.single.availableStatuses, ['accepted', 'failed']);
    await repo.transition(11, DriverChannel.b2c, 'accepted');
    expect(requests, [
      'GET /api/v1/driver/assignments',
      'POST /api/v1/driver/assignments/11/status',
    ]);
  });

  test('a mixed B2C and B2B list fails closed instead of hiding leaked rows',
      () async {
    var requests = 0;
    final repo = HttpDriverAssignmentRepository(
      'https://foodex.example/',
      'driver-token',
      client: MockClient((request) async {
        requests++;
        expect(request.method, 'GET');
        return http.Response(
          jsonEncode({
            'data': [
              _assignment('b2c', 11),
              // Deliberately lacks an id: the wrong-channel row must fail
              // before any foreign order/customer data are parsed.
              {
                'assignment_type': 'b2b',
                'order': {
                  'customer': {'name': 'X'}
                }
              },
            ],
          }),
          200,
        );
      }),
    );

    await expectLater(
      repo.list(DriverChannel.b2c),
      throwsA(isA<DriverApiException>()),
    );
    expect(requests, 1);
  });

  test('foreign-only, missing and unknown channels are never empty success',
      () async {
    for (final assignmentType in <String?>['b2b', null, 'wholesale']) {
      final row = _assignment('b2c', 21);
      if (assignmentType == null) {
        row.remove('assignment_type');
      } else {
        row['assignment_type'] = assignmentType;
      }
      final repo = HttpDriverAssignmentRepository(
        'https://foodex.example/',
        'driver-token',
        client: MockClient((request) async => http.Response(
              jsonEncode({
                'data': [row],
              }),
              200,
            )),
      );

      await expectLater(
        repo.list(DriverChannel.b2c),
        throwsA(isA<DriverApiException>()),
        reason: 'unexpected assignment channel $assignmentType',
      );
    }
  });

  test('legacy B2B Driver cannot request a list or mutate an assignment',
      () async {
    var requests = 0;
    final repo = HttpDriverAssignmentRepository(
      'https://foodex.example/',
      'legacy-b2b-token',
      client: MockClient((request) async {
        requests++;
        return http.Response('{}', 200);
      }),
    );

    await expectLater(
      repo.list(DriverChannel.b2b),
      throwsA(isA<DriverRoleDeniedException>()),
    );
    await expectLater(
      repo.transition(23, DriverChannel.b2b, 'accepted'),
      throwsA(isA<DriverRoleDeniedException>()),
    );
    expect(requests, 0);
  });

  test('server 401 and 403 remain distinct auth/authorization failures',
      () async {
    for (final status in [401, 403]) {
      final repo = HttpDriverAssignmentRepository(
        'https://foodex.example/',
        'driver-token',
        client: MockClient((request) async => http.Response('{}', status)),
      );

      await expectLater(
        repo.list(DriverChannel.b2c),
        throwsA(status == 401
            ? isA<DriverSessionExpiredException>()
            : isA<DriverAccessDeniedException>()),
      );
    }
  });
}
