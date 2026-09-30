import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/b2c_account_api.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('B2C account API maps network failures to an explicit offline state', () async {
    final api = HttpB2cAccountApi(
      baseUrl: 'https://foodex.example',
      guestSession: CustomerGuestSession(),
      client: MockClient((request) async {
        throw http.ClientException('offline', request.url);
      }),
    );

    await expectLater(
      api.cart(storeId: 7),
      throwsA(
        isA<B2cAccountException>().having(
          (error) => error.code,
          'code',
          'network_unavailable',
        ),
      ),
    );
  });

  test('B2C account API maps HTTP 401 to session expiration', () async {
    final api = HttpB2cAccountApi(
      baseUrl: 'https://foodex.example',
      token: 'expired-token',
      guestSession: CustomerGuestSession(),
      client: MockClient((request) async => http.Response('{}', 401)),
    );

    await expectLater(
      api.profile(),
      throwsA(
        isA<B2cAccountException>().having(
          (error) => error.code,
          'code',
          'session_expired',
        ),
      ),
    );
  });

  test('B2C account API posts explicit set-default address action', () async {
    http.Request? captured;
    final api = HttpB2cAccountApi(
      baseUrl: 'https://foodex.example',
      token: 'customer-token',
      guestSession: CustomerGuestSession(),
      client: MockClient((request) async {
        captured = request;
        return http.Response(
          '{"id":8,"is_default":true}',
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    final result = await api.setDefaultAddress(8);

    expect(captured?.method, 'POST');
    expect(captured?.url.path, '/api/v1/profile/addresses/8/default');
    expect(captured?.headers['Authorization'], 'Bearer customer-token');
    expect(result, isA<Map<String, dynamic>>());
  });
}
