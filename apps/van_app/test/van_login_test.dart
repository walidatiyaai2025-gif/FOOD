import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/app.dart';
import 'package:foodex_van_app/core/auth/van_auth_persistence.dart';
import 'package:foodex_van_app/core/auth/van_session.dart';
import 'package:foodex_van_app/core/auth/van_session_store.dart';

class _Auth implements VanAuthRepository {
  @override
  Future<VanSession> login({
    required String email,
    required String password,
  }) async {
    return VanSession(
      token: 'token',
      name: 'Authorized Van',
      email: email,
      locale: 'en',
      permissions: const {'van.login'},
      vanId: 12,
      vanCode: 'VAN-12',
      assignmentId: 34,
    );
  }

  @override
  Future<void> logout(String token) async {}
}

class _RejectingAuth implements VanAuthRepository {
  const _RejectingAuth(this.error);

  final Object error;

  @override
  Future<VanSession> login({
    required String email,
    required String password,
  }) async {
    throw error;
  }

  @override
  Future<void> logout(String token) async {}
}

class _MemoryStore implements VanSessionStore {
  VanSession? value;

  @override
  Future<void> clear() async => value = null;

  @override
  Future<VanSession?> read() async => value;

  @override
  Future<void> write(VanSession session) async => value = session;
}

class _PreferenceStore implements VanAuthPreferenceStore {
  VanAuthPreferences? value;

  @override
  Future<void> clear() async => value = null;

  @override
  Future<VanAuthPreferences?> read() async => value;

  @override
  Future<void> write(VanAuthPreferences preferences) async {
    value = preferences;
  }
}

class _Biometric implements VanBiometricAuthenticator {
  const _Biometric({this.available = true, this.result = true});

  final bool available;
  final bool result;

  @override
  Future<bool> authenticate({required String reason}) async => result;

  @override
  Future<bool> isAvailable() async => available;
}

VanSession _savedSession() => const VanSession(
      token: 'remembered-token',
      name: 'Remembered Van',
      email: 'remembered@example.test',
      locale: 'en',
      permissions: {'van.login'},
      vanId: 21,
      vanCode: 'VAN-21',
      assignmentId: 43,
    );

