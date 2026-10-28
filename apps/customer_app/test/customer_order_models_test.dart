import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/features/customer_orders/customer_order_models.dart';

void main() {
  test('only truly terminal order states stop polling', () {
    expect(CustomerOrderRefreshPolicy.shouldPoll('pending'), isTrue);
    expect(CustomerOrderRefreshPolicy.shouldPoll('out_for_delivery'), isTrue);
    expect(CustomerOrderRefreshPolicy.shouldPoll('failed'), isTrue);
    expect(CustomerOrderRefreshPolicy.shouldPoll('delivered'), isFalse);
    expect(CustomerOrderRefreshPolicy.shouldPoll('cancelled'), isFalse);
  });

  test('notification intent preserves exact order commerce context', () {
    final intent = CustomerOrderNotificationIntent.fromData({
      'order_id': 91,
      'store_id': 7,
      'channel': 'B2C',
      'status': 'delivered',
    });

    expect(intent?.orderId, 91);
    expect(intent?.context?.storeId, 7);
    expect(intent?.context?.normalizedChannel, 'b2c');
  });

  test('notification intent never invents missing store context', () {
    final intent = CustomerOrderNotificationIntent.fromData({
      'order_id': 91,
      'status': 'delivered',
    });

    expect(intent?.orderId, 91);
    expect(intent?.context, isNull);
    expect(
      CustomerOrderNotificationIntent.fromData(
          {'store_id': 7, 'channel': 'b2c'}),
      isNull,
    );
  });

  test('order detail keeps immutable delivery snapshot and status history', () {
    final details = CustomerOrderDetails.fromJson({
      'id': 91,
      'order_number': 'FO-91',
      'store_id': 7,
      'store': {'id': 7, 'name': 'Retail A'},
      'channel': 'b2c',
      'status': 'confirmed',
      'currency': 'KWD',
      'grand_total': 12.5,
      'delivery_address': {'area': 'Bayan', 'street': '1'},
      'driver': {'id': 12, 'name': 'Nasser', 'phone': '+96550000012'},
      'status_history': [
        {
          'id': 1,
          'from_status': 'pending',
          'to_status': 'confirmed',
          'created_at': '2026-10-01T10:10:00Z',
        }
      ],
      'items': const [],
    });

    expect(details.deliveryAddress?['area'], 'Bayan');
    expect(details.driver?.id, 12);
    expect(details.driver?.name, 'Nasser');
    expect(details.driver?.phone, '+96550000012');
    expect(details.history.single.fromStatus, 'pending');
    expect(details.history.single.toStatus, 'confirmed');
  });
  test('repeated tracking milestones collapse and authoritative split payment is retained', () {
    final details = CustomerOrderDetails.fromJson({
      'id': 118,
      'order_number': 'FO-118',
      'store_id': 3,
      'channel': 'b2c',
      'status': 'out_for_delivery',
      'currency': 'KWD',
      'grand_total': 20.000,
      'settlement': {
        'paid_amount': 7.500,
        'remaining_amount': 12.500,
        'payment_state': 'partially_settled',
      },
      'status_history': [
        {'id': 1, 'to_status': 'pending'},
        {'id': 2, 'to_status': 'out_for_delivery'},
        {'id': 3, 'to_status': 'OUT_FOR_DELIVERY'},
        {'id': 4, 'to_status': 'delivered'},
      ],
    });
    expect(details.uniqueHistory.map((row) => row.toStatus.toLowerCase()).toList(),
        ['pending', 'out_for_delivery', 'delivered']);
    expect(details.summary.paidAmount, 7.5);
    expect(details.summary.invoiceOutstandingAmount, 12.5);
    expect(details.summary.paymentState, 'partially_settled');
    expect(details.summary.fullySettled, isFalse);
  });

  test('fully paid and missing settlement remain distinct', () {
    final paid = CustomerOrderSummary.fromJson({
      'id': 2, 'store_id': 3, 'currency': 'KWD', 'grand_total': 5,
      'settlement': {
        'paid_amount': 5,
        'remaining_amount': 0,
        'payment_state': 'fully_settled'
      }
    });
    final missing = CustomerOrderSummary.fromJson({
      'id': 3, 'store_id': 3, 'currency': 'KWD', 'grand_total': 5
    });
    expect(paid.fullySettled, true);
    expect(paid.paymentState, 'fully_settled');
    expect(missing.paymentState, isNull);
    expect(missing.paidAmount, isNull);
  });

}
