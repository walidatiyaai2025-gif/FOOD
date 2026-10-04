import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/app.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/core/navigation/driver_shell.dart';
import 'package:foodex_driver_app/features/delivery/driver_assignment_contract.dart';
import 'package:foodex_driver_app/navigation.dart';

class _StatusRepo implements DriverAssignmentRepository {
  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async => const [
        DriverAssignment(
          id: 1,
          channel: DriverChannel.b2c,
          reference: 'ACCEPTED-1',
          status: 'accepted',
        ),
        DriverAssignment(
          id: 2,
          channel: DriverChannel.b2c,
          reference: 'PICKED-1',
          status: 'picked_up',
        ),
        DriverAssignment(
          id: 3,
          channel: DriverChannel.b2c,
          reference: 'OUT-1',
          status: 'out_for_delivery',
        ),
        DriverAssignment(
          id: 4,
          channel: DriverChannel.b2c,
          reference: 'FAILED-1',
          status: 'failed',
        ),
        DriverAssignment(
          id: 5,
          channel: DriverChannel.b2c,
          reference: 'DONE-1',
          status: 'delivered',
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

class _LiveStatusRepo implements DriverAssignmentRepository {
  List<DriverAssignment> rows = const [
    DriverAssignment(
      id: 10,
      channel: DriverChannel.b2c,
      reference: 'LIVE-ACCEPTED',
      status: 'accepted',
    ),
  ];
  int listCalls = 0;

  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async {
    listCalls++;
    return List<DriverAssignment>.unmodifiable(rows);
  }

  @override
  Future<void> transition(
    int id,
    DriverChannel channel,
    String status, {
    String? note,
    String? failureReason,
  }) async {}
}

class ChannelRecordingRepo implements DriverAssignmentRepository {
  DriverChannel? requestedChannel;

  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async {
    requestedChannel = channel;
    return const [];
  }

  @override
  Future<void> transition(
    int id,
    DriverChannel channel,
    String status, {
    String? note,
    String? failureReason,
  }) async {}
}

class EmptyRepo implements DriverAssignmentRepository {
  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async => const [];

  @override
  Future<void> transition(int id, DriverChannel channel, String status, {String? note, String? failureReason}) async {}
}

DriverSession session(DriverChannel channel) => DriverSession(
      token: 'token',
      name: 'Driver',
      email: 'driver@example.test',
      locale: 'ar',
      channel: channel,
    );

void main() {
  testWidgets('B2C driver starts inside the backend-derived B2C partition',
      (tester) async {
    final repo = ChannelRecordingRepo();
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2c),
        assignmentRepositoryFactory: (_) => repo,
      ),
    );
    await tester.pumpAndSettle();
    expect(repo.requestedChannel, DriverChannel.b2c);
    expect(find.byKey(const Key('driver-shell')), findsOneWidget);
    expect(find.byKey(const Key('driver-shell-navigation')), findsOneWidget);
    expect(
      tester.widget<NavigationBar>(
        find.byKey(const Key('driver-shell-navigation')),
      ).selectedIndex,
      DriverShellDestination.home.index,
    );
    expect(find.byKey(const Key('driver-open-deliveries')), findsOneWidget);
    expect(find.byKey(const Key('driver-route-denied')), findsNothing);
  });

  testWidgets('B2B driver starts inside the backend-derived B2B partition',
      (tester) async {
    final repo = ChannelRecordingRepo();
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2b),
        assignmentRepositoryFactory: (_) => repo,
      ),
    );
    await tester.pumpAndSettle();
    expect(repo.requestedChannel, DriverChannel.b2b);
    expect(find.byKey(const Key('driver-open-deliveries')), findsOneWidget);
    expect(find.byKey(const Key('driver-route-denied')), findsNothing);
  });

  testWidgets('driver home shows five status cards and opens failed deliveries exactly',
      (tester) async {
    final repo = _StatusRepo();
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2c),
        assignmentRepositoryFactory: (_) => repo,
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-home-driver-name')), findsOneWidget);
    expect(find.byKey(const Key('driver-home-status-grid')), findsOneWidget);
    expect(find.text('قناة العمل'), findsNothing);
    expect(find.byKey(const Key('driver-home-status-accepted')), findsOneWidget);
    expect(find.byKey(const Key('driver-home-status-picked_up')), findsOneWidget);
    expect(find.byKey(const Key('driver-home-status-out_for_delivery')), findsOneWidget);
    expect(find.byKey(const Key('driver-home-status-failed')), findsOneWidget);
    expect(find.byKey(const Key('driver-home-status-delivered')), findsOneWidget);

    final failedCard = find.byKey(const Key('driver-home-status-failed'));
    await tester.scrollUntilVisible(
      failedCard,
      180,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.pumpAndSettle();

    final failedTapTarget = find.descendant(
      of: failedCard,
      matching: find.byType(InkWell),
    );
    expect(failedTapTarget, findsOneWidget);
    await tester.tap(failedTapTarget);
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-shell')), findsOneWidget);
    expect(find.byKey(const Key('driver-shell-navigation')), findsOneWidget);
    expect(
      tester.widget<NavigationBar>(
        find.byKey(const Key('driver-shell-navigation')),
      ).selectedIndex,
      DriverShellDestination.deliveries.index,
    );
    expect(find.byKey(const Key('driver-active-status-filter')), findsOneWidget);
    expect(find.text('FAILED-1'), findsOneWidget);
    expect(find.text('OUT-1'), findsNothing);
    expect(find.text('ACCEPTED-1'), findsNothing);
    expect(find.text('DONE-1'), findsNothing);
  });

  testWidgets('home status cards refresh from server data without manual refresh',
      (tester) async {
    final repo = _LiveStatusRepo();
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2c),
        assignmentRepositoryFactory: (_) => repo,
      ),
    );
    await tester.pumpAndSettle();

    final failedCard = find.byKey(const Key('driver-home-status-failed'));
    expect(
      find.descendant(of: failedCard, matching: find.text('0')),
      findsOneWidget,
    );

    repo.rows = const [
      DriverAssignment(
        id: 10,
        channel: DriverChannel.b2c,
        reference: 'LIVE-ACCEPTED',
        status: 'accepted',
      ),
      DriverAssignment(
        id: 11,
        channel: DriverChannel.b2c,
        reference: 'LIVE-FAILED',
        status: 'failed',
      ),
    ];

    final callsBeforePoll = repo.listCalls;
    await tester.pump(const Duration(seconds: 15));
    await tester.pump();

    expect(repo.listCalls, greaterThan(callsBeforePoll));
    expect(
      find.descendant(of: failedCard, matching: find.text('1')),
      findsOneWidget,
    );
  });

  testWidgets('home live refresh timer is stopped when the page is disposed',
      (tester) async {
    final repo = _LiveStatusRepo();
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2c),
        assignmentRepositoryFactory: (_) => repo,
      ),
    );
    await tester.pumpAndSettle();

    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump();
    final callsAfterDispose = repo.listCalls;

    await tester.pump(const Duration(seconds: 45));
    expect(repo.listCalls, callsAfterDispose);
  });

  testWidgets('B2C driver cannot navigate into B2B routes', (tester) async {
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2c),
        assignmentRepositoryFactory: (_) => EmptyRepo(),
        initialRoute: DriverRoutes.b2bDeliveries,
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('driver-route-denied')), findsOneWidget);
  });

  testWidgets('B2B driver cannot navigate into B2C routes', (tester) async {
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2b),
        assignmentRepositoryFactory: (_) => EmptyRepo(),
        initialRoute: DriverRoutes.b2cDeliveries,
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('driver-route-denied')), findsOneWidget);
  });
}
