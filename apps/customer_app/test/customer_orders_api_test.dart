import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_order_models.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_orders_api.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('orders request preserves exact store and channel context', () async {
    http.Request? captured;
    final api = HttpCustomerOrdersApi(
      baseUrl: 'https://foodex.example',
      token: 'platform-token',
      client: MockClient((request) async {
        captured = request;
        return http.Response(
          '{"data":[{"id":91,"order_number":"FO-91","store_id":7,'
          '"store":{"id":7,"name":"Retail A","logo_url":null},'
          '"channel":"b2c","status":"confirmed","currency":"KWD",'
          '"grand_total":12.5,"created_at":"2026-10-01T10:00:00Z"}],'
          '"meta":{"current_page":1,"per_page":20,"total":1,'
          '"scope":"platform_customer"}}',
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    final page = await api.orders(
      context: const CustomerOrderContext(storeId: 7, channel: 'b2c'),
    );

    expect(captured?.url.path, '/api/v1/orders');
    expect(captured?.url.queryParameters['store_id'], '7');
    expect(captured?.url.queryParameters['channel'], 'b2c');
    expect(captured?.headers['X-FOODEX-Store-ID'], '7');
    expect(captured?.headers['X-FOODEX-Customer-Domain'], 'b2c');
    expect(captured?.headers['Authorization'], 'Bearer platform-token');
    expect(page.scope, 'platform_customer');
    expect(page.orders.single.storeId, 7);
    expect(page.orders.single.channel, 'b2c');
  });

  test('orders can filter by channel without inheriting an active store', () async {
    http.Request? captured;
    final api = HttpCustomerOrdersApi(
      baseUrl: 'https://foodex.example',
      token: 'platform-token',
      client: MockClient((request) async {
        captured = request;
        return http.Response(
          '{"data":[],"meta":{"current_page":1,"per_page":20,"total":0,"scope":"platform_customer"}}',
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    await api.orders(channel: 'b2b');

    expect(captured?.url.queryParameters['channel'], 'b2b');
    expect(captured?.url.queryParameters.containsKey('store_id'), isFalse);
    expect(captured?.headers.containsKey('X-FOODEX-Store-ID'), isFalse);
    expect(captured?.headers.containsKey('X-FOODEX-Customer-Domain'), isFalse);
  });

  test('orders preserve authoritative status filter and count metadata', () async {
    http.Request? captured;
    final api = HttpCustomerOrdersApi(
      baseUrl: 'https://foodex.example',
      token: 'platform-token',
      client: MockClient((request) async {
        captured = request;
        return http.Response(
          '{"data":[],"meta":{"current_page":1,"per_page":20,"total":1,'
          '"all_total":3,"scope":"b2b","status_codes":["pending","delivered"],'
          '"status_counts":{"pending":2,"delivered":1}}}',
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    final page = await api.orders(
      channel: 'b2b',
      status: 'delivered',
    );

    expect(captured?.url.queryParameters['channel'], 'b2b');
    expect(captured?.url.queryParameters['status'], 'delivered');
    expect(page.allTotal, 3);
    expect(page.statusCodes, <String>['pending', 'delivered']);
    expect(page.statusCounts['pending'], 2);
    expect(page.statusCounts['delivered'], 1);
  });

  test('orders reject an explicit channel that conflicts with store context',
      () async {
    final api = HttpCustomerOrdersApi(
      baseUrl: 'https://foodex.example',
      token: 'platform-token',
      client: MockClient((request) async => http.Response('{}', 200)),
    );

    await expectLater(
      api.orders(
        channel: 'b2b',
        context: const CustomerOrderContext(storeId: 7, channel: 'b2c'),
      ),
      throwsA(
        isA<CustomerOrdersException>().having(
          (error) => error.code,
          'code',
          'invalid_order_context',
        ),
      ),
    );
  });

  test('order detail decodes authoritative timeline payment and address', () async {
    http.Request? captured;
    final api = HttpCustomerOrdersApi(
      baseUrl: 'https://foodex.example',
      token: 'platform-token',
      client: MockClient((request) async {
        captured = request;
        return http.Response(
          '{"id":91,"order_number":"FO-91","store_id":7,'
          '"store":{"id":7,"name":"Retail A","logo_url":null},'
          '"channel":"b2c","status":"out_for_delivery","currency":"KWD",'
          '"subtotal":10,"discount_total":0,"delivery_total":2.5,'
          '"grand_total":12.5,"payment_method":"cash",'
          '"delivery_address":{"area":"Bayan","street":"1"},'
          '"items":[{"id":1,"product_id":3,"sku":"SKU-3","name":"Item",'
          '"quantity":2,"unit_price":5,"line_total":10}],'
          '"status_history":[{"id":1,"from_status":"ready",'
          '"to_status":"out_for_delivery","note":null,'
          '"created_at":"2026-10-01T10:10:00Z"}],'
          '"payment":{"id":4,"provider":"cash","status":"pending",'
          '"amount":12.5,"currency":"KWD"},'
          '"created_at":"2026-10-01T10:00:00Z"}',
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    final order = await api.order(
      orderId: 91,
      context: const CustomerOrderContext(storeId: 7, channel: 'b2c'),
    );

    expect(captured?.url.path, '/api/v1/orders/91');
    expect(captured?.url.queryParameters, {
      'store_id': '7',
      'channel': 'b2c',
    });
    expect(order.summary.status, 'out_for_delivery');
    expect(order.history.single.toStatus, 'out_for_delivery');
    expect(order.deliveryAddress?['area'], 'Bayan');
    expect(order.payment?.provider, 'cash');
    expect(order.items.single.name, 'Item');
  });

  test('orders API maps authentication and network failures explicitly', () async {
    final unauthorized = HttpCustomerOrdersApi(
      baseUrl: 'https://foodex.example',
      token: 'expired',
      client: MockClient((request) async => http.Response('{}', 401)),
    );

    await expectLater(
      unauthorized.orders(),
      throwsA(
        isA<CustomerOrdersException>().having(
          (error) => error.code,
          'code',
          'session_expired',
        ),
      ),
    );

    final offline = HttpCustomerOrdersApi(
      baseUrl: 'https://foodex.example',
      token: 'valid',
      client: MockClient((request) async {
        throw http.ClientException('offline', request.url);
      }),
    );

    await expectLater(
      offline.order(orderId: 91),
      throwsA(
        isA<CustomerOrdersException>().having(
          (error) => error.code,
          'code',
          'network_unavailable',
        ),
      ),
    );
  });
}
