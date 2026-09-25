import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/features/notifications/notification_feed.dart';

void main() {
  test('customer notification decodes localized API content', () {
    final item = AppNotification.fromJson({
      'id': 7,
      'title': 'تم قبول الطلب',
      'body': 'جاري التجهيز',
      'read_at': null,
    });

    expect(item.id, 7);
    expect(item.title, 'تم قبول الطلب');
    expect(item.body, 'جاري التجهيز');
    expect(item.readAt, isNull);
  });
}
