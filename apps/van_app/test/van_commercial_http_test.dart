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
    late Uri flashRequested;
    final client = MockClient((request) async {
      if (request.url.path.endsWith('/offers')) {
        return http.Response(
          jsonEncode({
            'data': [
              {'id': 1, 'name': 'Normal offer', 'type': 'percentage', 'value': 10},
            ],
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }
      flashRequested = request.url;
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

    expect(flashRequested.path, '/api/v1/flash-offers');
    expect(flashRequested.queryParameters['store_id'], '7');
    expect(flashRequested.queryParameters['channel'], 'van');
    expect(feed.serverTime, DateTime.parse('2026-10-06T05:30:00Z'));
    expect(feed.offers, hasLength(1));
    expect(feed.offers.single.products.single.sellingUnitCode, 'CARTON');
    expect(feed.offers.single.products.single.conversionFactor, 10);
    expect(feed.offers.single.products.single.flashPrice, 7);
    expect(feed.normalOffers.single.name, 'Normal offer');
  });

  test('loads canonical selected-customer commercial quote', () async {
    late http.Request captured;
    final repository = HttpVanCommercialRepository(
      VanApiClient(
        'https://foodex.example/',
        'token',
        client: MockClient((request) async {
          captured = request;
          return http.Response(
            jsonEncode({
              'data': {
                'effective_allowed': false,
                'override_applied': false,
                'decision': {
                  'allowed': false,
                  'status': 'CLOSED',
                  'reason_codes': ['PRODUCT_CLOSED'],
                },
                'selling_unit': {
                  'code': 'CARTON',
                  'name': 'Carton',
                  'conversion_factor': 10,
                  'price': 7,
                  'sku': 'SKU-1',
                  'barcode': null,
                  'is_base': false,
                },
                'selling_units': [
                  {
                    'code': 'CARTON',
                    'name': 'Carton',
                    'conversion_factor': 10,
                    'price': 7,
                    'sku': 'SKU-1',
                    'barcode': null,
                    'is_base': false,
                  }
                ],
              }
            }),
            200,
          );
        }),
      ),
    );

    final quote = await repository.quoteForCustomer(
      customer: customer,
      productId: 99,
      sellingUnitCode: 'CARTON',
      quantity: 2,
    );

    expect(
      captured.url.path,
      '/api/v1/van/customers/b2c/44/commercial/quote',
    );
    expect(quote.allowed, isFalse);
    expect(quote.status, 'CLOSED');
    expect(quote.reasonCodes, ['PRODUCT_CLOSED']);
    expect(quote.sellingUnit.conversionFactor, 10);
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
      overrideReason: 'Supervisor approved',
    );

    expect(
      captured.url.path,
      '/api/v1/van/customers/b2c/44/flash-offers/products/51/reserve',
    );
    final body = jsonDecode(captured.body) as Map<String, dynamic>;
    expect(body['store_id'], 7);
    expect(body['quantity'], 2);
    expect(body['idempotency_key'], 'idem-1');
    expect(body['override_reason'], 'Supervisor approved');
  });

  test('canonical commercial reason-code set stays aligned with #984 runtime', () {
    expect(vanCommercialReasonCodes, containsAll(const [
      'PRODUCT_INACTIVE',
      'PRODUCT_CLOSED',
      'PRODUCT_RESTRICTED',
      'CHANNEL_BLOCKED',
      'OUTSIDE_AVAILABILITY',
      'MAX_PER_ORDER_EXCEEDED',
      'MAX_PER_DAY_EXCEEDED',
      'MAX_PER_WEEK_EXCEEDED',
      'MAX_PER_MONTH_EXCEEDED',
      'MAX_LIFETIME_EXCEEDED',
      'FLASH_NOT_ACTIVE',
      'FLASH_SOLD_OUT',
      'FLASH_CUSTOMER_LIMIT_REACHED',
      'FLASH_RESERVATION_EXPIRED',
      'ONLINE_VALIDATION_REQUIRED',
      'OVERRIDE_REQUIRED',
    ]));
  });
}
