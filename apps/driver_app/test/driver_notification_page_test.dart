import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/features/notifications/driver_notification_page.dart';
import 'package:foodex_driver_app/features/notifications/notification_feed.dart';

class _FakeNotifications implements DriverNotificationRepository {
  _FakeNotifications(this.items);

  final List<DriverNotification> items;
  bool failList = false;
  final List<int> marked = <int>[];
  int listCount = 0;

  @override
  Future<List<DriverNotification>> list({required String locale}) async {
    listCount += 1;
    if (failList) {
      throw const DriverNotificationException('offline');
    }
    return items;
  }

  @override
  Future<void> markRead(int notificationId) async {
    marked.add(notificationId);
  }
}

void main() {
  testWidgets('notifications refresh when Driver app resumes', (tester) async {
    final repository = _FakeNotifications(const [
      DriverNotification(
        id: 70,
        title: 'New assignment',
        body: 'Open assignment',
        readAt: null,
        data: {'assignment_id': 700},
      ),
    ]);

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: DriverNotificationPage(
          repository: repository,
          onOpenAssignment: (_) {},
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(repository.listCount, 1);

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();

    expect(repository.listCount, 2);
  });

  testWidgets(
      'notifications poll in foreground and preserve stale confirmed data on failure',
      (tester) async {
    final repository = _FakeNotifications(const [
      DriverNotification(
        id: 71,
        title: 'New assignment',
        body: 'Open assignment',
        readAt: null,
        data: {'assignment_id': 701},
      ),
    ]);

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: DriverNotificationPage(
          repository: repository,
          onOpenAssignment: (_) {},
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(repository.listCount, 1);
    expect(find.byKey(const Key('driver-notification-71')), findsOneWidget);
    expect(find.byKey(const Key('driver-notifications-stale')), findsNothing);

    repository.failList = true;
    await tester.pump(const Duration(seconds: 15));
    await tester.pumpAndSettle();

    expect(repository.listCount, 2);
    expect(find.byKey(const Key('driver-notification-71')), findsOneWidget);
    expect(find.byKey(const Key('driver-notifications-stale')), findsOneWidget);
    expect(find.textContaining('last confirmed data'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('active assignment notification marks read and opens assignment',
      (tester) async {
    final repository = _FakeNotifications(const [
      DriverNotification(
        id: 7,
        title: 'طلب جديد',
        body: 'تم إسناد طلب جديد إليك',
        readAt: null,
        data: {
          'assignment_id': 42,
          'order_id': 100,
          'access_revoked': false,
        },
      ),
    ]);
    int? opened;

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('ar'),
        home: DriverNotificationPage(
          repository: repository,
          onOpenAssignment: (value) => opened = value,
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-shell')), findsOneWidget);
    expect(find.byKey(const Key('driver-shell-navigation')), findsOneWidget);
    expect(find.byKey(const Key('driver-notifications-list')), findsOneWidget);
    await tester.tap(find.byKey(const Key('driver-notification-7')));
    await tester.pumpAndSettle();

    expect(repository.marked, [7]);
    expect(opened, 42);
  });

  testWidgets('already-open assignment notification refreshes instead of reopening',
      (tester) async {
    final repository = _FakeNotifications(const [
      DriverNotification(
        id: 9,
        title: 'Assignment updated',
        body: 'Refresh the active assignment',
        readAt: null,
        data: {
          'assignment_id': 55,
          'order_id': 155,
          'access_revoked': false,
        },
      ),
    ]);
    int? opened;

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('en'),
        home: DriverNotificationPage(
          repository: repository,
          currentAssignmentId: 55,
          onOpenAssignment: (value) => opened = value,
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(repository.listCount, 1);
    await tester.tap(find.byKey(const Key('driver-notification-9')));
    await tester.pumpAndSettle();

    expect(repository.marked, [9]);
    expect(opened, isNull);
    expect(repository.listCount, 2);
  });

  testWidgets('revoked assignment notification never opens order detail',
      (tester) async {
    final repository = _FakeNotifications(const [
      DriverNotification(
        id: 8,
        title: 'تم سحب الطلب',
        body: 'لم يعد الطلب معيناً لك',
        readAt: null,
        data: {
          'assignment_id': 43,
          'order_id': 101,
          'access_revoked': true,
        },
      ),
    ]);
    int? opened;

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('ar'),
        home: DriverNotificationPage(
          repository: repository,
          onOpenAssignment: (value) => opened = value,
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('driver-notification-8')));
    await tester.pumpAndSettle();

    expect(repository.marked, [8]);
    expect(opened, isNull);
    expect(find.byKey(const Key('driver-notifications-list')), findsOneWidget);
  });
}
