import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';

void main() {
  testWidgets('customer shell is Arabic-first and RTL', (tester) async {
    await tester.pumpWidget(const FoodexCustomerApp());

    expect(find.byKey(const ValueKey('foodex-splash-logo')), findsNothing);

    final app = tester.widget<MaterialApp>(find.byType(MaterialApp));
    expect(find.byType(MaterialApp), findsOneWidget);
    expect(app.locale, const Locale('ar'));
    expect(app.supportedLocales, const [Locale('ar'), Locale('en')]);

    final directionality = tester.widget<Directionality>(
      find.ancestor(
        of: find.byType(Scaffold),
        matching: find.byType(Directionality),
      ).first,
    );
    expect(directionality.textDirection, TextDirection.rtl);
  });

  testWidgets('customer shell mirrors to English LTR', (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(locale: Locale('en')),
    );

    final app = tester.widget<MaterialApp>(find.byType(MaterialApp));
    expect(app.locale, const Locale('en'));

    final directionality = tester.widget<Directionality>(
      find.ancestor(
        of: find.byType(Scaffold),
        matching: find.byType(Directionality),
      ).first,
    );
    expect(directionality.textDirection, TextDirection.ltr);
  });
  testWidgets('dashboard can remove persistent customer footer with zero footer surface',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(showPersistentFooter: false),
    );

    expect(
      find.byKey(const ValueKey('customer-app-version-footer')),
      findsNothing,
    );
  });

}
