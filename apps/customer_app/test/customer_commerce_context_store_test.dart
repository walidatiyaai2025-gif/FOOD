import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/auth/customer_session_store.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context_store.dart';
import 'package:foodex_customer_app/core/routing/customer_pending_action_store.dart';

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
  test('commerce context persists independently from authentication identity',
      () async {
    final storage = _MemorySecureStore();
    final store = SecureCustomerCommerceContextStore(storage: storage);
    const context = CustomerCommerceContext(
      channel: CustomerCommerceChannel.retail,
      storeId: 17,
      retailReceiverId: 8,
    );

    await store.write(context);
    final restored =
        await SecureCustomerCommerceContextStore(storage: storage).read();

    expect(restored, context);
  });

  test('pending action round-trips and consumes exactly once', () async {
    final storage = _MemorySecureStore();
    final now = DateTime.utc(2026, 10, 2, 12, 0);
    final store = SecureCustomerPendingActionStore(
      storage: storage,
      now: () => now,
    );
    const context = CustomerCommerceContext(
      channel: CustomerCommerceChannel.wholesale,
      storeId: 70,
    );
    final action = CustomerPendingActionEnvelope(
      context: context,
      kind: CustomerPendingActionKind.addToCart,
      source: 'platform_marketplace',
      targetLocation:
          '/b2b/products/42?channel=wholesale&store_id=70',
      createdAtEpochMs: now.millisecondsSinceEpoch,
      productId: 42,
      variantId: 3,
      quantity: 2,
    );

    await store.write(action);

    final peeked = await store.peek();
    expect(peeked?.context, context);
    expect(peeked?.productId, 42);
    expect(peeked?.variantId, 3);
    expect(peeked?.quantity, 2);

    final consumed = await store.consume();
    expect(consumed?.targetLocation, action.targetLocation);
    expect(await store.consume(), isNull);
  });

  test('cross-store target is rejected and cleared', () async {
    final storage = _MemorySecureStore();
    final now = DateTime.utc(2026, 10, 2, 12, 0);
    final store = SecureCustomerPendingActionStore(
      storage: storage,
      now: () => now,
    );
    await storage.write(
      'foodex.customer.pending_action.v1',
      jsonEncode({
        'version': 1,
        'action': {
          'channel': 'retail',
          'store_id': 7,
          'kind': 'checkout',
          'source': 'retail_banner',
          'target_location':
              '/retail/8/home?channel=retail&store_id=8',
          'created_at_epoch_ms': now.millisecondsSinceEpoch,
        },
      }),
    );

    expect(await store.peek(), isNull);
    expect(storage.values, isEmpty);
    expect(storage.deletes, 1);
  });

  test('stale pending action is rejected before persistence', () async {
    final storage = _MemorySecureStore();
    final now = DateTime.utc(2026, 10, 2, 12, 0);
    final store = SecureCustomerPendingActionStore(
      storage: storage,
      maxAge: const Duration(minutes: 30),
      now: () => now,
    );
    final action = CustomerPendingActionEnvelope(
      context: const CustomerCommerceContext(
        channel: CustomerCommerceChannel.retail,
        storeId: 7,
      ),
      kind: CustomerPendingActionKind.checkout,
      source: 'cart',
      targetLocation:
          '/checkout/address-payment?channel=retail&store_id=7',
      createdAtEpochMs:
          now.subtract(const Duration(hours: 1)).millisecondsSinceEpoch,
    );

    await expectLater(store.write(action), throwsArgumentError);
    expect(storage.values, isEmpty);
  });
}
