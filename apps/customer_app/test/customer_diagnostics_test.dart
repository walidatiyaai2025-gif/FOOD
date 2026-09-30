import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:foodex_customer_app/core/diagnostics/customer_diagnostics.dart';
import 'package:foodex_customer_app/core/localization/app_translations.dart';
import 'package:foodex_customer_app/features/diagnostics/customer_diagnostics_screen.dart';

void main() {
  test('redacts secrets and private customer fields recursively', () {
    final value = CustomerDiagnostics.sanitize({
      'password': 'secret',
      'Authorization': 'Bearer abc.def.ghi',
      'nested': {
        'email': 'person@example.com',
        'safe': 'order_failed',
      },
    }) as Map<String, Object?>;

    expect(value['password'], '[REDACTED]');
    expect(value['Authorization'], '[REDACTED]');
    expect((value['nested'] as Map)['email'], '[REDACTED]');
    expect((value['nested'] as Map)['safe'], 'order_failed');
  });

  test('keeps a bounded rolling event window and exports stable schema', () {
    final diagnostics = CustomerDiagnostics(maxEvents: 3);
    for (var index = 0; index < 5; index++) {
      diagnostics.record('event', {'index': index});
    }
    diagnostics.updateContext(
      route: '/retail/17/home?email=private@example.com',
      locale: 'en',
      isAuthenticated: true,
      channel: 'b2c',
      storeId: 17,
    );

    expect(diagnostics.events.length, 3);
    expect(diagnostics.events.first.data['index'], 2);
    final exported = diagnostics.buildExport(note: 'fails for 96512345678');
    expect(exported['schema_version'], 1);
    expect((exported['context'] as Map)['route'], '/retail/17/home');
    expect((exported['events'] as List).length, 3);
    expect(jsonEncode(exported), isNot(contains('96512345678')));
  });

  test('HTTP inspector records sanitized failures without query values', () async {
    final diagnostics = CustomerDiagnostics();
    final client = CustomerDiagnosticsHttpClient(
      diagnostics,
      inner: MockClient(
        (_) async => http.Response(
          '{"email":"private@example.com"}',
          500,
          headers: {'x-request-id': 'req-123'},
        ),
      ),
    );

    await client.get(
      Uri.parse('https://foodex.test/api/v1/orders?email=private@example.com'),
      headers: {'Authorization': 'Bearer top-secret'},
    );

    final event = diagnostics.events.single;
    expect(event.type, 'api_failure');
    expect(event.data['path'], '/api/v1/orders');
    expect(event.data['status_code'], 500);
    expect(event.data['request_id'], 'req-123');
    expect(jsonEncode(event.toJson()), isNot(contains('private@example.com')));
    expect(jsonEncode(event.toJson()), isNot(contains('top-secret')));
  });

  testWidgets('diagnostics screen exposes English and Arabic labels', (tester) async {
    await tester.pumpWidget(
      const AppTranslations(
        locale: Locale('en'),
        overrides: {},
        child: MaterialApp(home: CustomerDiagnosticsScreen()),
      ),
    );
    expect(find.byKey(const ValueKey('customer-diagnostics-export')), findsOneWidget);
    expect(find.text('Export JSON'), findsOneWidget);

    await tester.pumpWidget(
      const AppTranslations(
        locale: Locale('ar'),
        overrides: {},
        child: MaterialApp(home: CustomerDiagnosticsScreen()),
      ),
    );
    expect(find.text('تصدير JSON'), findsOneWidget);
  });
}
