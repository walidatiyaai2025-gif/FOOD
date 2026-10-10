import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/network/customer_data_mode.dart';
import 'package:foodex_customer_app/core/network/customer_low_data_http_client.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues(<String, Object>{});
    CustomerDataModeController.instance.resetForTesting();
  });

  test('low-data client reuses cached JSON and serves it while offline',
      () async {
    var networkCalls = 0;
    final metrics = CustomerNetworkMetrics();
    final client = CustomerLowDataHttpClient(
      MockClient((request) async {
        networkCalls += 1;
        return http.Response(
          '{"data":[{"id":1,"name":"Cached"}]}',
          200,
          headers: <String, String>{
            'content-type': 'application/json',
            'etag': '"products-v1"',
          },
        );
      }),
      dataMode: CustomerDataModeController.instance,
      metrics: metrics,
      cache: CustomerHttpResponseCache(maxEntries: 8),
    );

    final uri = Uri.parse('https://foodex.example/api/v1/products?store=7');

    final first = await client.get(uri);
    expect(first.statusCode, 200);
    expect(networkCalls, 1);
    expect(first.headers['x-foodex-cache'], isNull);

    final second = await client.get(uri);
    expect(second.statusCode, 200);
    expect(second.headers['x-foodex-cache'], 'hit');
    expect(networkCalls, 1);

    CustomerDataModeController.instance
      ..observeFailure()
      ..observeFailure();
    expect(
      CustomerDataModeController.instance.effectiveMode,
      CustomerDataMode.offline,
    );

    final offline = await client.get(uri);
    expect(offline.statusCode, 200);
    expect(offline.headers['x-foodex-cache'], 'offline');
    expect(networkCalls, 1);

    final snapshot = metrics.snapshot;
    expect(snapshot.networkRequestCount, 1);
    expect(snapshot.cacheHits, 2);
    expect(snapshot.downloadedBytes, greaterThan(0));

    client.close();
  });

  test('weak connectivity degrades to lite and polling pauses in background',
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
      ..observeFailure();

    expect(controller.effectiveMode, CustomerDataMode.offline);
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
      ..observeFailure();

    await expectLater(
      client.get(Uri.parse('https://foodex.example/api/v1/categories')),
      throwsA(isA<http.ClientException>()),
    );
    expect(networkCalls, 0);

    client.close();
  });
}
