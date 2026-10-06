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
    expect(find.byKey(const Key('driver-login-password')), findsOneWidget);
    expect(find.byKey(const Key('driver-app-version-footer')), findsOneWidget);

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

  testWidgets('driver login requires normal email and password credentials', (tester) async {
    await tester.pumpWidget(const FoodexDriverApp());

    expect(find.byKey(const Key('driver-login-email')), findsOneWidget);
    expect(find.byKey(const Key('driver-login-password')), findsOneWidget);
    expect(find.byKey(const Key('driver-password-toggle')), findsOneWidget);
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
  testWidgets('dashboard can remove persistent driver footer while diagnostics remain reachable',
      (tester) async {
    await tester.pumpWidget(
      const FoodexDriverApp(showPersistentFooter: false),
    );

    expect(find.byKey(const Key('driver-app-version-footer')), findsNothing);
    expect(find.byKey(const Key('driver-floating-inspector')), findsOneWidget);
  });

}
