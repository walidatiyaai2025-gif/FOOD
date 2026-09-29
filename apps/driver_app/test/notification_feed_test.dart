import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/features/notifications/notification_feed.dart';

void main() {
  test('driver notification decodes localized API content', () {
    final item = DriverNotification.fromJson({
      'id': 9,
      'title': 'New delivery',
      'body': 'A delivery was assigned to you',
      'read_at': '2026-09-25T10:00:00Z',
      'type': 'delivery.assigned',
      'data': {
        'assignment_id': 77,
        'order_id': 101,
        'access_revoked': false,
      },
    });

    expect(item.id, 9);
    expect(item.title, 'New delivery');
    expect(item.body, 'A delivery was assigned to you');
    expect(item.readAt, isNotNull);
    expect(item.type, 'delivery.assigned');
    expect(item.assignmentId, 77);
    expect(item.orderId, 101);
    expect(item.accessRevoked, isFalse);
  });
}
