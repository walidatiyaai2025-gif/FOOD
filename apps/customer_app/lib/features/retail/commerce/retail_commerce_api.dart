import 'dart:async';
import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

import '../../../core/api/b2c_account_api.dart';
import '../../../core/api/customer_action_api.dart';
import '../../../core/routing/customer_commerce_context_store.dart';

class RetailCommerceException implements Exception {
  const RetailCommerceException(
    this.code, {
    this.fieldErrors = const <String, List<String>>{},
  });

  final String code;
  final Map<String, List<String>> fieldErrors;

  factory RetailCommerceException.fromCustomerAction(
    CustomerActionException error,
  ) =>
      RetailCommerceException(
        error.code,
        fieldErrors: error.fieldErrors,
      );
}

enum RetailCartSyncState { synced, savedLocally, waitingForNetwork, syncing }

class RetailCartItem {
  const RetailCartItem({
    required this.id,
    required this.productId,
    required this.name,
    required this.quantity,
    required this.lineTotal,
    required this.isAvailable,
  });

  final int id;
  final int productId;
  final String name;
  final double quantity;
  final double lineTotal;
  final bool isAvailable;

  factory RetailCartItem.fromMap(Map<String, dynamic> map) {
    final product = _asMap(map['product']);
    return RetailCartItem(
      id: _asInt(map['id']),
      productId: _asInt(product['id']),
      name: product['name']?.toString() ?? '',
      quantity: _asDouble(map['quantity']),
      lineTotal: _asDouble(map['line_total']),
      isAvailable: map['is_available'] != false,
    );
  }
}

class RetailCartSnapshot {
  const RetailCartSnapshot({
    required this.storeId,
    required this.currency,
    required this.items,
    required this.subtotal,
    required this.grandTotal,
    required this.hasUnavailableItems,
    this.syncState = RetailCartSyncState.synced,
  });

  final int storeId;
  final String currency;
  final List<RetailCartItem> items;
  final double subtotal;
  final double grandTotal;
  final bool hasUnavailableItems;
  final RetailCartSyncState syncState;

  RetailCartSnapshot withSyncState(RetailCartSyncState value) =>
      RetailCartSnapshot(
        storeId: storeId,
        currency: currency,
        items: items,
        subtotal: subtotal,
        grandTotal: grandTotal,
        hasUnavailableItems: hasUnavailableItems,
        syncState: value,
      );

  RetailCartSnapshot applyPending(List<RetailCartMutation> mutations) {
    var nextItems = items.toList(growable: true);
    for (final mutation in mutations) {
      final index = nextItems.indexWhere((item) => item.id == mutation.itemId);
      if (mutation.operation == RetailCartMutationOperation.remove) {
        if (index >= 0) nextItems.removeAt(index);
        continue;
      }
      if (index < 0 || mutation.quantity == null) continue;
      final current = nextItems[index];
      final unitPrice =
          current.quantity <= 0 ? 0.0 : current.lineTotal / current.quantity;
      nextItems[index] = RetailCartItem(
        id: current.id,
        productId: current.productId,
        name: current.name,
        quantity: mutation.quantity!,
        lineTotal: unitPrice * mutation.quantity!,
        isAvailable: current.isAvailable,
      );
    }
    final nextSubtotal = nextItems.fold<double>(
      0,
      (sum, item) => sum + item.lineTotal,
    );
    final delta = nextSubtotal - subtotal;
    return RetailCartSnapshot(
      storeId: storeId,
      currency: currency,
      items: List<RetailCartItem>.unmodifiable(nextItems),
      subtotal: nextSubtotal,
      grandTotal: (grandTotal + delta).clamp(0, double.infinity).toDouble(),
      hasUnavailableItems: nextItems.any((item) => !item.isAvailable),
      syncState: mutations.isEmpty
          ? RetailCartSyncState.synced
          : RetailCartSyncState.waitingForNetwork,
    );
  }

