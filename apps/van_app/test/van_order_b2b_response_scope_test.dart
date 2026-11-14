import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/core/api/http_van_api.dart';
import 'package:foodex_van_app/core/auth/van_session.dart';
import 'package:foodex_van_app/features/orders/http_van_order_repository.dart';
import 'package:foodex_van_app/features/wallet/van_wallet_contract.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

const _customer = VanCustomerScope(
  type: 'b2b',
  id: 42,
  name: 'Wholesale customer',
  storeId: 7,
);

Map<String, Object?> _order({
  int id = 501,
  String type = 'b2b',
  int customerId = 42,
  int storeId = 7,
}) =>
    {
      'id': id,
      'display_order_number': 'FDX-B2B-501',
      'customer_type': type,
      'customer_id': customerId,
      'store_id': storeId,
      'status': 'pending',
      'currency': 'EGP',
      'grand_total': 125.5,
    };

Map<String, Object?> _page(List<Map<String, Object?>> records) => {
      'data': records,
      'meta': {'current_page': 1, 'per_page': 50, 'total': records.length},
    };

HttpVanOrderRepository _repository(
  Future<http.Response> Function(http.Request) handler,
) =>
    HttpVanOrderRepository(
      VanApiClient(
        'https://foodex.example/',
        'van-token',
        client: MockClient(handler),
      ),
    );

