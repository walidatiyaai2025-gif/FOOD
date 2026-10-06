import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/features/retail/offers/customer_flash_offers.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  group('CustomerFlashOffer', () {
    test('parses canonical server fields and uses server time for countdown', () {
      final offer = CustomerFlashOffer.fromMap({
        'id': 44,
        'title': 'Flash',
        'body': 'Limited',
        'currency': 'KWD',
        'flash_price': 7,
        'normal_price': 10,
        'ends_at': '2026-10-06T08:10:00Z',
        'server_time': '2026-10-06T08:00:00Z',
        'remaining_allocation': 12,
        'remaining_customer_limit': 2,
        'eligible': true,
        'selling_units': [
          {
            'id': 3,
            'label': 'Carton',
            'conversion_factor': 10,
            'flash_price': 7,
          },
        ],
      });

      expect(offer.id, 44);
      expect(offer.flashPrice, 7);
      expect(offer.remainingCustomerLimit, 2);
      expect(offer.sellingUnits.single.id, 3);
      expect(offer.sellingUnits.single.conversionFactor, 10);
      expect(
        offer.remainingAfter(const Duration(seconds: 30)),
        const Duration(minutes: 9, seconds: 30),
      );
    });


    test('countdown is anchored to server sample, not device wall clock', () {
      final offer = CustomerFlashOffer.fromMap({
        'id': 2,
        'ends_at': '2026-10-06T08:10:00Z',
        'server_time': '2026-10-06T08:00:00Z',
      });

      expect(
        offer.remainingAfter(const Duration(seconds: 45)),
        const Duration(minutes: 9, seconds: 15),
      );
      expect(
        offer.remainingAfter(const Duration(seconds: -30)),
        const Duration(minutes: 10),
      );
    });

    test('never returns a negative remaining duration', () {
      final offer = CustomerFlashOffer.fromMap({
        'id': 1,
        'ends_at': '2026-10-06T08:00:00Z',
        'server_time': '2026-10-06T07:59:00Z',
      });

      expect(
        offer.remainingAfter(const Duration(minutes: 2)),
        Duration.zero,
      );
    });
  });

  group('CustomerFlashReservation', () {
    test('restores active reservation contract', () {
      final reservation = CustomerFlashReservation.fromMap({
        'id': 9,
        'offer_id': 44,
        'expires_at': '2026-10-06T08:05:00Z',
        'server_time': '2026-10-06T08:00:00Z',
        'status': 'ACTIVE',
      });

      expect(reservation.active, isTrue);
      expect(reservation.id, '9');
      expect(reservation.offerId, 44);
      expect(
        reservation.remainingAfter(const Duration(minutes: 2)),
        const Duration(minutes: 3),
      );
    });
  });
  test('customer analytics uses the canonical Flash event endpoint', () async {
    late http.Request captured;
    final api = HttpCustomerFlashOffersApi(
      token: 'token',
      baseUrl: 'https://foodex.example',
      client: MockClient((request) async {
        captured = request;
        return http.Response(jsonEncode({'accepted': true}), 202);
      }),
    );

    await api.trackEvent(
      storeId: 7,
      offerId: 44,
      event: 'buy_now_click',
    );

    expect(captured.url.path, '/api/v1/flash-offers/44/events');
    final body = jsonDecode(captured.body) as Map<String, dynamic>;
    expect(body['store_id'], 7);
    expect(body['event'], 'buy_now_click');
  });

}
