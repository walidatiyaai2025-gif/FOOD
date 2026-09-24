import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';

void main() {
  testWidgets('customer shell is Arabic-first and RTL', (tester) async {
    await tester.pumpWidget(const FoodexCustomerApp());

    expect(find.text('FOODEX Customer'), findsOneWidget);
    expect(find.text('Splash'), findsOneWidget);

    final app = tester.widget<MaterialApp>(find.byType(MaterialApp));
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
}
