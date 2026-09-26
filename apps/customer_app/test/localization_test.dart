import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';

void main() {
  testWidgets('customer app renders English LTR from the bilingual catalog', (tester) async {
    await tester.pumpWidget(const FoodexCustomerApp(locale: Locale('en'), initialRoute: '/entry'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));

    expect(find.text('Welcome'), findsOneWidget);
    expect(find.text('Browse as a guest or sign in to complete checkout'), findsOneWidget);

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

  testWidgets('customer app accepts remote translation overrides with bundled fallback', (tester) async {
    await tester.pumpWidget(
      FoodexCustomerApp(
        initialRoute: '/entry',
        translationFetcher: (locale) async => {
          'customer.entry.subtitle': 'وصف فودكس المخصص',
        },
      ),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));

    expect(find.text('مرحباً بك'), findsOneWidget);
    expect(find.text('وصف فودكس المخصص'), findsOneWidget);
  });
}