  factory RetailCartSnapshot.fromPayload(
    Object? value, {
    required int expectedStoreId,
  }) {
    final map = _asMap(value);
    final storeId = _asInt(map['store_id']);
    if (storeId != expectedStoreId) {
      throw const RetailCommerceException('cart_store_mismatch');
    }

    final quote = _asMap(map['quote']);
    return RetailCartSnapshot(
      storeId: storeId,
      currency: map['currency']?.toString() ?? 'KWD',
      items: _asList(map['items'])
          .map((item) => RetailCartItem.fromMap(_asMap(item)))
          .toList(growable: false),
      subtotal: _asDouble(map['subtotal']),
      grandTotal: _asDouble(
        quote['grand_total'],
        fallback: _asDouble(map['subtotal']),
      ),
      hasUnavailableItems: map['has_unavailable_items'] == true,
    );
  }
}

class RetailCheckoutAddress {
  const RetailCheckoutAddress({
    required this.id,
    required this.label,
    required this.line1,
    required this.city,
    required this.isDefault,
  });

  final int id;
  final String label;
  final String line1;
  final String city;
  final bool isDefault;

  factory RetailCheckoutAddress.fromMap(Map<String, dynamic> map) =>
      RetailCheckoutAddress(
        id: _asInt(map['id']),
        label: map['label']?.toString() ?? '',
        line1: map['line1']?.toString() ?? '',
        city: map['city']?.toString() ?? '',
        isDefault: map['is_default'] == true,
      );
}

class RetailCheckoutOptions {
  const RetailCheckoutOptions({
    required this.storeId,
    required this.addresses,
    required this.paymentMethods,
  });

  final int storeId;
  final List<RetailCheckoutAddress> addresses;
  final List<String> paymentMethods;

  factory RetailCheckoutOptions.fromPayload(
    Object? value, {
    required int expectedStoreId,
  }) {
    final map = _asMap(value);
    final storeId = _asInt(map['store_id']);
    if (storeId != expectedStoreId) {
      throw const RetailCommerceException('checkout_store_mismatch');
    }

    return RetailCheckoutOptions(
      storeId: storeId,
      addresses: _asList(map['addresses'])
          .map((item) => RetailCheckoutAddress.fromMap(_asMap(item)))
          .toList(growable: false),
      paymentMethods: _asList(map['payment_methods'])
          .map((item) => item.toString().trim())
          .where((item) => item.isNotEmpty)
          .toList(growable: false),
    );
  }
}

class RetailCreatedOrder {
  const RetailCreatedOrder({
    required this.id,
    required this.storeId,
  });

  final int id;
  final int storeId;

  factory RetailCreatedOrder.fromPayload(
    Object? value, {
    required int expectedStoreId,
  }) {
    final map = _asMap(value);
    final id = _asInt(map['id']);
    final storeId = _asInt(map['store_id'], fallback: expectedStoreId);
    if (id <= 0 || storeId != expectedStoreId) {
      throw const RetailCommerceException('invalid_checkout_response');
    }
    return RetailCreatedOrder(id: id, storeId: storeId);
  }
}

abstract interface class RetailCheckoutOptionsApi {
  Future<RetailCheckoutOptions> load({required int storeId});
}

class HttpRetailCheckoutOptionsApi implements RetailCheckoutOptionsApi {
  HttpRetailCheckoutOptionsApi({
    required this.baseUrl,
    required this.token,
    http.Client? client,
  }) : _client = client ?? http.Client();

  final String baseUrl;
  final String token;
  final http.Client _client;

  @override
  Future<RetailCheckoutOptions> load({required int storeId}) async {
    if (storeId <= 0) {
      throw const RetailCommerceException('store_required');
    }
    if (token.trim().isEmpty) {
      throw const RetailCommerceException('authentication_required');
    }

    final uri = Uri.parse('$baseUrl/api/v1/checkout/options').replace(
      queryParameters: {'store_id': '$storeId'},
    );
    final response = await _client.get(
      uri,
      headers: {
        'Accept': 'application/json',
        'Authorization': 'Bearer $token',
        'X-FOODEX-Store-ID': '$storeId',
        'X-FOODEX-Customer-Domain': 'b2c',
      },
    );

    final body = _decodeResponse(response);
    return RetailCheckoutOptions.fromPayload(
      body,
      expectedStoreId: storeId,
    );
  }
}

