import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/auth/customer_guest_cart_session.dart';
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
  group('CustomerGuestCartSession', () {
    test('persists independent guest tokens per Retail store across restart',
        () async {
      final storage = _MemorySecureStore();
      final first = CustomerGuestCartSession(
        store: SecureCustomerGuestCartSessionStore(storage: storage),
      );

      await first.restore();
      await first.captureToken(7, 'guest-store-7');
      await first.captureToken(8, 'guest-store-8');

      final restored = CustomerGuestCartSession(
        store: SecureCustomerGuestCartSessionStore(storage: storage),
      );
      await restored.restore();

      expect(restored.tokenForStore(7), 'guest-store-7');
      expect(restored.tokenForStore(8), 'guest-store-8');
      expect(restored.tokenForStore(9), isNull);
      expect(restored.activeStoreId, 8);
      expect(restored.activeToken, 'guest-store-8');
    });

    test('switching active store never falls back to another store token',
        () async {
      final storage = _MemorySecureStore();
      final session = CustomerGuestCartSession(
        store: SecureCustomerGuestCartSessionStore(storage: storage),
      );

      await session.restore();
      await session.captureToken(7, 'guest-store-7');

      expect(() => session.activateStore(8), throwsStateError);
      expect(session.tokenForStore(8), isNull);
      expect(session.activeStoreId, 7);
    });

    test('clearing one store preserves the other store cart identity', () async {
      final storage = _MemorySecureStore();
      final session = CustomerGuestCartSession(
        store: SecureCustomerGuestCartSessionStore(storage: storage),
      );

      await session.restore();
      await session.captureToken(7, 'guest-store-7');
      await session.captureToken(8, 'guest-store-8');
      await session.clearStore(7);

      final restored = CustomerGuestCartSession(
        store: SecureCustomerGuestCartSessionStore(storage: storage),
      );
      await restored.restore();

      expect(restored.tokenForStore(7), isNull);
      expect(restored.tokenForStore(8), 'guest-store-8');
    });

    test('invalid persisted guest token map is rejected and cleared', () async {
      final storage = _MemorySecureStore();
      storage.values['foodex.customer.guest_cart_session.v1'] =
          '{"version":1,"active_store_id":7,"tokens":{"8":"other"}}';

      final session = CustomerGuestCartSession(
        store: SecureCustomerGuestCartSessionStore(storage: storage),
      );
      await session.restore();

      expect(session.snapshot().tokens, isEmpty);
      expect(session.activeStoreId, isNull);
      expect(storage.values, isEmpty);
      expect(storage.deletes, 1);
    });
  });
}
