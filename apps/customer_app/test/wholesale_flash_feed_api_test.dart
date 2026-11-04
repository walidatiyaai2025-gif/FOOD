import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/storefront_api.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test(
      'guest wholesale Flash uses public B2B feed, never retail nor Van checkout',
      () async {
    final requests = <http.Request>[];
    final api = HttpStorefrontApi(
      baseUrl: 'https://api.example.test',
      client: MockClient((request) async {
        requests.add(request);
        return http.Response(
          jsonEncode({
            'fulfillment_channel': 'van',
            'data': [
              {
                'id': 7,
                'title_ar': 'عرض مكرونات',
                'products': [
                  {
                    'id': 91,
                    'product_id': 30,
                    'name': 'مكرونات',
                    'flash_price': 8,
                    'available_quantity': 5,
                  },
                ],
              },
            ],
          }),
          200,
        );
      }),
    );

    final payload = await api.wholesaleFlashOffers(4);
    expect(requests.single.url.path, '/api/v1/b2b/flash-offers/public');
    expect(requests.single.url.queryParameters['store_id'], '4');
    expect(requests.single.headers.containsKey('Authorization'), isFalse);
    expect(payload['fulfillment_channel'], 'van');
    expect((payload['data'] as List).first['products'][0]['name'], 'مكرونات');
  });

  test('signed-in wholesale Flash uses authenticated B2B feed', () async {
    final requests = <http.Request>[];
    final api = HttpStorefrontApi(
      baseUrl: 'https://api.example.test',
      token: 'customer-token',
      client: MockClient((request) async {
        requests.add(request);
        return http.Response(jsonEncode({'data': []}), 200);
      }),
    );

    await api.wholesaleFlashOffers(4, authenticated: true);
    expect(requests.single.url.path, '/api/v1/b2b/flash-offers');
    expect(requests.single.headers['Authorization'], 'Bearer customer-token');
    expect(requests.single.url.queryParameters['store_id'], '4');
  });
}
