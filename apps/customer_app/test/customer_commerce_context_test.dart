import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/auth/customer_session_store.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context_store.dart';

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
  group('CustomerCommerceContext', () {
    test('keeps authentication identity separate from Retail scope', () {
      const identity = CustomerSession.platformCustomer(
        accessToken: 'platform-token',
      );
      const context = CustomerCommerceContext.retail(storeId: 17);

      expect(identity.channel, CustomerChannel.b2c);
      expect(identity.platformWide, isTrue);
      expect(context.channel, CustomerChannel.b2c);
      expect(context.storeId, 17);
    });

    test('round-trips Wholesale receiver context through query contract', () {
      const context = CustomerCommerceContext.wholesale(
        storeId: 1,
        retailReceiverId: 77,
      );

      final restored = CustomerCommerceContext.tryParseQuery(
        context.toQueryParameters(),
      );

      expect(restored, context);
      expect(restored?.scopeKey, 'b2b:1:77');
    });

    test('restores selected commerce context after restart', () async {
      final storage = _MemorySecureStore();
      final firstStore = SecureCustomerCommerceContextStore(storage: storage);

      await firstStore.write(
        const CustomerCommerceContext.retail(storeId: 23),
      );

      final restored = await restoreCustomerCommerceContext(
        SecureCustomerCommerceContextStore(storage: storage),
      );

      expect(
        restored,
        const CustomerCommerceContext.retail(storeId: 23),
      );
    });

    test('rejects malformed persisted context instead of guessing a store',
        () async {
      final storage = _MemorySecureStore();
      storage.values['foodex.customer.commerce_context.v1'] =
          '{"version":1,"channel":"b2c","store_id":0}';

      final restored = await restoreCustomerCommerceContext(
        SecureCustomerCommerceContextStore(storage: storage),
      );

      expect(restored, isNull);
      expect(storage.values, isEmpty);
      expect(storage.deletes, 1);
    });
  });
}
