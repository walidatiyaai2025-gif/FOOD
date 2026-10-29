import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/core/auth/driver_auth_persistence.dart';
import 'package:foodex_driver_app/core/auth/driver_session.dart';
import 'package:foodex_driver_app/core/localization/driver_translations.dart';
import 'package:foodex_driver_app/features/auth/driver_login.dart';

class _Auth implements DriverAuthRepository {
  @override
  Future<DriverSession> login({
    required String email,
    required String password,
  }) async =>
      const DriverSession(
        token: 'token',
        name: 'Driver',
        email: 'driver@example.test',
        locale: 'ar',
        channel: DriverChannel.b2c,
        storeId: 7,
      );

  @override
  Future<void> logout(String token) async {}
}

class _FailingAuth implements DriverAuthRepository {
  const _FailingAuth();

  @override
  Future<DriverSession> login({
    required String email,
    required String password,
  }) async {
    throw const DriverAuthenticationException();
  }

  @override
  Future<void> logout(String token) async {}
}

class _Store implements DriverSessionStore {
  DriverStoredSession? stored;

  @override
  Future<void> clear() async => stored = null;

  @override
  Future<DriverStoredSession?> read() async => stored;

  @override
  Future<void> write(
    DriverSession session, {
    required bool biometricEnabled,
  }) async {
    stored = DriverStoredSession(
      session: session,
      biometricEnabled: biometricEnabled,
    );
  }
}

class _Biometric implements DriverBiometricAuthenticator {
  const _Biometric();

  static const bool available = true;
  static const bool result = true;

  @override
  Future<bool> authenticate({required String reason}) async => result;

  @override
  Future<bool> isAvailable() async => available;
}

Widget _host({
  required DriverSessionStore store,
  required DriverBiometricAuthenticator biometric,
  required DriverAuthenticatedCallback onAuthenticated,
  Locale locale = const Locale('ar'),
  DriverAuthRepository? repository,
}) =>
    MaterialApp(
      locale: locale,
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: DriverTranslations(
        locale: locale,
        overrides: const {},
        child: DriverLoginPage(
          repository: repository ?? _Auth(),
          sessionStore: store,
          biometricAuthenticator: biometric,
          onAuthenticated: onAuthenticated,
        ),
      ),
    );

