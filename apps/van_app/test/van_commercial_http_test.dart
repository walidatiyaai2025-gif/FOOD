import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/core/api/http_van_api.dart';
import 'package:foodex_van_app/features/commercial/http_van_commercial_repository.dart';
import 'package:foodex_van_app/features/commercial/van_commercial_contract.dart';
import 'package:foodex_van_app/features/wallet/van_wallet_contract.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  const customer = VanCustomerScope(
    type: 'b2c',
    id: 44,
    name: 'Scoped Customer',
    storeId: 7,
  );

  test('loads Van Flash offers from canonical server feed', () async {
    late Uri requested;
    final client = MockClient((request) async {
      requested = request.url;
      return http.Response(
        jsonEncode({
          'server_time': '2026-10-06T08:30:00+03:00',
          'data': [
            {
              'id': 11,
              'store_id': 7,
              'title_ar': 'عرض سريع',
              'title_en': 'Flash Offer',
              'body_ar': null,
              'body_en': 'Server authoritative offer',
              'status': 'active',
              'starts_at': '2026-10-06T08:00:00+03:00',
              'ends_at': '2026-10-06T09:00:00+03:00',
              'priority': 90,
              'reservation_seconds': 300,
              'products': [
                {
                  'id': 51,
                  'product_id': 99,
                  'selling_unit_id': 5,
                  'selling_unit_code': 'CARTON',
                  'conversion_factor': 10,
                  'flash_price': 7,
                  'allocation_base': 1000,
                },
              ],
            },
          ],
        }),
        200,
        headers: {'content-type': 'application/json'},
      );
    });

    final repository = HttpVanCommercialRepository(
      VanApiClient('https://foodex.example/', 'token', client: client),
    );

    final feed = await repository.offersFor(customer);

    expect(requested.path, '/api/v1/flash-offers');
    expect(requested.queryParameters['store_id'], '7');
    expect(requested.queryParameters['channel'], 'van');
    expect(feed.serverTime, DateTime.parse('2026-10-06T05:30:00Z'));
    expect(feed.offers, hasLength(1));
    expect(feed.offers.single.products.single.sellingUnitCode, 'CARTON');
    expect(feed.offers.single.products.single.conversionFactor, 10);
    expect(feed.offers.single.products.single.flashPrice, 7);
  });

  test('reserves Flash against selected customer scope, never Van operator', () async {
    late http.Request captured;
    final repository = HttpVanCommercialRepository(
      VanApiClient(
        'https://foodex.example/',
        'token',
        client: MockClient((request) async {
          captured = request;
          return http.Response(jsonEncode({'id': 'reservation-1'}), 201);
        }),
      ),
    );

    await repository.reserveFlashForCustomer(
      customer: customer,
      offerProductId: 51,
      quantity: 2,
      idempotencyKey: 'idem-1',
    );

    expect(
      captured.url.path,
      '/api/v1/van/customers/b2c/44/flash-offers/products/51/reserve',
    );
    final body = jsonDecode(captured.body) as Map<String, dynamic>;
    expect(body['store_id'], 7);
    expect(body['quantity'], 2);
    expect(body['idempotency_key'], 'idem-1');
  });

  test('canonical commercial reason-code set stays aligned with #983', () {
    expect(vanCommercialReasonCodes, containsAll(const [
      'PRODUCT_CLOSED',
      'CUSTOMER_NOT_ELIGIBLE',
      'CHANNEL_NOT_ALLOWED',
      'ORDER_LIMIT_EXCEEDED',
      'DAILY_LIMIT_REACHED',
      'WEEKLY_LIMIT_REACHED',
      'MONTHLY_LIMIT_REACHED',
      'LIFETIME_LIMIT_REACHED',
      'UNIT_NOT_ALLOWED',
      'INSUFFICIENT_STOCK',
      'FLASH_NOT_ACTIVE',
      'FLASH_SOLD_OUT',
      'FLASH_CUSTOMER_LIMIT_REACHED',
      'FLASH_RESERVATION_EXPIRED',
      'ONLINE_VALIDATION_REQUIRED',
      'OVERRIDE_REQUIRED',
    ]));
  });
}
