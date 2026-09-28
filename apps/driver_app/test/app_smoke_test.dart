import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/app.dart';

void main() {
  testWidgets('driver bootstrap is Arabic-first, RTL and uses configured production API',
      (tester) async {
    await tester.pumpWidget(const FoodexDriverApp());

    expect(find.text('فودكس للسائق'), findsOneWidget);
    expect(find.byKey(const Key('driver-config-missing')), findsNothing);
    expect(find.byKey(const Key('driver-login-email')), findsOneWidget);

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

  testWidgets('driver password eye toggles visibility', (tester) async {
    await tester.pumpWidget(const FoodexDriverApp());

    final password = find.byKey(const Key('driver-login-password'));
    expect(tester.widget<TextField>(password).obscureText, isTrue);

    await tester.tap(find.byKey(const Key('driver-password-toggle')));
    await tester.pump();
    expect(tester.widget<TextField>(password).obscureText, isFalse);

    await tester.tap(find.byKey(const Key('driver-password-toggle')));
    await tester.pump();
    expect(tester.widget<TextField>(password).obscureText, isTrue);
  });

  testWidgets('driver shell mirrors to English LTR', (tester) async {
    await tester.pumpWidget(
      const FoodexDriverApp(locale: Locale('en')),
    );

    final app = tester.widget<MaterialApp>(find.byType(MaterialApp));
    expect(app.locale, const Locale('en'));

    final directionality = tester.widget<Directionality>(
      find
          .ancestor(
            of: find.byType(Scaffold),
            matching: find.byType(Directionality),
          )
          .first,
    );
    expect(directionality.textDirection, TextDirection.ltr);
  });
}