void main() {
  testWidgets(
    'Van login uses the approved Customer business entry composition',
    (tester) async {
      await tester.pumpWidget(
        FoodexVanApp(
          locale: const Locale('en'),
          authRepository: _Auth(),
          sessionStore: _MemoryStore(),
          authPreferenceStore: _PreferenceStore(),
          biometricAuthenticator: const _Biometric(),
        ),
      );
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('van-customer-parity-login')),
        findsOneWidget,
      );
      expect(find.byKey(const ValueKey('van-login-header')), findsOneWidget);
      expect(find.byKey(const ValueKey('van-login-hero')), findsOneWidget);
      expect(find.byKey(const Key('van-login-email')), findsOneWidget);
      expect(find.byKey(const Key('van-login-password')), findsOneWidget);
      expect(find.byKey(const Key('van-login-remember')), findsOneWidget);
      expect(
        find.byKey(const Key('van-login-biometric-toggle')),
        findsOneWidget,
      );
      expect(find.byKey(const Key('van-login-submit')), findsOneWidget);
      expect(
        find.byKey(const ValueKey('van-business-forgot-password')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('van-business-contact-us')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('van-business-login-version')),
        findsOneWidget,
      );
    },
  );

  testWidgets(
    'blank Van credentials expose localized field errors and first-invalid focus',
    (tester) async {
      tester.view.physicalSize = const Size(360, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      for (final entry in const <(Locale, String, String)>[
        (Locale('en'), 'Email is required.', 'Password is required.'),
        (Locale('ar'), 'البريد الإلكتروني مطلوب.', 'كلمة المرور مطلوبة.'),
      ]) {
        await tester.pumpWidget(
          FoodexVanApp(
            locale: entry.$1,
            authRepository: _Auth(),
            sessionStore: _MemoryStore(),
            authPreferenceStore: _PreferenceStore(),
            biometricAuthenticator: const _Biometric(),
          ),
        );
        await tester.pumpAndSettle();

        final submit = find.byKey(const Key('van-login-submit'));
        await tester.ensureVisible(submit);
        await tester.tap(submit);
        await tester.pump();

        final emailFinder = find.byKey(const Key('van-login-email'));
        final passwordFinder = find.byKey(const Key('van-login-password'));
        var emailField = tester.widget<TextField>(emailFinder);
        var passwordField = tester.widget<TextField>(passwordFinder);

        expect(emailField.decoration?.errorText, entry.$2);
        expect(passwordField.decoration?.errorText, entry.$3);
        expect(emailField.focusNode?.hasFocus, isTrue);

        await tester.enterText(emailFinder, 'van@example.test');
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
    },
  );

  testWidgets(
    'Van authentication outcomes use a live region and clear on credential edit',
    (tester) async {
      for (final entry in const <(Object, String)>[
        (
          VanAuthenticationException(),
          'Invalid email or password.',
        ),
        (
          VanAccessDeniedException(),
          'This account is not authorized for the Van app.',
        ),
        (
          VanOfflineException(),
          'No network connection. Try again.',
        ),
      ]) {
        await tester.pumpWidget(
          FoodexVanApp(
            locale: const Locale('en'),
            authRepository: _RejectingAuth(entry.$1),
            sessionStore: _MemoryStore(),
            authPreferenceStore: _PreferenceStore(),
            biometricAuthenticator: const _Biometric(),
          ),
        );
        await tester.pumpAndSettle();

        final emailFinder = find.byKey(const Key('van-login-email'));
        final passwordFinder = find.byKey(const Key('van-login-password'));
        await tester.enterText(emailFinder, 'van@example.test');
        await tester.enterText(passwordFinder, 'wrong-password');

        final submit = find.byKey(const Key('van-login-submit'));
        await tester.ensureVisible(submit);
        await tester.tap(submit);
        await tester.pumpAndSettle();

        expect(find.text(entry.$2), findsOneWidget);
        final liveRegion = tester.widget<Semantics>(
          find.byKey(const Key('van-login-error-live')),
        );
        expect(liveRegion.properties.liveRegion, isTrue);

        await tester.enterText(emailFinder, 'van2@example.test');
        await tester.pump();
        expect(find.byKey(const Key('van-login-error')), findsNothing);

        await tester.pumpWidget(const SizedBox.shrink());
        await tester.pump();
      }
    },
  );

  testWidgets(
    'Remember Me without explicit opt-in does not enable biometric unlock',
    (tester) async {
      final store = _MemoryStore();
      final preferences = _PreferenceStore();

      await tester.pumpWidget(
        FoodexVanApp(
          locale: const Locale('en'),
          authRepository: _Auth(),
          sessionStore: store,
          authPreferenceStore: preferences,
          biometricAuthenticator: const _Biometric(),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('Van App'), findsOneWidget);

      await tester.ensureVisible(find.byKey(const Key('van-login-remember')));
      await tester.pump();
      await tester.tap(find.byKey(const Key('van-login-remember')));
      await tester.pump();
      await tester.enterText(
        find.byKey(const Key('van-login-email')),
        'van@example.test',
      );
      await tester.enterText(
        find.byKey(const Key('van-login-password')),
        'password',
      );
      await tester.ensureVisible(find.byKey(const Key('van-login-submit')));
      await tester.pump();
      await tester.tap(find.byKey(const Key('van-login-submit')));
      await tester.pumpAndSettle();

      expect(find.text('Authorized Van'), findsOneWidget);
      expect(find.text('Home Dashboard'), findsWidgets);
      expect(store.value?.token, 'token');
      expect(preferences.value?.rememberMe, isTrue);
      expect(preferences.value?.biometricEnabled, isFalse);
    },
  );

  testWidgets(
    'Van explicitly selected Remember Me plus biometric persists unlock',
    (tester) async {
      final store = _MemoryStore();
      final preferences = _PreferenceStore();

      await tester.pumpWidget(
        FoodexVanApp(
          locale: const Locale('en'),
          authRepository: _Auth(),
          sessionStore: store,
          authPreferenceStore: preferences,
          biometricAuthenticator: const _Biometric(),
        ),
      );
      await tester.pumpAndSettle();

      final remember = find.byKey(const Key('van-login-remember'));
      await tester.ensureVisible(remember);
      await tester.tap(remember);
      await tester.pump();
      final fingerprint = find.byKey(const Key('van-login-biometric-toggle'));
      await tester.ensureVisible(fingerprint);
      await tester.tap(fingerprint);
      await tester.pump();
      await tester.enterText(
        find.byKey(const Key('van-login-email')),
        'van@example.test',
      );
      await tester.enterText(
        find.byKey(const Key('van-login-password')),
        'password',
      );
      final submit = find.byKey(const Key('van-login-submit'));
      await tester.ensureVisible(submit);
      await tester.tap(submit);
      await tester.pumpAndSettle();

      expect(store.value?.token, 'token');
      expect(preferences.value?.rememberMe, isTrue);
      expect(preferences.value?.biometricEnabled, isTrue);
    },
  );

  testWidgets(
    'remembered biometric session waits for device unlock on restart',
    (tester) async {
      final store = _MemoryStore()..value = _savedSession();
      final preferences = _PreferenceStore()
        ..value = const VanAuthPreferences(
          rememberMe: true,
          biometricEnabled: true,
        );

      await tester.pumpWidget(
        FoodexVanApp(
          locale: const Locale('en'),
          authRepository: _Auth(),
          sessionStore: store,
          authPreferenceStore: preferences,
          biometricAuthenticator: const _Biometric(),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('van-login-biometric')), findsOneWidget);
      expect(find.text('Remembered Van'), findsNothing);

      await tester.ensureVisible(find.byKey(const Key('van-login-biometric')));
      await tester.pump();
      await tester.tap(find.byKey(const Key('van-login-biometric')));
      await tester.pumpAndSettle();

      expect(find.text('Remembered Van'), findsOneWidget);
      expect(find.text('Home Dashboard'), findsWidgets);
    },
  );

  testWidgets('failed biometric keeps password login available', (
    tester,
  ) async {
    final store = _MemoryStore()..value = _savedSession();
    final preferences = _PreferenceStore()
      ..value = const VanAuthPreferences(
        rememberMe: true,
        biometricEnabled: true,
      );

    await tester.pumpWidget(
      FoodexVanApp(
        locale: const Locale('en'),
        authRepository: _Auth(),
        sessionStore: store,
        authPreferenceStore: preferences,
        biometricAuthenticator: const _Biometric(result: false),
      ),
    );
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.byKey(const Key('van-login-biometric')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('van-login-biometric')));
    await tester.pumpAndSettle();

    expect(
      find.text('Biometric verification failed. Use your password to sign in.'),
      findsOneWidget,
    );
    expect(find.byKey(const Key('van-login-email')), findsOneWidget);
    expect(find.byKey(const Key('van-login-password')), findsOneWidget);
    expect(find.byKey(const Key('van-login-submit')), findsOneWidget);
  });

  testWidgets('logout clears remembered session and biometric preferences', (
    tester,
  ) async {
    final store = _MemoryStore();
    final preferences = _PreferenceStore();

    await tester.pumpWidget(
      FoodexVanApp(
        locale: const Locale('en'),
        authRepository: _Auth(),
        sessionStore: store,
        authPreferenceStore: preferences,
        biometricAuthenticator: const _Biometric(),
      ),
    );
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.byKey(const Key('van-login-remember')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('van-login-remember')));
    await tester.pump();
    await tester.enterText(
      find.byKey(const Key('van-login-email')),
      'van@example.test',
    );
    await tester.enterText(
      find.byKey(const Key('van-login-password')),
      'password',
    );
    await tester.ensureVisible(find.byKey(const Key('van-login-submit')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('van-login-submit')));
    await tester.pumpAndSettle();

    expect(store.value, isNotNull);
    expect(preferences.value?.biometricEnabled, isTrue);

    await tester.tap(find.byKey(const Key('van-logout')));
    await tester.pumpAndSettle();

    expect(store.value, isNull);
    expect(preferences.value, isNull);
    expect(find.byKey(const Key('van-login-submit')), findsOneWidget);
  });

  testWidgets('Arabic login exposes explicit Van App identity', (tester) async {
    await tester.pumpWidget(
      FoodexVanApp(
        locale: const Locale('ar'),
        authRepository: _Auth(),
        sessionStore: _MemoryStore(),
        authPreferenceStore: _PreferenceStore(),
        biometricAuthenticator: const _Biometric(available: false),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('تطبيق الفان'), findsOneWidget);
    expect(find.text('عمليات فودكس الميدانية'), findsOneWidget);
  });
}