void main() {
  test('legitimate B2B order list, create and detail remain available',
      () async {
    final requests = <String>[];
    final repository = _repository((request) async {
      requests.add(request.url.path);
      if (request.url.path.endsWith('/orders/501')) {
        return http.Response(
          jsonEncode({
            'data': {
              ..._order(),
              'items': <Object>[],
              'payments': <Object>[],
              'collections': <Object>[],
              'timeline': <Object>[],
            },
          }),
          200,
        );
      }
      if (request.method == 'POST') {
        expect(request.headers['Idempotency-Key'], 'van-create-b2b-501');
        return http.Response(jsonEncode({'data': _order()}), 200);
      }
      return http.Response(jsonEncode(_page([_order()])), 200);
    });

    final page = await repository.orders(customer: _customer);
    final created = await repository.createOrder(
      customer: _customer,
      lines: const [],
      paymentMethod: 'cash_on_delivery',
      idempotencyKey: 'van-create-b2b-501',
    );
    final detail = await repository.order(501);

    expect(page.items.single.customerType, 'b2b');
    expect(page.items.single.storeId, _customer.storeId);
    expect(created.customerId, _customer.id);
    expect(detail.summary.id, 501);
    expect(requests, [
      '/api/v1/van/orders',
      '/api/v1/van/customers/b2b/42/orders',
      '/api/v1/van/orders/501',
    ]);
  });

  test('mixed B2B and Retail history is rejected as one response', () async {
    final repository = _repository((request) async {
      return http.Response(
        jsonEncode(_page([_order(), _order(id: 502, type: 'b2c')])),
        200,
      );
    });

    await expectLater(
      repository.orders(),
      throwsA(isA<VanApiException>()),
    );
  });

  test('filtered B2B history rejects a foreign customer or store', () async {
    for (final foreign in [
      _order(customerId: 43),
      _order(storeId: 8),
    ]) {
      final repository = _repository((request) async {
        return http.Response(jsonEncode(_page([_order(), foreign])), 200);
      });
      await expectLater(
        repository.orders(customer: _customer),
        throwsA(isA<VanApiException>()),
      );
    }
  });

  test('create rejects forged B2B response identity without success', () async {
    for (final forged in [
      _order(type: 'b2c'),
      _order(customerId: 99),
      _order(storeId: 19),
    ]) {
      final repository = _repository((request) async {
        return http.Response(jsonEncode({'data': forged}), 200);
      });
      await expectLater(
        repository.createOrder(
          customer: _customer,
          lines: const [],
          paymentMethod: 'cash_on_delivery',
          idempotencyKey: 'van-create-guard-501',
        ),
        throwsA(isA<VanApiException>()),
      );
    }
  });

  test('Van order detail response cannot swap the requested order', () async {
    final repository = _repository((request) async {
      return http.Response(
        jsonEncode({'data': _order(id: 502)}),
        200,
      );
    });

    await expectLater(
      repository.order(501),
      throwsA(isA<VanApiException>()),
    );
  });

  test('execution reads and transitions reject another B2B order ID', () async {
    var sent = 0;
    final repository = _repository((request) async {
      sent += 1;
      return http.Response(
        jsonEncode({
          'data': {
            'order_id': 502,
            'order_status': 'pending',
            'status': 'pending',
            'allowed_actions': <Object>[],
          },
        }),
        200,
      );
    });

    await expectLater(
      repository.execution(501),
      throwsA(isA<VanApiException>()),
    );
    await expectLater(
      repository.transitionOrder(
        orderId: 501,
        status: 'in_progress',
        idempotencyKey: 'van-order-identity-501',
      ),
      throwsA(isA<VanApiException>()),
    );
    await expectLater(
      repository.retryOrder(
        orderId: 501,
        idempotencyKey: 'van-order-retry-identity-501',
      ),
      throwsA(isA<VanApiException>()),
    );
    expect(sent, 3);
  });

  test('Van order detail never accepts a Retail customer type', () async {
    final repository = _repository((request) async {
      return http.Response(
        jsonEncode({'data': _order(type: 'b2c')}),
        200,
      );
    });

    await expectLater(
      repository.order(501),
      throwsA(isA<VanApiException>()),
    );
  });

  test('Retail customer cannot reach Van catalog options or quote API', () async {
    var sent = 0;
    final repository = _repository((request) async {
      sent += 1;
      return http.Response('{}', 200);
    });
    const retail = VanCustomerScope(
      type: 'b2c',
      id: 55,
      name: 'Retail customer',
      storeId: 9,
    );

    await expectLater(
      repository.catalog(retail),
      throwsA(isA<VanAccessDeniedException>()),
    );
    await expectLater(
      repository.options(retail),
      throwsA(isA<VanAccessDeniedException>()),
    );
    await expectLater(
      repository.quote(
        customer: retail,
        lines: const [],
        paymentMethod: 'cash_on_delivery',
      ),
      throwsA(isA<VanAccessDeniedException>()),
    );
    expect(sent, 0);
  });

  test('valid Wholesale catalog options and quote remain accessible', () async {
    final requests = <String>[];
    final repository = _repository((request) async {
      requests.add(request.url.path);
      if (request.url.path.endsWith('/catalog')) {
        return http.Response(
          jsonEncode({
            'currency': 'EGP',
            'data': <Object>[],
            'meta': {'page': 1, 'per_page': 50, 'has_more': false},
          }),
          200,
        );
      }
      if (request.url.path.endsWith('/order-options')) {
        return http.Response(
          jsonEncode({
            'data': {
              'addresses': <Object>[],
              'warehouses': <Object>[],
              'payment_methods': ['cash_on_delivery'],
              'default_payment_method': 'cash_on_delivery',
            },
          }),
          200,
        );
      }
      return http.Response(
        jsonEncode({
          'data': {
            'currency': 'EGP',
            'subtotal': 0,
            'discount_total': 0,
            'delivery_total': 0,
            'tax_total': 0,
            'grand_total': 0,
            'has_unavailable_items': false,
          },
        }),
        200,
      );
    });

    final catalog = await repository.catalog(_customer);
    final options = await repository.options(_customer);
    final quote = await repository.quote(
      customer: _customer,
      lines: const [],
      paymentMethod: 'cash_on_delivery',
    );

    expect(catalog.products, isEmpty);
    expect(options.paymentMethods, contains('cash_on_delivery'));
    expect(quote.grandTotal, 0);
    expect(requests, [
      '/api/v1/van/customers/b2b/42/catalog',
      '/api/v1/van/customers/b2b/42/order-options',
      '/api/v1/van/customers/b2b/42/order-quote',
    ]);
  });

  test('Retail customer request never reaches a Van order endpoint', () async {
    var sent = 0;
    final repository = _repository((request) async {
      sent += 1;
      return http.Response(jsonEncode({'data': _order()}), 200);
    });
    const retail = VanCustomerScope(
      type: 'b2c',
      id: 55,
      name: 'Retail customer',
      storeId: 9,
    );

    await expectLater(
      repository.orders(customer: retail),
      throwsA(isA<VanAccessDeniedException>()),
    );
    await expectLater(
      repository.createOrder(
        customer: retail,
        lines: const [],
        paymentMethod: 'cash_on_delivery',
        idempotencyKey: 'van-retail-blocked',
      ),
      throwsA(isA<VanAccessDeniedException>()),
    );
    expect(sent, 0);
  });
}
