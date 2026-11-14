import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/core/auth/van_session.dart';
import 'package:foodex_van_app/features/auth/van_login_screen.dart';

class _OfflineVanAuthRepository implements VanAuthRepository {
  @override
  Future<VanSession> login({required String email, required String password}) {
    throw StateError('The accessibility test must never submit credentials.');
  }

  @override
  Future<void> logout(String token) async {}
}

void main() {
  for (final locale in const [Locale('ar'), Locale('en')]) {
    testWidgets('Van login exposes accessible localized fields, focus, and validation in ${locale.languageCode}', (tester) async {
      final semantics = tester.ensureSemantics();
      addTearDown(semantics.dispose);

      await tester.pumpWidget(MaterialApp(
        locale: locale,
        supportedLocales: const [Locale('ar'), Locale('en')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home: VanLoginScreen(
          repository: _OfflineVanAuthRepository(),
          onAuthenticated: (_, __, ___) async {},
        ),
      ));
      await tester.pumpAndSettle();

      final email = find.byKey(const Key('van-login-email'));
      final password = find.byKey(const Key('van-login-password'));
      final submit = find.byKey(const Key('van-login-submit'));
      expect(email, findsOneWidget);
      expect(password, findsOneWidget);
      expect(submit, findsOneWidget);

      await tester.ensureVisible(email);
      await tester.pump();
      final hint = tester.widget<TextField>(email).decoration?.hintText;
      expect(hint, locale.languageCode == 'ar' ? 'البريد الإلكتروني' : 'Email');
      final node = tester.getSemantics(email);
      expect('${node.label} ${node.hint}', contains(hint));

      await tester.tap(email);
      await tester.pump();
      final editable = find.descendant(of: email, matching: find.byType(EditableText));
      expect(tester.widget<EditableText>(editable).focusNode.hasFocus, isTrue);

      await tester.ensureVisible(submit);
      await tester.pump();
      expect(tester.getSemantics(submit).label.trim(), isNotEmpty);
      await tester.tap(submit);
      await tester.pump();
      expect(
        find.text(locale.languageCode == 'ar'
            ? 'البريد الإلكتروني مطلوب.'
            : 'Email is required.'),
        findsWidgets,
      );
      expect(tester.widget<TextField>(password).obscureText, isTrue);
    });
  }
}
