import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/b2b_api.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('B2B GET retries one transient client failure and preserves retail context',
      () async {
    var attempts = 0;
    final api = HttpB2bApi(
      baseUrl: 'https://foodex.example',
      token: 'opaque-token',
      retailStoreContextId: 17,
      maxGetAttempts: 2,
      client: MockClient((request) async {
        attempts++;
        expect(request.headers['X-FOODEX-Retail-Store-ID'], '17');
        expect(request.headers['X-FOODEX-Customer-Domain'], 'b2b');
        if (attempts == 1) {
          throw http.ClientException('temporary disconnect', request.url);
        }
        return http.Response('{"data":[]}', 200);
      }),
    );

    final result = await api.get('/api/v1/b2b/account-statement');

    expect(attempts, 2);
    expect(result, isA<Map>());
  });

  test('B2B GET stops after bounded attempts on network failure', () async {
    var attempts = 0;
    final api = HttpB2bApi(
      baseUrl: 'https://foodex.example',
      token: 'opaque-token',
      maxGetAttempts: 2,
      client: MockClient((request) async {
        attempts++;
        throw http.ClientException('offline', request.url);
      }),
    );

    await expectLater(
      api.get('/api/v1/b2b/account-statement'),
      throwsA(
        isA<B2bApiException>().having(
          (error) => error.code,
          'code',
          'network_unavailable',
        ),
      ),
    );
    expect(attempts, 2);
  });

  test('B2B GET timeout is bounded and reported distinctly', () async {
    final api = HttpB2bApi(
      baseUrl: 'https://foodex.example',
      token: 'opaque-token',
      requestTimeout: const Duration(milliseconds: 5),
      maxGetAttempts: 1,
      client: MockClient((_) async {
        await Future<void>.delayed(const Duration(milliseconds: 50));
        return http.Response('{}', 200);
      }),
    );

    await expectLater(
      api.get('/api/v1/b2b/account-statement'),
      throwsA(
        isA<B2bApiException>().having(
          (error) => error.code,
          'code',
          'timeout',
        ),
      ),
    );
  });
}
