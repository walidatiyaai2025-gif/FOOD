import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/app.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/core/push/firebase_push_service.dart';
import 'package:foodex_driver_app/features/delivery/driver_assignment_contract.dart';

class _PushHarness implements DriverPushService {
  final _opens = StreamController<DriverPushOpen>.broadcast();
  final _alerts = StreamController<DriverPushAlert>.broadcast();

  @override
  Stream<DriverPushOpen> get opens => _opens.stream;

  @override
  Stream<DriverPushAlert> get alerts => _alerts.stream;

  void emitAlert(DriverPushAlert alert) => _alerts.add(alert);

  @override
  DriverPushOpen? takePendingOpen() => null;

  @override
  Future<void> bindSession(String accessToken) async {}

  @override
  Future<void> revokeSession() async {}

  Future<void> dispose() async {
    await _opens.close();
    await _alerts.close();
  }
}

class _Assignments implements DriverAssignmentRepository {
  const _Assignments();

  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async => const [
        DriverAssignment(
          id: 42,
          orderId: 99,
          channel: DriverChannel.b2c,
          reference: 'ORDER-99',
          status: 'assigned',
        ),
        DriverAssignment(
          id: 43,
          orderId: 100,
          channel: DriverChannel.b2c,
          reference: 'ORDER-100',
          status: 'assigned',
        ),
      ];

  @override
  Future<void> transition(
    int id,
    DriverChannel channel,
    String status, {
    String? note,
    String? failureReason,
  }) async {}
}

void main() {
  testWidgets(
      'foreground order alert shows authoritative identity and opens exact assignment',
      (tester) async {
    final push = _PushHarness();
    addTearDown(push.dispose);

    await tester.pumpWidget(
      FoodexDriverApp(
        locale: const Locale('en'),
        initialSession: const DriverSession(
          token: 'driver-token',
          name: 'Driver One',
          email: 'driver@example.test',
          locale: 'en',
          channel: DriverChannel.b2c,
        ),
        assignmentRepositoryFactory: (_) => const _Assignments(),
        pushService: push,
        showPersistentFooter: false,
      ),
    );
    await tester.pumpAndSettle();

    push.emitAlert(
      const DriverPushAlert(
        title: 'New order',
        body: 'Ready for delivery',
        open: DriverPushOpen(
          assignmentId: 42,
          orderId: 99,
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.textContaining('Order #99'), findsOneWidget);
    expect(find.text('View order'), findsOneWidget);

    await tester.tap(find.text('View order'));
    await tester.pumpAndSettle();

    expect(
      find.byKey(const Key('driver-active-detail-42')),
      findsOneWidget,
    );
    expect(
      find.byKey(const Key('driver-active-detail-43')),
      findsNothing,
    );
    expect(find.text('ORDER-99'), findsWidgets);
    expect(find.text('ORDER-100'), findsNothing);
    expect(tester.takeException(), isNull);
  });
}
