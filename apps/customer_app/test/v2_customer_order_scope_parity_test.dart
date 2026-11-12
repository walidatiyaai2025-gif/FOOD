import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_order_models.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_orders_api.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  HttpCustomerOrdersApi returning(String body) => HttpCustomerOrdersApi(
        baseUrl: 'https://foodex.example',
        token: 'scoped-customer-token',
        client: MockClient((request) async => http.Response(
              body,
              200,
              headers: {'content-type': 'application/json'},
            )),
      );

  test('scoped orders reject foreign-store rows instead of displaying them',
      () async {
    final api = returning(
      '{"data":[{"id":92,"store_id":8,"channel":"b2c","status":"pending"}],'
      '"meta":{"current_page":1,"total":1}}',
    );

    await expectLater(
      api.orders(
        context: const CustomerOrderContext(storeId: 7, channel: 'b2c'),
      ),
      throwsA(isA<CustomerOrdersException>().having(
        (error) => error.code,
        'code',
        'forbidden',
      )),
    );
  });

  test('channel-filtered orders reject a mixed-channel response', () async {
    final api = returning(
      '{"data":[{"id":92,"store_id":7,"channel":"b2c","status":"pending"}],'
      '"meta":{"current_page":1,"total":1}}',
    );

    await expectLater(
      api.orders(channel: 'b2b'),
      throwsA(isA<CustomerOrdersException>().having(
        (error) => error.code,
        'code',
        'forbidden',
      )),
    );
  });

  test('scoped lists reject omitted channel instead of defaulting to retail',
      () async {
    final api = returning(
      '{"data":[{"id":92,"store_id":7,"status":"pending"}],'
      '"meta":{"current_page":1,"total":1}}',
    );

    await expectLater(
      api.orders(
        context: const CustomerOrderContext(storeId: 7, channel: 'b2c'),
      ),
      throwsA(isA<CustomerOrdersException>().having(
        (error) => error.code,
        'code',
        'invalid_response',
      )),
    );
  });

  test('status-filtered lists reject mismatched authoritative rows', () async {
    final api = returning(
      '{"data":[{"id":92,"store_id":7,"channel":"b2c","status":"pending"}],'
      '"meta":{"current_page":1,"total":1}}',
    );

    await expectLater(
      api.orders(channel: 'b2c', status: 'delivered'),
      throwsA(isA<CustomerOrdersException>().having(
        (error) => error.code,
        'code',
        'invalid_response',
      )),
    );
  });

  test('scoped order detail rejects an omitted channel', () async {
    final api = returning('{"id":91,"store_id":7,"status":"pending"}');

    await expectLater(
      api.order(
        orderId: 91,
        context: const CustomerOrderContext(storeId: 7, channel: 'b2c'),
      ),
      throwsA(isA<CustomerOrdersException>().having(
        (error) => error.code,
        'code',
        'invalid_response',
      )),
    );
  });

  test('order detail refuses another order ID in a successful response',
      () async {
    final api = returning(
      '{"id":92,"store_id":7,"channel":"b2c","status":"pending"}',
    );

    await expectLater(
      api.order(
        orderId: 91,
        context: const CustomerOrderContext(storeId: 7, channel: 'b2c'),
      ),
      throwsA(isA<CustomerOrdersException>().having(
        (error) => error.code,
        'code',
        'invalid_response',
      )),
    );
  });

  test('order detail rejects cross-store or cross-channel data', () async {
    for (final payload in [
      '{"id":91,"store_id":8,"channel":"b2c","status":"pending"}',
      '{"id":91,"store_id":7,"channel":"b2b","status":"pending"}',
    ]) {
      final api = returning(payload);

      await expectLater(
        api.order(
          orderId: 91,
          context: const CustomerOrderContext(storeId: 7, channel: 'b2c'),
        ),
        throwsA(isA<CustomerOrdersException>().having(
          (error) => error.code,
          'code',
          'forbidden',
        )),
      );
    }
  });

  test('scoped lists reject rows without a store identifier', () async {
    final api = returning(
      '{"data":[{"id":92,"channel":"b2c","status":"pending"}],'
      '"meta":{"current_page":1,"total":1}}',
    );

    await expectLater(
      api.orders(
        context: const CustomerOrderContext(storeId: 7, channel: 'b2c'),
      ),
      throwsA(isA<CustomerOrdersException>().having(
        (error) => error.code,
        'code',
        'forbidden',
      )),
    );
  });

  test('scoped detail rejects a missing store identifier', () async {
    final api = returning(
      '{"id":91,"channel":"b2c","status":"pending"}',
    );

    await expectLater(
      api.order(
        orderId: 91,
        context: const CustomerOrderContext(storeId: 7, channel: 'b2c'),
      ),
      throwsA(isA<CustomerOrdersException>().having(
        (error) => error.code,
        'code',
        'forbidden',
      )),
    );
  });

  test('matching order scope still returns the authoritative result', () async {
    final api = returning(
      '{"id":91,"store_id":7,"channel":"b2c","status":"delivered",'
      '"invoice":{"outstanding_amount":0},"grand_total":12.5}',
    );

    final result = await api.order(
      orderId: 91,
      context: const CustomerOrderContext(storeId: 7, channel: 'b2c'),
    );

    expect(result.summary.status, 'delivered');
    expect(result.summary.invoiceOutstandingAmount, 0);
  });
}
