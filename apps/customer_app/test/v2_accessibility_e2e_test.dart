import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';

void main() {
  for (final locale in const [Locale('ar'), Locale('en')]) {
    testWidgets('Customer diagnostics has a screen-reader label and usable note input in ${locale.languageCode}', (tester) async {
      final semantics = tester.ensureSemantics();
      addTearDown(semantics.dispose);

      await tester.pumpWidget(FoodexCustomerApp(
        locale: locale,
        initialRoute: CustomerRoutePaths.diagnostics,
        translationFetcher: (_) async => <String, String>{},
      ));
      await tester.pumpAndSettle();

      final note = find.byKey(const ValueKey('customer-diagnostics-note'));
      expect(note, findsOneWidget);
      await tester.ensureVisible(note);
      await tester.pump();

      final field = tester.widget<TextField>(note);
      final label = field.decoration?.labelText;
      expect(label, isNotNull);
      expect(label!.trim(), isNotEmpty);
      expect(RegExp(r'[\u0600-\u06ff]').hasMatch(label), locale.languageCode == 'ar');
      final node = tester.getSemantics(note);
      expect('${node.label} ${node.hint}', contains(label));

      await tester.tap(note);
      await tester.pump();
      final input = find.descendant(of: note, matching: find.byType(EditableText));
      expect(tester.widget<EditableText>(input).focusNode.hasFocus, isTrue);
      await tester.enterText(note, 'FOODEX accessibility fixture');
      expect(find.text('FOODEX accessibility fixture'), findsOneWidget);

      final export = find.byKey(const ValueKey('customer-diagnostics-export'));
      await tester.ensureVisible(export);
      await tester.pump();
      expect(tester.widget<FilledButton>(export).onPressed, isNotNull);
      expect(tester.getSemantics(export).label.trim(), isNotEmpty);
    });
  }
}
