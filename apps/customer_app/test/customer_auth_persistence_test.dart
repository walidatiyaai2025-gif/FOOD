import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/auth/customer_auth_persistence.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/auth/customer_session_store.dart';

void main() {
  group('Customer auth persistence', () {
    test('remember me off never restores a persisted token', () async {
      final storage = _MemorySecureStore();
      final sessions = SecureCustomerSessionStore(storage: storage);
      final preferences =
          SecureCustomerAuthPreferenceStore(storage: storage);

      await sessions.write(
        const CustomerSession.platformCustomer(accessToken: 'token-off'),
      );
      await preferences.write(const CustomerAuthPreferences());

      final bootstrap = await restoreCustomerAuthBootstrap(
        sessionStore: sessions,
        preferenceStore: preferences,
      );

      expect(bootstrap.session.isAuthenticated, isFalse);
      expect(bootstrap.preferences.rememberMe, isFalse);
      expect(
        await storage.read('foodex.customer.auth_session.v1'),
        isNull,
      );
    });

    test('remember me on restores the platform customer session', () async {
      final storage = _MemorySecureStore();
      final sessions = SecureCustomerSessionStore(storage: storage);
      final preferences =
          SecureCustomerAuthPreferenceStore(storage: storage);

      await sessions.write(
        const CustomerSession.platformCustomer(accessToken: 'remembered-token'),
      );
      await preferences.write(
        const CustomerAuthPreferences(rememberMe: true),
      );

      final bootstrap = await restoreCustomerAuthBootstrap(
        sessionStore: sessions,
        preferenceStore: preferences,
      );

      expect(bootstrap.session.isAuthenticated, isTrue);
      expect(bootstrap.session.platformWide, isTrue);
      expect(bootstrap.session.accessToken, 'remembered-token');
    });

    test('biometric remember keeps token locked until device auth', () async {
      final storage = _MemorySecureStore();
      final sessions = SecureCustomerSessionStore(storage: storage);
      final preferences =
          SecureCustomerAuthPreferenceStore(storage: storage);

      await sessions.write(
        const CustomerSession.platformCustomer(accessToken: 'locked-token'),
      );
      await preferences.write(
        const CustomerAuthPreferences(
          rememberMe: true,
          biometricEnabled: true,
        ),
      );

      final bootstrap = await restoreCustomerAuthBootstrap(
        sessionStore: sessions,
        preferenceStore: preferences,
      );

      expect(bootstrap.session.isAuthenticated, isFalse);
      expect(bootstrap.preferences.biometricEnabled, isTrue);
      expect((await sessions.read())?.accessToken, 'locked-token');
    });

    test('legacy persisted sessions migrate to remembered sessions', () async {
      final storage = _MemorySecureStore();
      final sessions = SecureCustomerSessionStore(storage: storage);
      final preferences =
          SecureCustomerAuthPreferenceStore(storage: storage);

      await sessions.write(
        const CustomerSession.platformCustomer(accessToken: 'legacy-token'),
      );

      final bootstrap = await restoreCustomerAuthBootstrap(
        sessionStore: sessions,
        preferenceStore: preferences,
      );

      expect(bootstrap.session.accessToken, 'legacy-token');
      expect(bootstrap.preferences.rememberMe, isTrue);
      expect((await preferences.read())?.rememberMe, isTrue);
    });

    test('biometric preference cannot exist without remember me', () async {
      final storage = _MemorySecureStore();
      final preferences =
          SecureCustomerAuthPreferenceStore(storage: storage);

      await storage.write(
        'foodex.customer.auth_preferences.v1',
        '{"version":1,"remember_me":false,"biometric_enabled":true}',
      );

      final restored = await preferences.read();

      expect(restored, isNotNull);
      expect(restored!.rememberMe, isFalse);
      expect(restored.biometricEnabled, isFalse);
    });

    test('secure preference payload contains no credential material', () async {
      final storage = _MemorySecureStore();
      final preferences =
          SecureCustomerAuthPreferenceStore(storage: storage);

      await preferences.write(
        const CustomerAuthPreferences(
          rememberMe: true,
          biometricEnabled: true,
        ),
      );

      final raw =
          await storage.read('foodex.customer.auth_preferences.v1') ?? '';
      expect(raw, contains('"remember_me":true'));
      expect(raw, contains('"biometric_enabled":true'));
      expect(raw.toLowerCase(), isNot(contains('password')));
      expect(raw.toLowerCase(), isNot(contains('email')));
    });
  });
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
