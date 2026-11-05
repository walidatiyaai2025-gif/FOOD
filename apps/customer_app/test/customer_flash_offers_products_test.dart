import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/features/retail/offers/customer_flash_offers.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  final offer = <String, dynamic>{
    'id': 31,
    'title': 'عرض سريع',
    'body': 'خصم لفترة محدودة',
    'currency': 'EGP',
    'flash_price': 8,
    'normal_price': 10,
    'ends_at': '2099-05-01T10:00:00Z',
    'server_time': '2099-05-01T09:00:00Z',
    'remaining_allocation': 20,
    'remaining_customer_limit': 10,
    'eligible': true,
    'selling_units': [],
    'products': [
      {
        'id': 57,
        'product_id': 701,
        'name': 'منتج العرض',
        'sku': 'FDX-701',
        'selling_unit_name': 'كرتونة',
        'flash_price': 8,
        'normal_price': 11,
        'available_quantity': 5,
      },
      {
        'id': 58,
        'product_id': 702,
        'name': 'منتج آخر',
        'sku': 'FDX-702',
        'selling_unit_name': 'قطعة',
        'flash_price': 4,
        'normal_price': 5,
        'available_quantity': 2,
      },
      {
        'id': 59,
        'product_id': 703,
        'name': 'غير متاح',
        'available_quantity': 0,
      },
    ],
  };

  test('guest reads only the public flash feed and maps available products',
      () async {
    final requests = <http.Request>[];
    final api = HttpCustomerFlashOffersApi(
      token: null,
      baseUrl: 'https://api.example.test',
      client: MockClient((request) async {
        requests.add(request);
        return http.Response(
            jsonEncode({
              'data': [offer]
            }),
            200,
            headers: {'content-type': 'application/json; charset=utf-8'});
      }),
    );
    final offers = await api.activeOffers(storeId: 4);
    expect(requests.single.url.path, '/api/v1/flash-offers/public');
    expect(requests.single.url.queryParameters['store_id'], '4');
    expect(requests.single.headers.containsKey('Authorization'), isFalse);
    expect(offers.single.products.length, 2);
    expect(offers.single.products.first.name, 'منتج العرض');
    expect(offers.single.products.first.sellingUnit, 'كرتونة');
    expect(offers.single.products.first.normalPrice, 11);
  });

  test('authenticated feed and reserve use the canonical offer-product route',
      () async {
    final urls = <String>[];
    final api = HttpCustomerFlashOffersApi(
      token: 'test-token',
      baseUrl: 'https://api.example.test',
      client: MockClient((request) async {
        urls.add(request.url.path);
        expect(request.headers['Authorization'], 'Bearer test-token');
        if (request.method == 'GET') {
          expect(request.url.queryParameters['channel'], 'customer');
          return http.Response(
              jsonEncode({
                'data': [offer]
              }),
              200);
        }
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        expect(body['channel'], 'customer');
        expect(body['quantity'], 1);
        expect(body['idempotency_key'], startsWith('flash-reserve-57-'));
        return http.Response(
            jsonEncode({
              'id': '00000000-0000-4000-8000-000000000057',
              'offer_id': 31,
              'server_time': '2099-05-01T09:00:00Z',
              'expires_at': '2099-05-01T09:05:00Z',
              'status': 'active',
            }),
            201);
      }),
    );
    expect((await api.activeOffers(storeId: 4)).single.id, 31);
    final reserved = await api.reserve(
      storeId: 4,
      offerProductId: 57,
      quantity: 1,
    );
    expect(reserved.id, '00000000-0000-4000-8000-000000000057');
    expect(urls, [
      '/api/v1/flash-offers',
      '/api/v1/flash-offers/products/57/reserve',
    ]);
  });

  testWidgets(
      'Offers page shows each eligible flash product and guest login action',
      (tester) async {
    tester.view.physicalSize = const Size(390, 950);
    tester.view.devicePixelRatio = 1;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    var loginCount = 0;
    await tester.pumpWidget(MaterialApp(
      locale: const Locale('ar'),
      home: CustomerFlashOffersScreen(
        storeId: 4,
        api: _ProductFlashApi(CustomerFlashOffer.fromMap(offer)),
        isAuthenticated: false,
        onAuthenticationRequired: () => loginCount++,
        onOpenNormalOffers: () {},
      ),
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));
    expect(find.byKey(const ValueKey('flash-product-card-57')), findsOneWidget);
    expect(find.byKey(const ValueKey('flash-product-card-58')), findsOneWidget);
    expect(find.byKey(const ValueKey('flash-product-card-59')), findsNothing);
    expect(find.text('منتج العرض'), findsOneWidget);
    expect(find.text('FDX-701'), findsOneWidget);

    final buy = find.byKey(const ValueKey('flash-buy-now-57'));
    await tester.ensureVisible(buy);
    await tester.tap(buy);
    await tester.pump();
    expect(loginCount, 1);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox.shrink());
  });
}

class _ProductFlashApi implements CustomerFlashOffersApi {
  const _ProductFlashApi(this.offer);

  final CustomerFlashOffer offer;

  @override
  Future<List<CustomerFlashOffer>> activeOffers({required int storeId}) async =>
      [offer];

  @override
  Future<CustomerFlashReservation?> activeReservation(
          {required int storeId}) async =>
      null;

  @override
  dynamic noSuchMethod(Invocation invocation) =>
      throw UnimplementedError(invocation.memberName.toString());
}
