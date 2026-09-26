import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/app.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/features/tasks/driver_journey.dart';
import 'package:foodex_driver_app/navigation.dart';

class EmptyRepo implements DriverAssignmentRepository {
  @override
  Future<List<DriverAssignment>> list(DriverChannel channel) async => const [];

  @override
  Future<void> transition(int id, DriverChannel channel, String status) async {}
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
