import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';

void main() {
  testWidgets('customer app renders English LTR from the bilingual catalog',
      (tester) async {
    await tester.pumpWidget(
      const FoodexCustomerApp(
        locale: Locale('en'),
        initialRoute: CustomerRoutePaths.diagnostics,
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('App diagnostics'), findsOneWidget);
    expect(
      find.text(
        'Export a safe JSON file with recent app failures and context to share with support.',
      ),
      findsOneWidget,
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

  testWidgets(
      'customer app accepts remote translation overrides with bundled fallback',
      (tester) async {
    await tester.pumpWidget(
      FoodexCustomerApp(
        initialRoute: CustomerRoutePaths.diagnostics,
        translationFetcher: (locale) async => {
          'customer.diagnostics.subtitle': 'وصف فودكس المخصص',
        },
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('تشخيص التطبيق'), findsOneWidget);
    expect(find.text('وصف فودكس المخصص'), findsOneWidget);
  });
}