abstract interface class RetailCommerceApi {
  Future<RetailCartSnapshot> loadCart({required int storeId});

  Future<RetailCartSnapshot> mergeGuestCartAfterAuthentication({
    required int storeId,
  });

  Future<RetailCartSnapshot> updateQuantity({
    required int storeId,
    required int itemId,
    required double quantity,
  });

  Future<RetailCartSnapshot> removeItem({
    required int storeId,
    required int itemId,
  });

  Future<RetailCheckoutOptions> checkoutOptions({required int storeId});

  Future<RetailCreatedOrder> submitCheckout({
    required int storeId,
    required int addressId,
    required String paymentMethod,
    String? couponCode,
    required String idempotencyKey,
  });
}

enum RetailCartMutationOperation { update, remove }

class RetailCartMutation {
  const RetailCartMutation({
    required this.operation,
    required this.itemId,
    this.quantity,
  });

  final RetailCartMutationOperation operation;
  final int itemId;
  final double? quantity;

  Map<String, Object?> toJson() => <String, Object?>{
        'operation': operation.name,
        'item_id': itemId,
        if (quantity != null) 'quantity': quantity,
      };

  static RetailCartMutation? fromJson(Object? value) {
    if (value is! Map) return null;
    final itemId = _asInt(value['item_id']);
    if (itemId <= 0) return null;
    final operation = value['operation']?.toString() == 'remove'
        ? RetailCartMutationOperation.remove
        : RetailCartMutationOperation.update;
    return RetailCartMutation(
      operation: operation,
      itemId: itemId,
      quantity: operation == RetailCartMutationOperation.update
          ? _asDouble(value['quantity'])
          : null,
    );
  }
}

abstract interface class RetailCartMutationQueue {
  Future<List<RetailCartMutation>> read(int storeId);
  Future<void> upsert(int storeId, RetailCartMutation mutation);
  Future<void> remove(int storeId, int itemId);
  Future<void> clear(int storeId);
}

