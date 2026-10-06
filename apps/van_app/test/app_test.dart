import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/app.dart';
import 'package:foodex_van_app/core/auth/van_session.dart';

void main() {
  testWidgets('authenticated Van shell exposes tabs-first foundation', (tester) async {
    await tester.pumpWidget(
      const FoodexVanApp(
        locale: Locale('en'),
        initialSession: VanSession(
          token: 'test-token',
          name: 'Van Operator',
          email: 'van@example.test',
          locale: 'en',
          permissions: {'van.login'},
        ),
      ),
    );

    expect(find.text('FOODEX Van'), findsOneWidget);
    expect(find.text('Van Operator'), findsOneWidget);
    expect(find.text('Overview'), findsOneWidget);
    expect(find.text('Routes'), findsOneWidget);
    expect(find.text('Visits'), findsOneWidget);
    expect(find.text('Wallet'), findsOneWidget);
  });
}
