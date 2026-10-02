import 'dart:async';
import 'dart:convert';

import '../auth/customer_session_store.dart';
import 'customer_commerce_context.dart';
import 'customer_routes.dart';

enum CustomerPendingActionKind {
  openProduct,
  addToCart,
  checkout,
}

extension CustomerPendingActionKindWireName on CustomerPendingActionKind {
  String get wireName => switch (this) {
        CustomerPendingActionKind.openProduct => 'open_product',
        CustomerPendingActionKind.addToCart => 'add_to_cart',
        CustomerPendingActionKind.checkout => 'checkout',
      };
}

/// Immutable auth-interruption envelope.
///
/// This object preserves the exact commerce scope and user intent across
/// sign-in/register. It is routing state only: the backend still validates
/// store/channel/product ownership and all checkout authorization.
class CustomerPendingAction {
  const CustomerPendingAction({
    required this.kind,
    required this.context,
    required this.nextLocation,
    required this.createdAtEpochMs,
    this.productId,
    this.variantId,
    this.quantity,
  })  : assert(createdAtEpochMs > 0),
        assert(productId == null || productId > 0),
        assert(variantId == null || variantId.length > 0),
        assert(variantId == null || variantId.length <= 128),
        assert(quantity == null || quantity > 0),
        assert(
          (kind != CustomerPendingActionKind.openProduct &&
                  kind != CustomerPendingActionKind.addToCart) ||
              productId != null,
        ),
        assert(
          kind != CustomerPendingActionKind.addToCart || quantity != null,
        );

  final CustomerPendingActionKind kind;
  final CustomerCommerceContext context;
  final String nextLocation;
  final int createdAtEpochMs;
  final int? productId;
  final String? variantId;
  final double? quantity;

  Map<String, Object?> toJson() => <String, Object?>{
        'version': 1,
        'kind': kind.wireName,
        'context': context.toJson(),
        'next': nextLocation,
        'created_at_ms': createdAtEpochMs,
        'product_id': productId,
        'variant_id': variantId,
        'quantity': quantity,
      };

  bool isExpired(
    DateTime now, {
    Duration maxAge = const Duration(hours: 24),
  }) {
    final createdAt =
        DateTime.fromMillisecondsSinceEpoch(createdAtEpochMs, isUtc: true);
    return now.toUtc().difference(createdAt) > maxAge;
  }

  static CustomerPendingAction? tryFromJson(
    Map<String, dynamic> payload, {
    DateTime? now,
    Duration maxAge = const Duration(hours: 24),
  }) {
    if (payload['version'] != 1) {
      return null;
    }

    final kind = _parseKind(payload['kind']?.toString());
    final rawContext = payload['context'];
    if (kind == null || rawContext is! Map) {
      return null;
    }

    final context = CustomerCommerceContext.tryFromJson(
      Map<String, dynamic>.from(rawContext),
    );
    if (context == null) {
      return null;
    }

    final next = payload['next'];
    if (next is! String ||
        safeCustomerContextReturnLocation(next, context: context) == null) {
      return null;
    }

    final createdAtMs = _positiveInt(payload['created_at_ms']);
    if (createdAtMs == null) {
      return null;
    }

    final productId = payload['product_id'] == null
        ? null
        : _positiveInt(payload['product_id']);
    if (payload['product_id'] != null && productId == null) {
      return null;
    }

    final variantRaw = payload['variant_id'];
    final variantId = variantRaw is String ? variantRaw.trim() : null;
    if (variantRaw != null &&
        (variantId == null || variantId.isEmpty || variantId.length > 128)) {
      return null;
    }

    final quantity = payload['quantity'] == null
        ? null
        : _positiveDouble(payload['quantity']);
    if (payload['quantity'] != null && quantity == null) {
      return null;
    }

    if ((kind == CustomerPendingActionKind.openProduct ||
            kind == CustomerPendingActionKind.addToCart) &&
        productId == null) {
      return null;
    }
    if (kind == CustomerPendingActionKind.addToCart && quantity == null) {
      return null;
    }

    final candidate = CustomerPendingAction(
      kind: kind,
      context: context,
      nextLocation: next,
      createdAtEpochMs: createdAtMs,
      productId: productId,
      variantId: variantId,
      quantity: quantity,
    );

    final clock = (now ?? DateTime.now()).toUtc();
    final createdAt =
        DateTime.fromMillisecondsSinceEpoch(createdAtMs, isUtc: true);
    if (createdAt.isAfter(clock.add(const Duration(minutes: 5))) ||
        candidate.isExpired(clock, maxAge: maxAge)) {
      return null;
    }

    return candidate;
  }

  static CustomerPendingActionKind? _parseKind(String? raw) => switch (raw) {
        'open_product' => CustomerPendingActionKind.openProduct,
        'add_to_cart' => CustomerPendingActionKind.addToCart,
        'checkout' => CustomerPendingActionKind.checkout,
        _ => null,
      };

  static int? _positiveInt(Object? raw) {
    final value = raw is int ? raw : int.tryParse(raw?.toString() ?? '');
    return value != null && value > 0 ? value : null;
  }

  static double? _positiveDouble(Object? raw) {
    final value = raw is num
        ? raw.toDouble()
        : double.tryParse(raw?.toString() ?? '');
    return value != null && value.isFinite && value > 0 ? value : null;
  }
}

abstract interface class CustomerPendingActionStore {
  Future<CustomerPendingAction?> read();

  Future<void> write(CustomerPendingAction action);

  /// Returns the pending action at most once for this store instance and
  /// removes it before the next queued consumer can observe it.
  Future<CustomerPendingAction?> take();

  Future<void> clear();
}

class SecureCustomerPendingActionStore implements CustomerPendingActionStore {
  SecureCustomerPendingActionStore({
    CustomerSecureKeyValueStore? storage,
    DateTime Function()? clock,
    Duration maxAge = const Duration(hours: 24),
  })  : _storage = storage ?? FlutterCustomerSecureKeyValueStore(),
        _clock = clock ?? DateTime.now,
        _maxAge = maxAge;

  static const _key = 'foodex.customer.pending_action.v1';

  final CustomerSecureKeyValueStore _storage;
  final DateTime Function() _clock;
  final Duration _maxAge;
  Future<void> _takeTail = Future<void>.value();

  @override
  Future<CustomerPendingAction?> read() async {
    final raw = await _storage.read(_key);
    if (raw == null || raw.trim().isEmpty) {
      return null;
    }

    try {
      final decoded = jsonDecode(raw);
      if (decoded is! Map) {
        await clear();
        return null;
      }

      final action = CustomerPendingAction.tryFromJson(
        Map<String, dynamic>.from(decoded),
        now: _clock(),
        maxAge: _maxAge,
      );
      if (action == null) {
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
  Future<void> write(CustomerPendingAction action) async {
    final normalized = CustomerPendingAction.tryFromJson(
      Map<String, dynamic>.from(action.toJson()),
      now: _clock(),
      maxAge: _maxAge,
    );
    if (normalized == null) {
      throw ArgumentError.value(
        action,
        'action',
        'must contain a fresh, safe same-context pending action',
      );
    }

    await _storage.write(_key, jsonEncode(normalized.toJson()));
  }

  @override
  Future<CustomerPendingAction?> take() {
    final completer = Completer<CustomerPendingAction?>();
    _takeTail = _takeTail.then((_) async {
      try {
        final action = await read();
        if (action != null) {
          await clear();
        }
        completer.complete(action);
      } catch (error, stackTrace) {
        completer.completeError(error, stackTrace);
      }
    });
    return completer.future;
  }

  @override
  Future<void> clear() => _storage.delete(_key);
}
