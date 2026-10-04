import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/app.dart';
import 'package:foodex_driver_app/core/api/http_driver_api.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/features/delivery/driver_assignment_contract.dart';
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
  Future<void> transition(int id, DriverChannel channel, String status, {String? note, String? failureReason}) async {}
}

void main() {
  test('HTTP auth derives exact driver channel from backend roles', () async {
    final client = MockClient((request) async {
      expect(request.url.path, '/api/v1/auth/login');
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      expect(body['email'], 'driver@example.test');
      expect(body['password'], 'secret-password');
      return http.Response(
        jsonEncode({
          'token': 'abc',
          'user': {
            'name': 'Driver',
            'email': 'driver@example.test',
            'locale': 'en',
            'roles': ['B2C_DRIVER'],
            'driver_scope': {
              'driver_id': 17,
              'channel': 'b2c',
              'store_id': 41,
            },
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
      password: 'secret-password',
    );
    expect(result.channel, DriverChannel.b2c);
    expect(result.storeId, 41);
    expect(result.token, 'abc');
  });

  test('HTTP assignment repository uses canonical list and transition endpoints',
      () async {
    final requests = <String>[];
    final client = MockClient((request) async {
      requests.add(
        '${request.method} ${request.url.path}${request.url.hasQuery ? '?${request.url.query}' : ''}',
      );
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
                'order': {
                  'number': 'B2B-55',
                  'currency': 'EGP',
                  'address': {
                    'line1': 'Warehouse Street',
                    'city': 'Cairo',
                  },
                  'navigation': {
                    'available': true,
                    'latitude': 30.04442,
                    'longitude': 31.235712,
                  },
                  'items': [
                    {
                      'sku': 'ITEM-1',
                      'name': 'Rice box',
                      'image_url': 'https://foodex.example/storage/rice.webp',
                      'variant': 'Large',
                      'quantity': 2,
                      'unit': 'Box',
                      'note': 'Keep upright',
                      'unit_price': 10,
                      'line_total': 20,
                    }
                  ],
                  'invoice': {
                    'id': 77,
                    'number': 'INV-B2B-55',
                    'revision': 1,
                    'status': 'issued',
                    'currency': 'EGP',
                    'subtotal': 50,
                    'discount_total': 0,
                    'delivery_total': 0,
                    'tax_total': 0,
                    'grand_total': 50,
                    'payment_method': 'account_credit',
                    'payment_status': 'pending',
                    'items': [
                      {
                        'sku': 'CASE-1',
                        'name': 'Wholesale case',
                        'quantity': 5,
                        'line_total': 50,
                      }
                    ],
                  },
                },
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
    expect(rows.single.invoice?.number, 'INV-B2B-55');
    expect(rows.single.invoice?.grandTotal, 50);
    expect(rows.single.address, contains('Warehouse Street'));
    expect(rows.single.hasNavigation, isTrue);
    expect(rows.single.navigationLatitude, 30.04442);
    expect(rows.single.navigationLongitude, 31.235712);
    expect(rows.single.items.single.sku, 'ITEM-1');
    expect(rows.single.items.single.imageUrl,
        'https://foodex.example/storage/rice.webp');
    expect(rows.single.items.single.variant, 'Large');
    expect(rows.single.items.single.unit, 'Box');
    expect(rows.single.items.single.note, 'Keep upright');
    expect(rows.single.items.single.unitPrice, 10);
    expect(rows.single.invoice?.items.single.sku, 'CASE-1');
    await repo.transition(9, DriverChannel.b2b, 'accepted');
    expect(requests, [
      'GET /api/v1/driver/assignments?scope=all',
      'POST /api/v1/driver/assignments/9/status',
    ]);
  });

  test('HTTP assignment repository loads central failed-delivery reasons',
      () async {
    final client = MockClient((request) async {
      expect(request.method, 'GET');
      expect(request.url.path, '/api/v1/lookups/failed-delivery-reasons');
      return http.Response(
        jsonEncode({
          'type': 'failed-delivery-reasons',
          'data': [
            {
              'code': 'customer_no_answer',
              'label_ar': 'العميل لا يرد',
              'label_en': 'Customer did not answer',
            },
            {
              'code': 'other',
              'label_ar': 'سبب آخر',
              'label_en': 'Other reason',
            },
          ],
        }),
        200,
      );
    });

    final repo = HttpDriverAssignmentRepository(
      'https://foodex.example/',
      'token',
      client: client,
    );
    final reasons = await repo.failedDeliveryReasons();

    expect(reasons.map((reason) => reason.code),
        ['customer_no_answer', 'other']);
    expect(reasons.first.labelFor('ar'), 'العميل لا يرد');
    expect(reasons.first.labelFor('en'), 'Customer did not answer');
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
      'secret-password',
    );
    await tester.tap(find.byKey(const Key('driver-login-submit')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('driver-open-deliveries')), findsOneWidget);
    expect(find.byKey(const Key('driver-route-denied')), findsNothing);

    await tester.tap(find.byKey(const Key('driver-open-deliveries')));
    await tester.pumpAndSettle();
    expect(find.text('#41'), findsOneWidget);
    expect(find.byKey(const Key('driver-route-denied')), findsNothing);
  });
}
