import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/features/retail/offers/customer_flash_offers.dart';

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
      });

      expect(offer.id, 44);
      expect(offer.flashPrice, 7);
      expect(offer.remainingCustomerLimit, 2);
      expect(
        offer.remainingAt(DateTime.parse('2026-10-06T08:00:30Z')),
        const Duration(minutes: 9, seconds: 30),
      );
    });

    test('never returns a negative remaining duration', () {
      final offer = CustomerFlashOffer.fromMap({
        'id': 1,
        'ends_at': '2026-10-06T08:00:00Z',
        'server_time': '2026-10-06T07:59:00Z',
      });

      expect(
        offer.remainingAt(DateTime.parse('2026-10-06T08:01:00Z')),
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
      expect(reservation.offerId, 44);
      expect(
        reservation.remainingAt(DateTime.parse('2026-10-06T08:02:00Z')),
        const Duration(minutes: 3),
      );
    });
  });
}