void main() {
  testWidgets('Driver App identity is visible in Arabic and English',
      (tester) async {
    for (final entry in const <(Locale, String)>[
      (Locale('ar'), 'تطبيق السائق'),
      (Locale('en'), 'Driver App'),
    ]) {
      await tester.pumpWidget(
        _host(
          store: _Store(),
          biometric: const _Biometric(),
          locale: entry.$1,
          onAuthenticated: (_, __, ___) {},
        ),
      );
      await tester.pumpAndSettle();

      final identity = find.byKey(const Key('driver-app-identity'));
      expect(identity, findsOneWidget);
      expect(find.text(entry.$2), findsOneWidget);
      expect(tester.getSize(identity).height, greaterThan(0));
    }
  });

  testWidgets(
      'blank Driver credentials expose localized field errors and first-invalid focus',
      (tester) async {
    tester.view.physicalSize = const Size(360, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    for (final entry in const <(Locale, String, String)>[
      (Locale('en'), 'Enter email and password', 'Enter email and password'),
      (
        Locale('ar'),
        'أدخل البريد الإلكتروني وكلمة المرور',
        'أدخل البريد الإلكتروني وكلمة المرور'
      ),
    ]) {
      await tester.pumpWidget(
        _host(
          store: _Store(),
          biometric: const _Biometric(),
          locale: entry.$1,
          onAuthenticated: (_, __, ___) {},
        ),
      );
      await tester.pumpAndSettle();

      final submit = find.byKey(const Key('driver-login-submit'));
      await tester.ensureVisible(submit);
      await tester.tap(submit);
      await tester.pump();

      final emailFinder = find.byKey(const Key('driver-login-email'));
      final passwordFinder = find.byKey(const Key('driver-login-password'));
      var emailField = tester.widget<TextField>(emailFinder);
      var passwordField = tester.widget<TextField>(passwordFinder);

      expect(emailField.decoration?.errorText, entry.$2);
      expect(passwordField.decoration?.errorText, entry.$3);
      expect(emailField.focusNode?.hasFocus, isTrue);

      await tester.enterText(emailFinder, 'driver@example.test');
      await tester.pump();
      emailField = tester.widget<TextField>(emailFinder);
      passwordField = tester.widget<TextField>(passwordFinder);
      expect(emailField.decoration?.errorText, isNull);
      expect(passwordField.decoration?.errorText, entry.$3);

      await tester.ensureVisible(submit);
      await tester.tap(submit);
      await tester.pump();
      passwordField = tester.widget<TextField>(passwordFinder);
      expect(passwordField.focusNode?.hasFocus, isTrue);

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
    }
  });

  testWidgets(
      'Driver authentication errors use a live region and clear on edit',
      (tester) async {
    await tester.pumpWidget(
      _host(
        store: _Store(),
        biometric: const _Biometric(),
        locale: const Locale('en'),
        repository: const _FailingAuth(),
        onAuthenticated: (_, __, ___) {},
      ),
    );
    await tester.pumpAndSettle();

    final emailFinder = find.byKey(const Key('driver-login-email'));
    final passwordFinder = find.byKey(const Key('driver-login-password'));
    await tester.enterText(emailFinder, 'driver@example.test');
    await tester.enterText(passwordFinder, 'wrong-password');

    final submit = find.byKey(const Key('driver-login-submit'));
    await tester.ensureVisible(submit);
    await tester.tap(submit);
    await tester.pumpAndSettle();

    expect(find.text('Invalid sign-in details'), findsOneWidget);
    final liveRegion = tester.widget<Semantics>(
      find.byKey(const Key('driver-login-error-live')),
    );
    expect(liveRegion.properties.liveRegion, isTrue);

    await tester.enterText(emailFinder, 'driver2@example.test');
    await tester.pump();
    expect(find.byKey(const Key('driver-login-error')), findsNothing);
  });

  testWidgets(
      'remember me and biometric flags are returned after password login',
      (tester) async {
    final store = _Store();
    DriverSession? session;
    bool? remember;
    bool? biometric;

    await tester.pumpWidget(
      _host(
        store: store,
        biometric: const _Biometric(),
        onAuthenticated: (value, rememberMe, biometricEnabled) {
          session = value;
          remember = rememberMe;
          biometric = biometricEnabled;
        },
      ),
    );
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const Key('driver-login-email')),
      'driver@example.test',
    );
    await tester.enterText(
      find.byKey(const Key('driver-login-password')),
      'secret',
    );
    final biometricToggle =
        find.byKey(const Key('driver-login-biometric-toggle'));
    await tester.ensureVisible(biometricToggle);
    await tester.tap(biometricToggle);
    await tester.pump();
    final submit = find.byKey(const Key('driver-login-submit'));
    await tester.ensureVisible(submit);
    await tester.tap(submit);
    await tester.pumpAndSettle();

    expect(session?.channel, DriverChannel.b2c);
    expect(remember, isTrue);
    expect(biometric, isTrue);
  });

  testWidgets('saved biometric session can authenticate without password',
      (tester) async {
    final store = _Store()
      ..stored = const DriverStoredSession(
        session: DriverSession(
          token: 'remembered-token',
          name: 'Remembered Driver',
          email: 'remembered@example.test',
          locale: 'ar',
          channel: DriverChannel.b2c,
          storeId: 7,
        ),
        biometricEnabled: true,
      );
    DriverSession? session;

    await tester.pumpWidget(
      _host(
        store: store,
        biometric: const _Biometric(),
        onAuthenticated: (value, rememberMe, biometricEnabled) {
          session = value;
        },
      ),
    );
    await tester.pumpAndSettle();

    final biometricButton = find.byKey(const Key('driver-login-biometric'));
    expect(biometricButton, findsOneWidget);
    expect(
      tester.widget<CheckboxListTile>(
        find.byKey(const Key('driver-login-remember')),
      ).value,
      isTrue,
    );
    expect(
      tester.widget<CheckboxListTile>(
        find.byKey(const Key('driver-login-biometric-toggle')),
      ).value,
      isTrue,
    );
    await tester.ensureVisible(biometricButton);
    await tester.tap(biometricButton);
    await tester.pumpAndSettle();

    expect(session?.token, 'remembered-token');
  });
}
