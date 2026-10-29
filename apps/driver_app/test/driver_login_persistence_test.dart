import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
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

  String? lastLoggedOutToken;

  @override
  Future<void> logout(String token) async {
    lastLoggedOutToken = token;
  }
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
    expect(find.byKey(const Key('driver-login-email')), findsNothing);
    expect(find.byKey(const Key('driver-login-password')), findsNothing);
    expect(find.byKey(const Key('driver-login-submit')), findsNothing);
    expect(find.byKey(const Key('driver-login-remember')), findsNothing);
    expect(find.byKey(const Key('driver-login-biometric-toggle')), findsNothing);
    expect(find.byKey(const Key('driver-login-logout')), findsOneWidget);
    await tester.ensureVisible(biometricButton);
    await tester.tap(biometricButton);
    await tester.pumpAndSettle();

    expect(session?.token, 'remembered-token');
  });

  test('secure sessions restore B2B and B2C', () async {
    for (final channel in DriverChannel.values) {
      FlutterSecureStorage.setMockInitialValues(<String, String>{});
      final store = SecureDriverSessionStore();
      final session = DriverSession(
        token: 'secure-${channel.name}-token',
        name: 'Driver',
        email: 'driver@example.test',
        locale: 'ar',
        channel: channel,
        storeId: 7,
      );
      await store.write(session, biometricEnabled: true);

      // A fresh store simulates the next app launch reading secure storage.
      final restored = await SecureDriverSessionStore().read();
      expect(restored, isNotNull);
      expect(restored!.session.token, session.token);
      expect(restored.session.channel, channel);
      expect(restored.session.storeId, session.storeId);
      expect(restored.biometricEnabled, isTrue);

      await store.clear();
      expect(await store.read(), isNull);
    }
  });

  test('B2B remembers without biometrics', () async {
    FlutterSecureStorage.setMockInitialValues(<String, String>{});
    final store = SecureDriverSessionStore();
    await store.write(
      const DriverSession(
        token: 'wholesale-remembered-token',
        name: 'Wholesale Driver',
        email: 'wholesale@example.test',
        locale: 'ar',
        channel: DriverChannel.b2b,
        storeId: 21,
      ),
      biometricEnabled: false,
    );
    final saved = await SecureDriverSessionStore().read();
    expect(saved?.session.channel, DriverChannel.b2b);
    expect(saved?.biometricEnabled, isFalse);
  });

  testWidgets('saved B2B fingerprint login', (tester) async {
    final store = _Store()
      ..stored = const DriverStoredSession(
        session: DriverSession(
          token: 'wholesale-biometric-token',
          name: 'Wholesale Driver',
          email: 'wholesale@example.test',
          locale: 'ar',
          channel: DriverChannel.b2b,
          storeId: 21,
        ),
        biometricEnabled: true,
      );

    DriverSession? authenticatedSession;
    bool? remembered;
    bool? biometricEnabled;
    await tester.pumpWidget(
      _host(
        store: store,
        biometric: const _Biometric(),
        onAuthenticated: (session, remember, biometric) {
          authenticatedSession = session;
          remembered = remember;
          biometricEnabled = biometric;
        },
      ),
    );
    await tester.pumpAndSettle();

    final fingerprint = find.byKey(const Key('driver-login-biometric'));
    expect(fingerprint, findsOneWidget);
    expect(find.byKey(const Key('driver-login-email')), findsNothing);
    expect(find.byKey(const Key('driver-login-password')), findsNothing);
    expect(find.byKey(const Key('driver-login-submit')), findsNothing);
    expect(find.byKey(const Key('driver-login-remember')), findsNothing);
    expect(find.byKey(const Key('driver-login-biometric-toggle')), findsNothing);
    expect(find.byKey(const Key('driver-login-logout')), findsOneWidget);

    await tester.ensureVisible(fingerprint);
    await tester.tap(fingerprint);
    await tester.pumpAndSettle();
    expect(authenticatedSession?.token, 'wholesale-biometric-token');
    expect(authenticatedSession?.channel, DriverChannel.b2b);
    expect(remembered, isTrue);
    expect(biometricEnabled, isTrue);
  });

  testWidgets('sign out clears saved session and restores sign-in form',
      (tester) async {
    for (final entry in const <(DriverChannel, Locale, String)>[
      (DriverChannel.b2b, Locale('ar'), 'تسجيل الخروج'),
      (DriverChannel.b2c, Locale('en'), 'Sign out'),
    ]) {
      final store = _Store()
        ..stored = DriverStoredSession(
          session: DriverSession(
            token: 'saved-${entry.$1.name}-token',
            name: 'Remembered Driver',
            email: 'driver@example.test',
            locale: entry.$2.languageCode,
            channel: entry.$1,
            storeId: 7,
          ),
          biometricEnabled: true,
        );
      final repository = _Auth();
      await tester.pumpWidget(
        _host(
          store: store,
          repository: repository,
          locale: entry.$2,
          biometric: const _Biometric(),
          onAuthenticated: (_, __, ___) {},
        ),
      );
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('driver-login-email')), findsNothing);
      expect(find.byKey(const Key('driver-login-password')), findsNothing);
      expect(find.byKey(const Key('driver-login-submit')), findsNothing);
      expect(find.byKey(const Key('driver-login-biometric')), findsOneWidget);
      expect(find.text(entry.$3), findsOneWidget);

      await tester.tap(find.byKey(const Key('driver-login-logout')));
      await tester.pumpAndSettle();

      expect(store.stored, isNull);
      expect(repository.lastLoggedOutToken, 'saved-${entry.$1.name}-token');
      expect(find.byKey(const Key('driver-login-biometric')), findsNothing);
      expect(find.byKey(const Key('driver-login-logout')), findsNothing);
      expect(find.byKey(const Key('driver-login-email')), findsOneWidget);
      expect(find.byKey(const Key('driver-login-password')), findsOneWidget);
      expect(find.byKey(const Key('driver-login-submit')), findsOneWidget);
      expect(
        tester.widget<CheckboxListTile>(
          find.byKey(const Key('driver-login-remember')),
        ).value,
        isFalse,
      );
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump();
    }
  });
}
