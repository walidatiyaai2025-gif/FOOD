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
        'message': 'Bearer hidden-token customer@example.test',
      });

    final json = diagnostics.exportJson(
      note: 'Contact customer@example.test if needed.',
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
    expect(details['path'], contains('token=%5BREDACTED%5D'));
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
    expect(
      find.byKey(const ValueKey('customer-diagnostics-clear')),
      findsOneWidget,
    );
  });

  testWidgets('Customer login exposes diagnostics before authentication',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(
        initialRoute: CustomerRoutePaths.checkoutAuth,
        locale: Locale('en'),
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('customer-login-diagnostics')),
      findsOneWidget,
    );
    expect(find.text('Open app diagnostics'), findsOneWidget);
  });
}
