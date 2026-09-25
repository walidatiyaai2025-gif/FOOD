import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/app.dart';

void main() {
  testWidgets('driver app renders English LTR from the bilingual catalog', (tester) async {
    await tester.pumpWidget(const FoodexDriverApp(locale: Locale('en')));
    await tester.pumpAndSettle();

    expect(find.text('FOODEX Driver'), findsOneWidget);

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

  testWidgets('driver app accepts remote translation overrides with fallback', (tester) async {
    await tester.pumpWidget(
      FoodexDriverApp(
        translationFetcher: (locale) async => {
          'driver.app.title': 'فودكس للسائق المخصص',
        },
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('فودكس للسائق المخصص'), findsOneWidget);
  });
}
