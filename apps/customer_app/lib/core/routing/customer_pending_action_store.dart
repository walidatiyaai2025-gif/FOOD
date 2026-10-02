import 'dart:convert';

import '../auth/customer_session_store.dart';
import 'customer_commerce_context.dart';

enum CustomerPendingActionKind {
  viewProduct,
  addToCart,
  openCart,
  checkout,
}

class CustomerPendingActionEnvelope {
  const CustomerPendingActionEnvelope({
    required this.context,
    required this.kind,
    required this.source,
    required this.targetLocation,
    required this.createdAtEpochMs,
    this.productId,
    this.variantId,
    this.quantity,
  });

  final CustomerCommerceContext context;
  final CustomerPendingActionKind kind;
  final String source;
  final String targetLocation;
  final int createdAtEpochMs;
  final int? productId;
  final int? variantId;
  final double? quantity;

  bool isExpired(
    DateTime now, {
    Duration maxAge = const Duration(hours: 2),
  }) {
    final createdAt =
        DateTime.fromMillisecondsSinceEpoch(createdAtEpochMs, isUtc: true);
    final age = now.toUtc().difference(createdAt);
    return age.isNegative || age > maxAge;
  }

  Map<String, Object?> toJson() => <String, Object?>{
        'channel': context.channel.name,
        'store_id': context.storeId,
        'retail_receiver_id': context.retailReceiverId,
        'kind': kind.name,
        'source': source,
        'target_location': targetLocation,
        'created_at_epoch_ms': createdAtEpochMs,
        'product_id': productId,
        'variant_id': variantId,
        'quantity': quantity,
      };

  static CustomerPendingActionEnvelope? tryFromJson(Object? value) {
    if (value is! Map) return null;
    final payload = Map<String, dynamic>.from(value);

    final channel = switch (payload['channel']) {
      'retail' => CustomerCommerceChannel.retail,
      'wholesale' => CustomerCommerceChannel.wholesale,
      _ => null,
    };
    final storeId = _positiveInt(payload['store_id']);
    final receiverRaw = payload['retail_receiver_id'];
    final receiverId =
        receiverRaw == null ? null : _positiveInt(receiverRaw);
    final kind = switch (payload['kind']) {
      'viewProduct' => CustomerPendingActionKind.viewProduct,
      'addToCart' => CustomerPendingActionKind.addToCart,
      'openCart' => CustomerPendingActionKind.openCart,
      'checkout' => CustomerPendingActionKind.checkout,
      _ => null,
    };
    final source = payload['source'] is String
        ? (payload['source'] as String).trim()
        : '';
    final targetLocation = payload['target_location'] is String
        ? (payload['target_location'] as String).trim()
        : '';
    final createdAt = _positiveInt(payload['created_at_epoch_ms']);
    final productRaw = payload['product_id'];
    final productId = productRaw == null ? null : _positiveInt(productRaw);
    final variantRaw = payload['variant_id'];
    final variantId = variantRaw == null ? null : _positiveInt(variantRaw);
    final quantityRaw = payload['quantity'];
    final quantity = quantityRaw == null
        ? null
        : quantityRaw is num
            ? quantityRaw.toDouble()
            : double.tryParse(quantityRaw.toString());

    if (channel == null ||
        storeId == null ||
        kind == null ||
        source.isEmpty ||
        targetLocation.isEmpty ||
        createdAt == null ||
        (receiverRaw != null && receiverId == null) ||
        (productRaw != null && productId == null) ||
        (variantRaw != null && variantId == null) ||
        (quantityRaw != null && (quantity == null || quantity <= 0))) {
      return null;
    }

    final context = CustomerCommerceContext(
      channel: channel,
      storeId: storeId,
      retailReceiverId: receiverId,
    );
    final targetContext =
        CustomerCommerceContext.tryParseLocation(targetLocation);
    if (targetContext == null || !context.sameScope(targetContext)) {
      return null;
    }

    return CustomerPendingActionEnvelope(
      context: context,
      kind: kind,
      source: source,
      targetLocation: targetLocation,
      createdAtEpochMs: createdAt,
      productId: productId,
      variantId: variantId,
      quantity: quantity,
    );
  }
}

abstract interface class CustomerPendingActionStore {
  Future<CustomerPendingActionEnvelope?> peek();

  Future<void> write(CustomerPendingActionEnvelope action);

  Future<CustomerPendingActionEnvelope?> consume();

  Future<void> clear();
}

class SecureCustomerPendingActionStore
    implements CustomerPendingActionStore {
  SecureCustomerPendingActionStore({
    CustomerSecureKeyValueStore? storage,
    this.maxAge = const Duration(hours: 2),
    DateTime Function()? now,
  })  : _storage = storage ?? FlutterCustomerSecureKeyValueStore(),
        _now = now ?? DateTime.now;

  static const _key = 'foodex.customer.pending_action.v1';
  static const _schemaVersion = 1;

  final CustomerSecureKeyValueStore _storage;
  final Duration maxAge;
  final DateTime Function() _now;

  @override
  Future<CustomerPendingActionEnvelope?> peek() async {
    final raw = await _storage.read(_key);
    if (raw == null || raw.trim().isEmpty) return null;

    try {
      final decoded = jsonDecode(raw);
      if (decoded is! Map) {
        await clear();
        return null;
      }
      final payload = Map<String, dynamic>.from(decoded);
      final action = CustomerPendingActionEnvelope.tryFromJson(
        payload['action'],
      );
      if (payload['version'] != _schemaVersion ||
          action == null ||
          action.isExpired(_now(), maxAge: maxAge)) {
        await clear();
        return null;
      }
      return action;
    } on FormatException {
      await clear();
      return null;
    } on TypeError {
      await clear();
      return null;
    }
  }

  @override
  Future<void> write(CustomerPendingActionEnvelope action) async {
    if (CustomerPendingActionEnvelope.tryFromJson(action.toJson()) == null ||
        action.isExpired(_now(), maxAge: maxAge)) {
      throw ArgumentError.value(
        action.targetLocation,
        'action',
        'pending action must be valid, same-scope, and current',
      );
    }
    await _storage.write(
      _key,
      jsonEncode(<String, Object?>{
        'version': _schemaVersion,
        'action': action.toJson(),
      }),
    );
  }

  @override
  Future<CustomerPendingActionEnvelope?> consume() async {
    final action = await peek();
    if (action == null) return null;
    await clear();
    return action;
  }

  @override
  Future<void> clear() => _storage.delete(_key);
}

int? _positiveInt(Object? raw) {
  final value = raw is int ? raw : int.tryParse(raw?.toString() ?? '');
  return value != null && value > 0 ? value : null;
}
