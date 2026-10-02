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
        channel: DriverChannel.b2b,
        storeId: 7,
      );

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
}) =>
    MaterialApp(
      locale: const Locale('ar'),
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: DriverTranslations(
        locale: const Locale('ar'),
        overrides: const {},
        child: DriverLoginPage(
          repository: _Auth(),
          sessionStore: store,
          biometricAuthenticator: biometric,
          onAuthenticated: onAuthenticated,
        ),
      ),
    );

void main() {
  testWidgets('remember me and biometric flags are returned after password login',
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
    await tester.tap(find.byKey(const Key('driver-login-biometric-toggle')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('driver-login-submit')));
    await tester.pumpAndSettle();

    expect(session?.channel, DriverChannel.b2b);
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
          channel: DriverChannel.b2b,
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

    expect(find.byKey(const Key('driver-login-biometric')), findsOneWidget);
    await tester.tap(find.byKey(const Key('driver-login-biometric')));
    await tester.pumpAndSettle();

    expect(session?.token, 'remembered-token');
  });
}
