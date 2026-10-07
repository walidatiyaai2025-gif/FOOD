import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/core/auth/customer_auth_persistence.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/auth/customer_session_store.dart';
import 'package:foodex_customer_app/core/localization/app_translations.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context.dart';
import 'package:foodex_customer_app/core/routing/customer_pending_action.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';
import 'package:foodex_customer_app/features/auth/unified_customer_auth_screen.dart';

void main() {
  testWidgets('unified auth uses one customer copy and remember-me policy',
      (tester) async {
    CustomerAuthPreferences? completedPreferences;
    String? completedToken;

    await tester.pumpWidget(
      _testApp(
        child: UnifiedCustomerAuthScreen(
          nextRoute: CustomerRoutePaths.marketplace,
          actionApi: const _SuccessfulActionApi(),
          onAuthenticated: (token, preferences) async {
            completedToken = token;
            completedPreferences = preferences;
          },
          biometricAuthenticator:
              const _FakeBiometric(available: false, result: false),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Customer App'), findsOneWidget);
    expect(find.text('Customer sign in'), findsWidgets);
    expect(find.textContaining('Business customer'), findsNothing);
    final identity = find.byKey(const ValueKey('customer-app-identity'));
    expect(identity, findsOneWidget);
    expect(tester.getSize(identity).height, greaterThan(0));
    expect(
      find.byKey(const ValueKey('customer-auth-remember-me')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('customer-auth-biometric-toggle')),
      findsNothing,
    );

    await tester.tap(
      find.byKey(const ValueKey('customer-auth-remember-me')),
    );
    await tester.enterText(
      find.byKey(const ValueKey('unified-auth-email')),
      'buyer@example.test',
    );
    await tester.enterText(
      find.byKey(const ValueKey('unified-auth-password')),
      'secret-pass',
    );
    await tester.tap(find.byKey(const ValueKey('unified-auth-submit')));
    await tester.pumpAndSettle();

    expect(completedToken, 'platform-token');
    expect(completedPreferences?.rememberMe, isTrue);
    expect(completedPreferences?.biometricEnabled, isFalse);
  });

  testWidgets('customer app identity is visible in Arabic RTL', (tester) async {
    await tester.pumpWidget(
      _testApp(
        locale: const Locale('ar'),
        child: UnifiedCustomerAuthScreen(
          nextRoute: CustomerRoutePaths.marketplace,
          actionApi: const _SuccessfulActionApi(),
          onAuthenticated: (_, __) async {},
          biometricAuthenticator:
              const _FakeBiometric(available: false, result: false),
        ),
      ),
    );
    await tester.pumpAndSettle();

    final identity = find.byKey(const ValueKey('customer-app-identity'));
    expect(identity, findsOneWidget);
    expect(find.text('تطبيق العميل'), findsOneWidget);
    expect(tester.getSize(identity).height, greaterThan(0));
    expect(Directionality.of(tester.element(identity)), TextDirection.rtl);
  });

  testWidgets('biometric unlock resumes exact pending action once',
      (tester) async {
    final storage = _MemorySecureStore();
    final sessions = SecureCustomerSessionStore(storage: storage);
    await sessions.write(
      const CustomerSession.platformCustomer(accessToken: 'saved-token'),
    );
    const context = CustomerCommerceContext(
      channel: CustomerCommerceChannel.retail,
      storeId: 7,
      source: CustomerCommerceSource.retailBanner,
      entryPlacementId: 701,
    );
    final pending = _MemoryPendingActionStore(
      CustomerPendingAction(
        kind: CustomerPendingActionKind.checkout,
        context: context,
        nextLocation: CustomerRouteLocations.retailCart(context),
        createdAtEpochMs: DateTime.utc(2026, 10, 2, 12).millisecondsSinceEpoch,
      ),
    );
    String? completedToken;

    await tester.pumpWidget(
      _testApp(
        child: UnifiedCustomerAuthScreen(
          nextRoute: CustomerRouteLocations.retailCheckout(context),
          actionApi: const _SuccessfulActionApi(),
          onAuthenticated: (token, preferences) async {
            completedToken = token;
            expect(preferences.rememberMe, isTrue);
            expect(preferences.biometricEnabled, isTrue);
          },
          commerceContext: context,
          pendingActionStore: pending,
          sessionStore: sessions,
          preferences: const CustomerAuthPreferences(
            rememberMe: true,
            biometricEnabled: true,
          ),
          biometricAuthenticator:
              const _FakeBiometric(available: true, result: true),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('customer-auth-biometric-login')),
      findsOneWidget,
    );
    final biometricLogin =
        find.byKey(const ValueKey('customer-auth-biometric-login'));
    await tester.ensureVisible(biometricLogin);
    await tester.pumpAndSettle();
    await tester.tap(biometricLogin);
    await tester.pumpAndSettle();

    expect(completedToken, 'saved-token');
    expect(pending.takeCount, 1);
    expect(
      find.text(CustomerRouteLocations.retailCart(context)),
      findsOneWidget,
    );
  });

  testWidgets(
      'pending storage failure falls back to the safe auth return route',
      (tester) async {
    await tester.pumpWidget(
      _testApp(
        child: UnifiedCustomerAuthScreen(
          nextRoute: CustomerRoutePaths.marketplace,
          actionApi: const _SuccessfulActionApi(),
          onAuthenticated: (_, __) async {},
          pendingActionStore: const _ThrowingPendingActionStore(),
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const ValueKey('unified-auth-email')),
      'buyer@example.test',
    );
    await tester.enterText(
      find.byKey(const ValueKey('unified-auth-password')),
      'secret-pass',
    );
    await tester.tap(find.byKey(const ValueKey('unified-auth-submit')));
    await tester.pumpAndSettle();

    expect(find.text(CustomerRoutePaths.marketplace), findsOneWidget);
    expect(
      find.byKey(const ValueKey('unified-customer-auth-screen')),
      findsNothing,
    );
  });

  testWidgets(
      'pending storage timeout still resumes the safe auth return route',
      (tester) async {
    await tester.pumpWidget(
      _testApp(
        child: UnifiedCustomerAuthScreen(
          nextRoute: CustomerRoutePaths.marketplace,
          actionApi: const _SuccessfulActionApi(),
          onAuthenticated: (_, __) async {},
          pendingActionStore: const _HangingPendingActionStore(),
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const ValueKey('unified-auth-email')),
      'buyer@example.test',
    );
    await tester.enterText(
      find.byKey(const ValueKey('unified-auth-password')),
      'secret-pass',
    );
    await tester.tap(find.byKey(const ValueKey('unified-auth-submit')));
    await tester.pump(const Duration(milliseconds: 800));
    await tester.pumpAndSettle();

    expect(find.text(CustomerRoutePaths.marketplace), findsOneWidget);
    expect(
      find.byKey(const ValueKey('unified-customer-auth-screen')),
      findsNothing,
    );
  });

  testWidgets('biometric failure keeps normal login usable', (tester) async {
    final storage = _MemorySecureStore();
    final sessions = SecureCustomerSessionStore(storage: storage);
    await sessions.write(
      const CustomerSession.platformCustomer(accessToken: 'saved-token'),
    );

    await tester.pumpWidget(
      _testApp(
        child: UnifiedCustomerAuthScreen(
          nextRoute: CustomerRoutePaths.marketplace,
          actionApi: const _SuccessfulActionApi(),
          onAuthenticated: (_, __) async {},
          sessionStore: sessions,
          preferences: const CustomerAuthPreferences(
            rememberMe: true,
            biometricEnabled: true,
          ),
          biometricAuthenticator:
              const _FakeBiometric(available: true, result: false),
        ),
      ),
    );
    await tester.pumpAndSettle();

    final biometricLogin =
        find.byKey(const ValueKey('customer-auth-biometric-login'));
    await tester.ensureVisible(biometricLogin);
    await tester.pumpAndSettle();
    await tester.tap(biometricLogin);
    await tester.pumpAndSettle();

    expect(
      find.textContaining('Biometric verification'),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('unified-auth-submit')), findsOneWidget);
    expect(find.byKey(const ValueKey('unified-auth-email')), findsOneWidget);
  });

  testWidgets(
      'business entry shows reference login controls and persists remember biometric choice',
      (tester) async {
    final storage = _MemorySecureStore();
    final sessions = SecureCustomerSessionStore(storage: storage);
    CustomerAuthPreferences? completedPreferences;

    await tester.pumpWidget(
      _testApp(
        locale: const Locale('ar'),
        child: UnifiedCustomerAuthScreen(
          nextRoute: CustomerRoutePaths.b2bDashboard,
          actionApi: const _SuccessfulActionApi(),
          onAuthenticated: (_, preferences) async {
            completedPreferences = preferences;
          },
          sessionStore: sessions,
          biometricAuthenticator:
              const _FakeBiometric(available: true, result: true),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('c13-business-login-hero')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('customer-auth-remember-me')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('customer-auth-biometric-login')),
      findsOneWidget,
    );
    expect(find.text('نسيت كلمة المرور؟'), findsOneWidget);
    expect(find.text('تواصل معنا'), findsOneWidget);
    expect(find.text('الإصدار 1.0.54'), findsOneWidget);

    final rememberMe =
        find.byKey(const ValueKey('customer-auth-remember-me'));
    await tester.ensureVisible(rememberMe);
    await tester.pumpAndSettle();
    await tester.tap(rememberMe);
    await tester.pump();

    final biometric =
        find.byKey(const ValueKey('customer-auth-biometric-login'));
    await tester.ensureVisible(biometric);
    await tester.pumpAndSettle();
    await tester.tap(biometric);
    await tester.pump();

    await tester.enterText(
      find.byKey(const ValueKey('unified-auth-email')),
      'business@example.test',
    );
    await tester.enterText(
      find.byKey(const ValueKey('unified-auth-password')),
      'secret-pass',
    );
    await tester.ensureVisible(
      find.byKey(const ValueKey('unified-auth-submit')),
    );
    await tester.tap(find.byKey(const ValueKey('unified-auth-submit')));
    await tester.pumpAndSettle();

    expect(completedPreferences?.rememberMe, isTrue);
    expect(completedPreferences?.biometricEnabled, isTrue);
  });

  testWidgets(
      'business entry with saved biometric replaces credentials with unlock card',
      (tester) async {
    final storage = _MemorySecureStore();
    final sessions = SecureCustomerSessionStore(storage: storage);
    await sessions.write(
      const CustomerSession.platformCustomer(accessToken: 'saved-token'),
    );
    String? completedToken;

    await tester.pumpWidget(
      _testApp(
        locale: const Locale('ar'),
        child: UnifiedCustomerAuthScreen(
          nextRoute: CustomerRoutePaths.b2bDashboard,
          actionApi: const _SuccessfulActionApi(),
          onAuthenticated: (token, preferences) async {
            completedToken = token;
            expect(preferences.rememberMe, isTrue);
            expect(preferences.biometricEnabled, isTrue);
          },
          sessionStore: sessions,
          preferences: const CustomerAuthPreferences(
            rememberMe: true,
            biometricEnabled: true,
          ),
          biometricAuthenticator:
              const _FakeBiometric(available: true, result: true),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.byKey(const ValueKey('c13-saved-biometric-login-panel')),
      findsOneWidget,
    );
    expect(find.text('تسجيل الدخول بالبصمة'), findsOneWidget);
    expect(find.text('اضغط على البصمة لتسجيل الدخول'), findsOneWidget);
    expect(find.byKey(const ValueKey('unified-auth-email')), findsNothing);
    expect(find.byKey(const ValueKey('unified-auth-password')), findsNothing);
    expect(find.byKey(const ValueKey('unified-auth-submit')), findsNothing);
    expect(
      find.byKey(const ValueKey('c13-business-forgot-password')),
      findsNothing,
    );
    expect(
      find.byKey(const ValueKey('c13-business-contact-us')),
      findsNothing,
    );
    expect(
      find.byKey(const ValueKey('c13-saved-biometric-logout')),
      findsOneWidget,
    );

    final biometricLogin =
        find.byKey(const ValueKey('customer-auth-biometric-login'));
    await tester.ensureVisible(biometricLogin);
    await tester.pumpAndSettle();
    await tester.tap(biometricLogin);
    await tester.pumpAndSettle();

    expect(completedToken, 'saved-token');
  });

  testWidgets('saved biometric card exposes logout action', (tester) async {
    final storage = _MemorySecureStore();
    final sessions = SecureCustomerSessionStore(storage: storage);
    await sessions.write(
      const CustomerSession.platformCustomer(accessToken: 'saved-token'),
    );
    var logoutCalled = false;

    await tester.pumpWidget(
      _testApp(
        locale: const Locale('ar'),
        child: UnifiedCustomerAuthScreen(
          nextRoute: CustomerRoutePaths.b2bDashboard,
          actionApi: const _SuccessfulActionApi(),
          onAuthenticated: (_, __) async {},
          sessionStore: sessions,
          preferences: const CustomerAuthPreferences(
            rememberMe: true,
            biometricEnabled: true,
          ),
          biometricAuthenticator:
              const _FakeBiometric(available: true, result: true),
          onLogout: () async {
            logoutCalled = true;
          },
        ),
      ),
    );
    await tester.pumpAndSettle();

    final logout =
        find.byKey(const ValueKey('c13-saved-biometric-logout'));
    await tester.ensureVisible(logout);
    await tester.pumpAndSettle();
    await tester.tap(logout);
    await tester.pumpAndSettle();

    expect(logoutCalled, isTrue);
  });

  testWidgets('Arabic auth surface remains RTL and customer-only',
      (tester) async {
    await tester.pumpWidget(
      _testApp(
        locale: const Locale('ar'),
        child: UnifiedCustomerAuthScreen(
          nextRoute: CustomerRoutePaths.marketplace,
          actionApi: const _SuccessfulActionApi(),
          onAuthenticated: (_, __) async {},
        ),
      ),
    );
    await tester.pumpAndSettle();

    final context = tester.element(
      find.byKey(const ValueKey('unified-customer-auth-screen')),
    );
    expect(Directionality.of(context), TextDirection.rtl);
    expect(find.text('تسجيل دخول العميل'), findsWidgets);
    expect(find.text('دخول عميل الأعمال'), findsNothing);
  });
}

Widget _testApp({
  required Widget child,
  Locale locale = const Locale('en'),
}) =>
    MaterialApp(
      locale: locale,
      onGenerateRoute: (settings) => MaterialPageRoute<void>(
        settings: settings,
        builder: (_) => Text(settings.name ?? '', textDirection: TextDirection.ltr),
      ),
      home: AppTranslations(
        locale: locale,
        overrides: const {},
        child: child,
      ),
    );

class _SuccessfulActionApi implements CustomerActionApi {
  const _SuccessfulActionApi();

  @override
  Future<CustomerLoginResult> login({required String username}) async =>
      const CustomerLoginResult(
        token: 'platform-token',
        platformCustomer: true,
      );

  @override
  Future<void> logout() async {}

  @override
  Future<Object?> addCartItem({
    required int storeId,
    required int productId,
    required double quantity,
  }) async =>
      null;

  @override
  Future<Object?> checkout({
    required int addressId,
    int? storeId,
    String? paymentMethod,
    String? couponCode,
    required String idempotencyKey,
  }) async =>
      null;
}

class _FakeBiometric implements CustomerBiometricAuthenticator {
  const _FakeBiometric({
    required this.available,
    required this.result,
  });

  final bool available;
  final bool result;

  @override
  Future<bool> isAvailable() async => available;

  @override
  Future<bool> authenticate({required String reason}) async => result;
}

class _HangingPendingActionStore implements CustomerPendingActionStore {
  const _HangingPendingActionStore();

  @override
  Future<void> clear() => Future<void>.value();

  @override
  Future<CustomerPendingAction?> read() =>
      Completer<CustomerPendingAction?>().future;

  @override
  Future<CustomerPendingAction?> take() =>
      Completer<CustomerPendingAction?>().future;

  @override
  Future<void> write(CustomerPendingAction action) =>
      Completer<void>().future;
}

class _ThrowingPendingActionStore implements CustomerPendingActionStore {
  const _ThrowingPendingActionStore();

  @override
  Future<void> clear() async {}

  @override
  Future<CustomerPendingAction?> read() async =>
      throw StateError('secure storage unavailable');

  @override
  Future<CustomerPendingAction?> take() async =>
      throw StateError('secure storage unavailable');

  @override
  Future<void> write(CustomerPendingAction action) async =>
      throw StateError('secure storage unavailable');
}

class _MemoryPendingActionStore implements CustomerPendingActionStore {
  _MemoryPendingActionStore(this.value);

  CustomerPendingAction? value;
  int takeCount = 0;

  @override
  Future<void> clear() async {
    value = null;
  }

  @override
  Future<CustomerPendingAction?> read() async => value;

  @override
  Future<CustomerPendingAction?> take() async {
    takeCount++;
    final current = value;
    value = null;
    return current;
  }

  @override
  Future<void> write(CustomerPendingAction action) async {
    value = action;
  }
}

class _MemorySecureStore implements CustomerSecureKeyValueStore {
  final Map<String, String> _values = <String, String>{};

  @override
  Future<String?> read(String key) async => _values[key];

  @override
  Future<void> write(String key, String value) async {
    _values[key] = value;
  }

  @override
  Future<void> delete(String key) async {
    _values.remove(key);
  }
}
