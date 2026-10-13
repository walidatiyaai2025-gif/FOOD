import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/network/customer_data_mode.dart';
import 'package:foodex_customer_app/core/network/customer_low_data_http_client.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

String? _headerValue(Map<String, String> headers, String name) {
  final target = name.toLowerCase();
  for (final entry in headers.entries) {
    if (entry.key.toLowerCase() == target) return entry.value;
  }
  return null;
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues(<String, Object>{});
    CustomerDataModeController.instance.resetForTesting();
  });

  test('healthy connectivity is network-first even when a cache exists',
      () async {
    var networkCalls = 0;
    final lowDataHeaders = <String?>[];
    final metrics = CustomerNetworkMetrics();
    final client = CustomerLowDataHttpClient(
      MockClient((request) async {
        networkCalls += 1;
        lowDataHeaders.add(
          _headerValue(request.headers, 'x-foodex-low-data'),
        );
        return http.Response(
          '{"version":$networkCalls}',
          200,
          headers: <String, String>{
            'content-type': 'application/json',
            'etag': '"products-v$networkCalls"',
          },
        );
      }),
      dataMode: CustomerDataModeController.instance,
      metrics: metrics,
      cache: CustomerHttpResponseCache(maxEntries: 8),
    );

    final uri = Uri.parse('https://foodex.example/api/v1/products?store=7');

    final first = await client.get(uri);
    expect(first.body, '{"version":1}');
    expect(networkCalls, 1);
    expect(first.headers['x-foodex-cache'], isNull);

    final second = await client.get(uri);
    expect(second.body, '{"version":2}');
    expect(networkCalls, 2);
    expect(second.headers['x-foodex-cache'], isNull);
    expect(lowDataHeaders, everyElement('0'));

    CustomerDataModeController.instance
      ..observeFailure()
      ..observeFailure()
      ..observeFailure()
      ..observeFailure();
    expect(
      CustomerDataModeController.instance.effectiveMode,
      CustomerDataMode.offline,
    );

    final offline = await client.get(uri);
    expect(offline.statusCode, 200);
    expect(offline.body, '{"version":2}');
    expect(offline.headers['x-foodex-cache'], 'offline');
    expect(networkCalls, 2);

    final snapshot = metrics.snapshot;
    expect(snapshot.networkRequestCount, 2);
    expect(snapshot.cacheHits, 1);
    expect(snapshot.downloadedBytes, greaterThan(0));

    client.close();
  });

  test('cached data is fallback only after a live network failure', () async {
    var networkCalls = 0;
    final client = CustomerLowDataHttpClient(
      MockClient((request) async {
        networkCalls += 1;
        if (networkCalls == 1) {
          return http.Response(
            '{"data":[{"id":1,"name":"Live"}]}',
            200,
            headers: const <String, String>{
              'content-type': 'application/json',
              'etag': '"products-v1"',
            },
          );
        }
        throw http.ClientException('network unavailable', request.url);
      }),
      dataMode: CustomerDataModeController.instance,
      cache: CustomerHttpResponseCache(maxEntries: 8),
    );

    final uri = Uri.parse('https://foodex.example/api/v1/products?store=7');
    final first = await client.get(uri);
    expect(first.headers['x-foodex-cache'], isNull);

    final fallback = await client.get(uri);
    expect(networkCalls, 2);
    expect(fallback.statusCode, 200);
    expect(fallback.headers['x-foodex-cache'], 'fallback');
    expect(fallback.body, first.body);
    expect(
      CustomerDataModeController.instance.effectiveMode,
      CustomerDataMode.lite,
    );

    client.close();
  });

  test('etag validation still contacts the server before using cache', () async {
    var networkCalls = 0;
    String? validator;
    final client = CustomerLowDataHttpClient(
      MockClient((request) async {
        networkCalls += 1;
        validator = _headerValue(request.headers, 'if-none-match');
        if (networkCalls == 2) {
          return http.Response('', 304);
        }
        return http.Response(
          '{"data":[{"id":1}]}',
          200,
          headers: const <String, String>{
            'content-type': 'application/json',
            'etag': '"products-v1"',
          },
        );
      }),
      dataMode: CustomerDataModeController.instance,
      cache: CustomerHttpResponseCache(maxEntries: 8),
    );

    final uri = Uri.parse('https://foodex.example/api/v1/products?store=7');
    final first = await client.get(uri);
    expect(first.statusCode, 200);

    final validated = await client.get(uri);
    expect(networkCalls, 2);
    expect(validator, '"products-v1"');
    expect(validated.statusCode, 200);
    expect(validated.headers['x-foodex-cache'], 'validated');
    expect(validated.body, first.body);

    client.close();
  });

  test('weak connectivity becomes lite before repeated failure becomes offline',
      () {
    final controller = CustomerDataModeController.instance;

    controller
      ..observeSuccess(const Duration(milliseconds: 3000))
      ..observeSuccess(const Duration(milliseconds: 3200));

    expect(controller.effectiveMode, CustomerDataMode.lite);
    expect(controller.orderPollingInterval(), const Duration(seconds: 90));

    controller.setBackgrounded(true);
    expect(controller.orderPollingInterval(), isNull);

    controller
      ..setBackgrounded(false)
      ..observeFailure()
      ..observeFailure()
      ..observeFailure();

    expect(controller.effectiveMode, CustomerDataMode.lite);
    expect(controller.networkOffline, isFalse);

    controller.observeFailure();
    expect(controller.effectiveMode, CustomerDataMode.offline);
    expect(controller.networkOffline, isTrue);
    expect(controller.allowsRefresh, isFalse);
  });

  test('offline cache miss fails without attempting the network', () async {
    var networkCalls = 0;
    final client = CustomerLowDataHttpClient(
      MockClient((request) async {
        networkCalls += 1;
        return http.Response('{}', 200);
      }),
      dataMode: CustomerDataModeController.instance,
      cache: CustomerHttpResponseCache(maxEntries: 8),
    );

    CustomerDataModeController.instance
      ..observeFailure()
      ..observeFailure()
      ..observeFailure()
      ..observeFailure();

    await expectLater(
      client.get(Uri.parse('https://foodex.example/api/v1/categories')),
      throwsA(isA<http.ClientException>()),
    );
    expect(networkCalls, 0);

    client.close();
  });

  test('identical concurrent GET cache misses share one network request',
      () async {
    var networkCalls = 0;
    final responseGate = Completer<http.Response>();
    final metrics = CustomerNetworkMetrics();
    final client = CustomerLowDataHttpClient(
      MockClient((request) {
        networkCalls += 1;
        return responseGate.future;
      }),
      dataMode: CustomerDataModeController.instance,
      metrics: metrics,
      cache: CustomerHttpResponseCache(maxEntries: 8),
    );

    final uri =
        Uri.parse('https://foodex.example/api/v1/b2b/products?store_id=4');
    final first = client.get(uri);
    final second = client.get(uri);

    await Future<void>.delayed(Duration.zero);
    expect(networkCalls, 1);

    responseGate.complete(
      http.Response(
        '{"data":[{"id":1}]}',
        200,
        headers: const {'content-type': 'application/json'},
      ),
    );

    final responses = await Future.wait([first, second]);
    expect(responses.every((response) => response.statusCode == 200), isTrue);
    expect(networkCalls, 1);
    expect(
      responses.where(
        (response) => response.headers['x-foodex-cache'] == 'coalesced',
      ),
      hasLength(1),
    );
    expect(metrics.snapshot.dedupedRequests, 1);

    client.close();
  });

  test('foreground cache-miss GET concurrency is capped at three', () async {
    var active = 0;
    var maxActive = 0;
    final gates = <Completer<http.Response>>[];
    final client = CustomerLowDataHttpClient(
      MockClient((request) {
        active += 1;
        if (active > maxActive) maxActive = active;
        final gate = Completer<http.Response>();
        gates.add(gate);
        return gate.future.whenComplete(() => active -= 1);
      }),
      dataMode: CustomerDataModeController.instance,
      cache: CustomerHttpResponseCache(maxEntries: 8),
    );

    final futures = List.generate(
      4,
      (index) => client.get(
        Uri.parse(
          'https://foodex.example/api/v1/b2b/products/${index + 1}?store_id=4',
        ),
      ),
    );

    for (var spin = 0; spin < 10 && gates.length < 3; spin++) {
      await Future<void>.delayed(Duration.zero);
    }
    expect(gates, hasLength(3));
    expect(maxActive, 3);

    gates.first.complete(
      http.Response(
        '{}',
        200,
        headers: const {'content-type': 'application/json'},
      ),
    );

    for (var spin = 0; spin < 10 && gates.length < 4; spin++) {
      await Future<void>.delayed(Duration.zero);
    }
    expect(gates, hasLength(4));
    expect(maxActive, 3);

    for (final gate in gates.skip(1)) {
      if (!gate.isCompleted) {
        gate.complete(
          http.Response(
            '{}',
            200,
            headers: const {'content-type': 'application/json'},
          ),
        );
      }
    }

    final responses = await Future.wait(futures);
    expect(responses, hasLength(4));
    expect(responses.every((response) => response.statusCode == 200), isTrue);

    client.close();
  });
}
