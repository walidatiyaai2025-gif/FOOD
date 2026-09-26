import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/app.dart';

void main() {
  testWidgets('driver bootstrap is Arabic-first, RTL and requires configured API',
      (tester) async {
    await tester.pumpWidget(const FoodexDriverApp());

    expect(find.text('فودكس للسائق'), findsOneWidget);
    expect(find.byKey(const Key('driver-config-missing')), findsOneWidget);

    final app = tester.widget<MaterialApp>(find.byType(MaterialApp));
    expect(app.locale, const Locale('ar'));
    expect(app.supportedLocales, const [Locale('ar'), Locale('en')]);

    final directionality = tester.widget<Directionality>(
      find
          .ancestor(
            of: find.byType(Scaffold),
            matching: find.byType(Directionality),
          )
          .first,
    );
    expect(directionality.textDirection, TextDirection.rtl);
  });
}
