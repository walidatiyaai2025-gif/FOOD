import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/core/auth/van_session.dart';
import 'package:foodex_van_app/features/auth/van_login_screen.dart';

class _FakeVanAuthRepository implements VanAuthRepository {
  @override
  Future<VanSession> login({
    required String email,
    required String password,
  }) {
    throw UnimplementedError();
  }

  @override
  Future<void> logout(String token) async {}
}

Widget _app(Locale locale) {
  return MaterialApp(
    locale: locale,
    supportedLocales: const [Locale('ar'), Locale('en')],
    localizationsDelegates: const [
      GlobalMaterialLocalizations.delegate,
      GlobalWidgetsLocalizations.delegate,
      GlobalCupertinoLocalizations.delegate,
    ],
    home: VanLoginScreen(
      repository: _FakeVanAuthRepository(),
      onAuthenticated: (_, __, ___) async {},
    ),
  );
}

void main() {
  testWidgets('Van login renders Arabic identity and labels for Arabic locale',
      (tester) async {
    await tester.pumpWidget(_app(const Locale('ar')));
    await tester.pumpAndSettle();

    expect(find.text('تطبيق الفان'), findsOneWidget);
    expect(find.text('البريد الإلكتروني'), findsOneWidget);
    expect(find.text('كلمة المرور'), findsOneWidget);
    expect(find.text('Van App'), findsNothing);
  });

  testWidgets('Van login renders English identity and labels for English locale',
      (tester) async {
    await tester.pumpWidget(_app(const Locale('en')));
    await tester.pumpAndSettle();

    expect(find.text('Van App'), findsOneWidget);
    expect(find.text('Email'), findsOneWidget);
    expect(find.text('Password'), findsOneWidget);
    expect(find.text('تطبيق الفان'), findsNothing);
  });
}
