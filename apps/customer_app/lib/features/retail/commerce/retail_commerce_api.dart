import 'dart:convert';

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
  });

  final int storeId;
  final String currency;
  final List<RetailCartItem> items;
  final double subtotal;
  final double grandTotal;
  final bool hasUnavailableItems;

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

class DefaultRetailCommerceApi implements RetailCommerceApi {
  DefaultRetailCommerceApi({
    required this.accountApi,
    required this.actionApi,
    required this.checkoutOptionsApi,
    required this.guestSession,
    required this.guestCartTokenStore,
  });

  final B2cAccountApi accountApi;
  final CustomerActionApi actionApi;
  final RetailCheckoutOptionsApi checkoutOptionsApi;
  final CustomerGuestSession guestSession;
  final CustomerGuestCartTokenStore guestCartTokenStore;

  @override
  Future<RetailCartSnapshot> loadCart({required int storeId}) async {
    _requireStore(storeId);
    await _hydrateGuestToken(storeId);
    guestSession.activateStore(storeId);
    final value = await accountApi.cart(storeId: storeId);
    await _persistGuestToken(storeId);
    return RetailCartSnapshot.fromPayload(value, expectedStoreId: storeId);
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
    final value = await accountApi.updateCartItem(itemId, quantity);
    await _persistGuestToken(storeId);
    return RetailCartSnapshot.fromPayload(value, expectedStoreId: storeId);
  }

  @override
  Future<RetailCartSnapshot> removeItem({
    required int storeId,
    required int itemId,
  }) async {
    _requireStore(storeId);
    await _hydrateGuestToken(storeId);
    guestSession.activateStore(storeId);
    await accountApi.removeCartItem(itemId);
    await _persistGuestToken(storeId);
    return loadCart(storeId: storeId);
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

class RetailCheckoutSubmissionGuard {
  RetailCheckoutSubmissionGuard(
    this.api, {
    RetailIdempotencyKeyFactory? idempotencyKeyFactory,
  }) : _idempotencyKeyFactory =
           idempotencyKeyFactory ??
           ((storeId) =>
               'retail-$storeId-${DateTime.now().microsecondsSinceEpoch}');

  final RetailCommerceApi api;
  final RetailIdempotencyKeyFactory _idempotencyKeyFactory;

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
      _idempotencyKey = _idempotencyKeyFactory(storeId);
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
    if (body is Map && body['message'] is String) {
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

double _asDouble(Object? value, {double fallback = 0}) =>
    value is num
    ? value.toDouble()
    : double.tryParse(value?.toString() ?? '') ?? fallback;
