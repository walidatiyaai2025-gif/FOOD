import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/app.dart';
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
    );
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

void main() {
  testWidgets('login reaches authenticated shell and persists securely through store contract', (tester) async {
    final store = _MemoryStore();
    await tester.pumpWidget(
      FoodexVanApp(
        locale: const Locale('en'),
        authRepository: _Auth(),
        sessionStore: store,
      ),
    );
    await tester.pumpAndSettle();

    await tester.enterText(find.byKey(const Key('van-login-email')), 'van@example.test');
    await tester.enterText(find.byKey(const Key('van-login-password')), 'password');
    await tester.tap(find.byKey(const Key('van-login-submit')));
    await tester.pumpAndSettle();

    expect(find.text('Authorized Van'), findsOneWidget);
    expect(find.text('Overview'), findsOneWidget);
    expect(store.value?.token, 'token');
  });
}
