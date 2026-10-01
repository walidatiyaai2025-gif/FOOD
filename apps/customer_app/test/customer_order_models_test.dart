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
      CustomerOrderNotificationIntent.fromData({'store_id': 7, 'channel': 'b2c'}),
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
    expect(details.history.single.fromStatus, 'pending');
    expect(details.history.single.toStatus, 'confirmed');
  });
}
