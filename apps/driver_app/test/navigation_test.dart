import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/app.dart';
import 'package:foodex_driver_app/navigation.dart';

void main() {
  testWidgets('B2C driver starts inside the B2C route partition', (tester) async {
    await tester.pumpWidget(const FoodexDriverApp(channel: DriverChannel.b2c));
    await tester.pumpAndSettle();

    expect(find.text(DriverRoutes.b2cHome), findsOneWidget);
    expect(find.text(DriverRoutes.b2bHome), findsNothing);
  });

  testWidgets('B2B driver starts inside the B2B route partition', (tester) async {
    await tester.pumpWidget(const FoodexDriverApp(channel: DriverChannel.b2b));
    await tester.pumpAndSettle();

    expect(find.text(DriverRoutes.b2bHome), findsOneWidget);
    expect(find.text(DriverRoutes.b2cHome), findsNothing);
  });

  testWidgets('B2C driver cannot navigate into B2B routes', (tester) async {
    await tester.pumpWidget(const FoodexDriverApp(
      channel: DriverChannel.b2c,
      initialRoute: DriverRoutes.b2bDeliveries,
    ));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-route-denied')), findsOneWidget);
  });

  testWidgets('B2B driver cannot navigate into B2C routes', (tester) async {
    await tester.pumpWidget(const FoodexDriverApp(
      channel: DriverChannel.b2b,
      initialRoute: DriverRoutes.b2cDeliveries,
    ));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('driver-route-denied')), findsOneWidget);
  });
}
