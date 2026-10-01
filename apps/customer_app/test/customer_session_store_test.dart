import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/auth/customer_session_store.dart';

class _MemorySecureStore implements CustomerSecureKeyValueStore {
  final Map<String, String> values = <String, String>{};
  int deletes = 0;

  @override
  Future<void> delete(String key) async {
    deletes++;
    values.remove(key);
  }

  @override
  Future<String?> read(String key) async => values[key];

  @override
  Future<void> write(String key, String value) async {
    values[key] = value;
  }
}

void main() {
  group('SecureCustomerSessionStore', () {
    test('restores B2C authenticated session after restart', () async {
      final storage = _MemorySecureStore();
      final store = SecureCustomerSessionStore(storage: storage);

      await store.write(
        const CustomerSession.authenticated(
          CustomerChannel.b2c,
          accessToken: 'customer-token',
        ),
      );

      final restored = await restoreCustomerSession(
        SecureCustomerSessionStore(storage: storage),
      );

      expect(restored.isAuthenticated, isTrue);
      expect(restored.channel, CustomerChannel.b2c);
      expect(restored.accessToken, 'customer-token');
      expect(restored.b2bRetailStoreId, isNull);
      expect(restored.platformWide, isFalse);
    });

    test('preserves wholesale retail context without crossing channel identity',
        () async {
      final storage = _MemorySecureStore();
      final store = SecureCustomerSessionStore(storage: storage);
      final scoped = const CustomerSession.authenticated(
        CustomerChannel.b2c,
        accessToken: 'retail-manager-token',
      ).asB2bRetailContext(77);

      await store.write(scoped);
      final restored = await restoreCustomerSession(store);

      expect(restored.channel, CustomerChannel.b2c);
      expect(restored.b2bRetailStoreId, 77);
      expect(restored.platformWide, isFalse);
    });

    test('preserves platform-wide B2B session', () async {
      final storage = _MemorySecureStore();
      final store = SecureCustomerSessionStore(storage: storage);

      await store.write(
        const CustomerSession.authenticated(
          CustomerChannel.b2b,
          accessToken: 'platform-token',
          platformWide: true,
        ),
      );
      final restored = await restoreCustomerSession(store);

      expect(restored.channel, CustomerChannel.b2b);
      expect(restored.platformWide, isTrue);
      expect(restored.accessToken, 'platform-token');
    });

    test('guest write clears durable credentials', () async {
      final storage = _MemorySecureStore();
      final store = SecureCustomerSessionStore(storage: storage);

      await store.write(
        const CustomerSession.authenticated(
          CustomerChannel.b2c,
          accessToken: 'token',
        ),
      );
      await store.write(const CustomerSession.guest());

      expect(await store.read(), isNull);
      expect(storage.deletes, 1);
    });

    test('invalid persisted payload is rejected and deleted', () async {
      final storage = _MemorySecureStore();
      final store = SecureCustomerSessionStore(storage: storage);
      storage.values['foodex.customer.auth_session.v1'] =
          '{"version":1,"channel":"invalid","access_token":"token"}';

      final restored = await restoreCustomerSession(store);

      expect(restored.isAuthenticated, isFalse);
      expect(storage.deletes, 1);
      expect(storage.values, isEmpty);
    });
  });
}
