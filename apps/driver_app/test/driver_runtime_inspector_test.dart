import 'dart:convert';

import 'package:foodex_driver_app/core/diagnostics/driver_runtime_inspector.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('redacts credentials and private values from exported diagnostics', () {
    final inspector = DriverRuntimeInspector(maxEvents: 10);

    inspector.recordException(
      Exception(
        'password=hunter2 Authorization=secret Bearer abc.def.ghi '
        'driver@example.com +965 5555 1234 29.375900',
      ),
      StackTrace.fromString(
        'token=topsecret https://example.test/path?token=hidden&lat=29.375900',
      ),
      source: 'flutter',
    );

    final encoded = jsonEncode(
      inspector.exportPayload(locale: 'en', authenticated: true),
    );

    expect(encoded, isNot(contains('hunter2')));
    expect(encoded, isNot(contains('abc.def.ghi')));
    expect(encoded, isNot(contains('topsecret')));
    expect(encoded, isNot(contains('hidden')));
    expect(encoded, isNot(contains('driver@example.com')));
    expect(encoded, isNot(contains('29.375900')));
    expect(encoded, contains('[REDACTED]'));
  });

  test('remote flush sends only sanitized queued Driver diagnostics', () async {
    final inspector = DriverRuntimeInspector(maxEvents: 10);
    inspector.recordException(
      Exception(
        'Bearer driver-secret driver@example.test 29.375900',
      ),
      StackTrace.fromString('token=driver-stack-secret'),
      source: 'flutter',
    );

    http.Request? captured;
    final client = MockClient((request) async {
      captured = request;
      return http.Response('{}', 202);
    });

    final submitted = await inspector.flushRemote(
      client: client,
      requestUri: Uri.parse(
        'https://foodex.example.test/api/v1/driver/assignments?token=request-secret',
      ),
      authorization: 'Bearer driver-transport-credential',
    );

    expect(submitted, 1);
    expect(captured, isNotNull);
    expect(captured!.url.path, '/api/v1/runtime-inspector/events');
    expect(captured!.headers['Authorization'], 'Bearer driver-transport-credential');

    final body = captured!.body;
    final payload = jsonDecode(body) as Map<String, dynamic>;
    expect(payload['app'], 'driver');
    expect(payload['category'], 'error');
    expect(body, isNot(contains('driver-secret')));
    expect(body, isNot(contains('driver@example.test')));
    expect(body, isNot(contains('29.375900')));
    expect(body, isNot(contains('driver-stack-secret')));
    expect(body, isNot(contains('driver-transport-credential')));
    expect(inspector.snapshot().single['remote_submitted_at'], isNotNull);
  });

  test('keeps only the configured bounded event window', () {
    final inspector = DriverRuntimeInspector(maxEvents: 3);

    for (var i = 0; i < 7; i++) {
      inspector.recordNavigation('/driver/page/$i');
    }

    expect(inspector.eventCount, 3);
    expect(
      inspector.snapshot().map((event) => event['route']),
      ['/driver/page/4', '/driver/page/5', '/driver/page/6'],
    );
  });

  test('HTTP client captures failure metadata without body or query values',
      () async {
    final inspector = DriverRuntimeInspector(maxEvents: 10);
    final client = DriverDiagnosticHttpClient(
      MockClient(
        (request) async => http.Response(
          '{"password":"server-secret","customer_phone":"55551234"}',
          503,
        ),
      ),
      inspector: inspector,
    );

    final response = await client.get(
      Uri.parse(
        'https://foodex.example/api/v1/orders/123?token=query-secret&lat=29.375900',
      ),
      headers: const {
        'Authorization': 'Bearer header-secret',
      },
    );

    expect(response.statusCode, 503);
    final encoded = jsonEncode(inspector.snapshot());
    expect(encoded, contains('"status_code":503'));
    expect(encoded, contains('/orders/:id'));
    expect(encoded, isNot(contains('query-secret')));
    expect(encoded, isNot(contains('header-secret')));
    expect(encoded, isNot(contains('server-secret')));
    expect(encoded, isNot(contains('55551234')));
  });
}
