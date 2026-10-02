import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/auth/customer_session_store.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context_store.dart';
import 'package:foodex_customer_app/core/routing/customer_pending_action.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';

void main() {
  group('Customer Commerce V4 context', () {
    test('retail banner provenance round-trips without becoming auth identity',
        () {
      const context = CustomerCommerceContext(
        channel: CustomerCommerceChannel.retail,
        storeId: 17,
        source: CustomerCommerceSource.retailBanner,
        entryPlacementId: 44,
      );

      final location = CustomerRouteLocations.retailProduct(context, 901);
      final restored = CustomerCommerceContext.tryParseLocation(location);

      expect(
        location,
        '/retail/17/products/901'
        '?channel=retail&store_id=17&source=retail_banner&placement_id=44',
      );
      expect(restored, context);
      expect(restored!.sameScope(context), isTrue);
      expect(restored.sameEntry(context), isTrue);
    });

    test('same store can preserve scope while changing safe entry provenance',
        () {
      const banner = CustomerCommerceContext(
        channel: CustomerCommerceChannel.retail,
        storeId: 17,
        source: CustomerCommerceSource.retailBanner,
        entryPlacementId: 44,
      );
      const search = CustomerCommerceContext(
        channel: CustomerCommerceChannel.retail,
        storeId: 17,
        source: CustomerCommerceSource.search,
      );

      expect(banner.sameScope(search), isTrue);
      expect(banner.sameEntry(search), isFalse);
    });

    test('unknown source and placement without source fail closed', () {
      expect(
        CustomerCommerceContext.tryParseLocation(
          '/retail/17/home?channel=retail&store_id=17&source=forged',
        ),
        isNull,
      );
      expect(
        CustomerCommerceContext.tryParseLocation(
          '/retail/17/home?channel=retail&store_id=17&placement_id=44',
        ),
        isNull,
      );
    });

    test('secure context store migrates v1 and writes provenance-aware v2',
        () async {
      final storage = _MemorySecureStore();
      await storage.write(
        'foodex.customer.commerce_context.v1',
        '{"version":1,"channel":"retail","store_id":17,'
        '"retail_receiver_id":null}',
      );

      final store = SecureCustomerCommerceContextStore(storage: storage);
      final legacy = await store.read();
      expect(legacy, isNotNull);
      expect(legacy!.storeId, 17);
      expect(legacy.source, isNull);

      const current = CustomerCommerceContext(
        channel: CustomerCommerceChannel.retail,
        storeId: 17,
        source: CustomerCommerceSource.retailBanner,
        entryPlacementId: 44,
      );
      await store.write(current);

      final restored = await store.read();
      expect(restored, current);
      expect(
        await storage.read('foodex.customer.commerce_context.v1'),
        contains('"version":2'),
      );
    });
  });

  group('Customer pending auth action', () {
    final now = DateTime.utc(2026, 10, 2, 12, 30);

    test('preserves source + store + product + variant + quantity exactly',
        () async {
      final storage = _MemorySecureStore();
      final store = SecureCustomerPendingActionStore(
        storage: storage,
        clock: () => now,
      );
      const context = CustomerCommerceContext(
        channel: CustomerCommerceChannel.retail,
        storeId: 17,
        source: CustomerCommerceSource.retailBanner,
        entryPlacementId: 44,
      );
      final action = CustomerPendingAction(
        kind: CustomerPendingActionKind.addToCart,
        context: context,
        nextLocation: CustomerRouteLocations.retailCart(context),
        createdAtEpochMs: now.millisecondsSinceEpoch,
        productId: 901,
        variantId: 'XL-BLACK',
        quantity: 3,
      );

      await store.write(action);
      final restored = await store.read();

      expect(restored, isNotNull);
      expect(restored!.kind, CustomerPendingActionKind.addToCart);
      expect(restored.context, context);
      expect(restored.productId, 901);
      expect(restored.variantId, 'XL-BLACK');
      expect(restored.quantity, 3);
      expect(
        restored.nextLocation,
        CustomerRouteLocations.retailCart(context),
      );
    });

    test('take consumes the pending action exactly once', () async {
      final storage = _MemorySecureStore();
      final store = SecureCustomerPendingActionStore(
        storage: storage,
        clock: () => now,
      );
      const context = CustomerCommerceContext(
        channel: CustomerCommerceChannel.wholesale,
        storeId: 2,
        source: CustomerCommerceSource.wholesaleEntry,
      );
      final action = CustomerPendingAction(
        kind: CustomerPendingActionKind.checkout,
        context: context,
        nextLocation: CustomerRouteLocations.wholesaleCart(context),
        createdAtEpochMs: now.millisecondsSinceEpoch,
      );

      await store.write(action);

      final first = await store.take();
      final second = await store.take();

      expect(first, isNotNull);
      expect(first!.kind, CustomerPendingActionKind.checkout);
      expect(second, isNull);
      expect(
        await storage.read('foodex.customer.pending_action.v1'),
        isNull,
      );
    });

    test('cross-store forged resume target is rejected and cleared', () async {
      final storage = _MemorySecureStore();
      final store = SecureCustomerPendingActionStore(
        storage: storage,
        clock: () => now,
      );
      await storage.write(
        'foodex.customer.pending_action.v1',
        jsonEncode(<String, Object?>{
          'version': 1,
          'kind': 'add_to_cart',
          'context': <String, Object?>{
            'channel': 'retail',
            'store_id': 17,
            'retail_receiver_id': null,
            'source': 'retail_banner',
            'placement_id': 44,
          },
          'next': '/cart?channel=retail&store_id=18',
          'created_at_ms': now.millisecondsSinceEpoch,
          'product_id': 901,
          'variant_id': 'XL-BLACK',
          'quantity': 3,
        }),
      );

      expect(await store.read(), isNull);
      expect(
        await storage.read('foodex.customer.pending_action.v1'),
        isNull,
      );
    });

    test('expired pending action is a stale target and fails closed', () async {
      final storage = _MemorySecureStore();
      final store = SecureCustomerPendingActionStore(
        storage: storage,
        clock: () => now,
        maxAge: const Duration(minutes: 30),
      );
      const context = CustomerCommerceContext(
        channel: CustomerCommerceChannel.retail,
        storeId: 17,
      );
      final stale = CustomerPendingAction(
        kind: CustomerPendingActionKind.checkout,
        context: context,
        nextLocation: CustomerRouteLocations.retailCheckout(context),
        createdAtEpochMs:
            now.subtract(const Duration(hours: 1)).millisecondsSinceEpoch,
      );

      await storage.write(
        'foodex.customer.pending_action.v1',
        jsonEncode(stale.toJson()),
      );

      expect(await store.read(), isNull);
      expect(
        await storage.read('foodex.customer.pending_action.v1'),
        isNull,
      );
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
