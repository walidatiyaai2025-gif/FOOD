import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/app.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/features/tasks/driver_journey.dart';
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
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2c),
        assignmentRepositoryFactory: (_) => EmptyRepo(),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text(DriverRoutes.b2cHome), findsOneWidget);
    expect(find.text(DriverRoutes.b2bHome), findsNothing);
  });

  testWidgets('B2B driver starts inside the backend-derived B2B partition',
      (tester) async {
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2b),
        assignmentRepositoryFactory: (_) => EmptyRepo(),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text(DriverRoutes.b2bHome), findsOneWidget);
    expect(find.text(DriverRoutes.b2cHome), findsNothing);
  });

  testWidgets('driver home shows four status cards and opens an exact filtered list',
      (tester) async {
    final repo = _StatusRepo();
    await tester.pumpWidget(
      FoodexDriverApp(
        initialSession: session(DriverChannel.b2c),
        assignmentRepositoryFactory: (_) => repo,
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-home-status-accepted')), findsOneWidget);
    expect(find.byKey(const Key('driver-home-status-picked_up')), findsOneWidget);
    expect(find.byKey(const Key('driver-home-status-out_for_delivery')), findsOneWidget);
    expect(find.byKey(const Key('driver-home-status-delivered')), findsOneWidget);

    await tester.tap(find.byKey(const Key('driver-home-status-out_for_delivery')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-exact-status-filter')), findsOneWidget);
    expect(find.text('OUT-1'), findsOneWidget);
    expect(find.text('ACCEPTED-1'), findsNothing);
    expect(find.text('DONE-1'), findsNothing);
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
