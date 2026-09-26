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
}
