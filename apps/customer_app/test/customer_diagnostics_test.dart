import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/diagnostics/customer_diagnostics.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('diagnostics redacts secrets and private customer data recursively', () {
    final diagnostics = CustomerDiagnostics(maxEvents: 10)
      ..updateContext(
        appVersion: '1.2.3',
        apiBaseUrl: 'https://foodex.example.test/api?token=secret',
        locale: 'ar',
        authenticated: true,
        channel: 'b2c',
        platformWide: true,
        retailStoreContextId: 7,
      )
      ..record('failure', {
        'password': 'super-secret',
        'Authorization': 'Bearer hidden-token',
        'nested': {
          'email': 'customer@example.test',
          'phone': '+96550000000',
          'address': 'Secret street',
          'latitude': 29.375859,
          'longitude': 47.977405,
        },
        'message': 'Bearer hidden-token customer@example.test; access_token=plain-secret; coordinates=29.375859,47.977405',
      });

    final json = diagnostics.exportJson(
      note: 'Call +96550000001; civil_id=123456789012; token=note-secret; Contact customer@example.test.',
    );

    expect(json, contains('[REDACTED]'));
    expect(json, contains('[REDACTED_EMAIL]'));
    expect(json, isNot(contains('super-secret')));
    expect(json, isNot(contains('hidden-token')));
    expect(json, isNot(contains('customer@example.test')));
    expect(json, isNot(contains('+96550000000')));
    expect(json, isNot(contains('Secret street')));
    expect(json, isNot(contains('29.375859')));
    expect(json, isNot(contains('47.977405')));
    expect(json, isNot(contains('token=secret')));
    expect(json, isNot(contains('plain-secret')));
    expect(json, isNot(contains('note-secret')));
    expect(json, isNot(contains('+96550000001')));
    expect(json, isNot(contains('123456789012')));
    expect(json, isNot(contains('29.375859,47.977405')));
  });

  test('runtime failure diagnostics sanitize paths and retain safe support refs', () {
    final diagnostics = CustomerDiagnostics(maxEvents: 10)
      ..recordRuntimeFailure(
        operation: 'b2b_remote_load',
        path: '/api/v1/b2b/products/42?store_id=7&token=secret-token',
        category: 'server_failure',
        statusCode: 503,
        supportReference: 'req-safe-503',
      );

    final json = diagnostics.exportJson();
    expect(json, contains('server_failure'));
    expect(json, contains('503'));
    expect(json, contains('store_id=7'));
    expect(json, contains('req-safe-503'));
    expect(json, contains('token=[REDACTED]'));
    expect(json, isNot(contains('secret-token')));
  });

  test('diagnostics retains only the configured rolling event window', () {
    final diagnostics = CustomerDiagnostics(maxEvents: 3);

    for (var index = 0; index < 5; index++) {
      diagnostics.record('event', {'index': index});
    }

    expect(diagnostics.events, hasLength(3));
    expect(
      diagnostics.events
          .map((event) => (event['details'] as Map)['index'])
          .toList(),
      [2, 3, 4],
    );
    expect(diagnostics.exportPayload()['schema_version'], 1);
    expect(diagnostics.exportPayload()['event_count'], 3);
  });

  test('HTTP observer captures failed status without headers or sensitive query values',
      () async {
    final diagnostics = CustomerDiagnostics(maxEvents: 10);
    final client = CustomerDiagnosticsHttpClient(
      MockClient(
        (request) async => http.Response(
          '{"message":"failed"}',
          500,
          headers: {'x-request-id': 'req-123'},
        ),
      ),
      diagnostics: diagnostics,
    );

    final response = await client.get(
      Uri.parse(
        'https://foodex.example.test/api/v1/orders?token=secret-token&store=7',
      ),
      headers: {'Authorization': 'Bearer should-never-be-recorded'},
    );

    expect(response.statusCode, 500);
    expect(diagnostics.events, hasLength(1));
    final event = diagnostics.events.single;
    expect(event['type'], 'api_failure');
    final details = Map<String, dynamic>.from(event['details'] as Map);
    expect(details['status_code'], 500);
    expect(details['correlation_id'], 'req-123');
    expect(details['path'], contains('store=7'));
    expect(details['path'], contains('token=[REDACTED]'));
    expect(details.toString(), isNot(contains('secret-token')));
    expect(details.toString(), isNot(contains('should-never-be-recorded')));

    client.close();
  });

  testWidgets('diagnostics route is available in Arabic without authentication',
      (tester) async {
    await CustomerDiagnostics.instance.clear();

    await tester.pumpWidget(
      const FoodexCustomerApp(
        initialRoute: CustomerRoutePaths.diagnostics,
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('تشخيص التطبيق'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('customer-diagnostics-export')),
      findsOneWidget,
    );
    await tester.scrollUntilVisible(
      find.byKey(const ValueKey('customer-diagnostics-clear')),
      220,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.drag(
      find.byType(Scrollable).first,
      const Offset(0, -500),
    );
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('customer-diagnostics-clear')),
      findsOneWidget,
    );
  });

  testWidgets('Customer checkout auth uses the unified customer auth surface',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(
        initialRoute:
            '/auth/checkout?channel=retail&store_id=7&next=%2Fcheckout%2Faddress-payment%3Fchannel%3Dretail%26store_id%3D7',
        locale: Locale('en'),
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('unified-customer-auth-screen')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('unified-auth-submit')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('customer-login-diagnostics')),
      findsOneWidget,
    );
  });

  test('central inspector keeps offline Customer failure pending until accepted',
      () async {
    final diagnostics = CustomerDiagnostics(maxEvents: 10)
      ..updateContext(
        appVersion: '1.0.53',
        apiBaseUrl: 'https://foodex.example.test',
        locale: 'en',
        authenticated: true,
        channel: 'b2c',
        platformWide: true,
        retailStoreContextId: 7,
      )
      ..recordRuntimeFailure(
        operation: 'orders_load',
        path: '/api/v1/orders?token=body-secret',
        category: 'server_failure',
        statusCode: 503,
        supportReference: 'cid-customer-896',
      );

    final offline = await diagnostics.flushToInspector(
      baseUrl: 'https://foodex.example.test',
      token: 'transport-secret',
      client: MockClient((request) async {
        throw http.ClientException('offline');
      }),
    );
    expect(offline, 0);
    expect(diagnostics.events.single['remote_submitted_at'], isNull);

    final requests = <http.Request>[];
    final onlineClient = MockClient((request) async {
      requests.add(request);
      return http.Response('', 202);
    });

    final submitted = await diagnostics.flushToInspector(
      baseUrl: 'https://foodex.example.test',
      token: 'transport-secret',
      client: onlineClient,
    );
    final repeated = await diagnostics.flushToInspector(
      baseUrl: 'https://foodex.example.test',
      token: 'transport-secret',
      client: onlineClient,
    );

    expect(submitted, 1);
    expect(repeated, 0);
    expect(requests, hasLength(1));
    expect(requests.single.url.path, '/api/v1/runtime-inspector/events');

    final payload =
        Map<String, dynamic>.from(jsonDecode(requests.single.body) as Map);
    expect(payload['app'], 'customer');
    expect(payload['category'], 'runtime_failure');
    expect(payload['status'], 503);
    expect(payload['store_id'], 7);
    expect(payload['channel'], 'b2c');
    expect(payload['correlation_id'], 'cid-customer-896');
    expect(requests.single.body, isNot(contains('body-secret')));
    expect(requests.single.body, isNot(contains('transport-secret')));
    expect(diagnostics.events.single['remote_submitted_at'], isNotNull);
  });

}
