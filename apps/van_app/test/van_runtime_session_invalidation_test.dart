import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/app.dart';
import 'package:foodex_van_app/core/auth/van_auth_persistence.dart';
import 'package:foodex_van_app/core/auth/van_session.dart';
import 'package:foodex_van_app/core/auth/van_session_store.dart';
import 'package:foodex_van_app/core/location/van_location_tracking_service.dart';

class _Location implements VanLocationSource {
  @override
  Future<VanLocationSample?> current() async => VanLocationSample(
        latitude: 29.3,
        longitude: 47.9,
        capturedAt: DateTime.utc(2026, 11, 11),
      );
}

class _Heartbeat implements VanLocationHeartbeatClient {
  _Heartbeat(this.error);
  final Object error;
  int sends = 0;

  @override
  Future<void> send(VanLocationSample sample) async {
    sends++;
    throw error;
  }
}

class _SilentTracker extends VanLocationTrackingController {
  _SilentTracker()
      : super(
          source: _Location(),
          heartbeatClient: _Heartbeat(const VanOfflineException()),
          interval: const Duration(days: 1),
        );
  int starts = 0;
  int disposals = 0;
  @override
  void start() => starts++;
  @override
  void dispose() => disposals++;
}

class _Auth implements VanAuthRepository {
  final List<String> loggedOut = <String>[];
  @override
  Future<VanSession> login({
    required String email,
    required String password,
  }) async =>
      VanSession(
        token: 'new-token',
        name: 'Authorized Van',
        email: email,
        locale: 'en',
        permissions: const {'van.login'},
        vanId: 8,
        vanCode: 'VAN-8',
        assignmentId: 81,
      );
  @override
  Future<void> logout(String token) async {
    loggedOut.add(token);
  }
}

class _SessionStore implements VanSessionStore {
  VanSession? value;
  @override
  Future<VanSession?> read() async => value;
  @override
  Future<void> write(VanSession session) async => value = session;
  @override
  Future<void> clear() async => value = null;
}

class _Preferences implements VanAuthPreferenceStore {
  VanAuthPreferences? value;
  @override
  Future<VanAuthPreferences?> read() async => value;
  @override
  Future<void> write(VanAuthPreferences preferences) async {
    value = preferences;
  }

  @override
  Future<void> clear() async => value = null;
}

class _Biometric implements VanBiometricAuthenticator {
  @override
  Future<bool> authenticate({required String reason}) async => true;
  @override
  Future<bool> isAvailable() async => false;
}

const _original = VanSession(
  token: 'old-token',
  name: 'Original Van Operator',
  email: 'original@example.test',
  locale: 'en',
  permissions: {'van.login'},
  vanId: 5,
  vanCode: 'VAN-5',
  assignmentId: 50,
);

void main() {
  for (final error in <Object>[
    const VanSessionExpiredException(),
    const VanAccessDeniedException(),
  ]) {
    test(
        'Van live heartbeat revokes entire app session for invalid token or permission',
        () async {
      final heartbeat = _Heartbeat(error);
      var invalidations = 0;
      final controller = VanLocationTrackingController(
        source: _Location(),
        heartbeatClient: heartbeat,
        onSessionExpired: () async {
          invalidations++;
        },
        interval: const Duration(days: 1),
      );
      addTearDown(controller.dispose);

      controller.start();
      await Future<void>.delayed(Duration.zero);
      expect(heartbeat.sends, 1);
      expect(invalidations, 1);
      expect(controller.isRunning, isFalse);
      await controller.syncNow();
      expect(invalidations, 1);
    });
  }

  test('offline heartbeat keeps Van token and retry behavior', () async {
    final heartbeat = _Heartbeat(const VanOfflineException());
    var invalidations = 0;
    final controller = VanLocationTrackingController(
      source: _Location(),
      heartbeatClient: heartbeat,
      onSessionExpired: () async {
        invalidations++;
      },
      interval: const Duration(days: 1),
    );
    addTearDown(controller.dispose);

    controller.start();
    await Future<void>.delayed(Duration.zero);
    expect(controller.isRunning, isTrue);
    await controller.syncNow();
    expect(heartbeat.sends, 2);
    expect(invalidations, 0);
  });

  for (final locale in const [Locale('ar'), Locale('en')]) {
    testWidgets(
      'revoked runtime returns to localized Van login (' +
          locale.languageCode +
          ')',
      (tester) async {
        final auth = _Auth();
        final saved = _SessionStore()..value = _original;
        final preferences = _Preferences()
          ..value = const VanAuthPreferences(rememberMe: true);
        final tracker = _SilentTracker();
        late Future<void> Function() invalidated;

        await tester.pumpWidget(FoodexVanApp(
          locale: locale,
          initialSession: _original,
          authRepository: auth,
          sessionStore: saved,
          authPreferenceStore: preferences,
          biometricAuthenticator: _Biometric(),
          locationTrackingFactory: (session, onSessionInvalidated) {
            expect(session.token, 'old-token');
            invalidated = onSessionInvalidated;
            return tracker;
          },
        ));
        await tester.pump();
        expect(tracker.starts, 1);

        await invalidated();
        await tester.pumpAndSettle();

        expect(tracker.disposals, 1);
        expect(auth.loggedOut, ['old-token']);
        expect(saved.value, isNull);
        expect(preferences.value, isNull);
        expect(find.byKey(const Key('van-login-submit')), findsOneWidget);
        await tester.pumpWidget(const SizedBox.shrink());
      },
    );
  }

  testWidgets('late revoked old token never logs out a fresh Van session',
      (tester) async {
    final auth = _Auth();
    final callbacks = <Future<void> Function()>[];
    await tester.pumpWidget(FoodexVanApp(
      locale: const Locale('en'),
      initialSession: _original,
      authRepository: auth,
      sessionStore: _SessionStore(),
      authPreferenceStore: _Preferences(),
      biometricAuthenticator: _Biometric(),
      locationTrackingFactory: (session, invalidated) {
        callbacks.add(invalidated);
        return _SilentTracker();
      },
    ));
    await tester.pump();
    await callbacks.first();
    await tester.pumpAndSettle();

    await tester.enterText(
        find.byKey(const Key('van-login-email')), 'new@example.test');
    await tester.enterText(
        find.byKey(const Key('van-login-password')), 'password');
    await tester.ensureVisible(find.byKey(const Key('van-login-submit')));
    await tester.tap(find.byKey(const Key('van-login-submit')));
    await tester.pumpAndSettle();

    expect(callbacks.length, 2);
    expect(find.text('Authorized Van'), findsOneWidget);
    await callbacks.first();
    await tester.pump();
    expect(find.text('Authorized Van'), findsOneWidget);
    expect(auth.loggedOut, ['old-token']);

    await callbacks.last();
    await tester.pumpAndSettle();
    expect(auth.loggedOut, ['old-token', 'new-token']);
    expect(find.byKey(const Key('van-login-submit')), findsOneWidget);
    await tester.pumpWidget(const SizedBox.shrink());
  });
}