class SecureRetailCartMutationQueue implements RetailCartMutationQueue {
  SecureRetailCartMutationQueue({FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;
  final Map<int, List<RetailCartMutation>> _fallback =
      <int, List<RetailCartMutation>>{};

  String _key(int storeId) => 'foodex.retail.cart.queue.v1.$storeId';

  @override
  Future<List<RetailCartMutation>> read(int storeId) async {
    try {
      final raw = await _storage.read(key: _key(storeId));
      if (raw == null || raw.isEmpty) return _fallback[storeId] ?? const [];
      final decoded = jsonDecode(raw);
      if (decoded is! List) return const [];
      return decoded
          .map(RetailCartMutation.fromJson)
          .whereType<RetailCartMutation>()
          .toList(growable: false);
    } catch (_) {
      return List<RetailCartMutation>.unmodifiable(
        _fallback[storeId] ?? const <RetailCartMutation>[],
      );
    }
  }

  @override
  Future<void> upsert(int storeId, RetailCartMutation mutation) async {
    final rows = (await read(storeId)).toList(growable: true)
      ..removeWhere((row) => row.itemId == mutation.itemId)
      ..add(mutation);
    _fallback[storeId] = rows;
    await _persist(storeId, rows);
  }

  @override
  Future<void> remove(int storeId, int itemId) async {
    final rows = (await read(storeId)).toList(growable: true)
      ..removeWhere((row) => row.itemId == itemId);
    _fallback[storeId] = rows;
    await _persist(storeId, rows);
  }

  @override
  Future<void> clear(int storeId) async {
    _fallback.remove(storeId);
    try {
      await _storage.delete(key: _key(storeId));
    } catch (_) {}
  }

  Future<void> _persist(int storeId, List<RetailCartMutation> rows) async {
    try {
      if (rows.isEmpty) {
        await _storage.delete(key: _key(storeId));
      } else {
        await _storage.write(
          key: _key(storeId),
          value: jsonEncode(rows.map((row) => row.toJson()).toList()),
        );
      }
    } catch (_) {}
  }
}

class DefaultRetailCommerceApi implements RetailCommerceApi {
  DefaultRetailCommerceApi({
    required this.accountApi,
    required this.actionApi,
    required this.checkoutOptionsApi,
    required this.guestSession,
    required this.guestCartTokenStore,
    RetailCartMutationQueue? mutationQueue,
  }) : mutationQueue = mutationQueue ?? SecureRetailCartMutationQueue();

  final B2cAccountApi accountApi;
  final CustomerActionApi actionApi;
  final RetailCheckoutOptionsApi checkoutOptionsApi;
  final CustomerGuestSession guestSession;
  final CustomerGuestCartTokenStore guestCartTokenStore;
  final RetailCartMutationQueue mutationQueue;

  @override
  Future<RetailCartSnapshot> loadCart({required int storeId}) async {
    _requireStore(storeId);
    await _hydrateGuestToken(storeId);
    guestSession.activateStore(storeId);
    await _flushPendingMutations(storeId);
    final value = await accountApi.cart(storeId: storeId);
    await _persistGuestToken(storeId);
    final snapshot =
        RetailCartSnapshot.fromPayload(value, expectedStoreId: storeId);
    return snapshot.applyPending(await mutationQueue.read(storeId));
  }

  @override
  Future<RetailCartSnapshot> mergeGuestCartAfterAuthentication({
    required int storeId,
  }) async {
    _requireStore(storeId);
    await _hydrateGuestToken(storeId);
    final guestToken = guestSession.tokenForStore(storeId);
    final cart = await loadCart(storeId: storeId);

    if (guestToken != null && guestToken.trim().isNotEmpty) {
      guestSession.activateStore(storeId);
      guestSession.token = null;
      await guestCartTokenStore.removeToken(storeId);
    }

    return cart;
  }

  @override
  Future<RetailCartSnapshot> updateQuantity({
    required int storeId,
    required int itemId,
    required double quantity,
  }) async {
    _requireStore(storeId);
    if (itemId <= 0 || quantity <= 0) {
      throw const RetailCommerceException('invalid_cart_item');
    }
    await _hydrateGuestToken(storeId);
    guestSession.activateStore(storeId);
    try {
      final value = await accountApi.updateCartItem(itemId, quantity);
      await mutationQueue.remove(storeId, itemId);
      await _persistGuestToken(storeId);
      final snapshot =
          RetailCartSnapshot.fromPayload(value, expectedStoreId: storeId);
      return snapshot.applyPending(await mutationQueue.read(storeId));
    } catch (error, stack) {
      if (!_isTransientCartError(error)) rethrow;
      await mutationQueue.upsert(
        storeId,
        RetailCartMutation(
          operation: RetailCartMutationOperation.update,
          itemId: itemId,
          quantity: quantity,
        ),
      );
      try {
        final cached = await accountApi.cart(storeId: storeId);
        return RetailCartSnapshot.fromPayload(
          cached,
          expectedStoreId: storeId,
        ).applyPending(await mutationQueue.read(storeId));
      } catch (_) {
        Error.throwWithStackTrace(error, stack);
      }
    }
  }

  @override
  Future<RetailCartSnapshot> removeItem({
    required int storeId,
    required int itemId,
  }) async {
    _requireStore(storeId);
    await _hydrateGuestToken(storeId);
    guestSession.activateStore(storeId);
    try {
      await accountApi.removeCartItem(itemId);
      await mutationQueue.remove(storeId, itemId);
      await _persistGuestToken(storeId);
      return await loadCart(storeId: storeId);
    } catch (error, stack) {
      if (!_isTransientCartError(error)) rethrow;
      await mutationQueue.upsert(
        storeId,
        RetailCartMutation(
          operation: RetailCartMutationOperation.remove,
          itemId: itemId,
        ),
      );
      try {
        final cached = await accountApi.cart(storeId: storeId);
        return RetailCartSnapshot.fromPayload(
          cached,
          expectedStoreId: storeId,
        ).applyPending(await mutationQueue.read(storeId));
      } catch (_) {
        Error.throwWithStackTrace(error, stack);
      }
    }
  }

  @override
  Future<RetailCheckoutOptions> checkoutOptions({required int storeId}) {
    _requireStore(storeId);
    return checkoutOptionsApi.load(storeId: storeId);
  }

  @override
  Future<RetailCreatedOrder> submitCheckout({
    required int storeId,
    required int addressId,
    required String paymentMethod,
    String? couponCode,
    required String idempotencyKey,
  }) async {
    _requireStore(storeId);
    if (addressId <= 0 || paymentMethod.trim().isEmpty) {
      throw const RetailCommerceException('checkout_fields_required');
    }

    try {
      final value = await actionApi.checkout(
        addressId: addressId,
        storeId: storeId,
        paymentMethod: paymentMethod,
        couponCode: couponCode,
        idempotencyKey: idempotencyKey,
      );
      return RetailCreatedOrder.fromPayload(
        value,
        expectedStoreId: storeId,
      );
    } on CustomerActionException catch (error) {
      throw RetailCommerceException.fromCustomerAction(error);
    }
  }

  Future<void> _flushPendingMutations(int storeId) async {
    final pending = await mutationQueue.read(storeId);
    if (pending.isEmpty) return;

    for (final mutation in pending) {
      try {
        if (mutation.operation == RetailCartMutationOperation.remove) {
          await accountApi.removeCartItem(mutation.itemId);
        } else {
          final quantity = mutation.quantity;
          if (quantity == null || quantity <= 0) {
            await mutationQueue.remove(storeId, mutation.itemId);
            continue;
          }
          await accountApi.updateCartItem(mutation.itemId, quantity);
        }
        await mutationQueue.remove(storeId, mutation.itemId);
      } catch (_) {
        // Preserve the remaining durable queue. A later foreground refresh will
        // retry it with the same final-per-item mutation order.
        return;
      }
    }
    await _persistGuestToken(storeId);
  }

  bool _isTransientCartError(Object error) {
    if (error is TimeoutException || error is http.ClientException) return true;
    return error is B2cAccountException &&
        const <String>{
          'network_unavailable',
          'request_timeout',
          'service_unavailable',
        }.contains(error.code);
  }

  Future<void> _hydrateGuestToken(int storeId) async {
    if (guestSession.tokenForStore(storeId) != null) {
      return;
    }

    final persisted = await guestCartTokenStore.readToken(storeId);
    if (persisted != null && persisted.trim().isNotEmpty) {
      guestSession.captureStoreToken(storeId, persisted.trim());
    }
  }

  Future<void> _persistGuestToken(int storeId) async {
    final token = guestSession.tokenForStore(storeId);
    if (token != null && token.trim().isNotEmpty) {
      await guestCartTokenStore.writeToken(storeId, token.trim());
    }
  }

  void _requireStore(int storeId) {
    if (storeId <= 0) {
      throw const RetailCommerceException('store_required');
    }
  }
}

typedef RetailIdempotencyKeyFactory = String Function(int storeId);

abstract interface class RetailCheckoutAttemptStore {
  Future<String?> read(String fingerprint);
  Future<void> write(String fingerprint, String idempotencyKey);
  Future<void> remove(String fingerprint);
}

class SecureRetailCheckoutAttemptStore implements RetailCheckoutAttemptStore {
  SecureRetailCheckoutAttemptStore({FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage();

  static const _key = 'foodex.retail.checkout.attempts.v1';
  final FlutterSecureStorage _storage;
  final Map<String, String> _fallback = <String, String>{};

  Future<Map<String, String>> _readAll() async {
    try {
      final raw = await _storage.read(key: _key);
      if (raw == null || raw.isEmpty) {
        return Map<String, String>.from(_fallback);
      }
      final decoded = jsonDecode(raw);
      if (decoded is! Map) return Map<String, String>.from(_fallback);
      return <String, String>{
        for (final entry in decoded.entries)
          entry.key.toString(): entry.value.toString(),
      };
    } catch (_) {
      return Map<String, String>.from(_fallback);
    }
  }

  Future<void> _persist(Map<String, String> values) async {
    _fallback
      ..clear()
      ..addAll(values);
    try {
      if (values.isEmpty) {
        await _storage.delete(key: _key);
      } else {
        await _storage.write(key: _key, value: jsonEncode(values));
      }
    } catch (_) {}
  }

  @override
  Future<String?> read(String fingerprint) async =>
      (await _readAll())[fingerprint];

  @override
  Future<void> write(String fingerprint, String idempotencyKey) async {
    final values = await _readAll();
    values[fingerprint] = idempotencyKey;
    await _persist(values);
  }

  @override
  Future<void> remove(String fingerprint) async {
    final values = await _readAll();
    values.remove(fingerprint);
    await _persist(values);
  }
}

class RetailCheckoutSubmissionGuard {
  RetailCheckoutSubmissionGuard(
    this.api, {
    RetailIdempotencyKeyFactory? idempotencyKeyFactory,
    RetailCheckoutAttemptStore? attemptStore,
  })  : _idempotencyKeyFactory = idempotencyKeyFactory ??
            ((storeId) =>
                'retail-$storeId-${DateTime.now().microsecondsSinceEpoch}'),
        _attemptStore = attemptStore ?? SecureRetailCheckoutAttemptStore();

  final RetailCommerceApi api;
  final RetailIdempotencyKeyFactory _idempotencyKeyFactory;
  final RetailCheckoutAttemptStore _attemptStore;

  bool _inFlight = false;
  String? _fingerprint;
  String? _idempotencyKey;

  bool get inFlight => _inFlight;

  Future<RetailCreatedOrder> submit({
    required int storeId,
    required int addressId,
    required String paymentMethod,
    String? couponCode,
  }) async {
    if (_inFlight) {
      throw const RetailCommerceException('checkout_in_progress');
    }

    final normalizedCoupon = couponCode?.trim().toUpperCase();
    final fingerprint = [
      storeId,
      addressId,
      paymentMethod.trim(),
      normalizedCoupon ?? '',
    ].join('|');

    if (_fingerprint != fingerprint || _idempotencyKey == null) {
      _fingerprint = fingerprint;
      _idempotencyKey = await _attemptStore.read(fingerprint) ??
          _idempotencyKeyFactory(storeId);
      await _attemptStore.write(fingerprint, _idempotencyKey!);
    }

    _inFlight = true;
    try {
      final order = await api.submitCheckout(
        storeId: storeId,
        addressId: addressId,
        paymentMethod: paymentMethod.trim(),
        couponCode: normalizedCoupon,
        idempotencyKey: _idempotencyKey!,
      );
      await _attemptStore.remove(fingerprint);
      _fingerprint = null;
      _idempotencyKey = null;
      return order;
    } finally {
      _inFlight = false;
    }
  }
}

Object? _decodeResponse(http.Response response) {
  Object? body;
  if (response.body.isNotEmpty) {
    try {
      body = jsonDecode(response.body);
    } catch (_) {
      body = null;
    }
  }

  if (response.statusCode < 200 || response.statusCode >= 300) {
    var code = 'http_${response.statusCode}';
    if (body is Map && body['code'] is String) {
      final serverCode = (body['code'] as String).trim();
      if (serverCode.isNotEmpty) code = serverCode;
    } else if (body is Map && body['message'] is String) {
      code = body['message'] as String;
    }
    throw RetailCommerceException(
      code,
      fieldErrors: _fieldErrors(body),
    );
  }

  return body;
}

Map<String, List<String>> _fieldErrors(Object? body) {
  if (body is! Map || body['errors'] is! Map) {
    return const <String, List<String>>{};
  }

  final result = <String, List<String>>{};
  for (final entry in (body['errors'] as Map).entries) {
    final value = entry.value;
    result[entry.key.toString()] = value is List
        ? value.map((item) => item.toString()).toList(growable: false)
        : <String>[value.toString()];
  }
  return result;
}

Map<String, dynamic> _asMap(Object? value) {
  if (value is Map<String, dynamic>) return value;
  if (value is Map) return Map<String, dynamic>.from(value);
  throw const RetailCommerceException('invalid_response');
}

List<Object?> _asList(Object? value) =>
    value is List ? List<Object?>.from(value) : const <Object?>[];

int _asInt(Object? value, {int fallback = 0}) =>
    value is int ? value : int.tryParse(value?.toString() ?? '') ?? fallback;

double _asDouble(Object? value, {double fallback = 0}) => value is num
    ? value.toDouble()
    : double.tryParse(value?.toString() ?? '') ?? fallback;
